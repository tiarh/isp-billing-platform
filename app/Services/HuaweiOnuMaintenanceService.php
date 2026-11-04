<?php

namespace App\Services;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Olt;
use App\Services\Olt\OltProvisioningGuard;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Huawei MA5608T ONU maintenance (UI): delete, rename (description) and reboot.
 *
 * Separate from the PSB / migration / device-recovery write paths: it reuses
 * only the SSH primitives and the Huawei session lock of
 * HuaweiOltOnlyProvisioningService and never changes how those paths work.
 *
 * Every write method:
 *  - pins the ONT by serial number before any write (the ONT-ID can be reused
 *    by PSB within seconds of a delete);
 *  - runs the whole write sequence inside ONE SSH session (session lock held);
 *  - returns a result array instead of throwing for every deterministic
 *    outcome. Only "OLT busy before any command" (HuaweiOltSessionBusyException)
 *    and the shared provisioning gate (OltProvisioningBlockedException) are
 *    thrown, because in both cases nothing was written.
 *
 * Command syntax marked [V] in the plan is verified on a test ONU during the
 * rollout before a customer ONU is touched.
 */
class HuaweiOnuMaintenanceService extends HuaweiOltOnlyProvisioningService
{
    public const NAME_MAX_LENGTH = 64;

    private const SAVE_CONNECT_ATTEMPTS = 6;

    /**
     * Delete the ONT and every service-port it owns.
     *
     * @return array<string, mixed>
     */
    public function deleteOnuWithServicePorts(Olt $olt, string $onuRef, string $expectedSerial, bool $allowOnline = false, string $operation = 'ui_delete_onu'): array
    {
        [$port, $onuId, $ref] = $this->resolveRef($onuRef);
        $expectedKey = $this->pinKey($expectedSerial);
        if ($expectedKey === '') {
            return $this->failure('serial_unknown', 'SN ONU tidak diketahui; tidak ada perubahan.', $ref);
        }

        $dry = $this->gate($olt, $operation, $ref, [
            "display ont info 0 0 {$port} {$onuId}",
            "display service-port port 0/0/{$port} ont {$onuId}",
            'undo service-port <index>',
            'interface gpon 0/0',
            "ont delete {$port} {$onuId}",
            'save',
        ]);
        if ($dry !== null) {
            return $dry + ['onu_ref' => $ref];
        }

        $outputs = [];
        $removed = [];
        $alreadyGone = [];
        $ssh = $this->connect($olt);
        $alreadyAbsent = false;

        try {
            // A1: pin the ONT.
            $info = $this->exec($ssh, "display ont info 0 0 {$port} {$onuId}", $outputs);
            $row = $this->ontRow($info, $port, $onuId);
            if ($row === null) {
                if ($this->isMissingOntOutput($info)) {
                    return $this->idempotentAbsent($ref, $outputs);
                }

                return $this->failure('ont_info_unreadable', 'Huawei tidak mengembalikan data ONT yang bisa dibaca; tidak ada perubahan.', $ref, $outputs);
            }
            if ($this->pinKey((string) ($row['serial_number'] ?? '')) !== $expectedKey) {
                return $this->failure('serial_mismatch', 'SN di OLT berbeda dengan SN yang dipilih (ONT-ID dipakai ulang?). Tidak ada perubahan.', $ref, $outputs);
            }
            if (($row['state'] ?? null) === 'online' && ! $allowOnline) {
                return $this->failure('onu_online', 'ONU masih online; hapus ditolak. Tidak ada perubahan.', $ref, $outputs);
            }

            // A2: list the service-ports of this ONT.
            $listCommand = "display service-port port 0/0/{$port} ont {$onuId}";
            $listOutput = $this->exec($ssh, $listCommand, $outputs);
            $scope = $this->scopedServicePorts($listOutput, $port, $onuId);
            if ($scope['error'] !== null) {
                return $this->failure($scope['error'], $scope['message'], $ref, $outputs);
            }
            $ports = $scope['rows'];

            // A3-A4: undo each (already verified) service-port.
            if ($ports !== []) {
                $this->exec($ssh, 'config', $outputs);
                foreach ($ports as $servicePort) {
                    $undo = $this->exec($ssh, 'undo service-port '.(int) $servicePort['index'], $outputs);
                    if ($this->isCommandFailure($undo)) {
                        if (preg_match('/does not exist|not exist/i', $undo) === 1) {
                            // Already gone: idempotent, but never counted as removed by us.
                            $alreadyGone[] = $servicePort;

                            continue;
                        }

                        return $this->decorateDeleteFailure($this->failure('command_rejected', 'Huawei menolak undo service-port '.(int) $servicePort['index'].'.', $ref, $outputs, $removed, partial: $removed !== []), $port, $onuId, $removed);
                    }
                    $removed[] = $servicePort;
                }

                // A5: nothing may be left.
                $recheck = $this->exec($ssh, $listCommand, $outputs);
                $left = $this->scopedServicePorts($recheck, $port, $onuId);
                if ($left['error'] !== null || $left['rows'] !== []) {
                    $code = $removed !== [] ? 'partial_service_ports_removed' : 'service_port_still_present';

                    return $this->decorateDeleteFailure($this->failure($code, 'Service-port ONU masih ada setelah undo; ONU tidak dihapus.', $ref, $outputs, $removed, partial: $removed !== []), $port, $onuId, $removed);
                }
            } else {
                $this->exec($ssh, 'config', $outputs);
            }

            // A6-A7: delete the ONT.
            $this->exec($ssh, 'interface gpon 0/0', $outputs);
            $deleteOutput = $this->exec($ssh, "ont delete {$port} {$onuId}", $outputs);
            $deleteCode = $this->deleteFailureCode($deleteOutput);
            if ($deleteCode === 'already_absent') {
                $alreadyAbsent = true;
            } elseif ($deleteCode !== null) {
                $message = $deleteCode === 'service_port_still_present'
                    ? 'Huawei: ONT masih punya service virtual port; ONU tidak dihapus.'
                    : 'Huawei menolak ont delete.';

                return $this->decorateDeleteFailure($this->failure($deleteCode, $message, $ref, $outputs, $removed, partial: $removed !== []), $port, $onuId, $removed);
            }
        } catch (HuaweiOltSessionBusyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->decorateDeleteFailure($this->throwableFailure($e, $ref, $outputs, $removed), $port, $onuId, $removed);
        } finally {
            $this->disconnectSafely($ssh);
        }

        // Session B: save + our own single-shot read-back.
        $saveSession = $this->saveAndReadBack($olt, $port, $onuId, $outputs, saveFirst: true);
        $absent = false;
        $stillPresent = false;
        $reusedBySerial = false;
        if ($saveSession['output'] !== null) {
            $row = $this->ontRow($saveSession['output'], $port, $onuId);
            if ($row === null) {
                $absent = $this->isMissingOntOutput($saveSession['output']);
            } elseif ($this->pinKey((string) ($row['serial_number'] ?? '')) === $expectedKey) {
                $stillPresent = true;
            } else {
                $absent = true;
                $reusedBySerial = true;
            }
        }

        if ($stillPresent) {
            return $this->decorateDeleteFailure($this->failure('still_present_after_delete', 'ONT masih terbaca di OLT setelah ont delete; tidak dibersihkan dari inventory.', $ref, $outputs, $removed, partial: true) + [
                'save_pending' => $saveSession['save_pending'],
            ], $port, $onuId, $removed);
        }

        return [
            'success' => true,
            'device_write' => true,
            'onu_ref' => $ref,
            'verified_absent' => $absent,
            'readback_pending' => ! $absent,
            'already_absent' => $alreadyAbsent,
            'id_reused_by_other_serial' => $reusedBySerial,
            'save_pending' => $saveSession['save_pending'],
            'removed_service_ports' => $removed,
            'already_absent_service_ports' => $alreadyGone,
            'rebuild_hint' => $this->rebuildHint($port, $onuId, $removed),
            'outputs' => $outputs,
        ];
    }

    /**
     * Read-only preview of the service-ports a delete would remove (A2 only:
     * one `display service-port` read, no gate, no write). Never throws.
     *
     * @return array{ok: bool, count: int, indexes: list<int>, error: ?string}
     */
    public function previewServicePorts(Olt $olt, string $onuRef): array
    {
        $ssh = null;

        try {
            [$port, $onuId] = $this->resolveRef($onuRef);
            $outputs = [];
            $ssh = $this->connect($olt);
            $output = $this->exec($ssh, "display service-port port 0/0/{$port} ont {$onuId}", $outputs);
            $scope = $this->scopedServicePorts($output, $port, $onuId);
            if ($scope['error'] !== null) {
                return ['ok' => false, 'count' => 0, 'indexes' => [], 'error' => (string) $scope['error']];
            }

            return [
                'ok' => true,
                'count' => count($scope['rows']),
                'indexes' => array_map(static fn (array $r): int => (int) $r['index'], $scope['rows']),
                'error' => null,
            ];
        } catch (HuaweiOltSessionBusyException) {
            return ['ok' => false, 'count' => 0, 'indexes' => [], 'error' => 'olt_session_busy'];
        } catch (\Throwable $e) {
            return ['ok' => false, 'count' => 0, 'indexes' => [], 'error' => 'preview_failed'];
        } finally {
            if ($ssh !== null) {
                $this->disconnectSafely($ssh);
            }
        }
    }

    /**
     * Change the ONT description (`ont modify P ID desc "NAME"`).
     *
     * @return array<string, mixed>
     */
    public function renameOnuDescription(Olt $olt, string $onuRef, string $expectedSerial, string $name): array
    {
        [$port, $onuId, $ref] = $this->resolveRef($onuRef);
        $expectedKey = $this->pinKey($expectedSerial);
        if ($expectedKey === '') {
            return $this->failure('serial_unknown', 'SN ONU tidak diketahui; tidak ada perubahan.', $ref);
        }
        $name = $this->validName($name);
        if ($name === null) {
            return $this->failure('invalid_name', 'Nama ONU tidak valid untuk OLT Huawei (A-Z, 0-9, titik, underscore, maks '.self::NAME_MAX_LENGTH.').', $ref);
        }

        $dry = $this->gate($olt, 'ui_rename_onu', $ref, [
            "display ont info 0 0 {$port} {$onuId}",
            'interface gpon 0/0',
            "ont modify {$port} {$onuId} desc \"{$name}\"",
            'save',
        ]);
        if ($dry !== null) {
            return $dry + ['onu_ref' => $ref];
        }

        $outputs = [];
        $oldName = null;
        $ssh = $this->connect($olt);

        try {
            $info = $this->exec($ssh, "display ont info 0 0 {$port} {$onuId}", $outputs);
            $row = $this->ontRow($info, $port, $onuId);
            if ($row === null) {
                $code = $this->isMissingOntOutput($info) ? 'onu_not_present' : 'ont_info_unreadable';

                return $this->failure($code, 'ONT tidak terbaca di OLT; tidak ada perubahan.', $ref, $outputs);
            }
            if ($this->pinKey((string) ($row['serial_number'] ?? '')) !== $expectedKey) {
                return $this->failure('serial_mismatch', 'SN di OLT berbeda dengan SN yang dipilih. Tidak ada perubahan.', $ref, $outputs);
            }
            $oldName = isset($row['onu_name']) ? trim((string) $row['onu_name']) : null;
            if ($oldName !== null && $oldName === $name) {
                return [
                    'success' => true,
                    'device_write' => false,
                    'unchanged' => true,
                    'verified_name' => true,
                    'save_pending' => false,
                    'onu_ref' => $ref,
                    'old_name' => $oldName,
                    'name' => $name,
                    'outputs' => $outputs,
                ];
            }

            $this->exec($ssh, 'config', $outputs);
            $this->exec($ssh, 'interface gpon 0/0', $outputs);
            $modify = $this->exec($ssh, "ont modify {$port} {$onuId} desc \"{$name}\"", $outputs);
            if ($this->isCommandFailure($modify)) {
                return $this->failure('command_rejected', 'Huawei menolak ont modify.', $ref, $outputs) + ['old_name' => $oldName];
            }
        } catch (HuaweiOltSessionBusyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->throwableFailure($e, $ref, $outputs, []) + ['old_name' => $oldName];
        } finally {
            $this->disconnectSafely($ssh);
        }

        // Session B: read-back FIRST (save only a name that was verified).
        $session = $this->saveAndReadBack(
            $olt,
            $port,
            $onuId,
            $outputs,
            saveFirst: false,
            saveIf: fn (array $row): bool => $this->pinKey((string) ($row['serial_number'] ?? '')) === $expectedKey
                && trim((string) ($row['onu_name'] ?? '')) === $name,
        );
        $verified = false;
        if ($session['output'] !== null) {
            $row = $this->ontRow($session['output'], $port, $onuId);
            $verified = $row !== null
                && $this->pinKey((string) ($row['serial_number'] ?? '')) === $expectedKey
                && trim((string) ($row['onu_name'] ?? '')) === $name;
        }

        if (! $verified) {
            return $this->failure('rename_not_verified', 'Nama baru belum terbukti pada read-back OLT; inventory tidak diubah.', $ref, $outputs, partial: true) + [
                'device_write' => true,
                'old_name' => $oldName,
                'name' => $name,
                'save_pending' => true,
            ];
        }

        return [
            'success' => true,
            'device_write' => true,
            'unchanged' => false,
            'verified_name' => true,
            'save_pending' => $session['save_pending'],
            'onu_ref' => $ref,
            'old_name' => $oldName,
            'name' => $name,
            'outputs' => $outputs,
        ];
    }

    /**
     * Reboot one ONT (`ont reset P ID` in the gpon interface context).
     *
     * @return array<string, mixed>
     */
    public function rebootOnuChecked(Olt $olt, string $onuRef, string $expectedSerial): array
    {
        [$port, $onuId, $ref] = $this->resolveRef($onuRef);
        $expectedKey = $this->pinKey($expectedSerial);
        if ($expectedKey === '') {
            return $this->failure('serial_unknown', 'SN ONU tidak diketahui; tidak ada perubahan.', $ref);
        }

        $dry = $this->gate($olt, 'ui_reboot_onu', $ref, [
            "display ont info 0 0 {$port} {$onuId}",
            'interface gpon 0/0',
            "ont reset {$port} {$onuId}",
        ]);
        if ($dry !== null) {
            return $dry + ['onu_ref' => $ref];
        }

        $outputs = [];
        $ssh = $this->connect($olt);

        try {
            $info = $this->exec($ssh, "display ont info 0 0 {$port} {$onuId}", $outputs);
            $row = $this->ontRow($info, $port, $onuId);
            if ($row === null) {
                $code = $this->isMissingOntOutput($info) ? 'onu_not_present' : 'ont_info_unreadable';

                return $this->failure($code, 'ONT tidak terbaca di OLT; tidak ada perubahan.', $ref, $outputs);
            }
            if ($this->pinKey((string) ($row['serial_number'] ?? '')) !== $expectedKey) {
                return $this->failure('serial_mismatch', 'SN di OLT berbeda dengan SN yang dipilih. Tidak ada perubahan.', $ref, $outputs);
            }

            $this->exec($ssh, 'config', $outputs);
            $this->exec($ssh, 'interface gpon 0/0', $outputs);
            $reset = $this->exec($ssh, "ont reset {$port} {$onuId}", $outputs);
            if ($this->isCommandFailure($reset)) {
                return $this->failure('command_rejected', 'Huawei menolak ont reset.', $ref, $outputs);
            }
        } catch (HuaweiOltSessionBusyException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->throwableFailure($e, $ref, $outputs, []);
        } finally {
            $this->disconnectSafely($ssh);
        }

        // Read-back in a fresh session: the ONT must still be registered with
        // the same serial (a reboot never deletes it). Run state is transient.
        $session = $this->saveAndReadBack($olt, $port, $onuId, $outputs, saveFirst: false, save: false);
        $registered = false;
        if ($session['output'] !== null) {
            $row = $this->ontRow($session['output'], $port, $onuId);
            $registered = $row !== null && $this->pinKey((string) ($row['serial_number'] ?? '')) === $expectedKey;
        }

        return [
            'success' => true,
            'device_write' => true,
            'onu_ref' => $ref,
            'reset_sent' => true,
            'verified_registered' => $registered,
            'readback_pending' => ! $registered,
            'outputs' => $outputs,
        ];
    }

    /**
     * Only `save` (retry of a pending save).
     *
     * @return array<string, mixed>
     */
    public function saveConfig(Olt $olt): array
    {
        $dry = $this->gate($olt, 'ui_save_config', 'olt-'.$olt->getKey(), ['save']);
        if ($dry !== null) {
            return $dry;
        }

        $outputs = [];
        $ssh = null;
        for ($attempt = 1; $attempt <= self::SAVE_CONNECT_ATTEMPTS; $attempt++) {
            try {
                $ssh = $this->connect($olt);
                break;
            } catch (HuaweiOltSessionBusyException) {
                $this->pauseBetweenSessionAttempts($attempt);
            }
        }
        if ($ssh === null) {
            throw new HuaweiOltSessionBusyException;
        }

        try {
            $output = $this->exec($ssh, 'save', $outputs);
        } catch (\Throwable $e) {
            return $this->throwableFailure($e, 'olt-'.$olt->getKey(), $outputs, []) + ['save_pending' => true];
        } finally {
            $this->disconnectSafely($ssh);
        }

        $ok = ! $this->isCommandFailure($output);

        return [
            'success' => $ok,
            'device_write' => true,
            'save_pending' => ! $ok,
            'outputs' => $outputs,
        ] + ($ok ? [] : ['error_code' => 'command_rejected', 'message' => 'Huawei menolak save.']);
    }

    /**
     * Parse the rows of `display service-port port 0/0/P ont ID` that belong to
     * exactly this PON and ONT-ID (F/S/P and VPI). Pure function.
     *
     * @return list<array{index: int, vlan: int, gemport: int, user_vlan: int, state: string}>
     */
    public function parseServicePortRows(string $output, int $port, int $onuId): array
    {
        $rows = [];
        foreach ($this->parseServicePortTable($output) as $row) {
            if ($row['frame'] === 0 && $row['slot'] === 0 && $row['port'] === $port && $row['ont_id'] === $onuId) {
                $rows[] = [
                    'index' => $row['index'],
                    'vlan' => $row['vlan'],
                    'gemport' => $row['gemport'],
                    'user_vlan' => $row['user_vlan'],
                    'rx' => $row['rx'],
                    'tx' => $row['tx'],
                    'state' => $row['state'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Every service-port row in the output, with its F/S/P and VPI (ONT-ID).
     *
     * @return list<array{index: int, vlan: int, frame: int, slot: int, port: int, ont_id: int, gemport: int, user_vlan: int, state: string}>
     */
    public function parseServicePortTable(string $output): array
    {
        $rows = [];
        $pattern = '/^\s*(\d+)\s+(\d+)\s+\S+\s+gpon\s+(\d+)\s*\/\s*(\d+)\s*\/\s*(\d+)\s+(\d+)\s+(\d+)\s+vlan\s+(\d+)\b(.*?)\b(up|down)\s*$/i';
        foreach (preg_split('/\R/', $this->stripTerminalArtifacts($output)) ?: [] as $line) {
            if (preg_match($pattern, $line, $m) !== 1) {
                continue;
            }
            $tokens = preg_split('/\s+/', trim($m[9]), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $rows[] = [
                'index' => (int) $m[1],
                'vlan' => (int) $m[2],
                'frame' => (int) $m[3],
                'slot' => (int) $m[4],
                'port' => (int) $m[5],
                'ont_id' => (int) $m[6],
                'gemport' => (int) $m[7],
                'user_vlan' => (int) $m[8],
                'rx' => count($tokens) >= 2 ? $tokens[count($tokens) - 2] : null,
                'tx' => count($tokens) >= 2 ? $tokens[count($tokens) - 1] : null,
                'state' => strtolower($m[10]),
            ];
        }

        return $rows;
    }

    public function validName(string $name): ?string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]*$/', $name) !== 1) {
            return null;
        }

        return $this->normalizeDescription($name) === '' ? null : $name;
    }

    /** Pause between busy-session attempts of session B (overridden in tests). */
    protected function pauseBetweenSessionAttempts(int $attempt): void
    {
        sleep(1);
    }

    /**
     * Session B: optional `save`, then a single-shot ONT read-back.
     *
     * @param  list<array{command: string, output: string}>  $outputs
     * @return array{output: ?string, save_pending: bool}
     */
    private function saveAndReadBack(Olt $olt, int $port, int $onuId, array &$outputs, bool $saveFirst, bool $save = true, ?\Closure $saveIf = null): array
    {
        $ssh = null;
        for ($attempt = 1; $attempt <= self::SAVE_CONNECT_ATTEMPTS; $attempt++) {
            try {
                $ssh = $this->connect($olt);
                break;
            } catch (HuaweiOltSessionBusyException) {
                $this->pauseBetweenSessionAttempts($attempt);
            } catch (\Throwable) {
                break;
            }
        }
        if ($ssh === null) {
            return ['output' => null, 'save_pending' => $save];
        }

        $savePending = $save;
        $readOutput = null;
        try {
            $readCommand = "display ont info 0 0 {$port} {$onuId}";
            if ($save && $saveFirst) {
                $savePending = $this->trySave($ssh, $outputs);
            }
            $readOutput = $this->exec($ssh, $readCommand, $outputs);
            if ($save && ! $saveFirst) {
                $row = $this->ontRow($readOutput, $port, $onuId);
                // Save only a read-back that shows the expected content.
                if ($row !== null && ($saveIf === null || $saveIf($row))) {
                    $savePending = $this->trySave($ssh, $outputs);
                }
            }
        } catch (\Throwable) {
            // The device change itself is already live; report pending.
        } finally {
            $this->disconnectSafely($ssh);
        }

        return ['output' => $readOutput, 'save_pending' => $savePending];
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     * @return bool true when the save is STILL pending
     */
    private function trySave(SSH2 $ssh, array &$outputs): bool
    {
        try {
            $output = $this->exec($ssh, 'save', $outputs);

            return $this->isCommandFailure($output);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     */
    private function exec(SSH2 $ssh, string $command, array &$outputs): string
    {
        $output = $this->runInteractive($ssh, $command, count($outputs) + 1);
        $outputs[] = ['command' => $command, 'output' => $this->trimRaw($output)];

        return $output;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, error: ?string, message: string}
     */
    private function scopedServicePorts(string $output, int $port, int $onuId): array
    {
        $table = $this->parseServicePortTable($output);

        // Every candidate row (and the table's own "Total : N") must have been
        // parsed. A non-vlan flow type or a wrapped line would otherwise hide a
        // service-port: abort before the first undo.
        $clean = $this->stripTerminalArtifacts($output);
        $candidates = preg_match_all('/^\s*\d+\s+\d+\s+\S+\s+gpon\b/im', $clean);
        $declared = preg_match('/\bTotal\s*:\s*(\d+)/i', $clean, $total) === 1 ? (int) $total[1] : null;
        if ($candidates !== count($table) || ($declared !== null && $declared !== count($table))) {
            return [
                'rows' => [],
                'error' => 'service_port_table_unparsed',
                'message' => 'Tabel service-port tidak terbaca utuh (baris non-vlan / terpotong / jumlah tidak sama dengan Total); tidak ada yang dihapus.',
            ];
        }

        foreach ($table as $row) {
            if ($row['frame'] !== 0 || $row['slot'] !== 0 || $row['port'] !== $port || $row['ont_id'] !== $onuId) {
                return [
                    'rows' => [],
                    'error' => 'service_port_scope_mismatch',
                    'message' => 'Tabel service-port berisi baris di luar ONT target; tidak ada yang dihapus.',
                ];
            }
        }

        if ($table === [] && $this->isCommandFailure($output)
            && ! preg_match('/no service virtual port|not exist|does not exist/i', $output)) {
            return [
                'rows' => [],
                'error' => 'command_rejected',
                'message' => 'Huawei menolak display service-port.',
            ];
        }

        return [
            'rows' => $this->parseServicePortRows($output, $port, $onuId),
            'error' => null,
            'message' => '',
        ];
    }

    private function deleteFailureCode(string $output): ?string
    {
        if (preg_match('/has some service virtual ports/i', $output) === 1) {
            return 'service_port_still_present';
        }
        if ($this->isMissingOntOutput($output)) {
            return 'already_absent';
        }
        if (preg_match('/\bonline\b/i', $output) === 1 && $this->isCommandFailure($output)) {
            return 'onu_online';
        }
        if ($this->isCommandFailure($output)) {
            return 'command_rejected';
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function ontRow(string $output, int $port, int $onuId): ?array
    {
        foreach ($this->parsePortOutput($output, $port) as $row) {
            if (($row['onu_ref'] ?? '') === "0/0/{$port}:{$onuId}") {
                return $row;
            }
        }

        return null;
    }

    private function pinKey(string $serial): string
    {
        return $this->serialKey($this->normalizeHuaweiSerial($serial));
    }

    /**
     * @return array{0: int, 1: int, 2: string}
     */
    private function resolveRef(string $onuRef): array
    {
        $normalized = $this->normalizeOnuRef($onuRef);
        if ($normalized === null) {
            throw new RuntimeException('Referensi ONU Huawei tidak valid (contoh 0/0/15:2).');
        }
        if (preg_match('/^0\/0\/(\d+):(\d+)$/', $normalized, $match) !== 1) {
            throw new RuntimeException('Referensi ONU Huawei tidak valid (contoh 0/0/15:2).');
        }

        return [(int) $match[1], (int) $match[2], $normalized];
    }

    /**
     * Shared provisioning gate with a FRESH internal approval (never reuse the
     * middleware approval: it is a consumed one-time lock).
     *
     * @param  list<string>  $commands
     * @return array<string, mixed>|null non-null = dry-run, nothing may be sent
     */
    private function gate(Olt $olt, string $operation, string $subject, array $commands): ?array
    {
        $approval = app(OltProvisioningGuard::class)->issueInternalApproval($olt, $operation, $subject);
        $guard = app(OltProvisioningGuard::class)->provision($olt, ['commands' => $commands, ...$approval]);
        if (($guard['dry_run'] ?? true) === true) {
            return [
                'success' => false,
                'device_write' => false,
                'dry_run' => true,
                'error_code' => 'dry_run',
                'message' => 'OLT write dry-run aktif; tidak ada perintah dikirim.',
            ];
        }

        return null;
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     * @param  list<array<string, mixed>>  $removed
     * @return array<string, mixed>
     */
    private function failure(string $code, string $message, string $ref, array $outputs = [], array $removed = [], bool $partial = false): array
    {
        return [
            'success' => false,
            'device_write' => $partial,
            'error_code' => $code,
            'message' => $message,
            'error' => $message,
            'onu_ref' => $ref,
            'partial' => $partial,
            'removed_service_ports' => $removed,
            'outputs' => $outputs,
        ];
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     * @return array<string, mixed>
     */
    private function idempotentAbsent(string $ref, array $outputs): array
    {
        return [
            'success' => true,
            'device_write' => false,
            'onu_ref' => $ref,
            'verified_absent' => true,
            'readback_pending' => false,
            'already_absent' => true,
            'save_pending' => false,
            'removed_service_ports' => [],
            'outputs' => $outputs,
        ];
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     * @param  list<array<string, mixed>>  $removed
     * @return array<string, mixed>
     */
    private function throwableFailure(\Throwable $e, string $ref, array $outputs, array $removed): array
    {
        $partial = $removed !== [] || $this->anyCommandSentWrite($outputs);
        if ($e instanceof HuaweiOltCommandException) {
            return $this->failure($e->causeClass, $e->getMessage(), $ref, $outputs, $removed, $partial) + [
                'failure_type' => $e->failureType,
                'failure_stage' => $e->stage,
                'command_index' => $e->commandIndex,
                'command_label' => $e->commandLabel,
            ];
        }

        return $this->failure('transport', 'Huawei SSH gagal secara aman ('.class_basename($e).').', $ref, $outputs, $removed, $partial);
    }

    /**
     * @param  list<array{command: string, output: string}>  $outputs
     */
    private function anyCommandSentWrite(array $outputs): bool
    {
        foreach ($outputs as $entry) {
            if (preg_match('/^(undo |ont delete|ont modify|ont reset)/i', (string) ($entry['command'] ?? '')) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * A failure after >= 1 service-port was removed leaves the ONU registered
     * without them: always carry the rebuild hint and a clear warning.
     *
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $removed
     * @return array<string, mixed>
     */
    private function decorateDeleteFailure(array $result, int $port, int $onuId, array $removed): array
    {
        if ($removed === []) {
            return $result;
        }

        $count = count($removed);
        $warning = "{$count} service-port sudah dihapus, ONU belum terhapus — periksa/kembalikan dengan petunjuk berikut";
        $result['rebuild_hint'] = $this->rebuildHint($port, $onuId, $removed);
        $result['partial'] = true;
        $result['device_write'] = true;
        $result['warning'] = $warning;
        if (($result['error_code'] ?? '') === 'partial_service_ports_removed') {
            $result['message'] = $result['error'] = $warning;
        } else {
            $result['message'] = $result['error'] = ($result['message'] ?? '').' ('.$warning.')';
        }

        return $result;
    }

    /**
     * Service-port rebuild hint for the audit log (same shape as buildPlan).
     *
     * @param  list<array<string, mixed>>  $removed
     * @return list<string>
     */
    private function rebuildHint(int $port, int $onuId, array $removed): array
    {
        $hint = [];
        foreach ($removed as $row) {
            $line = sprintf(
                'service-port vlan %d gpon 0/0/%d ont %d gemport %d multi-service user-vlan %d',
                (int) $row['vlan'],
                $port,
                $onuId,
                (int) $row['gemport'],
                (int) $row['user_vlan'],
            );
            foreach (['rx' => 'inbound', 'tx' => 'outbound'] as $key => $direction) {
                $table = $row[$key] ?? null;
                if ($table !== null && $table !== '-') {
                    $line .= preg_match('/^\d+$/', (string) $table) === 1
                        ? " {$direction} traffic-table index {$table}"
                        : " {$direction} traffic-table name {$table}";
                }
            }
            $hint[] = $line;
        }

        return $hint;
    }
}
