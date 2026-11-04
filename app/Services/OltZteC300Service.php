<?php

namespace App\Services;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Support\OltSnmpCommunity;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;
use RuntimeException;
use Symfony\Component\Process\Process;

class OltZteC300Service
{
    public static bool $inAdversarialTestContext = false;

    /** auto (config allow-list decides), snmp (SNMP first) or ssh (SSH only). */
    protected string $inventorySource = 'auto';

    public function onuDetail(Olt $olt, string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        $gponRef = $this->toGponOnuRef($onuRef);
        $portRef = $this->extractPortFromOnuRef($onuRef) ?: '';

        $commandMap = [
            'state' => "show gpon onu state {$gponRef}",
            'baseinfo' => "show gpon onu baseinfo {$gponRef}",
            'power' => "show pon power onu-rx {$gponRef}",
            'running_config' => "show running-config interface {$gponRef}",
        ];

        $pexpectOutputs = $this->runPexpectCommandsOrFail($olt, array_values($commandMap), 60);
        $outputs = [];
        foreach ($commandMap as $key => $command) {
            $outputs[$key] = $pexpectOutputs[$command] ?? '';
        }

        if ($outputs['power'] === '' && $portRef !== '') {
            $fallback = "show pon power onu-rx {$this->toGponOltRef($portRef)}";
            $fallbackOutputs = $this->runPexpectCommands($olt, [$fallback], 45) ?? [];
            $outputs['power'] = $fallbackOutputs[$fallback] ?? $outputs['power'];
        }

        $snapshot = $this->singleOnuSnapshot($onuRef, $outputs);

        return [
            'onu' => $snapshot,
            'raw' => $outputs,
            'commands' => array_values($commandMap),
        ];
    }

    /**
     * Read-only live probe for delete safety checks. This firmware rejects the
     * per-ONU `show gpon onu state|baseinfo`, so use detail-info (physical
     * serial + phase) plus the port-level state table for cross-checking and
     * for proving absence. Throws only on transport failure; parsing is done
     * by App\Support\OnuLiveState.
     *
     * @return array{ref: string, detail: string, port: string}
     */
    public function liveOnuProbe(Olt $olt, string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        $detailCommand = 'show gpon onu detail-info '.$this->toGponOnuRef($onuRef);
        $portCommand = 'show gpon onu state '.$this->toGponOltRef($this->extractPortFromOnuRef($onuRef) ?: '');

        $outputs = $this->runPexpectCommandsOrFail($olt, [$detailCommand, $portCommand], 60);

        return [
            'ref' => $onuRef,
            'detail' => (string) ($outputs[$detailCommand] ?? ''),
            'port' => (string) ($outputs[$portCommand] ?? ''),
        ];
    }

    /**
     * RCA-2 cause enrichment: ONE read-only command (`show gpon onu detail-info`) with a short timeout.
     * Never writes; the caller owns locking / rate limits (App\Services\Outage\OltCauseEnricher).
     */
    public function onuDetailInfoText(Olt $olt, string $onuRef, int $timeoutSeconds = 25): string
    {
        return $this->onuDetailAndClock($olt, $onuRef, $timeoutSeconds)['detail'];
    }

    /**
     * Same single session, plus the read-only `show clock` (the ZTE clock is UTC but drifts: no NTP).
     *
     * @return array{detail:string,clock:?string}
     */
    public function onuDetailAndClock(Olt $olt, string $onuRef, int $timeoutSeconds = 25): array
    {
        $clock = 'show clock';
        $command = 'show gpon onu detail-info '.$this->toGponOnuRef($this->normalizeOnuRef($onuRef));
        $outputs = $this->runPexpectCommandsOrFail($olt, [$clock, $command], max(5, $timeoutSeconds));

        $c = (string) ($outputs[$clock] ?? '');

        return ['detail' => (string) ($outputs[$command] ?? ''), 'clock' => $c !== '' ? $c : null];
    }

    public function renameOnu(Olt $olt, string $onuRef, string $name): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('ONU name is required.');
        }

        return $this->runConfigOperation($olt, $onuRef, [
            'configure terminal',
            "interface {$this->toGponOnuRef($onuRef)}",
            "name {$this->escapeCliValue($name)}",
            'exit',
            'exit',
            'write',
        ], ['name' => $name]);
    }

    /**
     * @param  list<array{onu_ref: string, name: string}>  $renames
     * @return array{renamed: int, failed: list<array{onu_ref: string, name: string, error: string}>}
     */
    public function batchRenameOnus(Olt $olt, array $renames): array
    {
        if ($renames === []) {
            return ['renamed' => 0, 'failed' => []];
        }

        $commands = ['configure terminal'];
        $planned = [];

        foreach ($renames as $rename) {
            $onuRef = $this->normalizeOnuRef($rename['onu_ref']);
            $name = trim($rename['name']);
            if ($name === '') {
                continue;
            }

            $commands[] = "interface {$this->toGponOnuRef($onuRef)}";
            $commands[] = "name {$this->escapeCliValue($name)}";
            $commands[] = 'exit';
            $planned[] = ['onu_ref' => $onuRef, 'name' => $name];
        }

        if ($planned === []) {
            return ['renamed' => 0, 'failed' => []];
        }

        $commands[] = 'exit';
        $commands[] = 'write';

        $timeout = max(120, count($planned) * 3 + 60);
        $renamed = 0;
        $failed = [];

        try {
            $this->runPexpectCommandsOrFail($olt, $commands, $timeout);
            $renamed = count($planned);
        } catch (\Throwable $e) {
            foreach ($planned as $item) {
                $failed[] = [
                    'onu_ref' => $item['onu_ref'],
                    'name' => $item['name'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['renamed' => $renamed, 'failed' => $failed];
    }

    public function rebootOnu(Olt $olt, string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);

        return $this->runConfigOperation($olt, $onuRef, [
            'configure terminal',
            "pon-onu-mng {$this->toGponOnuRef($onuRef)}",
            'reboot',
            'yes',
            'exit',
        ]);
    }

    public function setOnuAdminState(Olt $olt, string $onuRef, bool $enabled): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);

        return $this->runConfigOperation($olt, $onuRef, [
            'configure terminal',
            "interface {$this->toGponOnuRef($onuRef)}",
            $enabled ? 'no shutdown' : 'shutdown',
            'exit',
            'exit',
            'write',
        ], ['enabled' => $enabled]);
    }

    public function deleteOnu(Olt $olt, string $onuRef): array
    {
        $onuRef = $this->normalizeOnuRef($onuRef);
        $portRef = $this->extractPortFromOnuRef($onuRef);
        $onuId = $this->extractOnuId($onuRef);

        if (! $portRef || ! $onuId) {
            throw new RuntimeException('Invalid ONU ref. Expected format 1/2/1:3.');
        }

        $deleteCmd = "no onu {$onuId}";
        $verifyCmd = "show gpon onu state {$this->toGponOnuRef($onuRef)}";
        $coreCommands = [
            'configure terminal',
            "interface {$this->toGponOltRef($portRef)}",
            $deleteCmd,
            'exit',
            'exit',
        ];

        try {
            $outputs = $this->runPexpectCommandsOrFail($olt, $coreCommands, 45);
            $commands = $coreCommands;
            $via = 'pexpect';
        } catch (\Throwable $pexpectError) {
            try {
                return $this->deleteOnuViaShell($olt, $onuRef, $portRef, $onuId, $deleteCmd, $verifyCmd);
            } catch (\Throwable $shellError) {
                throw new RuntimeException(
                    'Gagal hapus ONU via SSH. Pexpect: '.$pexpectError->getMessage().' | Shell: '.$shellError->getMessage()
                );
            }
        }

        [$commands, $outputs] = $this->appendOptionalDeleteSteps(
            $olt,
            $commands,
            $outputs,
            $verifyCmd,
            $via,
        );

        return $this->buildDeleteOnuResult($onuRef, $portRef, $onuId, $deleteCmd, $verifyCmd, $commands, $outputs, $via);
    }

    /**
     * Best-effort write/verify after core delete — must not block HTTP beyond proxy timeout.
     *
     * @param  array<int, string>  $commands
     * @param  array<string, string>  $outputs
     * @return array{0: array<int, string>, 1: array<string, string>}
     */
    protected function appendOptionalDeleteSteps(
        Olt $olt,
        array $commands,
        array $outputs,
        string $verifyCmd,
        string $via,
    ): array {
        if ($via === 'pexpect') {
            $optional = $this->runPexpectCommands($olt, ['write', $verifyCmd], 20) ?? [];
            foreach (['write', $verifyCmd] as $command) {
                $commands[] = $command;
                $outputs[$command] = (string) ($optional[$command] ?? '[SKIPPED: optional step timeout]');
            }

            return [$commands, $outputs];
        }

        return [$commands, $outputs];
    }

    protected function deleteOnuViaShell(
        Olt $olt,
        string $onuRef,
        string $portRef,
        int $onuId,
        string $deleteCmd,
        string $verifyCmd,
    ): array {
        $ssh = $this->connect($olt);
        $commands = [];
        $outputs = [];

        try {
            $steps = [
                'configure terminal',
                "interface {$this->toGponOltRef($portRef)}",
                $deleteCmd,
                'exit',
                'exit',
            ];

            foreach ($steps as $command) {
                $commands[] = $command;
                $output = $this->runShellCommandWithReconnect($olt, $ssh, $command);
                $outputs[$command] = $this->normalizeOutput($output);

                if ($command !== $deleteCmd || ! $this->isCommandFailure($output)) {
                    continue;
                }

                if ($this->isBenignDeleteError($output)) {
                    continue;
                }

                throw new RuntimeException("OLT command failed: {$command}\n{$outputs[$command]}");
            }

            $writeCmd = 'write';
            $commands[] = $writeCmd;
            try {
                $writeOutput = $this->runShellCommandWithReconnect($olt, $ssh, $writeCmd);
                $outputs[$writeCmd] = $this->normalizeOutput($writeOutput);
            } catch (\Throwable $e) {
                $outputs[$writeCmd] = $e->getMessage();
            }

            $commands[] = $verifyCmd;
            try {
                $verifyOutput = $this->runShellCommandWithReconnect($olt, $ssh, $verifyCmd);
                $outputs[$verifyCmd] = $this->normalizeOutput($verifyOutput);
            } catch (\Throwable $e) {
                $outputs[$verifyCmd] = $e->getMessage();
            }

            return $this->buildDeleteOnuResult($onuRef, $portRef, $onuId, $deleteCmd, $verifyCmd, $commands, $outputs, 'shell');
        } finally {
            $ssh->disconnect();
        }
    }

    protected function buildDeleteOnuResult(
        string $onuRef,
        string $portRef,
        int $onuId,
        string $deleteCmd,
        string $verifyCmd,
        array $commands,
        array $outputs,
        string $via,
    ): array {
        $warnings = [];
        $normalizedOutputs = [];

        foreach ($outputs as $command => $output) {
            $normalizedOutputs[$command] = is_string($output)
                ? $this->normalizeOutput($output)
                : (string) $output;
        }

        $deleteOutput = $normalizedOutputs[$deleteCmd] ?? '';
        if ($this->isCommandFailure($deleteOutput) && ! $this->isBenignDeleteError($deleteOutput)) {
            throw new RuntimeException("OLT command failed: {$deleteCmd}\n{$deleteOutput}");
        }

        if ($this->isBenignDeleteError($deleteOutput)) {
            $warnings[] = 'ONU tidak ditemukan di gpon-olt (mungkin sudah dihapus sebelumnya).';
        }

        $writeOutput = $normalizedOutputs['write'] ?? '';
        if ($writeOutput === '' || str_starts_with($writeOutput, '[ERROR:')) {
            $warnings[] = 'Perintah write dilewati — cek penyimpanan config di OLT.';
        } elseif ($this->isCommandFailure($writeOutput)) {
            $warnings[] = 'Perintah write mengembalikan peringatan — cek penyimpanan config di OLT.';
        }

        $verifyOutput = $normalizedOutputs[$verifyCmd] ?? '';
        if ($verifyOutput === '' || str_starts_with($verifyOutput, '[ERROR:')) {
            $verifiedAbsent = true;
            $warnings[] = 'Verifikasi state dilewati — lanjut bersihkan data DB.';
        } else {
            $verifiedAbsent = $this->isOnuAbsentFromState($verifyOutput, $onuRef);
            if (! $verifiedAbsent) {
                $warnings[] = 'Verifikasi: ONU masih terlihat di show gpon onu state — cek manual di OLT.';
            }
        }

        return [
            'success' => true,
            'onu_ref' => $onuRef,
            'pon_port' => $portRef,
            'onu_id' => $onuId,
            'warnings' => $warnings,
            'verified_absent' => $verifiedAbsent,
            'via' => $via,
            'commands' => $commands,
            'outputs' => $normalizedOutputs,
        ];
    }

    protected function runShellCommandWithReconnect(Olt $olt, SSH2 &$ssh, string $command): string
    {
        try {
            return $this->runShellCommand($ssh, $command);
        } catch (\Throwable $e) {
            if (! $this->isConnectionError($e)) {
                throw $e;
            }

            $ssh->disconnect();
            $ssh = $this->connect($olt);

            return $this->runShellCommand($ssh, $command);
        }
    }

    protected function isConnectionError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        foreach (['connection closed', 'timed out', 'unable to connect', 'not connected', 'broken pipe'] as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function isBenignDeleteError(string $output): bool
    {
        $normalized = strtoupper($this->normalizeOutput($output));

        foreach ([
            'NOT FOUND',
            'DOES NOT EXIST',
            'NO SUCH',
            'NOT EXIST',
            'NOT EXISTING',
            'INVALID INPUT',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function isOnuAbsentFromState(string $output, string $onuRef): bool
    {
        $normalized = strtolower($this->normalizeOutput($output));
        if ($normalized === '') {
            return true;
        }

        foreach (['not found', 'no related', 'does not exist', 'invalid'] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        $ref = strtolower(preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef);

        return ! str_contains($normalized, $ref);
    }

    public function authorizeOnu(Olt $olt, array $payload): array
    {
        $portRef = $this->normalizePonPort($payload['pon_port'] ?? '');
        $onuId = (int) ($payload['onu_id'] ?? 0);
        $serial = strtoupper(trim((string) ($payload['serial_number'] ?? '')));
        // ZTE C300 (RA + Krian) accepts `onu type ALL sn FHTT...` for FiberHome
        // ONTs; the old ZTEG-F660 default registered a wrong type.
        $onuType = trim((string) ($payload['onu_type'] ?? 'ALL'));
        $name = trim((string) ($payload['name'] ?? ''));
        $lineProfile = trim((string) ($payload['line_profile'] ?? ''));
        $serviceProfile = trim((string) ($payload['service_profile'] ?? ''));

        if (! $portRef || $onuId < 1 || $onuId > 128 || $serial === '') {
            throw new RuntimeException('PON port, ONU ID, and serial number are required.');
        }

        $onuRef = "{$portRef}:{$onuId}";
        $commands = [
            'configure terminal',
            "interface {$this->toGponOltRef($portRef)}",
            "onu {$onuId} type {$this->escapeCliValue($onuType)} sn {$this->escapeCliValue($serial)}",
            'exit',
        ];

        if ($name !== '' || $lineProfile !== '' || $serviceProfile !== '') {
            $commands[] = "interface {$this->toGponOnuRef($onuRef)}";
            if ($name !== '') {
                $commands[] = "name {$this->escapeCliValue($name)}";
            }
            if ($lineProfile !== '') {
                $commands[] = "line-profile {$this->escapeCliValue($lineProfile)}";
            }
            if ($serviceProfile !== '') {
                $commands[] = "service-profile {$this->escapeCliValue($serviceProfile)}";
            }
            $commands[] = 'exit';
        }

        $commands[] = 'exit';
        $commands[] = 'write';

        return $this->runConfigOperation($olt, $onuRef, $commands, [
            'pon_port' => $portRef,
            'onu_id' => $onuId,
            'serial_number' => $serial,
            'onu_type' => $onuType,
            'name' => $name,
            'line_profile' => $lineProfile,
            'service_profile' => $serviceProfile,
        ]);
    }

    public function collectSnapshot(Olt $olt): array
    {
        // Primary: use pexpect-based collector (works with old SSH servers)
        $pexpectSnapshot = $this->collectSnapshotViaPexpect($olt);
        if ($pexpectSnapshot !== null && ! empty($pexpectSnapshot['onus'])) {
            return $this->keepBestSnapshot(
                $pexpectSnapshot,
                is_array($olt->last_gpon_snapshot) ? $olt->last_gpon_snapshot : null
            );
        }

        // The phpseclib fallback was removed; report an empty result instead of a fatal error.
        return $this->emptyInventorySnapshot('pexpect snapshot returned no ONUs; phpseclib fallback removed');
    }

    protected function keepBestSnapshot(array $snapshot, ?array $cached): array
    {
        if (! $cached || empty($cached['onus']) || empty($cached['pon_ports'])) {
            return $snapshot;
        }

        $newOnus = count($snapshot['onus'] ?? []);
        $cachedOnus = count($cached['onus'] ?? []);
        $newPorts = count($snapshot['pon_ports'] ?? []);
        $cachedPorts = count($cached['pon_ports'] ?? []);

        if ($cachedOnus > $newOnus || $cachedPorts > $newPorts) {
            $cached['meta']['warning'] = 'Snapshot terbaru dari C300 lebih kecil dari cache, jadi backend mempertahankan data terbaik yang sudah tersimpan.';
            $cached['meta']['last_attempt_at'] = now()->toISOString();
            $cached['meta']['last_attempt_onus_count'] = $newOnus;
            $cached['meta']['last_attempt_pon_ports_count'] = $newPorts;

            return $cached;
        }

        return $snapshot;
    }

    protected function connect(Olt $olt): SSH2
    {
        if (! $olt->management_ip || ! $olt->username || ! $olt->password) {
            throw new RuntimeException('OLT must have management IP, username, and password.');
        }

        if (($olt->management_protocol ?: 'ssh') !== 'ssh') {
            throw new RuntimeException('ZTE C300 backend currently supports SSH only.');
        }

        $ssh = new SSH2($olt->management_ip, $olt->management_port ?: 22, 30);

        try {
            $ssh->setPreferredAlgorithms([
                'hostkey' => ['ssh-rsa', 'ssh-dss'],
                'keyexchange' => ['diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1'],
                'crypt' => ['aes128-cbc', '3des-cbc', 'blowfish-cbc'],
            ]);
        } catch (\Throwable) {
            // Ignore if version mismatch
        }

        if (! $ssh->login($olt->username, $olt->password)) {
            throw new RuntimeException('Failed to log in to OLT via SSH.');
        }

        $this->prepareShell($ssh, $olt->password);

        return $ssh;
    }

    /**
     * @param  float|null  $deadline  microtime(true) by which collection must stop;
     *                                ONU detail batches past it are skipped and the
     *                                snapshot is flagged meta.partial (state still complete).
     */
    protected function collectSnapshotViaPexpect(Olt $olt, ?float $deadline = null): ?array
    {
        $commands = [
            'show gpon onu state',
        ];

        $stateTimeout = 60;
        if ($deadline !== null) {
            $stateTimeout = (int) max(10, min(60, floor($deadline - microtime(true))));
        }

        $outputs = $this->runPexpectCommands($olt, $commands, $stateTimeout);
        if ($outputs === null) {
            return null;
        }

        $stateOutput = $outputs['show gpon onu state'] ?? '';
        if (! $stateOutput || preg_match('/(?:invalid|error|unrecognized)/i', $stateOutput)) {
            return null;
        }

        $onuRefs = $this->extractOnuRefs($stateOutput);
        if (empty($onuRefs)) {
            return null;
        }

        // Batch fetch detail-info for each ONU (distance + onu_name)
        // ZTE C300 uses: show gpon onu detail-info gpon-onu_{port}:{id}
        $detailCommands = [];
        foreach ($onuRefs as $ref) {
            $detailCommands[] = "show gpon onu detail-info gpon-onu_{$ref}";
        }

        $batchSize = 8;
        $batches = array_chunk($detailCommands, $batchSize);
        $allDetailOutputs = [];
        $partial = false;
        $detailAttempted = 0;

        foreach ($batches as $batch) {
            $batchTimeout = 90;
            if ($deadline !== null) {
                $remaining = $deadline - microtime(true);
                // Not enough budget for another SSH login + batch: stop here
                // and let the caller persist what was collected.
                if ($remaining < 15) {
                    $partial = true;
                    break;
                }
                $batchTimeout = (int) max(10, min(90, floor($remaining)));
            }

            $detailAttempted += count($batch);
            $batchOutputs = $this->runPexpectCommands($olt, $batch, $batchTimeout);
            if ($batchOutputs) {
                foreach ($batch as $cmd) {
                    $key = strtolower(str_replace('show gpon onu detail-info gpon-onu_', '', $cmd));
                    $allDetailOutputs[$key] = $batchOutputs[$cmd] ?? '';
                }
            }
        }

        // Parse all collected data
        $states = $this->parseStates($stateOutput);
        $distances = $this->parseDistances($allDetailOutputs);
        $detailOnuNames = $this->parseOnuNamesFromDetail($allDetailOutputs);
        $serialNumbers = $this->parseSerialNumbersFromDetail($allDetailOutputs);

        // NOTE: SNMP rx-power collection is available via separate API endpoint
        // (does not block main snapshot - SNMP may be unreachable from VPS)

        $onus = [];
        foreach ($onuRefs as $onuRef) {
            $lower = strtolower($onuRef);
            $onus[] = [
                'onu_ref' => $onuRef,
                'pon_port' => $this->extractPortFromOnuRef($onuRef),
                'state' => $states[$lower]['state'] ?? null,
                'phase_state' => $states[$lower]['phase_state'] ?? null,
                'serial_number' => $serialNumbers[$lower] ?? null,
                'onu_name' => $detailOnuNames[$lower] ?? null,
                'rx_power_dbm' => null, // RX comes from the SNMP tier; the pexpect detail path never collects it
                'distance_m' => $distances[$lower] ?? null,
                'detail_raw' => $allDetailOutputs[$lower] ?? null,
            ];
        }

        $ports = $this->extractPorts($onuRefs);

        return [
            'meta' => [
                'olt' => $olt->name,
                'host' => $olt->management_ip,
                'port' => $olt->management_port ?: 22,
                'protocol' => 'ssh-pexpect',
                'collector' => 'zte-c300-cli',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count(array_unique($ports)),
                'onus_count' => count($onus),
                'partial' => $partial,
                'detail_total' => count($detailCommands),
                'detail_collected' => $detailAttempted,
            ],
            'pon_ports' => array_map(fn (string $item) => ['name' => $item], array_unique($ports)),
            'onus' => $onus,
            'raw' => [
                'state' => $stateOutput,
                'detail' => $allDetailOutputs,
            ],
            'commands' => [],
        ];
    }

    protected function runPexpectCommands(Olt $olt, array $commands, int $timeout): ?array
    {
        try {
            return $this->runPexpectCommandsOrFail($olt, $commands, $timeout);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function runPexpectCommandsOrFail(Olt $olt, array $commands, int $timeout): array
    {
        $script = base_path('app/Support/zte_c300_collect.py');
        $process = new Process(['python3', $script], base_path(), [
            'OLT_HOST' => (string) $olt->management_ip,
            'OLT_PORT' => (string) ($olt->management_port ?: 22),
            'OLT_USERNAME' => (string) $olt->username,
            'OLT_PASSWORD' => (string) $olt->password,
            'OLT_COMMANDS' => json_encode(array_values($commands)),
        ]);
        $process->setTimeout($timeout);
        $process->run();

        $raw = trim($process->getOutput());
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            throw new RuntimeException(
                'OLT pexpect collector returned invalid JSON: '
                .str($process->getErrorOutput().$raw)->limit(300)->toString()
            );
        }

        if (($payload['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($payload['error'] ?? 'OLT pexpect collector failed'));
        }

        $outputs = $payload['outputs'] ?? null;
        if (! is_array($outputs)) {
            throw new RuntimeException('OLT pexpect collector returned no outputs');
        }

        return $outputs;
    }

    protected function connectForSnapshot(Olt $olt): SSH2
    {
        if (! $olt->management_ip || ! $olt->username || ! $olt->password) {
            throw new RuntimeException('OLT must have management IP, username, and password.');
        }

        if (($olt->management_protocol ?: 'ssh') !== 'ssh') {
            throw new RuntimeException('ZTE C300 backend currently supports SSH only.');
        }

        $ssh = new SSH2($olt->management_ip, $olt->management_port ?: 22, 10);
        $ssh->setTimeout(8);

        if (! $ssh->login($olt->username, $olt->password)) {
            throw new RuntimeException('Failed to log in to OLT via SSH.');
        }

        return $ssh;
    }

    protected function candidatePonPorts(): array
    {
        $ports = [];

        foreach ([2] as $slot) {
            for ($pon = 1; $pon <= 16; $pon++) {
                $ports[] = "1/{$slot}/{$pon}";
            }
        }

        return $ports;
    }

    protected function runConfigOperation(Olt $olt, string $onuRef, array $commands, array $context = []): array
    {
        $timeout = max(60, count($commands) * 8 + 30);
        $outputs = $this->runPexpectCommandsOrFail($olt, $commands, $timeout);

        foreach ($commands as $command) {
            $output = $outputs[$command] ?? '';
            if ($this->isCommandFailure($output)) {
                throw new RuntimeException("OLT command failed: {$command}\n{$this->normalizeOutput($output)}");
            }
        }

        return [
            'success' => true,
            'onu_ref' => $onuRef,
            'context' => $context,
            'commands' => $commands,
            'outputs' => collect($outputs)->map(fn ($output) => $this->normalizeOutput($output))->all(),
        ];
    }

    /**
     * Lightweight ONU name sync from running-config (single SSH session).
     */
    public function syncOnuNamesFromRunningConfig(Olt $olt): int
    {
        $outputs = $this->runPexpectCommands($olt, ['show running-config'], 180);
        if ($outputs === null) {
            return 0;
        }

        $names = $this->parseRunningConfigOnuNames($outputs['show running-config'] ?? '');
        if ($names === []) {
            return 0;
        }

        $now = now();
        $updated = 0;

        foreach ($names as $ref => $name) {
            $normalizedRef = $this->normalizeOnuRef($ref);
            $trimmed = trim((string) $name);
            if ($normalizedRef === '' || $trimmed === '' || $this->isGenericOnuName($trimmed)) {
                continue;
            }

            $changed = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('onu_ref', $normalizedRef)
                ->where(function ($query) use ($trimmed) {
                    $query->whereNull('onu_name')
                        ->orWhere('onu_name', '!=', $trimmed);
                })
                ->update([
                    'onu_name' => $trimmed,
                    'name_source' => 'ssh_running_config',
                    'inventory_collected_at' => $now,
                ]);

            $updated += $changed;
        }

        return $updated;
    }

    /**
     * Decode ZTE C300 SNMP optical INTEGER (col10) → dBm.
     *
     * Scale: Cepat-Kilat `raw * 0.002 - 30`.
     * High raw (>32767) is uint16 two's-complement of int16 — CLI-verified
     * (e.g. 64032 → -33.01, 60536 → -40.00). Never invent values: null if sentinel.
     */
    public static function decodeZteSnmpRxRaw(int $raw): ?float
    {
        if (in_array($raw, [0, 255, 65533, 65534, 65535], true)) {
            return null;
        }

        // uint16 → signed int16 for high optical encodings
        $num = $raw > 32767 ? $raw - 65536 : $raw;

        if ($raw > 32767 || $raw > 0) {
            $rx = round($num * 0.002 - 30.0, 2);
        } else {
            // legacy negative path (rare)
            $rx = round($num / 10.0, 2);
        }

        return self::sanitizeSnmpRxDbm($rx);
    }

    /**
     * Accept physical GPON RX range only.
     *
     * Distance-based rejection was REMOVED (2026-07-12): production snmpget proved
     * many "SNMP kosong" ONUs return valid ~-17..-21 dBm at 3–4 km (splitter-limited,
     * not fiber-km limited). Old rules (3km ⇒ must be < -21) caused false empties
     * on whole PON clusters (e.g. 1/2/10 BEDALI).
     *
     * $distanceM kept for API compat; ignored for accept/reject.
     */
    public static function sanitizeSnmpRxDbm(mixed $rx, ?int $distanceM = null): ?float
    {
        if ($rx === null || $rx === '') {
            return null;
        }

        $value = round((float) $rx, 2);
        // Physical plausible optical window (sentinel/wrong-encode → outside).
        // Lower -42: CLI-verified online ONUs can sit near -40 dBm and still pass traffic.
        if ($value > -8.0 || $value < -42.0) {
            return null;
        }

        return $value;
    }

    protected function isGenericOnuName(?string $name): bool
    {
        if (! $name) {
            return true;
        }

        return (bool) preg_match('/^ONU-\d+:\d+$/i', trim($name));
    }

    protected function prepareShell(SSH2 $ssh, string $password): void
    {
        // phpseclib 3 enablePTY() takes no arguments; the old 'vt100', 180, 1000 were ignored.
        $ssh->enablePTY();
        $ssh->setTimeout(0.25);
        $ssh->read();

        // Try enable (some ZTE require it), then page-break and terminal length 0
        try {
            $enableOutput = $this->runShellCommand($ssh, 'enable');
        } catch (\Throwable) {
            $enableOutput = '';
        }

        if (preg_match('/password[: ]*$/i', $enableOutput)) {
            $this->runShellCommand($ssh, $password);
        }

        // Nuke paging for fast collection
        foreach ([
            'terminal page-break disable',
            'terminal length 0',
        ] as $command) {
            try {
                $this->runShellCommand($ssh, $command);
            } catch (\Throwable) {
                // Skip if command not available
            }
        }
    }

    protected function exec(SSH2 $ssh, string $command, array &$executedCommands = []): string
    {
        $executedCommands[] = $command;
        $output = $this->runShellCommand($ssh, $command);

        if ($this->isCommandFailure($output)) {
            throw new RuntimeException("OLT command failed: {$command}\n{$output}");
        }

        return $this->normalizeOutput($output);
    }

    protected function execAny(SSH2 $ssh, array $commands, array &$executedCommands = []): string
    {
        $errors = [];

        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $this->runShellCommand($ssh, $command);

            if (! $this->isCommandFailure($output)) {
                return $this->normalizeOutput($output);
            }

            $errors[] = "[{$command}] ".$this->normalizeOutput($output);
        }

        throw new RuntimeException(implode("\n\n", $errors));
    }

    protected function execFirstSuccessful(SSH2 $ssh, array $commands, array &$executedCommands = []): string
    {
        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $this->runShellCommand($ssh, $command);

            if (! $this->isCommandFailure($output)) {
                return $this->normalizeOutput($output);
            }
        }

        return '';
    }

    protected function execSnapshotAny(SSH2 $ssh, array $commands, array &$executedCommands = []): string
    {
        $errors = [];

        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $ssh->exec($command);

            if (is_string($output) && ! $this->isCommandFailure($output)) {
                return $this->normalizeOutput($output);
            }

            $errors[] = "[{$command}] ".$this->normalizeOutput((string) $output);
        }

        throw new RuntimeException(implode("\n\n", $errors));
    }

    protected function execSnapshotFirstSuccessful(SSH2 $ssh, array $commands, array &$executedCommands = []): string
    {
        foreach ($commands as $command) {
            $executedCommands[] = $command;
            $output = $ssh->exec($command);

            if (is_string($output) && ! $this->isCommandFailure($output)) {
                return $this->normalizeOutput($output);
            }
        }

        return '';
    }

    protected function runShellCommand(SSH2 $ssh, string $command): string
    {
        $ssh->write($command."\n");
        $output = '';
        $sawData = false;
        $promptPattern = '/[#>]\s*$/';

        for ($packet = 0; $packet < 120; $packet++) {
            $chunk = $ssh->read('', SSH2::READ_NEXT);

            if ($chunk === false) {
                if ($sawData) {
                    return $output;
                }

                throw new RuntimeException("OLT command timed out: {$command}");
            }

            $output .= $chunk;
            $sawData = true;

            if (preg_match('/(--More--|More:|Press any key to continue)/i', $chunk)) {
                $ssh->write(" \n");
                $sawData = false;

                continue;
            }

            // Stop early on prompt pattern to avoid timeout
            $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $chunk);
            if (preg_match($promptPattern, $clean)) {
                return $output;
            }
        }

        return $output;
    }

    protected function isCommandFailure(string $output): bool
    {
        $normalized = strtoupper($this->normalizeOutput($output));

        foreach ([
            'INVALID INPUT',
            'UNKNOWN COMMAND',
            'INCOMPLETE COMMAND',
            'COMMAND NOT SUPPORT',
            'ERROR:',
            '% ',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeOutput(string $output): string
    {
        $output = preg_replace('/\e\\[[\\d;]*[A-Za-z]/', '', $output) ?? $output;
        $output = preg_replace('/(?:\x08\s?)+/', '', $output) ?? $output;
        $output = preg_replace('/--More--|More:|Press any key to continue/i', '', $output) ?? $output;

        return trim(str_replace("\r", '', $output));
    }

    protected function extractOnuRefs(string $output): array
    {
        preg_match_all('/(?:gpon-onu_)?\\d+\\/\\d+\\/\\d+:\\d+/i', $output, $matches);

        return array_values(array_unique(array_map(
            fn (string $ref) => strtolower(preg_replace('/^gpon-onu_/i', '', $ref) ?? $ref),
            $matches[0] ?? []
        )));
    }

    protected function extractPorts(array $onuRefs): array
    {
        $ports = [];
        foreach ($onuRefs as $onuRef) {
            $ports[] = $this->extractPortFromOnuRef($onuRef);
        }

        return array_values(array_unique(array_filter($ports)));
    }

    protected function extractPortFromOnuRef(string $onuRef): ?string
    {
        if (! preg_match('/(?:gpon-onu_)?(\\d+)\\/(\\d+)\\/(\\d+):(\\d+)/i', $onuRef, $matches)) {
            return null;
        }

        return sprintf('%s/%s/%s', $matches[1], $matches[2], $matches[3]);
    }

    protected function extractOnuId(string $onuRef): ?int
    {
        if (! preg_match('/(?:gpon-onu_)?\d+\/\d+\/\d+:(\d+)/i', $onuRef, $matches)) {
            return null;
        }

        return (int) $matches[1];
    }

    protected function normalizeOnuRef(string $onuRef): string
    {
        $onuRef = strtolower(trim($onuRef));
        $onuRef = preg_replace('/^gpon-onu_/i', '', $onuRef) ?? $onuRef;

        if (! preg_match('/^\d+\/\d+\/\d+:\d+$/', $onuRef)) {
            throw new RuntimeException('Invalid ONU ref. Expected format 1/2/1:3.');
        }

        return $onuRef;
    }

    protected function normalizePonPort(string $portRef): ?string
    {
        $portRef = strtolower(trim($portRef));
        $portRef = preg_replace('/^gpon-olt_/i', '', $portRef) ?? $portRef;

        return preg_match('/^\d+\/\d+\/\d+$/', $portRef) ? $portRef : null;
    }

    /**
     * @deprecated since 2026-08-31
     *
     * Use VendorCommandEncoder dependency instead. Kept during transition;
     * trigger_error outside adversarial-test contexts.
     */
    protected function escapeCliValue(string $value): string
    {
        if (! self::$inAdversarialTestContext) {
            @trigger_error(
                'escapeCliValue() is deprecated; inject VendorCommandEncoder instead.',
                E_USER_DEPRECATED,
            );
        }

        return trim(str_replace(["\r", "\n", '"'], ['', '', ''], $value));
    }

    protected function singleOnuSnapshot(string $onuRef, array $outputs): array
    {
        $onuRef = strtolower($onuRef);
        $states = $this->parseStates($outputs['state'] ?? '');
        $state = $states[$onuRef] ?? [];
        // Single-ONU CLI often returns one row — use it if key format differs
        if ($state === [] && count($states) === 1) {
            $state = (array) reset($states);
        }
        // Raw text fallback (phase O5 / working present but line parser missed)
        if (($state['state'] ?? null) !== 'online') {
            $rawState = (string) ($outputs['state'] ?? '');
            if (preg_match('/\bO5\b/i', $rawState) || preg_match('/\bworking\b/i', $rawState) || preg_match('/\bonline\b/i', $rawState)) {
                $state['state'] = 'online';
                if (empty($state['phase_state']) && preg_match('/\b(O[1-9])\b/i', $rawState, $pm)) {
                    $state['phase_state'] = strtoupper($pm[1]);
                }
            }
        }

        $baseInfo = $this->parseBaseInfos([$outputs['baseinfo'] ?? ''])[$onuRef] ?? [];
        $serial = $baseInfo['serial_number'] ?? null;
        if (! $serial) {
            $serial = $this->parseSerialFromText((string) ($outputs['baseinfo'] ?? ''))
                ?? $this->parseSerialFromText((string) ($outputs['running_config'] ?? ''));
        }

        $configName = $this->parseRunningConfigOnuNames($outputs['running_config'] ?? '')[$onuRef] ?? null;
        $rxPowers = $this->parseRxPowers([$this->extractPortFromOnuRef($onuRef) ?: '' => $outputs['power'] ?? '']);
        $rx = $rxPowers[$onuRef] ?? null;
        // Power table sometimes only has value without full ref on single-ONU query
        if ($rx === null && count($rxPowers) === 1) {
            $rx = (float) reset($rxPowers);
        }

        $opState = $state['state'] ?? null;
        $phase = $state['phase_state'] ?? null;
        $opState = OltOnu::normalizeOperationalState($opState, $phase, is_float($rx) || is_int($rx) ? (float) $rx : null);

        return [
            'onu_ref' => $onuRef,
            'pon_port' => $this->extractPortFromOnuRef($onuRef),
            'state' => $opState,
            'phase_state' => $phase,
            'serial_number' => $serial,
            'onu_name' => $configName ?? $baseInfo['onu_name'] ?? null,
            'rx_power_dbm' => $rx,
            'distance_m' => self::parseDistanceMeters(implode("\n", array_map(
                static fn ($chunk): string => is_string($chunk) ? $chunk : '',
                $outputs
            ))),
            'detail_raw' => $outputs,
        ];
    }

    /**
     * Metres from ZTE detail-info. Firmware prints either `1234m` or `DISTANCE(km)`.
     */
    public static function parseDistanceMeters(string $output): ?int
    {
        if (preg_match('/distance\s*\(km\)\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $output, $match) === 1) {
            $meters = (int) round(((float) $match[1]) * 1000);

            return $meters > 0 && $meters <= 40000 ? $meters : null;
        }

        if (preg_match('/distance(?:\s*\(m\))?\s*[:=]?\s*(\d+(?:\.\d+)?)\s*m?\b/i', $output, $match) === 1) {
            $meters = (int) round((float) $match[1]);

            return $meters > 0 && $meters <= 40000 ? $meters : null;
        }

        return null;
    }

    /**
     * Never persist SNMP community strings in run meta / logs. The docker
     * collector path passes them as `-e KEY=value` argv fragments, and the
     * command line is stored in `olt_collect_runs.meta`.
     */
    public static function maskSnmpSecrets(string $value): string
    {
        return (string) preg_replace(
            '/((?:OLT_COMMUNITY|SNMP_COMMUNITY)[=\s:]+)([^\s"\']+)/i',
            '$1[MASKED]',
            $value
        );
    }

    public static function normalizeSnmpSerial(mixed $value): ?string
    {
        $serial = strtoupper(str_replace('-', '', trim((string) $value)));
        $serial = preg_replace('/[^A-Z0-9]/', '', $serial) ?? '';
        $serial = self::stripSnmpIndexPrefix($serial);

        return preg_match('/^[A-Z0-9]{8,16}$/', $serial) === 1 ? $serial : null;
    }

    /**
     * Some ZTE SNMP walks prepend one extra leading digit (walk/index nibble)
     * to the ONU serial, e.g. `1FHTT0000A001` instead of `FHTT0000A001`.
     * Strip it only when the remainder starts with a known GPON vendor ID so
     * genuine serials are never corrupted.
     */
    public static function stripSnmpIndexPrefix(string $serial): string
    {
        if (preg_match('/^1((?:ZTEG|FHTT|HWTC|ALCL)[A-Z0-9]{4,12})$/', $serial, $match) === 1) {
            return $match[1];
        }

        return $serial;
    }

    public static function normalizeSnmpDistance(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $meters = (int) $value;

        return $meters > 0 && $meters <= 40000 ? $meters : null;
    }

    protected function parseSerialFromText(string $output): ?string
    {
        if ($output === '') {
            return null;
        }

        return self::extractCliSerial($output);
    }

    protected function parseDistances(array $distanceOutputs): array
    {
        $results = [];

        foreach ($distanceOutputs as $onuRef => $output) {
            $meters = self::parseDistanceMeters((string) $output);
            if ($meters !== null) {
                $results[strtolower((string) $onuRef)] = $meters;
            }
        }

        return $results;
    }

    /**
     * Parse onu_name from show gpon onu detail-info output.
     */
    protected function parseOnuNamesFromDetail(array $detailOutputs): array
    {
        $results = [];
        foreach ($detailOutputs as $onuRef => $output) {
            if (preg_match("/name[\s:]*([^\s\n,]+)/i", $output, $m)) {
                $results[strtolower($onuRef)] = trim($m[1]);
            }
            if (! isset($results[strtolower($onuRef)])) {
                if (preg_match("/description[\s:]*([^\n]+)/i", $output, $m)) {
                    $results[strtolower($onuRef)] = trim($m[1]);
                }
            }
        }

        return $results;
    }

    protected function parseRxPowers(array $powerOutputs): array
    {
        $results = [];

        foreach ($powerOutputs as $portRef => $output) {
            $lines = preg_split('/\n+/', $output) ?: [];
            foreach ($lines as $line) {
                if (! preg_match('/(?:gpon-onu_)?(\d+\/\d+\/\d+:\d+)/i', $line, $refMatch)) {
                    continue;
                }

                if (! preg_match('/\s(-?\d+(?:\.\d+)?)\s*\(?dbm\)?/i', $line, $powerMatch)) {
                    continue;
                }

                $results[strtolower($refMatch[1])] = (float) $powerMatch[1];
            }
        }

        return $results;
    }

    protected function parseStates(string $output): array
    {
        $results = [];
        $lines = preg_split('/\\n+/', $output) ?: [];

        foreach ($lines as $line) {
            if (! preg_match('/(?:gpon-onu_)?(\\d+\\/\\d+\\/\\d+:\\d+)/i', $line, $matches)) {
                continue;
            }

            $onuRef = strtolower($matches[1]);
            $phaseState = null;
            if (preg_match('/\\b(O[1-9])\\b/i', $line, $phaseMatches)) {
                $phaseState = strtoupper($phaseMatches[1]);
            }

            $phaseState = $phaseState ?: $this->parseColumnAfterOnuRef($line, 3);
            if (is_string($phaseState)) {
                $phaseState = strtoupper(trim($phaseState));
            }

            $results[$onuRef] = [
                'state' => $this->deriveOnuOperationalState($line, is_string($phaseState) ? $phaseState : null),
                'phase_state' => is_string($phaseState) ? $phaseState : null,
            ];
        }

        return $results;
    }

    public function deriveOnuOperationalState(string $line, ?string $phaseState = null): string
    {
        $upper = strtoupper($line);
        $phase = strtoupper(trim((string) $phaseState));

        if (
            str_contains($upper, 'WORKING')
            || preg_match('/\\bONLINE\\b/', $upper)
            || $phase === 'O5'
        ) {
            return 'online';
        }

        if (
            str_contains($upper, 'OFFLINE')
            || str_contains($upper, ' LOS')
            || preg_match('/\\bLOS\\b/', $upper)
            || in_array($phase, ['O7', 'LOS', 'DYINGGASP', 'DGI', 'SYNCMIB'], true)
        ) {
            return 'offline';
        }

        if (in_array($phase, ['O1', 'O2', 'O3', 'O4', 'O6'], true)) {
            return 'offline';
        }

        return 'offline';
    }

    /**
     * @return array{refs: list<string>, states: array<string, array{state?: string, phase_state?: string}>}
     */
    public function collectOltLiveStates(Olt $olt): array
    {
        $command = 'show gpon onu state';
        $outputs = $this->runPexpectCommands($olt, [$command], 90);
        if ($outputs === null) {
            throw new RuntimeException('Failed to read ONU state from OLT');
        }

        $stateOutput = $outputs[$command] ?? '';
        if ($stateOutput === '' || preg_match('/(?:invalid|error|unrecognized)/i', $stateOutput)) {
            throw new RuntimeException('OLT returned empty/invalid gpon onu state');
        }

        return [
            'refs' => $this->extractOnuRefs($stateOutput),
            'states' => $this->parseStates($stateOutput),
        ];
    }

    protected function parseBaseInfos(array $baseInfoOutputs, array $detailOutputs = []): array
    {
        $results = [];

        foreach ($baseInfoOutputs as $output) {
            $lines = preg_split('/\\n+/', $output) ?: [];
            foreach ($lines as $line) {
                if (! preg_match('/(?:gpon-onu_)?(\\d+\\/\\d+\\/\\d+:\\d+)/i', $line, $matches)) {
                    continue;
                }

                $onuRef = strtolower($matches[1]);
                preg_match('/\bSN\s*:\s*([A-Z0-9]+)/i', $line, $serialMatches);

                $results[$onuRef] = [
                    'serial_number' => $serialMatches[1] ?? null,
                    'onu_name' => null,
                ];
            }
        }

        foreach ($detailOutputs as $onuRef => $output) {
            if (! $output) {
                continue;
            }

            $key = strtolower($onuRef);
            $results[$key] = [
                'serial_number' => $results[$key]['serial_number'] ?? $this->parseSerial($output),
                'onu_name' => $results[$key]['onu_name'] ?? $this->parseOnuName($output),
            ];
        }

        return $results;
    }

    protected function parseRunningConfigOnuNames(string $output): array
    {
        $results = [];
        $currentOnuRef = null;
        $lines = preg_split('/\\n+/', $output) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^\\s*interface\\s+gpon-onu_(\\d+\\/\\d+\\/\\d+:\\d+)/i', $line, $matches)) {
                $currentOnuRef = strtolower($matches[1]);

                continue;
            }

            if ($currentOnuRef && preg_match('/^\\s*name\\s+(.+)\\s*$/i', $line, $matches)) {
                $results[$currentOnuRef] = trim($matches[1]);

                continue;
            }

            if (trim($line) === '!' || preg_match('/^\\s*end\\s*$/i', $line)) {
                $currentOnuRef = null;
            }
        }

        return $results;
    }

    protected function toGponOnuRef(string $onuRef): string
    {
        return str_starts_with(strtolower($onuRef), 'gpon-onu_') ? $onuRef : "gpon-onu_{$onuRef}";
    }

    protected function toGponOltRef(string $portRef): string
    {
        return str_starts_with(strtolower($portRef), 'gpon-olt_') ? $portRef : "gpon-olt_{$portRef}";
    }

    protected function parseColumnAfterOnuRef(string $line, int $index): ?string
    {
        $columns = preg_split('/\\s+/', trim($line)) ?: [];

        return $columns[$index] ?? null;
    }

    protected function parseSerial(string $output): ?string
    {
        return self::extractCliSerial($output);
    }

    public static function extractCliSerial(string $output): ?string
    {
        if (preg_match('/(?:Serial\s*Number|SerialNumber|SN)\s*[:=]\s*([A-Z0-9-]{4,})/i', $output, $matches) === 1) {
            $serial = self::normalizeSnmpSerial($matches[1]);
            if ($serial !== null) {
                return $serial;
            }
        }

        if (preg_match('/\b((?:ZTEG|FHTT|HWTC|ALCL)-?[A-F0-9]{6,12})\b/i', $output, $matches) === 1) {
            return self::normalizeSnmpSerial($matches[1]);
        }

        if (preg_match('/\b([A-F0-9]{12,16})\b/i', $output, $matches) === 1) {
            return self::normalizeSnmpSerial($matches[1]);
        }

        return null;
    }

    protected function parseOnuName(string $output): ?string
    {
        foreach ([
            '/(?:ONU\\s*)?Name\\s*[:=]\\s*([^\\r\\n]+)/i',
            '/Description\\s*[:=]\\s*([^\\r\\n]+)/i',
        ] as $pattern) {
            if (preg_match($pattern, $output, $matches)) {
                return trim($matches[1]);
            }
        }

        $lines = preg_split('/\\n+/', $output) ?: [];
        foreach ($lines as $line) {
            if (! preg_match('/(?:gpon-onu_)?\\d+\\/\\d+\\/\\d+:\\d+/i', $line)) {
                continue;
            }

            $columns = preg_split('/\\s+/', trim($line)) ?: [];
            $tail = array_values(array_filter(array_slice($columns, 1), function ($column) {
                return ! preg_match('/^(enable|disable|online|offline|working|o\\d|\\d\\(gpon\\)|ZTEG[A-Z0-9]+|[A-Z0-9]{8,})$/i', $column);
            }));

            if ($tail !== []) {
                return end($tail) ?: null;
            }
        }

        return null;
    }

    /**
     * Parse serial numbers from show gpon onu detail-info output.
     */
    protected function parseSerialNumbersFromDetail(array $detailOutputs): array
    {
        $results = [];
        foreach ($detailOutputs as $onuRef => $output) {
            $sn = $this->parseSerial($output);
            if ($sn) {
                $results[strtolower($onuRef)] = $sn;
            }
        }

        return $results;
    }

    /**
     * Attempt to collect ONU RX power via SNMP.
     * Tries: 1) Direct SNMP, 2) SSH tunnel with pexpect + socat UDP relay.
     * Returns [onu_ref => rx_power_dbm] or [] on failure (graceful degradation).
     */
    protected function collectSnmpRxPower(Olt $olt): array
    {
        $host = $olt->management_ip;
        $community = $olt->snmp_community ?: OltSnmpCommunity::fallback();

        if (! $host) {
            return [];
        }

        $this->lastSnmpCollectMeta = [];

        // Direct UDP SNMP (works from container to OLT RA on C300 v2.1)
        $rxPowers = $this->snmpWalkRxPower($host, 161, $community);
        if (! empty($rxPowers)) {
            return $rxPowers;
        }

        // Fallback: SSH tunnel when UDP 161 is blocked
        $localUdpPort = 11600 + ($olt->id % 100);
        $localTcpPort = 11700 + ($olt->id % 100);
        $sshPort = $olt->management_port ?: 22;

        $tunnelScript = base_path('app/Support/zte_c300_snmp_tunnel.py');
        if (! file_exists($tunnelScript)) {
            return [];
        }

        try {
            $walkTimeout = (int) (config('services.snmp_collector.walk_timeout') ?: 120);

            $process = new Process(
                ['python3', $tunnelScript],
                base_path(),
                [
                    'OLT_HOST' => $host,
                    'OLT_PORT' => (string) $sshPort,
                    'OLT_USERNAME' => $olt->username ?: '',
                    'OLT_PASSWORD' => $olt->password ?: '',
                    'SNMP_COMMUNITY' => $community,
                    'SNMP_TUNNEL_PORT' => (string) $localUdpPort,
                    'SNMP_WALK_TIMEOUT' => (string) $walkTimeout,
                ]
            );
            $process->setTimeout(max(150, $walkTimeout + 45));
            $process->run();

            if (! $process->isSuccessful()) {
                return [];
            }

            $data = json_decode($process->getOutput(), true);
            if (empty($data['ok']) || empty($data['rx_powers'])) {
                return [];
            }

            $results = [];
            foreach ($data['rx_powers'] as $idx => $power) {
                $ref = $this->snmpIndexToOnuRef($idx);
                if ($ref) {
                    $results[strtolower($ref)] = $power;
                }
            }

            return $results;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Run SNMP walk for ZTE ONU RX power using Python script.
     */
    /**
     * @param  array<string, string>  $extraEnv
     * @return array{rx: array<string, float>, meta: array<string, mixed>}
     */
    protected function runSnmpRxScript(string $host, int $port, string $community, array $extraEnv = []): array
    {
        $script = base_path('app/Support/zte_c300_snmp_rxpower.py');
        if (! file_exists($script)) {
            $this->lastSnmpCollectMeta = [];

            return ['rx' => [], 'meta' => []];
        }

        try {
            // READ tier: full RX tree walk (only path that reaches ~520 RX on this OLT).
            // Prefer host network (docker --network host) when docker.sock is available —
            // avoids bridge NAT; host cron scripts/host-olt-snmp-rx.sh is the primary path.
            $walkTimeout = (int) (config('services.snmp_collector.walk_timeout') ?: 130);
            $walkTimeout = max(60, min(160, $walkTimeout));
            $env = array_merge([
                'OLT_HOST' => $host,
                'OLT_COMMUNITY' => $community,
                'OLT_SNMP_PORT' => (string) $port,
                'SNMP_TIMEOUT' => (string) $walkTimeout,
                'SNMP_BULK_REPS' => (string) (config('services.snmp_collector.bulk_reps') ?: 15),
                'SNMP_MODE' => (string) (config('services.snmp_collector.mode') ?: 'tree'),
            ], $extraEnv);

            $process = $this->buildSnmpCollectProcess($script, $env, $walkTimeout);
            $process->run();

            $rx = $this->parseSnmpRxPayload($process->getOutput());
            $meta = $this->lastSnmpCollectMeta;
            if (is_array($meta)) {
                $meta['collector'] = self::maskSnmpSecrets($process->getCommandLine());
            }

            return [
                'rx' => $rx,
                'meta' => $meta ?? $this->lastSnmpCollectMeta,
            ];
        } catch (\Throwable $e) {
            $this->lastSnmpCollectMeta = [];

            return ['rx' => [], 'meta' => []];
        }
    }

    /**
     * Prefer docker --network host (host routing to OLT); fallback in-container.
     *
     * @param  array<string, string>  $env
     */
    protected function buildSnmpCollectProcess(string $script, array $env, int $walkTimeout): Process
    {
        $useHostNet = filter_var(config('services.snmp_collector.use_host_network', true), FILTER_VALIDATE_BOOLEAN);
        $dockerSock = '/var/run/docker.sock';
        $dockerBin = '/usr/bin/docker';
        $image = (string) (config('services.snmp_collector.docker_image') ?: 'skynet-ebilling-app:latest');

        if ($useHostNet && file_exists($dockerSock) && is_executable($dockerBin)) {
            $cmd = [
                $dockerBin, 'run', '--rm', '--network', 'host',
                '-v', $script.':/snmp_rx.py:ro',
            ];
            foreach ($env as $k => $v) {
                $cmd[] = '-e';
                $cmd[] = $k.'='.$v;
            }
            $cmd[] = '--entrypoint';
            $cmd[] = 'python3';
            $cmd[] = $image;
            $cmd[] = '/snmp_rx.py';

            $process = new Process($cmd, base_path(), null);
            $process->setTimeout($walkTimeout + 30);

            return $process;
        }

        $process = new Process(['python3', $script], base_path(), $env);
        $process->setTimeout($walkTimeout + 20);

        return $process;
    }

    protected function snmpWalkRxPower(string $host, int $port, string $community): array
    {
        return $this->runSnmpRxScript($host, $port, $community)['rx'];
    }

    /**
     * @param  list<string>  $snmpIndices
     * @return array{rx: array<string, float>, meta: array<string, mixed>}
     */
    public function collectSnmpGapPayload(Olt $olt, array $snmpIndices): array
    {
        $host = $olt->management_ip;
        $community = $olt->snmp_community ?: OltSnmpCommunity::fallback();

        if (! $host || $snmpIndices === []) {
            return ['rx' => [], 'meta' => []];
        }

        return $this->runSnmpRxScript($host, 161, $community, [
            'SNMP_GAP_INDICES' => implode(',', array_slice($snmpIndices, 0, 150)),
            'SNMP_TIMEOUT' => '120',
        ]);
    }

    public function parseSnmpRxPayload(string $output): array
    {
        $data = json_decode($output, true);
        if (empty($data['ok'])) {
            $this->lastSnmpCollectMeta = [];

            return [];
        }

        $this->lastSnmpCollectMeta = [
            'stats' => $data['stats'] ?? [],
            'onus' => $data['onus'] ?? [],
        ];

        if (! empty($data['onus']) && is_array($data['onus'])) {
            $results = [];
            foreach ($data['onus'] as $ref => $info) {
                $lower = strtolower((string) $ref);
                $rx = is_array($info) ? ($info['rx_dbm'] ?? null) : $info;
                $rx = self::sanitizeSnmpRxDbm($rx);
                if ($rx !== null) {
                    $results[$lower] = $rx;
                }
            }

            if (! empty($results)) {
                return $results;
            }
        }

        $results = [];
        foreach ($data['rx_powers'] ?? [] as $idx => $power) {
            $ref = preg_match('/^\d+\/\d+\/\d+:\d+$/', (string) $idx)
                ? strtolower((string) $idx)
                : strtolower((string) ($this->snmpIndexToOnuRef((string) $idx) ?? ''));
            $power = self::sanitizeSnmpRxDbm($power);
            if ($ref !== '' && $power !== null) {
                $results[$ref] = $power;
            }
        }

        return $results;
    }

    /**
     * Convert ZTE SNMP index to ONU ref format.
     */
    protected function snmpIndexToOnuRef(string $idx): ?string
    {
        $parts = array_map('intval', explode('.', $idx));
        $count = count($parts);

        if ($count === 4) {
            return sprintf('%d/%d/%d:%d', $parts[0], $parts[1], $parts[2], $parts[3]);
        }

        if ($count === 7) {
            $slot = $parts[1] * 100 + $parts[2];
            $port = $parts[3] * 100 + $parts[4];
            $onuId = $parts[5] * 1000000 + $parts[6];

            return sprintf('%d/%d/%d:%d', $parts[0], $slot, $port, $onuId);
        }

        if ($count === 3) {
            return $this->snmpC300V21IndexToOnuRef($parts[0], $parts[1]);
        }

        if ($count === 2) {
            return $this->snmpC300V21IndexToOnuRef($parts[0], $parts[1]);
        }

        return null;
    }

    protected function snmpC300V21IndexToOnuRef(int $ifIndex, int $onuId): ?string
    {
        if ($onuId < 1) {
            return null;
        }

        $slot = ($ifIndex >> 8) & 0xFF;
        $port = $ifIndex & 0xFF;

        if ($slot > 0 && $port > 0 && $slot < 32 && $port < 32) {
            return sprintf('1/%d/%d:%d', $slot, $port, $onuId);
        }

        return null;
    }

    /**
     * Collect RX power for all ONUs using CLI 'show pon power attenuation'.
     *
     * This is the ONLY reliable way to get rx_power on ZTE C300 firmware
     * that lacks SNMP support or CLI 'show gpon onu rx-power' command.
     *
     * @param  array  $onuRefs  List of ONU refs (e.g. ["1/2/1:1", "1/2/1:3", ...])
     * @return array [onu_ref => ["rx_dbm" => float|null, ...], ...]
     */
    public function collectRxPowerViaCli(Olt $olt, array $onuRefs): array
    {
        if (empty($onuRefs)) {
            return ['onus' => [], 'stats' => ['total' => 0, 'collected' => 0, 'errors' => 0, 'elapsed_seconds' => 0]];
        }

        $script = base_path('app/Support/zte_c300_rxpower.py');
        if (! file_exists($script)) {
            return ['onus' => [], 'error' => 'RX power collector script not found'];
        }

        $host = $olt->management_ip;
        $port = $olt->management_port ?: 22;
        $username = $olt->username ?: '';
        $password = $olt->password ?: '';

        // Calculate timeout: ~1s per ONU + 30s SSH overhead
        $timeout = max(60, count($onuRefs) + 30);

        try {
            $process = new Process(
                ['python3', $script, $host, (string) $port, $username, $password, json_encode($onuRefs)],
                base_path(),
                [],
                $timeout
            );
            $process->run();

            if (! $process->isSuccessful()) {
                $errOutput = $process->getErrorOutput();

                return ['onus' => [], 'error' => 'Collector script failed: '.trim($errOutput)];
            }

            $data = json_decode($process->getOutput(), true);
            if (empty($data) || ! isset($data['onus'])) {
                return ['onus' => [], 'error' => 'Invalid collector output'];
            }

            return $data;
        } catch (\Throwable $e) {
            return ['onus' => [], 'error' => $e->getMessage()];
        }
    }

    protected array $lastSnmpCollectMeta = [];

    /**
     * @return array{rx: array<string, float|null>, meta: array<string, mixed>}
     */
    public function collectSnmpRxPayload(Olt $olt, array $extraEnv = []): array
    {
        $host = $olt->management_ip;
        $community = $olt->snmp_community ?: OltSnmpCommunity::fallback();

        if (! $host) {
            return ['rx' => [], 'meta' => []];
        }

        $this->lastSnmpCollectMeta = [];
        $direct = $this->runSnmpRxScript($host, 161, $community, $extraEnv);
        if (! empty($direct['rx']) || ! empty($direct['meta']['onus'] ?? [])) {
            return $direct;
        }

        $rx = $this->collectSnmpRxPower($olt);

        if ($this->lastSnmpCollectMeta === []) {
            $this->lastSnmpCollectMeta = [
                'onus' => collect($rx)
                    ->mapWithKeys(fn ($value, $ref) => [
                        strtolower((string) $ref) => [
                            'rx_dbm' => $value,
                            'snmp_index' => null,
                        ],
                    ])
                    ->all(),
                'stats' => [
                    'mapped' => count($rx),
                    'with_rx' => count(array_filter($rx, fn ($v) => $v !== null && $v <= 0)),
                    'snmp_entries' => count($rx),
                ],
            ];
        }

        return [
            'rx' => $rx,
            'meta' => $this->lastSnmpCollectMeta,
        ];
    }

    /**
     * @return array{refs: list<string>, states: array<string, array{state?: string, phase_state?: string}>}
     */
    public function collectPonLiveRefs(Olt $olt, string $ponPort): array
    {
        $ponPort = strtolower(trim($ponPort));
        $ponPort = preg_replace('/^gpon-olt_/', '', $ponPort) ?? $ponPort;

        if (! preg_match('/^\d+\/\d+\/\d+$/', $ponPort)) {
            throw new RuntimeException('Invalid PON port. Expected format 1/3/11.');
        }

        $command = "show gpon onu state {$this->toGponOltRef($ponPort)}";
        $outputs = $this->runPexpectCommands($olt, [$command], 45);
        if ($outputs === null) {
            throw new RuntimeException("Failed to read ONU state for PON {$ponPort}");
        }

        $stateOutput = $outputs[$command] ?? '';
        if ($stateOutput === '' || preg_match('/(?:invalid|error|unrecognized)/i', $stateOutput)) {
            throw new RuntimeException("OLT returned empty/invalid state for PON {$ponPort}");
        }

        $refs = $this->extractOnuRefs($stateOutput);
        $states = $this->parseStates($stateOutput);

        return [
            'refs' => $refs,
            'states' => $states,
        ];
    }

    /**
     * Inventory-only snapshot: state, serial, name, distance — no RX collection.
     */
    public function collectInventorySnapshot(Olt $olt, ?float $deadline = null): array
    {
        $source = $this->inventorySource;
        $snmpMeta = null;

        if ($source === 'snmp' || ($source === 'auto' && self::snmpInventoryEnabledFor($olt))) {
            $snmp = $this->collectInventorySnapshotViaSnmp($olt);
            if ($snmp['accepted']) {
                return $snmp['snapshot'];
            }

            $snmpMeta = [
                'snmp_rejected_reason' => $snmp['reason'],
                'snmp_coverage' => $snmp['coverage'],
                'snmp_onus' => $snmp['count'],
            ];

            if (! config('services.olt_inventory_sync.snmp_fallback_ssh', true)) {
                return $this->emptyInventorySnapshot('SNMP inventory rejected ('.$snmp['reason'].'); SSH fallback disabled', $snmpMeta);
            }

            if (! $this->claimSshFallbackSlot($olt)) {
                return $this->emptyInventorySnapshot('SNMP inventory rejected ('.$snmp['reason'].'); SSH fallback already used within the throttle window', $snmpMeta);
            }
        }

        $mode = strtolower((string) config('services.olt_inventory_sync.zte_mode', 'bulk'));
        $snapshot = $mode === 'detail'
            ? $this->collectSnapshotViaPexpect($olt, $deadline)
            : $this->collectInventoryViaBulkCli($olt, $deadline);

        if ($snapshot === null || empty($snapshot['onus'])) {
            return $this->emptyInventorySnapshot('Inventory collector returned no ONUs', $snmpMeta);
        }

        $snapshot['meta']['collector'] = 'zte-c300-inventory';
        $snapshot['meta']['inventory_mode'] = $mode === 'detail' ? 'detail' : 'bulk';
        $snapshot['meta']['source'] = 'ssh';
        if ($snmpMeta !== null) {
            $snapshot['meta'] = array_merge($snapshot['meta'], $snmpMeta, ['snmp_fallback' => true]);
        }

        return $snapshot;
    }

    public function withInventorySource(?string $source): static
    {
        $source = strtolower(trim((string) $source));
        $this->inventorySource = in_array($source, ['snmp', 'ssh'], true) ? $source : 'auto';

        return $this;
    }

    public static function snmpInventoryEnabledFor(Olt $olt): bool
    {
        $ids = (array) config('services.olt_inventory_sync.snmp_olt_ids', []);

        return $olt->id !== null && in_array((int) $olt->id, array_map('intval', $ids), true);
    }

    /**
     * SNMP inventory accepted only when the status walk finished and covers
     * at least `snmp_min_coverage` of the OLT's existing olt_onus rows.
     *
     * @return array{accepted: bool, reason: ?string, coverage: ?float, count: int, snapshot: ?array}
     */
    public function collectInventorySnapshotViaSnmp(Olt $olt): array
    {
        $reject = fn (string $reason, int $count = 0, ?float $coverage = null): array => [
            'accepted' => false, 'reason' => $reason, 'coverage' => $coverage, 'count' => $count, 'snapshot' => null,
        ];

        if (! $olt->management_ip) {
            return $reject('no management_ip');
        }

        $started = microtime(true);
        $parsed = $this->parseSnmpInventoryPayload($this->runSnmpInventoryScript($olt));
        if ($parsed === null || $parsed['onus'] === []) {
            return $reject('SNMP returned no ONUs');
        }

        $count = count($parsed['onus']);
        if (! empty($parsed['stats']['status_partial'])) {
            return $reject('SNMP status walk incomplete', $count);
        }

        $existing = $olt->id ? OltOnu::query()->where('olt_id', $olt->id)->count() : 0;
        $coverage = $existing > 0 ? round($count / $existing, 4) : 1.0;
        $min = (float) config('services.olt_inventory_sync.snmp_min_coverage', 0.9);
        if ($coverage < $min) {
            return $reject(sprintf('SNMP coverage %.2f below %.2f (%d of %d rows)', $coverage, $min, $count, $existing), $count, $coverage);
        }

        $ports = array_values(array_unique(array_filter(array_column($parsed['onus'], 'pon_port'))));

        return [
            'accepted' => true,
            'reason' => null,
            'coverage' => $coverage,
            'count' => $count,
            'snapshot' => [
                'meta' => [
                    'olt' => $olt->name,
                    'protocol' => 'snmp',
                    'collector' => 'zte-c300-snmp-inventory',
                    'source' => 'snmp',
                    'inventory_mode' => 'snmp',
                    'collected_at' => now()->toISOString(),
                    'pon_ports_count' => count($ports),
                    'onus_count' => $count,
                    'names_found' => count(array_filter(array_column($parsed['onus'], 'onu_name'))),
                    'snmp_coverage' => $coverage,
                    'snmp_existing_rows' => $existing,
                    'snmp_stats' => $parsed['stats'],
                    'snmp_wall_ms' => (int) round((microtime(true) - $started) * 1000),
                    'partial' => false,
                ],
                'pon_ports' => array_map(fn (string $item) => ['name' => $item], $ports),
                'onus' => $parsed['onus'],
            ],
        ];
    }

    /**
     * Python inventory payload -> the same onus[] shape as the SSH snapshot.
     * Index decode is done here (snmpIndexToOnuRef), not trusted from python.
     *
     * @return array{onus: list<array<string, mixed>>, stats: array<string, mixed>}|null
     */
    public function parseSnmpInventoryPayload(string $output): ?array
    {
        $data = json_decode(trim($output), true);
        if (! is_array($data) || empty($data['ok']) || ! is_array($data['onus'] ?? null)) {
            return null;
        }

        $onus = [];
        foreach ($data['onus'] as $key => $info) {
            if (! is_array($info)) {
                continue;
            }
            $index = (string) ($info['snmp_index'] ?? '');
            $ref = $index !== '' ? $this->snmpIndexToOnuRef($index) : null;
            $ref ??= preg_match('/^\d+\/\d+\/\d+:\d+$/', (string) $key) ? (string) $key : null;
            if ($ref === null || isset($onus[$ref])) {
                continue;
            }

            $code = isset($info['status_code']) && is_numeric($info['status_code']) ? (int) $info['status_code'] : null;
            $name = trim((string) ($info['name'] ?? ''));
            $description = trim((string) ($info['description'] ?? ''));

            $onus[$ref] = [
                'onu_ref' => $ref,
                'pon_port' => $this->extractPortFromOnuRef($ref),
                'state' => self::snmpInventoryState($code),
                'phase_state' => self::snmpInventoryPhaseState($code),
                'serial_number' => self::normalizeSnmpInventorySerial($info['serial'] ?? null),
                'onu_name' => $name !== '' ? $name : null,
                'onu_description' => $description !== '' ? $description : null,
                'distance_m' => self::normalizeSnmpDistance($info['distance_m'] ?? null),
                'snmp_status_code' => $code,
            ];
        }

        return [
            'onus' => array_values($onus),
            'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : [],
        ];
    }

    /** ZTE status 4 = working -> online; every other code is offline. */
    public static function snmpInventoryState(?int $code): ?string
    {
        if ($code === null) {
            return null;
        }

        return $code === 4 ? 'online' : 'offline';
    }

    /** ZTE status code -> CLI `Phase State` label (2/4/5/7 verified 2026-10-08). */
    public static function snmpInventoryPhaseState(?int $code): ?string
    {
        return match ($code) {
            1 => 'logging',
            2 => 'LOS',
            3 => 'syncMib',
            4 => 'working',
            5 => 'DyingGasp',
            6 => 'authFailed',
            7 => 'OffLine',
            default => null,
        };
    }

    /** "1,FHTTxxxxxxxx" (C300 v2.1 serial column) -> "FHTTxxxxxxxx". */
    public static function normalizeSnmpInventorySerial(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $serial = (string) preg_replace('/^\s*(?:STRING:\s*)?"?\s*\d+,/i', '', trim((string) $value));

        return self::normalizeSnmpSerial(trim($serial, ' "'));
    }

    /** Raw stdout of zte_c300_snmp_rxpower.py in SNMP_MODE=inventory. */
    protected function runSnmpInventoryScript(Olt $olt): string
    {
        $script = base_path('app/Support/zte_c300_snmp_rxpower.py');
        if (! file_exists($script)) {
            return '';
        }

        $timeout = (int) config('services.olt_inventory_sync.snmp_timeout_seconds', 60);
        $oids = (array) config('services.olt_inventory_sync.snmp_oids', []);
        $env = [
            'OLT_HOST' => (string) $olt->management_ip,
            'OLT_COMMUNITY' => (string) ($olt->snmp_community ?: OltSnmpCommunity::fallback()),
            'OLT_SNMP_PORT' => '161',
            'SNMP_TIMEOUT' => (string) $timeout,
            'SNMP_BULK_REPS' => (string) (config('services.snmp_collector.bulk_reps') ?: 15),
            'SNMP_MODE' => 'inventory',
        ];
        foreach (['status', 'name', 'description', 'serial', 'distance'] as $field) {
            if (! empty($oids[$field])) {
                $env['SNMP_INV_'.strtoupper($field).'_OID'] = (string) $oids[$field];
            }
        }

        try {
            $process = $this->buildSnmpCollectProcess($script, $env, $timeout);
            $process->run();

            return $process->getOutput();
        } catch (\Throwable) {
            return '';
        }
    }

    /** One SSH fallback per OLT per throttle window (0 = unlimited). */
    protected function claimSshFallbackSlot(Olt $olt): bool
    {
        $minutes = (int) config('services.olt_inventory_sync.snmp_fallback_min_interval_minutes', 60);
        if ($minutes <= 0) {
            return true;
        }

        return Cache::add('olt-inventory:ssh-fallback:'.($olt->id ?? 0), now()->toISOString(), $minutes * 60);
    }

    /** @param array<string, mixed>|null $extra */
    protected function emptyInventorySnapshot(string $warning, ?array $extra = null): array
    {
        return [
            'meta' => array_merge([
                'collector' => 'zte-c300-inventory',
                'collected_at' => now()->toISOString(),
                'warning' => $warning,
            ], $extra ?? []),
            'onus' => [],
        ];
    }

    /**
     * Inventory in ONE SSH session: `show gpon onu state` (refs + state) and
     * `show running-config` (ONU names). Serial + distance come from the SNMP
     * tier (every 15 min) and are left untouched here.
     *
     * Audit 2026-10-08 (OLT RA, 1223 ONUs, read-only timing): the per-ONU
     * `detail-info` path needed one SSH login per 8 ONUs — 153 logins at
     * ~5.5s each (~14 min) — while state (~19s) + running-config (~48s,
     * 1216 names) take about one minute in total.
     */
    protected function collectInventoryViaBulkCli(Olt $olt, ?float $deadline = null): ?array
    {
        $stateCommand = 'show gpon onu state';
        $configCommand = 'show running-config';

        $remaining = fn (): float => $deadline === null ? 600.0 : $deadline - microtime(true);

        // Session 1: state. Kept separate so a slow running-config can never
        // cost the state snapshot of every ONU.
        $outputs = $this->runPexpectCommands($olt, [$stateCommand], (int) max(15, min(90, floor($remaining()))));
        if ($outputs === null) {
            return null;
        }

        $stateOutput = (string) ($outputs[$stateCommand] ?? '');
        if ($stateOutput === '' || preg_match('/(?:invalid|error|unrecognized)/i', $stateOutput)) {
            return null;
        }

        $onuRefs = $this->extractOnuRefs($stateOutput);
        if (empty($onuRefs)) {
            return null;
        }

        $states = $this->parseStates($stateOutput);

        // Session 2: names from running-config, bounded by what is left.
        $names = [];
        $partialReason = null;
        if ($remaining() < 20) {
            $partialReason = 'budget exhausted before running-config (names not refreshed)';
        } else {
            $configOutputs = $this->runPexpectCommands($olt, [$configCommand], (int) min(240, floor($remaining())));
            $configOutput = (string) ($configOutputs[$configCommand] ?? '');
            if ($configOutputs !== null && ! str_starts_with($configOutput, '[ERROR')) {
                $names = $this->parseRunningConfigOnuNames($configOutput);
            }
            if ($names === []) {
                $partialReason = 'running-config returned no ONU names (timeout or truncated output)';
            }
        }

        $onus = [];
        $named = 0;
        foreach ($onuRefs as $onuRef) {
            $lower = strtolower($onuRef);
            $name = $names[$lower] ?? null;
            if ($name !== null) {
                $named++;
            }
            $onus[] = [
                'onu_ref' => $onuRef,
                'pon_port' => $this->extractPortFromOnuRef($onuRef),
                'state' => $states[$lower]['state'] ?? null,
                'phase_state' => $states[$lower]['phase_state'] ?? null,
                // Serial/distance: SNMP tier owns these; null never clobbers.
                'serial_number' => null,
                'onu_name' => $name,
                'distance_m' => null,
            ];
        }

        $ports = array_values(array_unique($this->extractPorts($onuRefs)));
        $partial = $partialReason !== null;

        return [
            'meta' => [
                'olt' => $olt->name,
                'protocol' => 'ssh-pexpect',
                'collector' => 'zte-c300-bulk-cli',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count($ports),
                'onus_count' => count($onus),
                'names_found' => $named,
                'partial' => $partial,
                'partial_reason' => $partialReason,
                'detail_total' => count($onuRefs),
                'detail_collected' => $named,
            ],
            'pon_ports' => array_map(fn (string $item) => ['name' => $item], $ports),
            'onus' => $onus,
        ];
    }
}
