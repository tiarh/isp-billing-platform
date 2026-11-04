<?php

namespace App\Services;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Olt;
use App\Support\CacheLockWait;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use phpseclib3\Net\SSH2;
use RuntimeException;

/**
 * Read-only Huawei MA5608T collector.
 *
 * Huawei write operations stay deliberately blocked until their command
 * contract is separately reviewed and tested against the target firmware.
 */
class OltHuaweiMa5608tService
{
    private const GPON_PORTS = 16;

    private const SSH_CONNECT_TIMEOUT_SECONDS = 30;

    private const SSH_COMMAND_TIMEOUT_SECONDS = 60;

    private const SSH_KEEP_ALIVE_SECONDS = 10;

    private const SSH_SESSION_LOCK_SECONDS = 1800;

    private const SSH_SESSION_LOCK_WAIT_SECONDS = 5;

    protected const MAX_INTERACTIVE_PACKETS = 4096;

    /** RCA-2 enrichment session budget (connect + each read + whole session); the job timeout is above it. */
    public const ENRICH_CONNECT_TIMEOUT_SECONDS = 8;

    public const ENRICH_READ_TIMEOUT_SECONDS = 15;

    public const ENRICH_SESSION_DEADLINE_SECONDS = 40;

    /** Session lock TTL of an enrichment session: a killed worker frees PSB after 2 minutes, not 30. */
    public const ENRICH_LOCK_SECONDS = 120;

    /** RCA-4 manual ("Cek sekarang") session limits: connect + each read + whole session. */
    public const MANUAL_CONNECT_TIMEOUT_SECONDS = 5;

    public const MANUAL_READ_TIMEOUT_SECONDS = 8;

    public const MANUAL_SESSION_DEADLINE_SECONDS = 15;

    /** @var array{connect:int,read:int,lock:int,wait:int}|null limits for the current enrichment session only */
    protected ?array $sessionLimits = null;

    /** Unix time (float) after which an enrichment session starts no more commands; null outside enrichment. */
    protected ?float $sessionDeadlineAt = null;

    /** @var array<int, Lock> */
    protected array $sessionLocks = [];

    public function collectSnapshot(Olt $olt): array
    {
        $ssh = $this->connect($olt);
        $commands = [];
        $raw = [];
        $onus = [];

        try {
            for ($port = 0; $port < self::GPON_PORTS; $port++) {
                $command = "display ont info 0 0 {$port} all";
                $commands[] = $command;
                $output = $this->run($ssh, $command);
                $raw[$command] = $this->trimRaw($output);

                if ($this->isCommandFailure($output)) {
                    continue;
                }

                foreach ($this->parsePortOutput($output, $port) as $onu) {
                    $onus[$onu['onu_ref']] = $onu;
                }
            }
        } finally {
            $this->disconnectSafely($ssh);
        }

        $onus = array_values($onus);
        $ports = array_values(array_unique(array_filter(array_map(
            static fn (array $onu) => $onu['pon_port'] ?? null,
            $onus,
        ))));

        return [
            'meta' => [
                'olt' => $olt->name,
                'host' => $olt->management_ip,
                'port' => $olt->management_port ?: 22,
                'protocol' => 'ssh',
                'vendor' => 'huawei_ma5608t',
                'collector' => 'huawei-ma5608t-cli',
                'collected_at' => now()->toISOString(),
                'pon_ports_count' => count($ports),
                'onus_count' => count($onus),
                'warning' => $onus === []
                    ? 'Huawei CLI reachable, tetapi tidak ada baris ONU yang dikenali dari display ont info.'
                    : null,
            ],
            'pon_ports' => array_map(static fn (string $port) => ['name' => $port], $ports),
            'onus' => $onus,
            'raw' => $raw,
            'commands' => $commands,
        ];
    }

    /**
     * Parse one Huawei `display ont info 0 0 <port> all` response.
     * Kept public so the parser can be tested with a synthetic CLI fixture.
     *
     * @return list<array<string, mixed>>
     */
    public function parsePortOutput(string $output, int $port): array
    {
        $rows = [];
        $tableRows = [];
        $descriptions = [];
        $current = null;

        $flush = function () use (&$rows, &$current, $port): void {
            if (! is_array($current) || ! isset($current['onu_id'])) {
                $current = null;

                return;
            }

            $ponPort = "0/0/{$port}";
            $rows[] = [
                'onu_ref' => "{$ponPort}:{$current['onu_id']}",
                'pon_port' => $ponPort,
                'state' => $this->normalizeState($current['run_state'] ?? null),
                'phase_state' => $current['config_state'] ?? ($current['match_state'] ?? null),
                'serial_number' => $current['serial_number'] ?? null,
                'onu_name' => $current['description'] ?? null,
                'rx_power_dbm' => $current['rx_power_dbm'] ?? null,
                'distance_m' => $current['distance_m'] ?? null,
                'detail_raw' => null,
            ];
            $current = null;
        };

        foreach (preg_split('/\R/', $this->stripTerminalArtifacts($output)) ?: [] as $line) {
            if (preg_match('/^\s*0\/\s*0\/\s*(\d+)\s+(\d+)\s+([0-9A-Fa-f]{8,16})\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)(?:\s+\S+)*\s*$/', $line, $match)) {
                if ((int) $match[1] === $port) {
                    $tableRows[(int) $match[2]] = [
                        'serial_number' => $this->normalizeHuaweiSerial($match[3]),
                        'run_state' => trim($match[5]),
                        'config_state' => trim($match[6]),
                        'match_state' => trim($match[7]),
                    ];
                }

                continue;
            }

            if (preg_match('/^\s*0\/\s*0\/\s*\d+\s+(\d+)\s+(.*?)\s*$/', $line, $match)) {
                $descriptions[(int) $match[1]] = trim($match[2]) ?: null;

                continue;
            }
            if (preg_match('/^\s*ONT[\s-]?ID\s*[:：]\s*(\d+)/i', $line, $match)) {
                $flush();
                $current = ['onu_id' => (int) $match[1]];

                continue;
            }

            if (! is_array($current)) {
                continue;
            }

            if (preg_match('/^\s*(?:Run state|State)\s*[:：]\s*(.+?)\s*$/i', $line, $match)) {
                $current['run_state'] = trim($match[1]);
            } elseif (preg_match('/^\s*Config state\s*[:：]\s*(.+?)\s*$/i', $line, $match)) {
                $current['config_state'] = trim($match[1]);
            } elseif (preg_match('/^\s*Match state\s*[:：]\s*(.+?)\s*$/i', $line, $match)) {
                $current['match_state'] = trim($match[1]);
            } elseif (preg_match('/^\s*(?:SN|Serial number)\s*[:：]\s*([A-Za-z0-9][A-Za-z0-9-]{5,63})/i', $line, $match)) {
                $current['serial_number'] = $this->normalizeHuaweiSerial($match[1]);
            } elseif (preg_match('/^\s*(?:Description|Name)\s*[:：]\s*(.*?)\s*$/i', $line, $match)) {
                $current['description'] = trim($match[1]) ?: null;
            } elseif (preg_match('/^\s*(?:ONT Rx optical power|Rx optical power|Rx power)\s*[:：]\s*(-?\d+(?:\.\d+)?)\s*d?Bm?/i', $line, $match)) {
                $current['rx_power_dbm'] = (float) $match[1];
            } elseif (preg_match('/^\s*(?:ONT distance|Distance)\s*[:：]\s*(\d+(?:\.\d+)?)\s*m?/i', $line, $match)) {
                $current['distance_m'] = (int) round((float) $match[1]);
            }
        }

        $flush();

        foreach ($tableRows as $onuId => $tableRow) {
            $serialNumber = $tableRow['serial_number'] ?? null;
            $ponPort = "0/0/{$port}";
            $rows[] = [
                'onu_ref' => "{$ponPort}:{$onuId}",
                'pon_port' => $ponPort,
                'state' => $this->normalizeState($tableRow['run_state'] ?? null),
                'phase_state' => $tableRow['config_state'] ?? ($tableRow['match_state'] ?? null),
                'serial_number' => $serialNumber,
                'vendor_id' => $this->vendorIdFromSerial($serialNumber),
                'onu_name' => $descriptions[$onuId] ?? null,
                'rx_power_dbm' => null,
                'distance_m' => null,
                'detail_raw' => null,
            ];
        }

        return $rows;
    }

    /**
     * Remove terminal paging residue from raw Huawei CLI output.
     *
     * When the CLI pages, `---- More ( Press 'Q' to break ) ----` is printed
     * without a newline and then erased in place with ANSI cursor moves
     * (e.g. `ESC[37D`, spaces, `ESC[37D`). The next table row therefore lands
     * on the same physical line as the prompt and fails the anchored row
     * regexes, silently dropping that ONT. Strip the prompt, ANSI sequences
     * and stray control characters (backspaces etc.) before line parsing.
     */
    protected function stripTerminalArtifacts(string $output): string
    {
        $output = preg_replace('/-{4}\s*More\b[^\r\n]*?-{4}/i', '', $output) ?? $output;
        $output = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $output) ?? $output;

        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $output) ?? $output;
    }

    public function onuDetail(Olt $olt, string $onuRef): array
    {
        [$port, $onuId] = $this->splitOnuRef($onuRef);
        $ssh = $this->connect($olt);
        $commands = [
            "display ont info 0 0 {$port} {$onuId}",
            "display ont optical-info 0 0 {$port} {$onuId}",
        ];
        $raw = [];

        try {
            foreach ($commands as $command) {
                $raw[$command] = $this->trimRaw($this->run($ssh, $command));
            }
        } finally {
            $this->disconnectSafely($ssh);
        }

        $parsed = $this->parsePortOutput($raw[$commands[0]] ?? '', (int) $port);
        $onu = $parsed[0] ?? [
            'onu_ref' => "0/0/{$port}:{$onuId}",
            'pon_port' => "0/0/{$port}",
            'state' => null,
            'phase_state' => null,
            'serial_number' => null,
            'onu_name' => null,
            'rx_power_dbm' => null,
            'distance_m' => null,
            'detail_raw' => null,
        ];
        $onu['detail_raw'] = $raw;

        return [
            'onu' => $onu,
            'raw' => $raw,
            'commands' => $commands,
        ];
    }

    /**
     * RCA-2 cause enrichment: ONE read-only `display ont info` for a single ONT (no optical-info). Throws
     * HuaweiOltSessionBusyException when another session holds the OLT (the caller backs off).
     */
    public function ontInfoText(Olt $olt, string $onuRef): string
    {
        return $this->ontInfoAndClock($olt, $onuRef)['info'];
    }

    /**
     * Same single session, plus the read-only `display time` (to measure the OLT clock drift).
     *
     * @return array{info:string,clock:?string}
     */
    public function ontInfoAndClock(Olt $olt, string $onuRef): array
    {
        return $this->enrichmentSession($olt, $onuRef, self::ENRICH_CONNECT_TIMEOUT_SECONDS, self::ENRICH_READ_TIMEOUT_SECONDS, self::ENRICH_SESSION_DEADLINE_SECONDS);
    }

    /**
     * RCA-4 "Cek sekarang": the same single read-only session with tighter limits (connect 5 s / read 8 s / whole
     * session 15 s), because a person is waiting and PSB must not be held up.
     *
     * @return array{info:string,clock:?string}
     */
    public function ontInfoAndClockManual(Olt $olt, string $onuRef): array
    {
        return $this->enrichmentSession($olt, $onuRef, self::MANUAL_CONNECT_TIMEOUT_SECONDS, self::MANUAL_READ_TIMEOUT_SECONDS, self::MANUAL_SESSION_DEADLINE_SECONDS);
    }

    /** @return array{info:string,clock:?string} */
    protected function enrichmentSession(Olt $olt, string $onuRef, int $connectSeconds, int $readSeconds, int $deadlineSeconds): array
    {
        [$port, $onuId] = $this->splitOnuRef($onuRef);
        // Enrichment shares the SSH session lock with PSB and the collector, so it must ALWAYS end quickly and free
        // the lock: short connect / read timeouts, no waiting for a busy lock, a lock TTL of 2 minutes (a killed
        // worker cannot leave PSB blocked for the normal 30 minutes) and a whole-session deadline.
        $this->sessionLimits = [
            'connect' => $connectSeconds,
            'read' => $readSeconds,
            'lock' => self::ENRICH_LOCK_SECONDS,
            'wait' => 0,
        ];
        // The deadline is checked before EVERY command (run()) and bounds each read, so one slow read can no longer
        // run past it (#563 follow-up).
        $this->sessionDeadlineAt = $this->nowFloat() + $deadlineSeconds;
        $ssh = null;

        try {
            $ssh = $this->connect($olt);
            // An unsupported command only prints an error text (unparsable => no drift correction); a transport
            // failure aborts the read, so the session never continues desynchronised.
            $clock = $this->trimRaw($this->run($ssh, 'display time'));

            return ['info' => $this->trimRaw($this->run($ssh, "display ont info 0 0 {$port} {$onuId}")), 'clock' => $clock];
        } finally {
            $this->sessionLimits = null;
            $this->sessionDeadlineAt = null;
            $this->disconnectSafely($ssh);
        }
    }

    protected function nowFloat(): float
    {
        return microtime(true);
    }

    /**
     * Enrichment sessions only: refuse to start a command after the session deadline and cap the read timeout of the
     * next command by what is left. No effect on PSB / collector sessions (no deadline set).
     */
    protected function guardSessionDeadline(SSH2 $ssh): void
    {
        if ($this->sessionDeadlineAt === null) {
            return;
        }
        $remaining = $this->sessionDeadlineAt - $this->nowFloat();
        if ($remaining <= 0) {
            throw new RuntimeException('Huawei enrichment session deadline exceeded.');
        }
        $read = (int) ($this->sessionLimits['read'] ?? self::SSH_COMMAND_TIMEOUT_SECONDS);
        $ssh->setTimeout(max(1, min($read, (int) ceil($remaining))));
    }

    public function renameOnu(Olt $olt, string $onuRef, string $name): array
    {
        $this->writeBlocked();
    }

    public function rebootOnu(Olt $olt, string $onuRef): array
    {
        $this->writeBlocked();
    }

    public function setOnuAdminState(Olt $olt, string $onuRef, bool $enabled): array
    {
        $this->writeBlocked();
    }

    public function deleteOnu(Olt $olt, string $onuRef): array
    {
        $this->writeBlocked();
    }

    public function authorizeOnu(Olt $olt, array $payload): array
    {
        $this->writeBlocked();
    }

    protected function connect(Olt $olt): SSH2
    {
        if (! $olt->management_ip || ! $olt->username || ! $olt->password) {
            throw new RuntimeException('Huawei MA5608T requires management IP, SSH username, and SSH password.');
        }

        if (strtolower((string) ($olt->management_protocol ?: 'ssh')) !== 'ssh') {
            throw new RuntimeException('Huawei MA5608T collector currently supports SSH only.');
        }

        $limits = $this->sessionLimits ?? [];
        $sessionLock = Cache::lock(
            'huawei-olt-ssh-session:'.(int) $olt->getKey(),
            (int) ($limits['lock'] ?? self::SSH_SESSION_LOCK_SECONDS),
        );
        if (! CacheLockWait::acquire($sessionLock, (int) ($limits['wait'] ?? self::SSH_SESSION_LOCK_WAIT_SECONDS))) {
            // Thrown before any SSH connection exists: nothing was sent.
            throw new HuaweiOltSessionBusyException;
        }

        try {
            $ssh = new SSH2($olt->management_ip, $olt->management_port ?: 22, (int) ($limits['connect'] ?? self::SSH_CONNECT_TIMEOUT_SECONDS));
            $this->sessionLocks[spl_object_id($ssh)] = $sessionLock;
        } catch (\Throwable $e) {
            try {
                $sessionLock->release();
            } catch (\Throwable) {
                // Lock cleanup must not replace the connection error.
            }
            throw $this->transportFailure('connect', null, null, $e);
        }

        try {
            $ssh->setTimeout((int) ($limits['read'] ?? self::SSH_COMMAND_TIMEOUT_SECONDS));
            $ssh->setKeepAlive(self::SSH_KEEP_ALIVE_SECONDS);

            if (! $ssh->login($olt->username, $olt->password)) {
                throw new RuntimeException('Failed to log in to Huawei MA5608T via SSH.');
            }

            $this->synchronizeShell($ssh);
            $this->run($ssh, 'enable');

            return $ssh;
        } catch (\Throwable $e) {
            $this->disconnectSafely($ssh);

            throw $e;
        }
    }

    protected function synchronizeShell(SSH2 $ssh): void
    {
        $this->writeSsh($ssh, "\r", 'shell_sync');
        $output = '';

        for ($packet = 0; $packet < self::MAX_INTERACTIVE_PACKETS; $packet++) {
            $chunk = $this->readSsh($ssh, 'shell_sync');
            if ($chunk === false) {
                throw $this->timeoutFailure('shell_sync');
            }
            $output .= $chunk;
            $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;

            if ($this->isHuaweiCliPromptAtEnd($clean)) {
                return;
            }
        }

        throw new RuntimeException('Huawei CLI prompt timeout.');
    }

    protected function run(SSH2 $ssh, string $command): string
    {
        $this->guardSessionDeadline($ssh);
        // MA5600 CLI treats CR as the terminal Enter key. A bare LF opens the
        // command help prompt and leaves the command unexecuted.
        $this->writeSsh($ssh, $command."\r", 'command', $command);
        $output = '';
        $commandEchoSeen = false;
        $paginationOffset = null;
        $confirmationOffset = null;
        $optionPrompt = null;

        for ($packet = 0; $packet < self::MAX_INTERACTIVE_PACKETS; $packet++) {
            if ($packet > 0) {
                $this->guardSessionDeadline($ssh); // a trickling multi-page answer cannot run past the session deadline
            }
            $chunk = $this->readSsh($ssh, 'command', $command);
            if ($chunk === false) {
                throw $this->timeoutFailure('command', $command);
            }
            $output .= $chunk;
            $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $output) ?? $output;

            if (preg_match_all('/----\s*More\b.*?----/i', $clean, $paginationMatches, PREG_OFFSET_CAPTURE)) {
                $lastPagination = end($paginationMatches[0]);
                $currentPaginationOffset = (int) $lastPagination[1];
                if ($paginationOffset !== $currentPaginationOffset) {
                    $paginationOffset = $currentPaginationOffset;
                    $this->writeSsh($ssh, ' ', 'pagination', $command);

                    continue;
                }
            }

            if (preg_match_all('/(?:are you sure|save.*\(y\/n\)|\[y\/n\])/i', $clean, $confirmationMatches, PREG_OFFSET_CAPTURE)) {
                $lastConfirmation = end($confirmationMatches[0]);
                $currentConfirmationOffset = (int) ($lastConfirmation[1] ?? -1);
                if ($confirmationOffset !== $currentConfirmationOffset) {
                    $confirmationOffset = $currentConfirmationOffset;
                    $this->writeSsh($ssh, "y\r", 'confirmation', $command);

                    continue;
                }
            }

            // Huawei prints `{ <cr>|... }:` for commands with optional
            // arguments. A partial `{ <cr>` chunk is also common. The `>` in
            // `<cr>` is not the CLI prompt, so accept the default first.
            $currentOptionPrompt = $this->huaweiOptionPromptAtEnd($clean);
            if ($currentOptionPrompt !== null && $currentOptionPrompt !== $optionPrompt) {
                $optionPrompt = $currentOptionPrompt;
                $this->writeSsh($ssh, "\r", 'option_prompt', $command);

                continue;
            }

            $commandEchoSeen = $commandEchoSeen || $this->commandEchoSeen($clean, $command);

            if ($commandEchoSeen && $this->isHuaweiCliPromptAtEnd($clean) && ! preg_match('/(?:are you sure|\(y\/n\)|\[y\/n\])\s*$/i', $clean)) {
                return trim($output);
            }
        }

        throw $this->timeoutFailure('command', $command);
    }

    protected function huaweiOptionPromptAtEnd(string $clean): ?string
    {
        $lines = preg_split('/\R/', $clean) ?: [];
        $lastLine = '';

        foreach (array_reverse($lines) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lastLine = $line;
                break;
            }
        }

        if (preg_match('/(?<prompt>\{[^\r\n]*<cr\b[^\r\n]*\}\s*:?)\s*$/i', $lastLine, $match) === 1) {
            return $match['prompt'];
        }

        return preg_match('/(?<prompt>\{[^\r\n]*<cr\b[^\r\n]*)$/i', $lastLine, $match) === 1
            ? $match['prompt']
            : null;
    }

    protected function isHuaweiCliPromptAtEnd(string $clean): bool
    {
        if ($this->huaweiOptionPromptAtEnd($clean) !== null) {
            return false;
        }

        $lines = preg_split('/\R/', $clean) ?: [];
        $lastLine = '';

        foreach (array_reverse($lines) as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lastLine = $line;
                break;
            }
        }

        return $lastLine !== '' && preg_match('/^[^<\r\n]+[>#]\s*$/', $lastLine) === 1;
    }

    protected function writeSsh(SSH2 $ssh, string $payload, string $stage, ?string $command = null, ?int $commandIndex = null): void
    {
        try {
            $ssh->write($payload);
        } catch (\Throwable $e) {
            throw $this->transportFailure($stage, $command, $commandIndex, $e);
        }
    }

    protected function readSsh(SSH2 $ssh, string $stage, ?string $command = null, ?int $commandIndex = null): string|false
    {
        try {
            return $ssh->read('', SSH2::READ_NEXT);
        } catch (\Throwable $e) {
            throw $this->transportFailure($stage, $command, $commandIndex, $e);
        }
    }

    protected function timeoutFailure(string $stage, ?string $command = null, ?int $commandIndex = null): HuaweiOltCommandException
    {
        $position = $commandIndex !== null ? ' #'.$commandIndex : '';
        $label = $command ? ' command '.$this->safeCommandLabel($command) : '';

        return new HuaweiOltCommandException(
            'timeout',
            $stage,
            $commandIndex,
            $command ? $this->safeCommandLabel($command) : null,
            'read_timeout',
            'Huawei SSH timeout at '.$stage.$position.$label.'.',
        );
    }

    protected function transportFailure(string $stage, ?string $command, ?int $commandIndex, \Throwable $exception): HuaweiOltCommandException
    {
        $position = $commandIndex !== null ? ' #'.$commandIndex : '';
        $label = $command ? ' command '.$this->safeCommandLabel($command) : '';
        $cause = class_basename($exception);

        return new HuaweiOltCommandException(
            'transport',
            $stage,
            $commandIndex,
            $command ? $this->safeCommandLabel($command) : null,
            $cause,
            'Huawei SSH transport failure at '.$stage.$position.$label.' ('.$cause.').',
            $exception,
        );
    }

    protected function disconnectSafely(?SSH2 $ssh): void
    {
        if (! $ssh) {
            return;
        }

        try {
            $ssh->disconnect();
        } catch (\Throwable) {
            // The OLT may close the channel first. Cleanup must not turn a
            // completed command or the original failure into a new failure.
        } finally {
            $lock = $this->sessionLocks[spl_object_id($ssh)] ?? null;
            unset($this->sessionLocks[spl_object_id($ssh)]);
            try {
                $lock?->release();
            } catch (\Throwable) {
                // Lock cleanup must not mask the command or transport result.
            }
        }
    }

    protected function commandEchoSeen(string $output, string $command): bool
    {
        $normalize = static function (string $value): string {
            $value = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $value) ?? $value;
            $value = preg_replace('/[\x00-\x1F\x7F]+/', '', $value) ?? $value;

            return strtolower(preg_replace('/[^a-z0-9]+/i', '', $value) ?? '');
        };

        $expected = $normalize($command);

        return $expected !== '' && str_contains($normalize($output), $expected);
    }

    protected function safeCommandLabel(string $command): string
    {
        $label = trim($command);

        return preg_replace_callback(
            '/(sn-auth\s+)(["\']?)([^\s"\']+)\2/i',
            static fn (array $match): string => $match[1].$match[2].'[redacted]'.$match[2],
            $label,
        ) ?? $label;
    }

    protected function normalizeHuaweiSerial(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/^([0-9A-F]{8})([0-9A-F]+)$/', $value, $match)) {
            $vendor = hex2bin($match[1]);
            if ($vendor !== false && preg_match('/^[A-Z0-9]{2,8}$/', $vendor)) {
                return $vendor.$match[2];
            }
        }

        return $value;
    }

    protected function vendorIdFromSerial(?string $serialNumber): ?string
    {
        return preg_match('/^([A-Z]{4})/', (string) $serialNumber, $match) === 1
            ? $match[1]
            : null;
    }

    protected function trimRaw(string $output): string
    {
        return mb_substr($output, 0, 5000);
    }

    protected function normalizeState(?string $state): ?string
    {
        $value = trim((string) $state);
        $lower = strtolower($value);

        return match (true) {
            $value === '' => null,
            str_contains($lower, 'online') || str_contains($lower, 'up') || str_contains($lower, 'active') => 'online',
            str_contains($lower, 'offline') || str_contains($lower, 'down') || str_contains($lower, 'inactive') => 'offline',
            default => $value,
        };
    }

    protected function isCommandFailure(string $output): bool
    {
        return preg_match('/(^|\b)(failure|failed|error|invalid|incomplete command|unrecognized|unknown command|parameter error)(\b|:)/i', $output) === 1;
    }

    protected function splitOnuRef(string $onuRef): array
    {
        $value = strtolower(trim(str_replace('gpon-onu_', '', $onuRef)));

        if (! preg_match('/^(\d+\/\d+\/(\d+)):(\d+)$/', $value, $match)) {
            throw new RuntimeException('Invalid Huawei ONU reference. Expected 0/0/port:ont-id.');
        }

        return [$match[2], $match[3]];
    }

    protected function writeBlocked(): never
    {
        throw new RuntimeException('Huawei MA5608T write operation is blocked; only read-only inventory is enabled.');
    }
}
