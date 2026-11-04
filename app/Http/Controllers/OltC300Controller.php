<?php

namespace App\Http\Controllers;

use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Jobs\ProcessGenieAcsInformJob;
use App\Jobs\RefreshOltSnapshotJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\OltVlanMapping;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Services\FhttEnrollmentService;
use App\Services\GenieAcsService;
use App\Services\ManualProvisionMigrationLinker;
use App\Services\Olt\Encoders\VendorRuleViolationException;
use App\Services\Olt\Encoders\ZteC300CommandEncoder;
use App\Services\Olt\OltProvisioningGuard;
use App\Services\OltCollectorService;
use App\Services\PsbSiteRegistry;
use App\Support\OltSnmpCommunity;
use App\Support\OltVendor;
use App\Support\SensitiveDataRedactor;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use phpseclib3\Net\SSH2;
use Symfony\Component\Process\Process;

/**
 * OLT C300 Controller - Optimized for speed & stability
 *
 * @route /api/olt-c300
 */
class OltC300Controller extends Controller
{
    use AutoPppoeTrait;

    private function provisioningGate(Olt $olt, Request $request, string $operation): ?JsonResponse
    {
        if ($request->attributes->get('olt_provisioning_gate_checked') === true) {
            return null;
        }

        $approval = $request->attributes->get('olt_provisioning_approval');
        if (! is_array($approval)) {
            $approval = [
                'approval' => $request->input('approval') ?: $request->header('X-OLT-Approval'),
                'approved_at' => $request->input('approved_at') ?: $request->header('X-OLT-Approved-At'),
                'signature' => $request->input('signature') ?: $request->header('X-OLT-Signature'),
            ];
        }
        if (OltProvisioningGuard::matchesLiveConfirmation($request->input('live_confirm'), $operation)
            && $request->user()?->isGlobalAdmin()
            && trim((string) ($approval['approval'] ?? '')) === '') {
            $approval = app(OltProvisioningGuard::class)->issueInternalApproval(
                $olt,
                $operation,
                'operator-'.$request->user()->getKey(),
            );
        }

        try {
            $result = app(OltProvisioningGuard::class)->provision($olt, [
                'commands' => [$operation],
                'approval' => $approval['approval'] ?? null,
                'approved_at' => $approval['approved_at'] ?? null,
                'signature' => $approval['signature'] ?? null,
            ]);
        } catch (OltProvisioningBlockedException) {
            return $this->provisioningBlockedResponse();
        }

        if ($result['dry_run']) {
            return $this->provisioningBlockedResponse('dry_run');
        }

        return null;
    }

    private function provisioningBlockedResponse(string $code = 'approval_required'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error_code' => 'olt_provisioning_'.$code,
            'message' => 'OLT write blocked by the provisioning safety gate. No device write was performed.',
        ], 423);
    }

    private function validateFiberhomeCliValues(
        string $sn,
        string $port,
        string $name,
        string $onuType,
        string $trafficUp,
        string $trafficDown,
        int $vlan,
        int $tr069Vlan,
    ): ?JsonResponse {
        $encoder = app(ZteC300CommandEncoder::class);
        $values = [
            'sn' => $sn,
            'port' => $port,
            'name' => $name,
            'onu_type' => $onuType,
            'traffic_up' => $trafficUp,
            'traffic_down' => $trafficDown,
            'vlan' => (string) $vlan,
            'tr069_vlan' => (string) $tr069Vlan,
        ];

        foreach ($values as $field => $value) {
            try {
                $encoder->encodeArg($value);
            } catch (VendorRuleViolationException) {
                return response()->json([
                    'success' => false,
                    'error_code' => 'olt_cli_value_invalid',
                    'error' => "Nilai {$field} tidak valid untuk command OLT. Periksa input lalu coba lagi.",
                    'field' => $field,
                ], 422);
            }
        }

        return null;
    }

    /**
     * SSH connect with retry (3 attempts, exponential backoff).
     * Prevents transient network failures from killing the whole provision.
     */
    private function sshWithRetry(Olt $olt, int $maxAttempts = 3): SSH2
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $this->ssh($olt);
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning("SSH connect attempt {$attempt}/{$maxAttempts} failed", ['error' => SensitiveDataRedactor::redact($e->getMessage())]);
                if ($attempt < $maxAttempts) {
                    usleep(500000 * $attempt); // 0.5s, 1s backoff
                }
            }
        }
        throw $lastError;
    }

    /**
     * Parse the ONU IDs already configured on a port from its running-config output.
     * Returns a list of ints (e.g. [1, 2, 5, 12]).
     */
    private function parseConfiguredOnuIds(string $cfgOut): array
    {
        $ids = [];
        if (preg_match_all('/onu\s+(\d+)\s+type/i', $cfgOut, $m)) {
            foreach ($m[1] as $oid) {
                $ids[(int) $oid] = true; // dedupe
            }
        }

        return array_keys($ids);
    }

    /**
     * Satu aturan untuk MANUAL FiberHome UI + PSB WA:
     * - Baca running-config gpon-olt_X → daftar ONU terpakai
     * - Ambil ID kosong terkecil (gap-fill 1..128)
     * - Kalau 1..N berurutan tanpa lubang → otomatis N+1 (sama “last+1”)
     * - JANGAN percaya index uncfg (sering :1 padahal ID 1 sudah SN lain)
     *
     * @param  list<int>  $usedIds
     */
    private function nextOnuIdGapFill(array $usedIds, int $max = 128): ?int
    {
        $used = array_flip(array_map('intval', $usedIds));
        for ($i = 1; $i <= $max; $i++) {
            if (! isset($used[$i])) {
                return $i;
            }
        }

        return null; // port full
    }

    /**
     * Strip ANSI escape codes from SSH output.
     */
    private function stripAnsi(string $text): string
    {
        return preg_replace('/\e\[[0-9;]*[a-zA-Z]/', '', $text);
    }

    /**
     * SSH connect with paging disabled and banner consumed.
     * Uses decrypt() for double-encrypted DB password.
     */
    private function ssh(Olt $olt): SSH2
    {
        $prevErrorReporting = error_reporting(error_reporting() & ~E_DEPRECATED);
        $port = $olt->management_port ?? 22;
        $ssh = new SSH2($olt->management_ip, $port, 15);

        // Try legacy algorithms for older ZTE firmware
        try {
            $ssh->setPreferredAlgorithms([
                'hostkey' => ['ssh-rsa', 'ssh-dss'],
                'keyexchange' => ['diffie-hellman-group1-sha1', 'diffie-hellman-group14-sha1'],
                'crypt' => ['aes128-cbc', '3des-cbc', 'blowfish-cbc'],
            ]);
        } catch (\Throwable) {
        }

        if (! $ssh->login($olt->username, $olt->password)) {
            $sshError = $ssh->getLastError() ?: 'Unknown error';
            throw new \RuntimeException("SSH login failed to {$olt->management_ip} — username/password rejected (user: {$olt->username}, error: {$sshError})");
        }

        // Consume login banner/motd
        $ssh->setTimeout(5);
        $prompt = $ssh->read('/[#>]/', SSH2::READ_REGEX);

        // Enter enable mode if in user mode (>)
        // ZTE C300: "terminal length 0" only works in enable mode (#)
        if (strpos($prompt, '>') !== false || ! preg_match('/#$/', trim($this->stripAnsi($prompt)))) {
            $ssh->setTimeout(5);
            $ssh->write("enable\n");
            try {
                $enableResp = $ssh->read('/[#>]|[Pp]assword/', SSH2::READ_REGEX);
                // If password prompt, send enable password (same as login password)
                if (preg_match('/[Pp]assword/', $this->stripAnsi($enableResp))) {
                    $ssh->write($olt->password."\n");
                    try {
                        $ssh->read('/[#>]/', SSH2::READ_REGEX);
                    } catch (\Throwable) {
                    }
                }
            } catch (\Throwable) {
            }
        }

        // Disable paging - try multiple ZTE-compatible commands (now in enable mode)
        foreach (['terminal length 0', 'scroll 0', 'no clipaging'] as $cmd) {
            $ssh->setTimeout(3);
            $ssh->write($cmd."\n");
            try {
                $ssh->read('/[#>]/', SSH2::READ_REGEX);
            } catch (\Throwable) {
            }
        }

        $ssh->setTimeout(30);
        error_reporting($prevErrorReporting);

        return $ssh;
    }

    /**
     * execFast - lightweight write+read for config commands.
     * No heavy ANSI stripping or echo detection - just write + read until prompt.
     * ~2-3x faster than exec() for simple ZTE config commands.
     * Returns raw output (with ANSI codes still present - use stripAnsi() to clean).
     */
    private function execFast(SSH2 $ssh, string $cmd, int $timeout = 20): string
    {
        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        $ssh->setTimeout($timeout);
        $ssh->write($cmd."\n");
        try {
            $raw = $ssh->read('/[#>]/', SSH2::READ_REGEX);
        } catch (\Throwable) {
            error_reporting($prev);

            return '';
        }
        error_reporting($prev);

        return $raw ?: '';
    }

    private function saveConfig(SSH2 $ssh): string
    {
        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        $ssh->setTimeout(35);
        $raw = '';

        // ZTE `write` only from enable. Use leaveToEnable (end-first) —
        // never spam exit at SKYNET# (that logs out SSH).
        $this->leaveToEnable($ssh);
        $raw .= $this->execFast($ssh, '', 5);

        $ssh->write("write\n");
        $start = time();

        while ((time() - $start) < 35) {
            try {
                $chunk = $ssh->read('/[#>]|\\[yes\\/no\\]|\\(y\\/n\\)|[Cc]onfirm|[Yy]es|[Nn]o|[Oo]verwrite|[Ss]ave/', SSH2::READ_REGEX);
            } catch (\Throwable) {
                usleep(100000);

                continue;
            }

            if ($chunk === false || $chunk === '') {
                usleep(100000);

                continue;
            }

            $raw .= $chunk;
            $clean = $this->stripAnsi($raw);
            if (preg_match('/\\[yes\\/no\\]|\\(y\\/n\\)|[Cc]onfirm|[Yy]es|[Nn]o|[Oo]verwrite|[Ss]ave/i', $clean) && ! preg_match('/[#>]\\s*$/', trim($clean))) {
                $ssh->write("yes\n");

                continue;
            }
            if (preg_match('/[#>]\\s*$/', trim($clean))) {
                break;
            }
        }

        error_reporting($prev);

        return $raw;
    }

    private function parsePowerValue(string $text, string $direction = 'rx'): ?float
    {
        $patterns = $direction === 'tx'
            ? [
                '/\\bTx\\s*(?:power)?\\s*[:=\\-]?\\s*(-?\\d+(?:\\.\\d+)?)\\s*(?:dBm|dbm)?/i',
                '/\\btransmit(?:ted)?\\s*(?:power)?\\s*[:=\\-]?\\s*(-?\\d+(?:\\.\\d+)?)\\s*(?:dBm|dbm)?/i',
            ]
            : [
                '/\\bRx\\s*(?:power)?\\s*[:=\\-]?\\s*(-?\\d+(?:\\.\\d+)?)\\s*(?:dBm|dbm)?/i',
                '/\\breceive(?:d)?\\s*(?:power)?\\s*[:=\\-]?\\s*(-?\\d+(?:\\.\\d+)?)\\s*(?:dBm|dbm)?/i',
                '/\\bonu\\s*rx\\s*[:=\\-]?\\s*(-?\\d+(?:\\.\\d+)?)\\s*(?:dBm|dbm)?/i',
            ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = (float) $m[1];

                return $value > 0 ? null : $value;
            }
        }

        return null;
    }

    private function normalizeOnuRefKey(?string $ref): string
    {
        $ref = strtolower(trim((string) $ref));
        $ref = preg_replace('/^gpon-onu_/', '', $ref);

        return $ref ?? '';
    }

    /**
     * exec - send one command, read until ZTE prompt.
     * Uses SSH2::READ_REGEX for reliable prompt detection.
     * Strips ANSI, echoes, pagination from output.
     */
    private function exec(SSH2 $ssh, string $cmd, int $timeout = 30): string
    {
        $prev = error_reporting(error_reporting() & ~E_DEPRECATED);
        $ssh->setTimeout($timeout);
        $ssh->write($cmd."\n");

        $raw = '';
        $start = time();

        while ((time() - $start) < $timeout) {
            try {
                $chunk = $ssh->read('/[#>]/', SSH2::READ_REGEX);
            } catch (\Throwable) {
                usleep(100000);

                continue;
            }
            if ($chunk !== false && $chunk !== '') {
                $raw .= $chunk;
            }

            // Handle --More-- / --more-- / (Q)uit pagination (ZTE C300)
            $rawClean = $this->stripAnsi($raw);
            if (preg_match('/--\s*More\s*--|--more--|\(Q\)uit/i', $rawClean)) {
                $ssh->write(' ');
                // Strip the --More-- marker from accumulated output
                $raw = preg_replace('/--\s*More\s*--|--more--/i', '', $raw);
                // Also strip any (Q)uit line
                $raw = preg_replace('/\(Q\)uit\s*[^\n]*/i', '', $raw);

                continue;
            }

            // ZTE prompt at end of output => command complete
            $clean = preg_replace('/\e\[[\d;]*[A-Za-z]/', '', $raw);
            if (preg_match('/[A-Z]+.*#\s*$/', $clean)) {
                break;
            }
        }

        if (empty($raw)) {
            return '';
        }

        // Strip ANSI escape codes
        $raw = preg_replace('/\e\[[0-9;]*[a-zA-Z]/', '', $raw);

        // Remove the echoed command line and trailing prompt
        $lines = explode("\n", $raw);
        $result = [];
        $foundCmd = false;
        $cmdNorm = preg_replace('/\s+/', ' ', trim($cmd));
        $cmdPrefix = substr($cmdNorm, 0, min(strlen($cmdNorm), 30));

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }

            // Skip echoed command
            if (! $foundCmd) {
                $lineNorm = preg_replace('/\s+/', ' ', $t);
                if (stripos($lineNorm, $cmdPrefix) !== false) {
                    $foundCmd = true;
                }

                continue;
            }

            // Skip trailing ZTE prompt
            if (preg_match('/^[A-Z]+.*#$/', $t)) {
                continue;
            }
            // Skip separator lines
            if (preg_match('/^[-]{20,}/', $t)) {
                continue;
            }

            $result[] = $t;
        }

        $output = implode("\n", $result);
        $output = preg_replace('/(?:\x08\s?)+/', '', $output);

        return trim($output);
    }

    /**
     * execBatch - send multiple commands sequentially via exec().
     * Returns [cmd => output] map.
     */
    private function execBatch(SSH2 $ssh, array $commands, int $timeoutPerCmd = 15): array
    {
        $results = [];
        foreach ($commands as $cmd) {
            $results[trim($cmd)] = $this->exec($ssh, $cmd, $timeoutPerCmd);
        }

        return $results;
    }

    /**
     * isZteError - strict ZTE C300 error detection.
     * Only matches REAL ZTE errors, not % in normal output.
     * Does NOT flag wan-ip 2 mode tr069 (vlan-profile 69 missing is non-fatal).
     */
    private function isZteError(string $output): bool
    {
        if (empty($output)) {
            return false;
        }
        $upper = strtoupper($output);

        // ZTE C300: %Error / %Error 20200 at line start
        if (preg_match('/^%\s*ERROR/m', $upper)) {
            return true;
        }
        // Compact form sometimes glued after echo: "%Error 20200: Invalid input..."
        if (preg_match('/%ERROR\s*\d*/', $upper)) {
            return true;
        }
        // GPON register rejects: "%Code 63904-GPONRM : Not support this ONU."
        // MUST be treated as error — otherwise claim "succeeds" but gpon-onu_X:Y never exists
        if (preg_match('/%CODE\s*\d+/', $upper)) {
            return true;
        }
        if (preg_match('/NOT SUPPORT THIS ONU|ONU TYPE.*NOT|TYPE.*NOT EXIST|UNKNOWN ONU TYPE/i', $upper)) {
            return true;
        }

        // Other ZTE canonical error strings (include "Invalid command" from wrong CLI mode)
        return (bool) preg_match(
            '/(?:INVALID INPUT DETECTED|INVALID COMMAND|INVALID PARAMETER|UNKNOWN COMMAND|INCOMPLETE COMMAND|COMMAND NOT SUPPORT|CONFIGURATION CONFLICT)/im',
            $upper
        );
    }

    /**
     * True if last prompt is (config-if)# — service-port HANYA valid di sini.
     */
    private function isConfigIfPrompt(string $output): bool
    {
        $clean = $this->stripAnsi($output);

        return (bool) preg_match('/\(config-if[^)]*\)#\s*$/m', $clean)
            || (bool) preg_match('/\(config-if\)#/i', $clean);
    }

    /**
     * True if privilege-exec enable prompt (SKYNET#) — not (config)# / (config-if)#.
     */
    private function isEnablePrompt(string $output): bool
    {
        $clean = $this->stripAnsi($output);
        if (preg_match('/\([^)]*\)[#>]\s*$/m', $clean)) {
            return false; // still in (config) / (config-if) / etc
        }

        return (bool) preg_match('/[A-Za-z0-9_-]+#\s*$/m', $clean);
    }

    /**
     * Leave config/submode to enable. Prefer `end`.
     * CRITICAL: never spam `exit` at enable — on ZTE C300 that LOGS OUT SSH.
     * (Root cause draft #50/#52: leaveToEnable exit×3 → session dead → interface at SKYNET# → Invalid command)
     */
    private function leaveToEnable(SSH2 $ssh): void
    {
        for ($i = 0; $i < 5; $i++) {
            $out = $this->stripAnsi($this->execFast($ssh, 'end', 8));
            if ($this->isEnablePrompt($out)) {
                return;
            }
            // Still in submode (e.g. pon-onu-mng) where `end` alone is not enough
            if (preg_match('/\([^)]*\)[#>]\s*$/m', $out) || preg_match('/pon-onu-mng|gpon-onu_|gpon-olt_/i', $out)) {
                $out2 = $this->stripAnsi($this->execFast($ssh, 'exit', 8));
                if ($this->isEnablePrompt($out2)) {
                    return;
                }

                continue;
            }
            // Already enable or unknown — stop (do NOT exit again)
            if ($this->isEnablePrompt($out) || $out === '') {
                return;
            }
            // end may print Invalid at enable — treat as enable-ish and stop
            if (preg_match('/invalid command/i', $out) && ! preg_match('/\([^)]*\)#/i', $out)) {
                return;
            }
        }
    }

    /**
     * Enter configure + interface gpon-onu — MUST land on (config-if)#.
     * Working live path: end → conf t → interface gpon-onu_X:Y → SKYNET(config-if)#
     * Never uses CLI '?' help. Never exit-at-enable (logs out).
     *
     * @return array{0: bool, 1: list<array<string,string>>, 2: string}
     */
    private function enterGponOnuInterface(SSH2 $ssh, string $port, int $onuId): array
    {
        $log = [];
        $ifCmd = "interface gpon-onu_{$port}:{$onuId}";
        $out = '';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->leaveToEnable($ssh);
            $ct = $this->stripAnsi($this->execFast($ssh, 'configure terminal'));
            $log[] = [
                'cmd' => 'configure terminal'.($attempt > 1 ? " (retry {$attempt})" : ''),
                'output' => substr(preg_replace('/\s+/', ' ', $ct), 0, 120),
            ];

            $inConfig = (bool) preg_match('/\(config(?!-if)[^)]*\)#/i', $ct)
                || (bool) preg_match('/\(config\)#/i', $ct);
            // Also accept if already dropped into some config-* mode
            if (! $inConfig && ! preg_match('/\(config/i', $ct)) {
                // conf t failed — session may be half-dead; end + conf t once more
                $this->execFast($ssh, 'end', 5);
                $ct = $this->stripAnsi($this->execFast($ssh, 'configure terminal'));
                $log[] = ['cmd' => 'configure terminal (force)', 'output' => substr(preg_replace('/\s+/', ' ', $ct), 0, 120)];
                if (! preg_match('/\(config/i', $ct)) {
                    continue; // cannot enter config — retry leave+conf
                }
            }

            $out = $this->stripAnsi($this->execFast($ssh, $ifCmd));
            $label = $attempt === 1 ? $ifCmd : $ifCmd." (retry {$attempt})";
            $log[] = ['cmd' => $label, 'output' => substr(preg_replace('/\s+/', ' ', $out), 0, 220)];

            if ($this->isConfigIfPrompt($out)) {
                return [true, $log, $out];
            }
        }

        return [false, $log, $out];
    }

    /**
     * If last CLI output still shows (config-if)#, stay. Otherwise re-enter once.
     *
     * @return array{0: bool, 1: list<array<string,string>>, 2: string}
     */
    private function ensureGponOnuInterface(SSH2 $ssh, string $port, int $onuId, string $lastOut = ''): array
    {
        if ($lastOut !== '' && $this->isConfigIfPrompt($lastOut)) {
            return [true, [], $lastOut];
        }

        return $this->enterGponOnuInterface($ssh, $port, $onuId);
    }

    /**
     * service-port success: OK, or already-existed on re-provision.
     * Fail hard on wrong mode (bare (config)# / enable) or real ZTE errors.
     */
    private function isServicePortOk(string $out): bool
    {
        // Re-provision / idempotent apply
        if (preg_match('/already\s+exist|service\s+is\s+already|already\s+existed/i', $out)) {
            return true;
        }
        // Wrong CLI mode (the draft #50/#52 failure)
        if (preg_match('/invalid command/i', $out) && ! $this->isConfigIfPrompt($out)) {
            return false;
        }
        if (preg_match('/\(config\)#\s*$/m', $out) && ! preg_match('/config-if/i', $out)) {
            return false;
        }
        if ($this->isEnablePrompt($out) && preg_match('/invalid command|%error/i', $out)) {
            return false;
        }
        if ($this->isZteError($out)) {
            return false;
        }

        return true;
    }

    /**
     * Human hint when service-port fails.
     * VLAN sering SUDAH ada di chassis — jangan selalu bilang "VLAN belum ada".
     */
    private function explainServicePortFailure(string $oltOut, string $port, int $vlan, int $onuId): string
    {
        // Drop echoed command line so "vport" in the cmd does not fake the root cause
        $body = preg_replace('/^.*service-port[^\n]*/im', '', $oltOut) ?? $oltOut;
        $u = strtoupper($body);
        $snip = substr(preg_replace('/\s+/', ' ', $oltOut), 0, 160);

        if ((preg_match('/\(config\)#/i', $oltOut) && ! preg_match('/config-if/i', $oltOut))
            || (preg_match('/invalid command/i', $oltOut) && $this->isEnablePrompt($oltOut))) {
            return 'CLI masih di mode salah (bukan (config-if)#) — interface gpon-onu belum aktif. '
                ."Coba #psb lanjut lagi. OLT: {$snip}";
        }
        if (preg_match('/conflict|duplicate|occupied|in use/i', $body)
            && ! preg_match('/already\s+exist/i', $body)) {
            return "Konflik di gpon-onu_{$port}:{$onuId} (ONU ID mungkin sudah dipakai SN lain). "
                ."Index uncfg :{$onuId} sering BUKAN ID free — harus gap-fill. "
                ."Coba #psb lanjut lagi. Cek: show running-config interface gpon-olt_{$port}";
        }
        if (preg_match('/not exist|unknown vlan|invalid vlan/i', $u)) {
            return "OLT bilang VLAN {$vlan} bermasalah. Cek: show vlan {$vlan}. Output: {$snip}";
        }

        return "OLT menolak service-port (VLAN {$vlan} biasanya sudah ada di chassis). "
            ."Penyebab umum: CLI mode salah / residual di gpon-onu_{$port}:{$onuId}. OLT: {$snip}";
    }

    /**
     * rollback - remove partially configured ONU from gpon-olt, gpon-onu, pon-onu-mng.
     */
    private function rollback(SSH2 $ssh, string $port, int $onuId): void
    {
        try {
            $this->execBatch($ssh, [
                'configure terminal',
                "interface gpon-olt_{$port}",
                "no onu {$onuId}",
                'exit',
                'exit',
            ], 10);
            // Save config so OLT persists after reboot
            $this->saveConfig($ssh);
            Log::info("OLT rollback: removed onu {$onuId} from gpon-olt_{$port} (saved)");
        } catch (\Throwable $e) {
            Log::error('OLT rollback failed', ['port' => $port, 'onuId' => $onuId, 'error' => SensitiveDataRedactor::redact($e->getMessage())]);
        }
    }

    /**
     * Normalize PON port key for map lookup (avoid miss → wrong VLAN 100).
     * "gpon-olt_1/3/3", "1/3/3 ", "1 / 3 / 3" → "1/3/3"
     */
    private static function normalizePonPort(string $port): string
    {
        $port = trim($port);
        $port = preg_replace('/^gpon-olt_/i', '', $port) ?? $port;
        $port = preg_replace('/\s+/', '', $port) ?? $port;
        // strip trailing :onuId if pasted
        if (preg_match('/^(\d+\/\d+\/\d+)(?::\d+)?$/', $port, $m)) {
            return $m[1];
        }

        return $port;
    }

    /**
     * VLAN map per OLT — HANYA dari DB `olt_vlan_mappings` (tanpa hardcode fallback).
     * Port tanpa baris mapping = tidak ada VLAN (caller harus error / bilang ke user).
     *
     * @return array<string, int> pon_port => vlan
     */
    private static function vlanMap(?int $oltId = null): array
    {
        $map = [];

        if (! $oltId || ! Schema::hasTable('olt_vlan_mappings')) {
            return $map;
        }

        OltVlanMapping::query()
            ->where('olt_id', $oltId)
            ->where(function ($q) {
                $q->where('service_type', 'internet')
                    ->orWhereNull('service_type')
                    ->orWhere('service_type', '');
            })
            ->where('is_active', true)
            ->orderBy('pon_port')
            ->get(['pon_port', 'vlan'])
            ->each(function (OltVlanMapping $mapping) use (&$map) {
                $key = self::normalizePonPort((string) $mapping->pon_port);
                if ($key !== '' && (int) $mapping->vlan > 0) {
                    $map[$key] = (int) $mapping->vlan;
                }
            });

        return $map;
    }

    /**
     * STEP 2026-10-01 (owner): fixed C300 traffic limits per OLT. Per-user speed
     * is controlled by the MikroTik profile, so the OLT side gets one big pipe:
     *   OLT RA (id 1)    -> 500M
     *   OLT KRIAN (id 9) -> 375M
     * Returns the Mbps number, or null when the OLT has no fixed limit (falls
     * back to the paket-derived UP/DW). Override via setting psb_olt_traffic_limits
     * JSON {"1":500,"9":375}.
     */
    private static function c300FixedTrafficLimit(int $oltId): ?int
    {
        $defaults = [1 => 500, 9 => 375];
        $raw = Setting::get('psb_olt_traffic_limits', null);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id => $mbps) {
                    $defaults[(int) $id] = (int) $mbps;
                }
            }
        }

        return isset($defaults[$oltId]) && $defaults[$oltId] > 0
            ? (int) $defaults[$oltId]
            : null;
    }

    /**
     * Lookup VLAN internet untuk PON — null jika mapping belum diisi.
     * JANGAN fallback angka default (100/dll).
     */
    private static function vlanForPort(?int $oltId, string $port): ?int
    {
        $key = self::normalizePonPort($port);
        if ($key === '' || ! $oltId) {
            return null;
        }
        $map = self::vlanMap($oltId);

        return isset($map[$key]) ? (int) $map[$key] : null;
    }

    /** Pesan error standar bila mapping VLAN kosong. */
    private static function missingVlanMappingError(?int $oltId, string $port): string
    {
        $key = self::normalizePonPort($port);

        return "VLAN mapping belum diisi untuk PON *{$key}*".
            ($oltId ? " (OLT id={$oltId})" : '').
            '. Buka Aktivasi → pilih OLT → *VLAN Mapping OLT* → isi port + VLAN internet, lalu simpan. Aktivasi *tidak* memakai fallback VLAN.';
    }

    /**
     * Stable option key from router name.
     * Only Randuagung (RA) is an active FH path. Migrasi/Srigading/Lawang → other.
     */
    private static function mikrotikOptionKey(string $name): string
    {
        $u = strtoupper(trim($name));
        // Retired paths
        if (str_contains($u, 'MIGRASI') || str_contains($u, 'SRIGADING') || str_contains($u, 'LAWANG')) {
            return 'other';
        }
        if (str_contains($u, 'RANDUAGUNG') || preg_match('/\bRA\b/', $u) || str_contains($u, 'OLT RA')) {
            return 'randuagung';
        }

        return 'other';
    }

    /**
     * Options for FiberHome "Pilih MikroTik" (PPPoE/TR-069 path).
     * Active C300 sites: MikroTik RA/Randuagung and Krian · TR-069 VLAN 69.
     *
     * @return list<array{key: string, router_id: int|null, label: string, tr069_vlan: int, hint: string, site_key?: string, olt_id?: int|null}>
     */
    private static function mikrotikTr069Options(): array
    {
        $options = [];
        $seenKeys = [];

        // Prefer site registry: RA + Krian (and any full_olt site with router)
        try {
            foreach (app(PsbSiteRegistry::class)->all() as $site) {
                if (($site['mode'] ?? '') !== PsbSiteRegistry::MODE_FULL_OLT) {
                    continue;
                }
                $router = $site['router'] ?? null;
                if (! $router && ! empty($site['router_id'])) {
                    $router = Router::query()->whereKey((int) $site['router_id'])->where('is_active', true)->first();
                }
                if (! $router) {
                    continue;
                }
                $key = (string) ($site['key'] ?? self::mikrotikOptionKey((string) $router->name));
                // Normalise to the frontend key BEFORE the dedupe check, otherwise
                // the legacy fallback below adds a second 'randuagung' entry
                // (registry key 'ra' vs fallback key 'randuagung').
                $feKey = $key === 'ra' ? 'randuagung' : $key;
                if ($feKey === '' || isset($seenKeys[$feKey])) {
                    continue;
                }
                $seenKeys[$feKey] = true;
                $label = (string) $router->name;
                $hint = $feKey === 'krian'
                    ? 'PPPoE '.$label.' · TR-069 VLAN 69 · OLT KRIAN C300'
                    : 'PPPoE '.$label.' · TR-069 VLAN 69 · path C300';
                $options[] = [
                    'key' => $feKey, // keep FE key compatible
                    'router_id' => (int) $router->id,
                    'label' => $label,
                    'tr069_vlan' => 69,
                    'hint' => $hint,
                    'site_key' => (string) ($site['key'] ?? $key),
                    'olt_id' => isset($site['olt_id']) ? (int) $site['olt_id'] : null,
                ];
            }
        } catch (\Throwable) {
            // fall through to legacy query
        }

        if (Schema::hasTable('routers')) {
            $routers = Router::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('name', 'like', '%Randuagung%')
                        ->orWhere('name', 'like', '%randuagung%')
                        ->orWhere('name', 'like', '%RA-OLT%')
                        ->orWhere('name', 'like', '%RA OLT%')
                        ->orWhere('name', 'like', '%Krian-OLT%')
                        ->orWhere('name', 'like', '%Krian%OLT%')
                        ->orWhere('name', 'like', '%Skynet-Krian%');
                })
                ->where('name', 'not like', '%Migrasi%')
                ->where('name', 'not like', '%Srigading%')
                ->where('name', 'not like', '%Lawang%')
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($routers as $router) {
                $name = (string) $router->name;
                $key = self::mikrotikOptionKey($name);
                if (stripos($name, 'krian') !== false) {
                    $key = 'krian';
                } elseif ($key === 'other' && (stripos($name, 'RA-OLT') !== false || stripos($name, 'RA OLT') !== false)) {
                    $key = 'randuagung';
                }
                if ($key === 'other' || isset($seenKeys[$key])) {
                    continue;
                }
                $seenKeys[$key] = true;
                $oltQuery = Olt::query()->where('router_id', (int) $router->id);
                if ($key === 'krian') {
                    $oltQuery->where('name', 'like', '%krian%');
                } else {
                    $oltQuery->where(function ($q) {
                        $q->where('name', 'like', '%ra%')
                            ->orWhere('name', 'like', '%c300%');
                    });
                }
                $mappedOltId = $oltQuery->orderBy('id')->value('id');
                $options[] = [
                    'key' => $key,
                    'router_id' => (int) $router->id,
                    'label' => $name,
                    'tr069_vlan' => 69,
                    'hint' => $key === 'krian'
                        ? 'PPPoE Krian OLT · TR-069 VLAN 69 · OLT KRIAN'
                        : 'PPPoE MikroTik RA · TR-069 VLAN 69 · path OLT C300',
                    'site_key' => $key,
                    'olt_id' => $mappedOltId ? (int) $mappedOltId : null,
                ];
            }
        }

        if ($options === []) {
            $options[] = [
                'key' => 'randuagung',
                'router_id' => null,
                'label' => 'MikroTik RA / Randuagung',
                'tr069_vlan' => 69,
                'hint' => 'PPPoE MikroTik RA · TR-069 VLAN 69 · path OLT C300',
            ];
        }

        return $options;
    }

    /**
     * TR-069 / GenieACS management VLAN — always 69 (MikroTik RA/Krian / OLT C300).
     * VLAN 68 Migrasi path is retired.
     *
     * Still respects explicit olt_vlan_mappings tr069 if set to 69;
     * ignores legacy mapping of 68 (treat as 69).
     */
    private static function tr069Vlan(
        ?int $oltId = null,
        ?Olt $olt = null,
        ?string $mikrotik = null,
        ?int $routerId = null,
    ): int {
        // Hard policy: only VLAN 69 is operational for TR-069 on C300 RA/Krian paths.
        if ($oltId && Schema::hasTable('olt_vlan_mappings')) {
            $mapped = OltVlanMapping::query()
                ->where('olt_id', $oltId)
                ->where('service_type', 'tr069')
                ->where('is_active', true)
                ->orderBy('id')
                ->value('vlan');
            if ($mapped !== null && (int) $mapped === 69) {
                return 69;
            }
            // If mapped to 68 (legacy Migrasi), force 69
        }

        return 69;
    }

    /**
     * @route GET /olt-c300
     * Lightweight SSR props only — no MikroTik/OLT live calls on page open.
     * Areas/routers embedded so browser skips extra round-trips after JS boot.
     */
    public function index(Request $request)
    {
        $olts = Olt::query()
            ->with('area:id,name')
            ->select(['id', 'name', 'area_id'])
            ->where(function ($q) {
                $q->where('name', 'like', '%c300%')
                    ->orWhere('name', 'like', '%C300%')
                    ->orWhere('vendor', 'like', '%zte%');
            })
            ->orderBy('name')
            ->get();
        if ($olts->isEmpty()) {
            $olts = Olt::query()->with('area:id,name')->select(['id', 'name', 'area_id'])->orderBy('name')->limit(10)->get();
        }

        $huaweiOlts = Olt::query()
            ->with('area:id,name')
            ->select(['id', 'name', 'area_id', 'vendor'])
            ->where(function ($q) {
                $q->where('vendor', 'like', '%huawei%')
                    ->orWhere('vendor', 'like', '%ma5608%');
            })
            ->orderBy('name')
            ->get();

        $areas = Area::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'name' => (string) $a->name])
            ->values()
            ->all();

        $routers = Router::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])
            ->values()
            ->all();

        $tab = strtolower(trim((string) $request->query('tab', 'autopp')));
        if (! in_array($tab, ['autopp', 'activate', 'reconfigure', 'fiberhome', 'huawei', 'psb', 'psb-wa', 'vlan-olt', 'vlan-mt', 'rename'], true)) {
            $tab = 'autopp';
        }
        if ($tab === 'psb-wa') {
            $tab = 'psb';
        }

        return Inertia::render('OltC300/Index', [
            'olts' => $olts->map(fn ($o) => [
                'id' => (int) $o->id,
                'name' => (string) $o->name,
                'area_id' => $o->area_id ? (int) $o->area_id : null,
                'area_name' => $o->area?->name,
            ])->values()->all(),
            'areas' => $areas,
            'routers' => $routers,
            'huawei_olts' => $huaweiOlts->map(fn ($o) => [
                'id' => (int) $o->id,
                'name' => (string) $o->name,
                'vendor' => (string) $o->vendor,
                'area_id' => $o->area_id ? (int) $o->area_id : null,
                'area_name' => $o->area?->name,
            ])->values()->all(),
            'initialTab' => $tab, 'psb_default_acs_url' => (string) config('site.acs_url'),
            'psb_password_hint' => (string) config('site.psb_password_hint'),
        ]);
    }

    /**
     * @route GET /api/olt-c300/customer-by-sn
     */
    public function customerBySn(Request $request)
    {
        $request->validate([
            'sn' => 'required|string|max:64',
        ]);

        $sn = strtoupper(trim($request->sn));

        return response()->json($this->resolveOnuNameSuggestion($sn));
    }

    private function normalizeOnuSerial(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', trim($value)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    private function findCustomerByOnuSerial(string $sn): ?Customer
    {
        $normalized = $this->normalizeOnuSerial($sn);
        if ($normalized === null) {
            return null;
        }

        return Customer::query()
            ->whereRaw('UPPER(REPLACE(REPLACE(onu_serial, "-", ""), " ", "")) = ?', [$normalized])
            ->first();
    }

    /**
     * @return array{id: int, code: string|null, name: string|null, pppoe_user: string|null}|null
     */
    private function customerLookupPayload(?Customer $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'pppoe_user' => trim((string) $customer->pppoe_user) ?: null,
        ];
    }

    /**
     * @return array{
     *   found: bool,
     *   sn: string,
     *   suggested_name: string|null,
     *   name_source: string|null,
     *   customer: array<string, mixed>|null,
     *   genieacs: array<string, mixed>|null
     * }
     */
    private function resolveOnuNameSuggestion(string $sn): array
    {
        $genieAcs = app(GenieAcsService::class);
        $customer = $this->findCustomerByOnuSerial($sn);
        $customerPayload = $this->customerLookupPayload($customer);

        $suggestedName = null;
        $nameSource = null;

        if ($customerPayload !== null && filled($customerPayload['pppoe_user'])) {
            $suggestedName = $genieAcs->normalizeOnuDisplayName((string) $customerPayload['pppoe_user']);
            $nameSource = 'ebilling_pppoe';
        } elseif ($customerPayload !== null && filled($customerPayload['name'])) {
            $suggestedName = $genieAcs->normalizeOnuDisplayName((string) $customerPayload['name']);
            $nameSource = 'ebilling_name';
        }

        $genieacsPayload = null;
        if ($suggestedName === null) {
            $genieacsPayload = $genieAcs->suggestOnuNameForSerial($sn);
            if ($genieacsPayload !== null) {
                $suggestedName = $genieacsPayload['suggested_name'];
                $nameSource = $genieacsPayload['name_source'];
            }
        }

        return [
            'found' => $suggestedName !== null,
            'sn' => $sn,
            'suggested_name' => $suggestedName,
            'name_source' => $nameSource,
            'customer' => $customerPayload,
            'genieacs' => $genieacsPayload,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withCustomerOnuNameSuggestion(array $payload, string $sn): array
    {
        $suggestion = $this->resolveOnuNameSuggestion($sn);
        if ($suggestion['found']) {
            $payload['suggested_name'] = $suggestion['suggested_name'];
            $payload['name_source'] = $suggestion['name_source'];
        }
        if ($suggestion['customer'] !== null) {
            $payload['customer'] = $suggestion['customer'];
        }
        if ($suggestion['genieacs'] !== null) {
            $payload['genieacs'] = $suggestion['genieacs'];
        }

        return $payload;
    }

    /**
     * Setelah aktivasi/migrasi FiberHome: tag GenieACS = PPPoE (RT/RW/ODP) bila ada, else nama form.
     *
     * @return array{pushed: bool, pending?: bool, queued?: bool, device_id?: string, tag?: string, message?: string, attempts?: int}
     */
    private function pushGenieacsCustomerTag(string $sn, string $name): array
    {
        // Always soft — never throw, never put raw decrypt/HTTP errors in UI.
        try {
            $genie = app(GenieAcsService::class);
            $customer = null;
            try {
                $customer = $this->findCustomerByOnuSerial($sn);
            } catch (\Throwable) {
                $customer = null;
            }
            $tag = $genie->resolvePreferredTag($name, $customer);
            $result = $genie->ensureTagAfterProvision($sn, $tag);
            // Sanitize any leak of internal exceptions into the provision response
            if (! ($result['pushed'] ?? false)) {
                $result['pending'] = true;
                $msg = (string) ($result['message'] ?? '');
                if ($msg === '' || stripos($msg, 'payload is invalid') !== false
                    || stripos($msg, 'DecryptException') !== false
                    || stripos($msg, 'cURL') !== false
                    || stripos($msg, 'Connection') !== false) {
                    $result['message'] = 'Tag ACS dijadwalkan otomatis (device mungkin belum inform)';
                }
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('FiberHome GenieACS tag soft-fail', [
                'sn' => $sn,
                'error' => SensitiveDataRedactor::redact($e->getMessage()),
            ]);

            try {
                ProcessGenieAcsInformJob::dispatch([
                    'serial' => $sn,
                    'tag' => $name,
                    'event' => 'post_provision_error',
                ])->delay(now()->addSeconds(5));
            } catch (\Throwable) {
            }

            return [
                'pushed' => false,
                'pending' => true,
                'queued' => true,
                'tag' => $name,
                'message' => 'Tag ACS dijadwalkan otomatis',
            ];
        }
    }

    /**
     * Tulis ONU ke inventory eBilling (olt_onus + snapshot) segera setelah provision OLT.
     * Contoh: 1/2/1:3 + SN + nama — UI OLT RA langsung lihat tanpa nunggu inventory-sync.
     *
     * @param  array{status?: ?string, rx_power?: ?float, distance?: ?int}  $verification
     * @return array{ok: bool, onu_ref?: string, cleared_old?: int, message?: string}
     */
    private function upsertOltRaInventory(
        Olt $olt,
        string $port,
        int $onuId,
        string $sn,
        string $name,
        array $verification = [],
    ): array {
        try {
            if (! Schema::hasTable('olt_onus')) {
                return ['ok' => false, 'message' => 'olt_onus belum ada'];
            }

            $rx = $verification['rx_power'] ?? null;
            $rxFloat = is_numeric($rx) ? (float) $rx : null;
            $distance = isset($verification['distance']) && is_numeric($verification['distance'])
                ? (int) $verification['distance']
                : null;

            return app(OltCollectorService::class)->upsertProvisionedOnu(
                $olt,
                $port,
                $onuId,
                $sn,
                $name,
                isset($verification['status']) ? (string) $verification['status'] : null,
                $rxFloat,
                $distance,
            );
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @route POST /api/olt-c300/detect
     *
     * Finds the PON port for a given Serial Number.
     * 1. Connects SSH + disables paging
     * 2. Runs "show gpon onu uncfg" to find ONUs waiting to be provisioned
     * 3. Scans output for matching SN
     * 4. Returns port, next available ONU ID, and VLAN
     */
    public function detect(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $sn = strtoupper(trim($request->sn));
        $vlanMap = self::vlanMap((int) $olt->id);

        try {
            $ssh = $this->sshWithRetry($olt);

            // Step 1: Get unconfigured ONUs
            $uncfgOutput = $this->exec($ssh, 'show gpon onu uncfg', 30);
            Log::info('OLT detect: uncfg output', ['sn' => $sn, 'output_len' => strlen($uncfgOutput)]);

            // Step 2: Collect ALL ports matching our SN (don't break on first)
            $ports = [];
            $lines = explode("\n", $uncfgOutput);
            foreach ($lines as $line) {
                if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $portMatch)) {
                    $lineUpper = strtoupper($line);
                    if (strpos($lineUpper, $sn) !== false) {
                        $p = $portMatch[1];
                        $ports[$p] = ['port' => $p, 'onu_id' => (int) $portMatch[2], 'already_registered' => false];
                        Log::info('OLT detect: found SN in uncfg', ['port' => $p, 'sn' => $sn]);
                    }
                }
            }

            // Collect ALL uncfg ONUs (for fallback display)
            $allUncfg = [];
            foreach ($lines as $line) {
                if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $portMatch)) {
                    // Also extract SN from the line if present
                    $lineSn = '';
                    if (preg_match('/([A-Z]{4}[A-Z0-9]{8})/i', $line, $snMatch)) {
                        $lineSn = strtoupper($snMatch[1]);
                    }
                    $allUncfg[] = ['port' => $portMatch[1], 'id' => (int) $portMatch[2], 'sn' => $lineSn];
                }
            }

            // If SN not matched yet, try broader SN matching (ANSI artifacts, whitespace)
            if (empty($ports) && ! empty($allUncfg)) {
                $snClean = preg_replace('/[^A-Z0-9]/', '', $sn);
                foreach ($allUncfg as $u) {
                    if (! empty($u['sn'])) {
                        $uSnClean = preg_replace('/[^A-Z0-9]/', '', $u['sn']);
                        // Match: exact, or SN contains user input, or user input contains SN
                        if ($uSnClean === $snClean || strpos($uSnClean, $snClean) !== false || strpos($snClean, $uSnClean) !== false) {
                            $p = $u['port'];
                            $ports[$p] = ['port' => $p, 'onu_id' => $u['id'], 'already_registered' => false];
                            Log::info('OLT detect: matched SN via broad match', ['port' => $p, 'sn' => $sn, 'matched_sn' => $u['sn']]);
                        }
                    }
                }
            }

            // Fallback: if only one uncfg ONU (any SN), assume it is ours
            if (empty($ports) && count($allUncfg) === 1) {
                $p = $allUncfg[0]['port'];
                $ports[$p] = ['port' => $p, 'onu_id' => $allUncfg[0]['id'], 'already_registered' => false];
                Log::info('OLT detect: single uncfg ONU, assuming match', ['port' => $p, 'sn' => $sn]);
            }

            // Step 2b: If not in uncfg, check DB snapshot for already-registered ONU
            if (empty($ports)) {
                Log::info('OLT detect: SN not in uncfg, checking DB snapshot', ['sn' => $sn]);
                $snapshot = $olt->last_gpon_snapshot;
                if (is_string($snapshot)) {
                    $snapshot = json_decode($snapshot, true);
                }
                $onus = $snapshot['onus'] ?? [];
                foreach ($onus as $onu) {
                    $snField = strtoupper($onu['serial_number'] ?? '');
                    if ($snField === $sn) {
                        $ref = $onu['onu_ref'] ?? '';
                        if (preg_match('/^(\d+\/\d+\/\d+):(\d+)$/', $ref, $m)) {
                            $p = $m[1];
                            $ports[$p] = ['port' => $p, 'onu_id' => (int) $m[2], 'already_registered' => true];
                            Log::info('OLT detect: found SN in snapshot', ['port' => $p, 'onu_id' => $m[2]]);
                        }
                    }
                }
            }

            // Step 2c: Still nothing - scan per-port baseinfo from OLT
            if (empty($ports)) {
                Log::info('OLT detect: SN not in snapshot, scanning per-port baseinfo', ['sn' => $sn]);
                $ponPorts = [];
                if (! empty($snapshot['pon_ports'])) {
                    foreach ($snapshot['pon_ports'] as $pp) {
                        $pname = $pp['name'] ?? $pp['port'] ?? '';
                        if ($pname) {
                            $ponPorts[] = $pname;
                        }
                    }
                }
                if (empty($ponPorts)) {
                    for ($s = 1; $s <= 2; $s++) {
                        for ($c = 1; $c <= 16; $c++) {
                            $ponPorts[] = "1/{$s}/{$c}";
                        }
                    }
                }
                foreach ($ponPorts as $pp) {
                    $bio = $this->exec($ssh, "show gpon onu baseinfo gpon-olt_{$pp}", 10);
                    foreach (explode("\n", $bio) as $line) {
                        if (stripos(strtoupper($line), $sn) !== false) {
                            if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $m)) {
                                $p = $m[1];
                                $ports[$p] = ['port' => $p, 'onu_id' => (int) $m[2], 'already_registered' => true];
                                Log::info('OLT detect: found SN in baseinfo', ['port' => $p, 'onu_id' => $m[2]]);
                            }
                        }
                    }
                }
            }

            $ssh->disconnect();

            if (empty($ports)) {
                // Build list of uncfg ONUs for user reference
                $uncfgList = [];
                foreach ($allUncfg as $u) {
                    $uncfgList[] = [
                        'port' => $u['port'],
                        'onu_id' => $u['id'],
                        'sn' => $u['sn'] ?: '(tidak terbaca)',
                    ];
                }
                $msg = 'SN tidak terdeteksi di OLT. ';
                if (count($allUncfg) > 0) {
                    $msg .= 'ONU uncfg yang ada: '.collect($allUncfg)->map(fn ($u) => $u['port'].':'.$u['id'].($u['sn'] ? " ({$u['sn']})" : ''))->implode(', ');
                } else {
                    $msg .= 'Tidak ada ONU uncfg. Pastikan ONU sudah terhubung fiber dan menyala.';
                }

                return response()->json([
                    'found' => false,
                    'sn' => $sn,
                    'message' => $msg,
                    'uncfg_onus' => $uncfgList,
                    'raw_output' => $uncfgOutput,
                ]);
            }

            // Normalize ports: add vlan + next_onu_id for each
            $portChoices = [];
            foreach ($ports as $p => $info) {
                $pKey = self::normalizePonPort((string) $p);
                $vlan = self::vlanForPort((int) $olt->id, $pKey);
                $choice = [
                    'port' => $p,
                    'onu_id' => $info['onu_id'],
                    'vlan' => $vlan,
                    'already_registered' => $info['already_registered'],
                ];

                // For uncfg ONUs, compute next available ONU ID
                if (! $info['already_registered']) {
                    $ssh2 = $this->sshWithRetry($olt);
                    $cfgOut = $this->exec($ssh2, "show running-config interface gpon-olt_{$p}", 15);
                    $ssh2->disconnect();
                    $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                    $lastOnuId = $usedIds ? max($usedIds) : 0;
                    $nextOnuId = $this->nextOnuIdGapFill($usedIds); // gap-fill = manual PSB
                    if ($nextOnuId === null) {
                        $choice['full'] = true;
                        $choice['message'] = "Port {$p} penuh (ONU ID terakhir: {$lastOnuId})";
                    } else {
                        $choice['onu_id'] = $nextOnuId;
                    }
                }

                $portChoices[] = $choice;
            }

            // Single port - return as before (backward compatible)
            if (count($portChoices) === 1) {
                $c = $portChoices[0];
                $resp = [
                    'found' => true,
                    'sn' => $sn,
                    'port' => $c['port'],
                    'onu_id' => $c['onu_id'],
                    'vlan' => $c['vlan'],
                    'already_registered' => $c['already_registered'],
                    'raw_output' => $uncfgOutput,
                ];
                if ($c['already_registered']) {
                    $resp['message'] = "SN {$sn} sudah terdaftar di Port {$c['port']} ONU ID {$c['onu_id']}.";
                }
                if (! empty($c['full'])) {
                    return response()->json([
                        'found' => false,
                        'sn' => $sn,
                        'port' => $c['port'],
                        'message' => $c['message'],
                    ]);
                }

                return response()->json($this->withCustomerOnuNameSuggestion($resp, $sn));
            }

            // Multiple ports - return choices for user to pick
            return response()->json($this->withCustomerOnuNameSuggestion([
                'found' => true,
                'sn' => $sn,
                'multiple_ports' => true,
                'ports' => $portChoices,
                'message' => "SN {$sn} terdeteksi di ".count($portChoices).' PON port. Pilih port yang ingin dikonfigurasi.',
            ], $sn));
        } catch (\Throwable $e) {
            Log::error('OLT detect failed', ['sn' => $sn, 'error' => SensitiveDataRedactor::redact($e->getMessage())]);

            // Distinguish SSH connection errors from other errors
            $errorMsg = $e->getMessage();
            $errorType = 'unknown';
            $userHint = 'Koneksi ke OLT gagal. Cek kredensial dan jaringan.';

            if (stripos($errorMsg, 'login failed') !== false || stripos($errorMsg, 'SSH login failed') !== false) {
                $errorType = 'ssh_auth_failed';
                $userHint = 'SSH login ke OLT gagal — username atau password salah. Update kredensial OLT terlebih dahulu.';
            } elseif (stripos($errorMsg, 'Connection refused') !== false || stripos($errorMsg, 'timed out') !== false) {
                $errorType = 'ssh_network_error';
                $userHint = 'OLT tidak bisa dijangkau dari server. Cek koneksi jaringan dan port SSH.';
            } elseif (stripos($errorMsg, 'no compatible') !== false || stripos($errorMsg, 'algorithm') !== false) {
                $errorType = 'ssh_compat';
                $userHint = 'Versi SSH OLT tidak kompatibel dengan server.';
            }

            return response()->json([
                'found' => false,
                'sn' => $sn,
                'error' => $errorMsg,
                'error_type' => $errorType,
                'user_hint' => $userHint,
            ], 500);
        }
    }

    /**
     * SSH connection test - verify OLT connectivity
     *
     * @route POST /api/olt-c300/test-connection
     */
    public function testConnection(Request $request)
    {
        $request->validate(['olt_id' => 'required|integer|exists:olts,id']);
        $olt = Olt::findOrFail($request->olt_id);

        $result = [
            'olt' => $olt->name,
            'host' => $olt->management_ip,
            'port' => $olt->management_port ?: 22,
            'username' => $olt->username,
            'tcp_reachable' => false,
            'ssh_login' => false,
            'uncfg_available' => false,
            'uncfg_count' => 0,
            'errors' => [],
        ];

        // Step 1: TCP connectivity check
        $fp = @fsockopen($olt->management_ip, $olt->management_port ?: 22, $errno, $errstr, 5);
        if (! $fp) {
            $result['errors'][] = "TCP connection failed to {$olt->management_ip}:".($olt->management_port ?: 22)." (error {$errno}: {$errstr})";

            return response()->json($result);
        }
        fclose($fp);
        $result['tcp_reachable'] = true;

        // Step 2: SSH login
        try {
            $ssh = $this->sshWithRetry($olt);
            $result['ssh_login'] = true;

            // Step 3: Try running show gpon onu uncfg
            try {
                $uncfgOutput = $this->exec($ssh, 'show gpon onu uncfg', 30);
                $result['uncfg_available'] = true;

                // Count uncfg ONUs
                $count = 0;
                foreach (explode("\n", $uncfgOutput) as $line) {
                    if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line)) {
                        $count++;
                    }
                }
                $result['uncfg_count'] = $count;
                $result['uncfg_output'] = $uncfgOutput;
            } catch (\Throwable $e) {
                $result['errors'][] = 'Command failed: '.$e->getMessage();
            }

            $ssh->disconnect();
        } catch (\Throwable $e) {
            $result['errors'][] = 'SSH login failed: '.$e->getMessage();
            $result['ssh_error'] = $e->getMessage();

            // Provide actionable hint
            $msg = $e->getMessage();
            if (stripos($msg, 'login failed') !== false) {
                $result['hint'] = 'Username atau password OLT salah. Update password OLT di halaman pengaturan.';
                $result['error_type'] = 'auth_failed';
            } elseif (stripos($msg, 'Connection refused') !== false || stripos($msg, 'Connection timed out') !== false) {
                $result['hint'] = 'OLT tidak bisa dijangkau dari server. Cek koneksi jaringan dan port SSH.';
                $result['error_type'] = 'network_error';
            } elseif (stripos($msg, 'no compatible') !== false || stripos($msg, 'algorithm') !== false) {
                $result['hint'] = 'Versi SSH OLT tidak kompatibel. Hubungi admin.';
                $result['error_type'] = 'ssh_compat';
            } else {
                $result['hint'] = 'Koneksi SSH ke OLT gagal. Cek kredensial dan jaringan.';
                $result['error_type'] = 'unknown';
            }
        }

        return response()->json($result);
    }

    /**
     * Update OLT credentials (SSH username/password) from the OLT C300 page.
     *
     * @route PUT /api/olt-c300/update-credentials
     */
    public function updateCredentials(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'username' => 'nullable|string|max:64',
            'password' => 'nullable|string|max:255',
        ]);

        $olt = Olt::findOrFail($request->olt_id);

        if ($request->filled('username')) {
            $olt->username = $request->username;
        }
        if ($request->filled('password')) {
            $olt->password = $request->password;
        }

        $olt->save();

        Log::info('OLT credentials updated', ['olt_id' => $olt->id, 'olt_name' => $olt->name, 'username' => $olt->username]);

        return response()->json([
            'success' => true,
            'olt' => $olt->name,
            'username' => $olt->username,
            'password_updated' => $request->filled('password'),
        ]);
    }

    /**
     * LIST UNCFG - show all unconfigured ONUs on OLT
     *
     * @route POST /api/olt-c300/list-uncfg
     */
    public function listUncfg(Request $request)
    {
        $request->validate(['olt_id' => 'required|integer|exists:olts,id']);
        $olt = Olt::findOrFail($request->olt_id);

        try {
            $ssh = $this->sshWithRetry($olt);
            $uncfgOutput = $this->exec($ssh, 'show gpon onu uncfg', 30);

            $onus = [];
            $portToSn = [];   // port => [onu_id => sn]
            $lines = explode("\n", $uncfgOutput);
            foreach ($lines as $line) {
                if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $portMatch)) {
                    $lineSn = '';
                    if (preg_match('/([A-Z]{4}[A-Z0-9]{8})/i', $line, $snMatch)) {
                        $lineSn = strtoupper($snMatch[1]);
                    }
                    $p = $portMatch[1];
                    $oid = (int) $portMatch[2];
                    $portToSn[$p][$oid] = $lineSn ?: '(tidak terbaca)';
                    $onus[] = [
                        'port' => $p,
                        'onu_id' => $oid,
                        'sn' => $lineSn ?: '(tidak terbaca)',
                    ];
                }
            }

            // Per-port running-config: compute last_onu_id + next_onu_id (gap-fill) for context
            $portStats = [];
            foreach (array_keys($portToSn) as $p) {
                $cfgOut = $this->exec($ssh, "show running-config interface gpon-olt_{$p}", 15);
                $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                $lastId = $usedIds ? max($usedIds) : 0;
                $portStats[$p] = [
                    'port' => $p,
                    'configured_count' => count($usedIds),
                    'last_onu_id' => $lastId,
                    'next_onu_id' => $this->nextOnuIdGapFill($usedIds), // gap-fill = manual
                ];
            }
            $ssh->disconnect();

            return response()->json([
                'olt' => $olt->name,
                'count' => count($onus),
                'onus' => $onus,
                'port_stats' => array_values($portStats),
                'raw_output' => $uncfgOutput,
            ]);
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $errorType = 'unknown';
            $userHint = 'Koneksi ke OLT gagal.';

            if (stripos($errorMsg, 'login failed') !== false || stripos($errorMsg, 'SSH login failed') !== false) {
                $errorType = 'ssh_auth_failed';
                $userHint = 'SSH login gagal — username atau password OLT salah.';
            } elseif (stripos($errorMsg, 'Connection refused') !== false || stripos($errorMsg, 'timed out') !== false) {
                $errorType = 'ssh_network_error';
                $userHint = 'OLT tidak bisa dijangkau. Cek jaringan.';
            }

            return response()->json([
                'error' => $errorMsg,
                'error_type' => $errorType,
                'user_hint' => $userHint,
            ], 500);
        }
    }

    /**
     * CHECK - show configured ONUs on a specific PON port
     *
     * @route POST /api/olt-c300/check
     */
    public function check(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'port' => 'required|string|max:32',
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $port = trim($request->port);
        $vlanMap = self::vlanMap((int) $olt->id);

        try {
            $ssh = $this->sshWithRetry($olt);

            $runningCfg = $this->exec($ssh, "show running-config interface gpon-olt_{$port}");

            $configured = [];
            if (preg_match_all("/onu\s+(\d+)\s+type\s+(\S+)\s+sn\s+(\S+)(?:\s+name\s+(\S+))?/xi", $runningCfg, $m)) {
                foreach ($m[0] as $i => $raw) {
                    $onuId = (int) $m[1][$i];
                    $configured[$onuId] = [
                        'onu_id' => $onuId,
                        'sn' => trim($m[3][$i]),
                        'vendor' => trim($m[2][$i]),
                        'name' => isset($m[4][$i]) && trim($m[4][$i]) ? trim($m[4][$i]) : null,
                        'status' => 'unknown',
                        'rx_power' => null,
                        'distance' => null,
                    ];
                }
            }

            foreach ($configured as $onuId => &$onu) {
                try {
                    $detail = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$onuId}", 15);
                    if ($detail) {
                        if (preg_match('/operational|working|online|active/i', $detail)) {
                            $onu['status'] = 'online';
                        } elseif (preg_match('/dying.gasp|fault/i', $detail)) {
                            $onu['status'] = 'dying-gasp';
                        } elseif (preg_match("/offline|los\b|losing/i", $detail)) {
                            $onu['status'] = 'offline';
                        }
                        $onu['rx_power'] = $this->parsePowerValue($detail, 'rx');
                        if (preg_match("/distance[\s:-]*(\d+)\s*m/i", $detail, $pm)) {
                            $onu['distance'] = (int) $pm[1];
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("OLT check: detail-info failed for onu {$onuId}", ['error' => SensitiveDataRedactor::redact($e->getMessage())]);
                }
            }
            unset($onu);

            $uncfgRaw = $this->exec($ssh, 'show gpon onu uncfg', 30);
            $uncfgOnus = [];
            $portPattern = preg_quote($port, '/');
            if (preg_match_all("~gpon-onu_{$portPattern}:(\d+)~i", $uncfgRaw, $m)) {
                $uncfgOnus = array_map('intval', array_unique($m[1]));
            }

            // Compute last_onu_id (display) and next_onu_id via gap-fill (smallest unused 1..128).
            // When IDs are contiguous (no gaps) this naturally yields max+1.
            $usedIds = array_map('intval', array_keys($configured));
            $lastOnuId = $usedIds ? max($usedIds) : 0;
            $nextOnuId = $this->nextOnuIdGapFill($usedIds); // gap-fill = manual

            return response()->json([
                'port' => $port,
                'vlan' => self::vlanForPort((int) $olt->id, (string) $port),
                'vlan_mapping_ok' => self::vlanForPort((int) $olt->id, (string) $port) !== null,
                'total_configured' => count($configured),
                'onu' => array_values($configured),
                'uncfg_onu_ids' => $uncfgOnus,
                'last_onu_id' => $lastOnuId,
                'next_onu_id' => $nextOnuId,
            ]);
        } catch (\Throwable $e) {
            Log::error('OLT check failed', ['port' => $port, 'error' => SensitiveDataRedactor::redact($e->getMessage())]);

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @route POST /api/olt-c300/provision
     *
     * Optimized: 3 logical groups with strict error detection, proper rollback, and logging.
     * wan-ip 2 mode tr069 is treated as non-fatal (vlan-profile 69 may be missing).
     */
    /**
     * ACTIVATE - Combined detect + provision in ONE SSH connection.
     * Saves ~7 seconds by not opening a second SSH connection.
     *
     * @route POST /api/olt-c300/activate
     */
    public function activate(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
            'name' => 'required|string|max:64',
            'password' => 'required|string|max:255',
            'port' => 'nullable|string|max:32',  // optional: user-chosen port from multi-port detect
            'onu_type' => 'nullable|string|max:64',  // ZTE, ZTE-F660, ALL, Fiberhome, etc
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $sn = strtoupper(trim($request->sn));
        $name = strtoupper(str_replace(' ', '_', trim($request->name)));
        $password = $request->password;
        $userPort = $request->port;  // user-chosen port from multi-port detect
        $onuType = strtoupper(trim($request->onu_type ?? 'ALL')); // ZTE C300 (RA+Krian) terbukti pakai 'ALL' untuk ONT FiberHome; default lama ZTE-F660 -> ONU tipe salah -> tidak pernah online
        $vlanMap = self::vlanMap((int) $olt->id);
        $tr069Vlan = self::tr069Vlan((int) $olt->id, $olt);

        if ($blocked = $this->provisioningGate($olt, $request, 'activate')) {
            return $blocked;
        }

        // Port chosen by the user: the VLAN must come from the mapping, checked before any SSH.
        $vlan = null;
        if ($userPort) {
            $vlan = self::vlanForPort((int) $olt->id, (string) $userPort);
            if ($vlan === null) {
                return response()->json([
                    'success' => false,
                    'error' => self::missingVlanMappingError((int) $olt->id, (string) $userPort),
                    'log' => [],
                    'phase' => 'vlan_map',
                    'port' => self::normalizePonPort((string) $userPort),
                ], 422);
            }
        }

        $log = [];
        $onuRegistered = false;
        $ssh = null;
        $totalStart = microtime(true);

        try {
            // === PHASE 1: Connect + Detect ===
            $ssh = $this->sshWithRetry($olt);
            $log[] = ['cmd' => '_connect', 'output' => 'SSH connected'];
            $log[] = ['cmd' => '_tr069_vlan', 'output' => "TR-069 VLAN {$tr069Vlan}"];

            // If user already chose a port (from multi-port detect), skip full detect
            if ($userPort) {
                $port = self::normalizePonPort((string) $userPort);
                $onuId = null;
                $alreadyRegistered = false;
                $log[] = ['cmd' => '_note', 'output' => "User selected port {$port}"];

                // Check if SN is already registered on this port
                $cfgOut = $this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15);
                if (preg_match_all('/onu\s+(\d+)\s+type\s+\S+\s+sn\s+'.preg_quote($sn, '/').'/i', $cfgOut, $m)) {
                    $onuId = (int) $m[1][0];
                    $alreadyRegistered = true;
                    $log[] = ['cmd' => '_detect', 'output' => "SN already registered at Port {$port} ONU ID {$onuId}"];
                }

                // Find next ONU ID if not already registered (gap-fill: smallest unused 1..128)
                if (! $alreadyRegistered) {
                    $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                    $lastOnuId = $usedIds ? max($usedIds) : 0;
                    $onuId = $this->nextOnuIdGapFill($usedIds);
                    if ($onuId === null) {
                        return response()->json([
                            'success' => false,
                            'error' => "Port {$port} penuh (ONU ID terakhir: {$lastOnuId})",
                            'log' => $log, 'phase' => 'detect',
                        ], 400);
                    }
                }
            } else {
                // Step 1: Check unconfigured ONUs first
                $uncfgOutput = $this->exec($ssh, 'show gpon onu uncfg', 30);
                $log[] = ['cmd' => 'show gpon onu uncfg', 'output' => substr($uncfgOutput, 0, 500)];

                $port = null;
                $onuId = null;
                $alreadyRegistered = false;

                // Try to find SN in uncfg list
                $lines = explode("\n", $uncfgOutput);
                foreach ($lines as $line) {
                    if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $portMatch)) {
                        if (stripos(strtoupper($line), $sn) !== false) {
                            $port = $portMatch[1];
                            break;
                        }
                    }
                }

                // Fallback: single uncfg ONU
                if (! $port) {
                    $allUncfg = [];
                    foreach ($lines as $line) {
                        if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $portMatch)) {
                            $allUncfg[] = $portMatch[1];
                        }
                    }
                    if (count($allUncfg) === 1) {
                        $port = $allUncfg[0];
                    }
                }

                // Step 2: If not in uncfg, check DB snapshot for already-registered ONU
                if (! $port) {
                    $log[] = ['cmd' => '_note', 'output' => 'SN not in uncfg, checking DB snapshot...'];
                    $snapshot = $olt->last_gpon_snapshot;
                    if (is_string($snapshot)) {
                        $snapshot = json_decode($snapshot, true);
                    }
                    $onus = $snapshot['onus'] ?? [];
                    foreach ($onus as $onu) {
                        $snField = strtoupper($onu['serial_number'] ?? '');
                        if ($snField === $sn) {
                            $ref = $onu['onu_ref'] ?? '';
                            if (preg_match('/^(\d+\/\d+\/\d+):(\d+)$/', $ref, $m)) {
                                $port = $m[1];
                                $onuId = (int) $m[2];
                                $alreadyRegistered = true;
                                $log[] = ['cmd' => '_snapshot', 'output' => "Found: Port {$port}, ONU ID {$onuId}"];
                                break;
                            }
                        }
                    }

                    // If still not found, scan per-port baseinfo from OLT
                    if (! $port) {
                        $log[] = ['cmd' => '_note', 'output' => 'Scanning per-port baseinfo...'];
                        $ponPorts = [];
                        if (! empty($snapshot['pon_ports'])) {
                            foreach ($snapshot['pon_ports'] as $pp) {
                                $pname = $pp['name'] ?? $pp['port'] ?? '';
                                if ($pname) {
                                    $ponPorts[] = $pname;
                                }
                            }
                        }
                        if (empty($ponPorts)) {
                            for ($s = 1; $s <= 2; $s++) {
                                for ($c = 1; $c <= 16; $c++) {
                                    $ponPorts[] = "1/{$s}/{$c}";
                                }
                            }
                        }
                        foreach ($ponPorts as $pp) {
                            $bio = $this->exec($ssh, "show gpon onu baseinfo gpon-olt_{$pp}", 10);
                            foreach (explode("\n", $bio) as $line) {
                                if (stripos(strtoupper($line), $sn) !== false) {
                                    if (preg_match('/gpon-onu_(\d+\/\d+\/\d+):(\d+)/i', $line, $m)) {
                                        $port = $m[1];
                                        $onuId = (int) $m[2];
                                        $alreadyRegistered = true;
                                        $log[] = ['cmd' => "baseinfo {$pp}", 'output' => "Found: Port {$port}, ONU ID {$onuId}"];
                                        break 2;
                                    }
                                }
                            }
                        }
                    }
                }

                if (! $port) {
                    // No ONU found at all — SN not connected to OLT
                    return response()->json([
                        'success' => false,
                        'error' => "SN {$sn} tidak ditemukan di OLT. Pastikan ONU sudah terhubung fiber dan menyala.",
                        'log' => $log,
                        'phase' => 'detect',
                    ], 404);
                }

                if ($alreadyRegistered && $onuId) {
                    // ONU already registered — just re-provision config, keep old ONU
                    $vlan = self::vlanForPort((int) $olt->id, (string) $port);
                    if ($vlan === null) {
                        return response()->json([
                            'success' => false,
                            'error' => self::missingVlanMappingError((int) $olt->id, (string) $port),
                            'log' => $log,
                            'phase' => 'vlan_map',
                            'port' => self::normalizePonPort((string) $port),
                        ], 422);
                    }
                    $log[] = ['cmd' => '_detect', 'output' => "SN already registered at Port {$port} ONU ID {$onuId} — re-configuring (VLAN {$vlan})..."];
                    // Skip delete & re-register, go directly to provision phase below
                }

                // Find next ONU ID for uncfg ONU (gap-fill; skip if already re-provisioned above)
                if (! $alreadyRegistered) {
                    $cfgOut = $this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15);
                    $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                    $lastOnuId = $usedIds ? max($usedIds) : 0;
                    $onuId = $this->nextOnuIdGapFill($usedIds);

                    if ($onuId === null) {
                        return response()->json([
                            'success' => false,
                            'error' => "Port {$port} penuh (ONU ID terakhir: {$lastOnuId})",
                            'log' => $log,
                            'phase' => 'detect',
                        ], 400);
                    }

                    $vlan = self::vlanForPort((int) $olt->id, (string) $port);
                    if ($vlan === null) {
                        return response()->json([
                            'success' => false,
                            'error' => self::missingVlanMappingError((int) $olt->id, (string) $port),
                            'log' => $log,
                            'phase' => 'vlan_map',
                            'port' => self::normalizePonPort((string) $port),
                        ], 422);
                    }
                    $log[] = ['cmd' => '_detect', 'output' => "Port: {$port}, ONU ID: {$onuId}, VLAN: {$vlan}"];
                }

            } // end else (full detect)

            // === PHASE 2: Provision (same SSH connection!) ===
            $provStart = microtime(true);

            // -- Register ONU (skip if already re-registered above) --
            if (! $alreadyRegistered) {
                $this->execFast($ssh, 'configure terminal');
                $this->execFast($ssh, "interface gpon-olt_{$port}");

                $out = $this->execFast($ssh, "onu {$onuId} type {$onuType} sn {$sn}");
                $onuRegistered = true;
                $cleanOut = $this->stripAnsi($out);
                $log[] = ['cmd' => "onu {$onuId} type {$onuType} sn {$sn}", 'output' => substr($cleanOut, 0, 200)];
                if ($this->isZteError($cleanOut)) {
                    $this->rollback($ssh, $port, $onuId);

                    return response()->json([
                        'success' => false,
                        'error' => "Registrasi ONU gagal — ONU ID {$onuId} mungkin sudah dipakai",
                        'log' => $log, 'rollback' => true, 'phase' => 'provision',
                    ], 500);
                }

                $this->execFast($ssh, 'exit');
            } else {
                $onuRegistered = true;
            }

            // -- Configure gpon-onu interface --
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-onu_{$port}:{$onuId}");

            // Clear old config if already-registered — check before removing
            if ($alreadyRegistered) {
                $log[] = ['cmd' => '_note', 'output' => 'Clearing old config...'];
                // Read current config to see what service-ports/tcont/gemport exist
                $curCfg = $this->exec($ssh, "show running-config interface gpon-onu_{$port}:{$onuId}", 15);
                // Only remove service-ports that exist
                if (preg_match('/service-port\s+1\b/', $curCfg)) {
                    $this->execFast($ssh, 'no service-port 1');
                    $log[] = ['cmd' => 'no service-port 1', 'output' => 'removed'];
                }
                if (preg_match('/service-port\s+2\b/', $curCfg)) {
                    $this->execFast($ssh, 'no service-port 2');
                    $log[] = ['cmd' => 'no service-port 2', 'output' => 'removed'];
                }
                if (preg_match('/gemport\s+1\b/', $curCfg)) {
                    $this->execFast($ssh, 'no gemport 1');
                    $log[] = ['cmd' => 'no gemport 1', 'output' => 'removed'];
                }
                if (preg_match('/tcont\s+1\b/', $curCfg)) {
                    $this->execFast($ssh, 'no tcont 1');
                    $log[] = ['cmd' => 'no tcont 1', 'output' => 'removed'];
                }
            }

            $out = $this->execFast($ssh, "name {$name}");
            $log[] = ['cmd' => "name {$name}", 'output' => substr($this->stripAnsi($out), 0, 200)];

            $this->execFast($ssh, 'tcont 1 profile 1G');
            $this->execFast($ssh, 'gemport 1 tcont 1');

            $out = $this->execFast($ssh, "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "service-port 1 vlan {$vlan}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "service-port 1 gagal (vlan {$vlan})",
                    'log' => $log, 'rollback' => true, 'phase' => 'provision',
                ], 500);
            }

            $out = $this->execFast($ssh, "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}");
            $log[] = ['cmd' => "service-port 2 vlan {$tr069Vlan}", 'output' => $this->isZteError($this->stripAnsi($out)) ? 'non-fatal' : 'OK'];

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            // -- ONU Management --
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "pon-onu-mng gpon-onu_{$port}:{$onuId}");

            // Clear old wan-ip if already-registered — check before removing
            if ($alreadyRegistered) {
                $curMng = $this->exec($ssh, "show running-config pon-onu-mng gpon-onu_{$port}:{$onuId}", 15);
                if (preg_match('/wan-ip\s+1\b/', $curMng)) {
                    $this->execFast($ssh, 'no wan-ip 1');
                }
                if (preg_match('/wan-ip\s+2\b/', $curMng)) {
                    $this->execFast($ssh, 'no wan-ip 2');
                }
                if (preg_match('/service\s+1\b/', $curMng)) {
                    $this->execFast($ssh, 'no service 1');
                }
                if (preg_match('/service\s+2\b/', $curMng)) {
                    $this->execFast($ssh, 'no service 2');
                }
            }

            // Verify (do not log a blind "OK"): the OLT rejects wrong syntax.
            $svc1Out = $this->stripAnsi($this->execFast($ssh, "service 1 gemport 1 vlan {$vlan}"));
            $log[] = ['cmd' => "service 1 vlan {$vlan}", 'output' => $this->isZteError($svc1Out) ? substr($svc1Out, 0, 180) : 'OK'];

            $svc2Out = $this->stripAnsi($this->execFast($ssh, "service 2 gemport 1 vlan {$tr069Vlan}"));
            $log[] = ['cmd' => "service 2 vlan {$tr069Vlan}", 'output' => $this->isZteError($svc2Out) ? substr($svc2Out, 0, 180) : 'OK'];

            $out = $this->execFast($ssh, "wan-ip 1 mode pppoe username {$name} password {$password} vlan-profile PPPoE{$vlan} host 1");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "wan-ip pppoe {$name}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "PPPoE config gagal — vlan-profile PPPoE{$vlan} belum ada",
                    'log' => $log, 'rollback' => true, 'phase' => 'provision',
                ], 500);
            }

            $out = $this->execFast($ssh, "wan-ip 2 mode tr069 vlan-profile {$tr069Vlan} host 2");
            if ($this->isZteError($this->stripAnsi($out))) {
                $log[] = ['cmd' => 'wan-ip 2 tr069', 'note' => "non-fatal — vlan-profile {$tr069Vlan} belum ada"];
            }

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            $provElapsed = round((microtime(true) - $provStart) * 1000);
            $log[] = ['cmd' => '_stats', 'output' => "Config: {$provElapsed}ms"];

            // -- Quick verify --
            $vi = ['status' => null, 'rx_power' => null, 'tx_power' => null, 'distance' => null, 'configured' => true];
            try {
                $detail = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$onuId}", 15);
                if (preg_match('/operational|working|online|active/i', $detail)) {
                    $vi['status'] = 'online';
                } elseif (preg_match('/dying.gasp|fault/i', $detail)) {
                    $vi['status'] = 'dying-gasp';
                } elseif (preg_match('/offline|los\b|losing/i', $detail)) {
                    $vi['status'] = 'offline';
                }
                $vi['rx_power'] = $this->parsePowerValue($detail, 'rx');
                $vi['tx_power'] = $this->parsePowerValue($detail, 'tx');
                if (preg_match('/distance[\s:-]*(\d+)\s*m/i', $detail, $m)) {
                    $vi['distance'] = (int) $m[1];
                }
            } catch (\Throwable) {
            }

            $totalElapsed = round((microtime(true) - $totalStart) * 1000);

            // Save running-config to NVRAM so changes persist after reboot
            try {
                $saveOut = $this->saveConfig($ssh);
                $log[] = ['cmd' => 'write', 'output' => substr($this->stripAnsi($saveOut), 0, 200)];
            } catch (\Throwable $e) {
                $log[] = ['cmd' => 'write', 'note' => 'save config failed: '.$e->getMessage()];
            }

            $oltInventory = $this->upsertOltRaInventory($olt, (string) $port, (int) $onuId, $sn, $name, $vi);
            $log[] = [
                'cmd' => '_olt_inventory',
                'output' => ($oltInventory['ok'] ?? false)
                    ? 'OLT RA inventory: '.($oltInventory['onu_ref'] ?? "{$port}:{$onuId}")
                        .(! empty($oltInventory['cleared_old']) ? " (cleared {$oltInventory['cleared_old']} old SN)" : '')
                    : ($oltInventory['message'] ?? 'inventory upsert skipped'),
            ];

            $genieacsTag = $this->pushGenieacsCustomerTag($sn, $name);
            $log[] = [
                'cmd' => '_genieacs_tag',
                'output' => ($genieacsTag['pushed'] ?? false)
                    ? 'Tag '.($genieacsTag['tag'] ?? $name).' ditambahkan ke GenieACS'
                    : ($genieacsTag['message'] ?? 'Tag GenieACS belum terkirim'),
            ];

            return response()->json(array_merge([
                'success' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'name' => $name,
                'vlan' => $vlan,
                'log' => $log,
                'verification' => $vi,
                'elapsed_ms' => $totalElapsed,
                'olt_inventory' => $oltInventory,
                'genieacs_tag' => $genieacsTag,
            ], $this->manualProvisionMigrationLink($olt, (string) $port, (int) $onuId, $sn, $name, $vlan ?? null)));
        } catch (\Throwable $e) {
            if ($onuRegistered && $ssh) {
                try {
                    $this->rollback($ssh, $port ?? '', $onuId ?? 0);
                } catch (\Throwable) {
                }
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $log,
                'rollback' => $onuRegistered,
            ], 500);
        }
    }

    public function provision(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
            'port' => 'required|string',
            'onu_id' => 'required|integer',
            'name' => 'required|string|max:64',
            'password' => 'required|string|max:255',
            'onu_type' => 'nullable|string|max:64',  // ZTE, ZTE-F660, ALL, Fiberhome, etc
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $sn = strtoupper(trim($request->sn));
        $port = $request->port;
        $onuId = (int) $request->onu_id;
        $name = strtoupper(str_replace(' ', '_', trim($request->name)));
        $password = $request->password;
        $onuType = strtoupper(trim($request->onu_type ?? 'ALL')); // ZTE C300 (RA+Krian) terbukti pakai 'ALL' untuk ONT FiberHome; default lama ZTE-F660 -> ONU tipe salah -> tidak pernah online
        $userPort = $request->port;  // user-chosen port from multi-port detect

        $port = self::normalizePonPort((string) $port);
        $vlan = self::vlanForPort((int) $olt->id, $port);
        if ($vlan === null) {
            return response()->json([
                'success' => false,
                'error' => self::missingVlanMappingError((int) $olt->id, $port),
                'phase' => 'vlan_map',
                'port' => $port,
            ], 422);
        }
        $tr069Vlan = self::tr069Vlan((int) $olt->id, $olt);

        if ($blocked = $this->provisioningGate($olt, $request, 'provision')) {
            return $blocked;
        }

        $log = [];
        $onuRegistered = false;
        $ssh = null;
        $provStart = microtime(true);

        try {
            $ssh = $this->sshWithRetry($olt);
            $log[] = ['cmd' => '_connect', 'output' => 'SSH connected'];
            $log[] = ['cmd' => '_tr069_vlan', 'output' => "TR-069 VLAN {$tr069Vlan}"];

            // === OPTIMIZED: All config commands via execFast() ===
            // execFast() is 2-3x faster than exec() for simple config commands

            // -- Step 1: Register ONU on gpon-olt interface --
            $out = $this->execFast($ssh, 'configure terminal');
            $out = $this->execFast($ssh, "interface gpon-olt_{$port}");

            $out = $this->execFast($ssh, "onu {$onuId} type {$onuType} sn {$sn}");
            $onuRegistered = true;
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "onu {$onuId} type {$onuType} sn {$sn}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "ONU registration failed (type={$onuType}): {$sn} — mungkin ONU ID {$onuId} sudah dipakai",
                    'log' => $log,
                    'rollback' => true,
                ], 500);
            }

            $this->execFast($ssh, 'exit');

            // -- Step 2: Configure gpon-onu interface --
            $this->execFast($ssh, "interface gpon-onu_{$port}:{$onuId}");

            $out = $this->execFast($ssh, "name {$name}");
            $log[] = ['cmd' => "name {$name}", 'output' => substr($this->stripAnsi($out), 0, 200)];

            $out = $this->execFast($ssh, 'tcont 1 profile 1G');
            $log[] = ['cmd' => 'tcont 1 profile 1G', 'output' => 'OK'];

            $out = $this->execFast($ssh, 'gemport 1 tcont 1');
            $log[] = ['cmd' => 'gemport 1 tcont 1', 'output' => 'OK'];

            $out = $this->execFast($ssh, "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "service-port 1 vlan {$vlan}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "service-port 1 gagal (vlan {$vlan}) — cek konfigurasi VLAN",
                    'log' => $log,
                    'rollback' => true,
                ], 500);
            }

            $out = $this->execFast($ssh, "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}");
            // service-port 2 (TR-069) may fail — non-fatal
            $log[] = ['cmd' => "service-port 2 vlan {$tr069Vlan}", 'output' => $this->isZteError($this->stripAnsi($out)) ? 'non-fatal' : 'OK'];

            $this->execFast($ssh, 'exit'); // exit gpon-onu
            $this->execFast($ssh, 'exit'); // exit config

            // -- Step 3: ONU Management (pon-onu-mng) --
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "pon-onu-mng gpon-onu_{$port}:{$onuId}");

            $out = $this->execFast($ssh, "service 1 gemport 1 vlan {$vlan}");
            $log[] = ['cmd' => "service 1 vlan {$vlan}", 'output' => 'OK'];

            $out = $this->execFast($ssh, "service 2 gemport 1 vlan {$tr069Vlan}");
            $log[] = ['cmd' => "service 2 vlan {$tr069Vlan}", 'output' => 'OK'];

            $out = $this->execFast($ssh, "wan-ip 1 mode pppoe username {$name} password {$password} vlan-profile PPPoE{$vlan} host 1");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "wan-ip pppoe {$name}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "wan-ip pppoe gagal — vlan-profile PPPoE{$vlan} mungkin belum dibuat di OLT",
                    'log' => $log,
                    'rollback' => true,
                ], 500);
            }

            $out = $this->execFast($ssh, "wan-ip 2 mode tr069 vlan-profile {$tr069Vlan} host 2");
            if ($this->isZteError($this->stripAnsi($out))) {
                $log[] = ['cmd' => 'wan-ip 2 tr069', 'note' => "non-fatal — vlan-profile {$tr069Vlan} belum ada"];
            } else {
                $log[] = ['cmd' => 'wan-ip 2 tr069', 'output' => 'OK'];
            }

            $this->execFast($ssh, 'exit'); // exit pon-onu-mng
            $this->execFast($ssh, 'exit'); // exit config

            $provElapsed = round((microtime(true) - $provStart) * 1000);
            $log[] = ['cmd' => '_stats', 'output' => "Config selesai dalam {$provElapsed}ms"];

            // -- Step 4: Quick Verify via show gpon onu state (faster than detail-info) --
            $verify = $this->exec($ssh, 'show gpon onu state', 15);
            $log[] = ['cmd' => 'show gpon onu state', 'output' => substr($verify, 0, 200)];

            $vi = [
                'raw' => null,
                'status' => null,
                'rx_power' => null,
                'tx_power' => null,
                'distance' => null,
                'configured' => true,
                'elapsed_ms' => $provElapsed,
            ];

            // Quick state check from show gpon onu state
            $ref = "gpon-onu_{$port}:{$onuId}";
            if (stripos($verify, $ref) !== false) {
                // Find the line with our ONU ref
                foreach (explode("\n", $verify) as $line) {
                    if (stripos($line, $ref) !== false) {
                        if (preg_match('/online|working|operational/i', $line)) {
                            $vi['status'] = 'online';
                        } elseif (preg_match('/dying.gasp|fault/i', $line)) {
                            $vi['status'] = 'dying-gasp';
                        } elseif (preg_match('/offline|los\b|losing/i', $line)) {
                            $vi['status'] = 'offline';
                        }
                        break;
                    }
                }
            }

            // Get detail-info for rx_power + distance (more complete but slower)
            try {
                $detail = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$onuId}", 15);
                $log[] = ['cmd' => "detail-info {$port}:{$onuId}", 'output' => substr($detail, 0, 200)];
                $vi['raw'] = $detail;

                $vi['rx_power'] = $this->parsePowerValue($detail, 'rx');
                $vi['tx_power'] = $this->parsePowerValue($detail, 'tx');
                if (preg_match('/distance[\s:-]*(\d+)\s*m/i', $detail, $m)) {
                    $vi['distance'] = (int) $m[1];
                }

                // Override status if detail-info has more info
                if (! $vi['status']) {
                    if (preg_match('/operational|working|online|active/i', $detail)) {
                        $vi['status'] = 'online';
                    } elseif (preg_match('/dying.gasp|fault/i', $detail)) {
                        $vi['status'] = 'dying-gasp';
                    } elseif (preg_match('/offline|los\b|losing/i', $detail)) {
                        $vi['status'] = 'offline';
                    }
                }
            } catch (\Throwable $e) {
                $log[] = ['cmd' => 'detail-info', 'note' => 'skip — non-critical'];
            }

            // Save running-config to NVRAM so changes persist after reboot
            try {
                $saveOut = $this->saveConfig($ssh);
                $log[] = ['cmd' => 'write', 'output' => substr($this->stripAnsi($saveOut), 0, 200)];
            } catch (\Throwable $e) {
                $log[] = ['cmd' => 'write', 'note' => 'save config failed: '.$e->getMessage()];
            }

            $oltInventory = $this->upsertOltRaInventory($olt, (string) $port, (int) $onuId, $sn, $name, $vi);
            $log[] = [
                'cmd' => '_olt_inventory',
                'output' => ($oltInventory['ok'] ?? false)
                    ? 'OLT RA inventory: '.($oltInventory['onu_ref'] ?? "{$port}:{$onuId}")
                        .(! empty($oltInventory['cleared_old']) ? " (cleared {$oltInventory['cleared_old']} old SN)" : '')
                    : ($oltInventory['message'] ?? 'inventory upsert skipped'),
            ];

            $genieacsTag = $this->pushGenieacsCustomerTag($sn, $name);
            $log[] = [
                'cmd' => '_genieacs_tag',
                'output' => ($genieacsTag['pushed'] ?? false)
                    ? 'Tag '.($genieacsTag['tag'] ?? $name).' ditambahkan ke GenieACS'
                    : ($genieacsTag['message'] ?? 'Tag GenieACS belum terkirim'),
            ];

            return response()->json(array_merge([
                'success' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'name' => $name,
                'vlan' => $vlan,
                'password_masked' => $password ? str_repeat('*', strlen($password) - 2).substr($password, -2) : null,
                'log' => $log,
                'verification' => $vi,
                'elapsed_ms' => $provElapsed,
                'olt_inventory' => $oltInventory,
                'genieacs_tag' => $genieacsTag,
            ], $this->manualProvisionMigrationLink($olt, (string) $port, (int) $onuId, $sn, $name, $vlan)));
        } catch (\Throwable $e) {
            if ($onuRegistered && $ssh) {
                try {
                    $this->rollback($ssh, $port, $onuId);
                } catch (\Throwable) {
                }
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'last_step' => count($log),
                'log' => $log,
                'rollback' => $onuRegistered,
            ], 500);
        }
    }

    /**
     * REPROVISION - Delete existing ONU then detect + re-register + provision.
     * Used when ONU is stuck or needs full reset. Verifies after deletion that
     * the SN reappears in `show gpon onu uncfg` before re-registering.
     *
     * @route POST /api/olt-c300/reprovision
     */
    public function reprovision(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'port' => 'required|string|max:32',
            'onu_id' => 'required|integer|min:1|max:128',
            'sn' => 'required|string|max:64',
            'name' => 'required|string|max:64',
            'password' => 'required|string|max:255',
            'onu_type' => 'nullable|string|max:64',
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $port = trim($request->port);
        $onuId = (int) $request->onu_id;
        $sn = strtoupper(trim($request->sn));
        $name = strtoupper(str_replace(' ', '_', trim($request->name)));
        $password = $request->password;
        $onuType = strtoupper(trim($request->onu_type ?? 'ALL')); // ZTE C300 (RA+Krian) terbukti pakai 'ALL' untuk ONT FiberHome; default lama ZTE-F660 -> ONU tipe salah -> tidak pernah online
        $port = self::normalizePonPort((string) $port);
        $vlan = self::vlanForPort((int) $olt->id, $port);
        if ($vlan === null) {
            return response()->json([
                'success' => false,
                'error' => self::missingVlanMappingError((int) $olt->id, $port),
                'phase' => 'vlan_map',
                'port' => $port,
            ], 422);
        }
        $tr069Vlan = self::tr069Vlan((int) $olt->id, $olt);

        if ($blocked = $this->provisioningGate($olt, $request, 'reprovision')) {
            return $blocked;
        }

        $log = [];
        $ssh = null;
        $start = microtime(true);

        try {
            $ssh = $this->sshWithRetry($olt);
            $log[] = ['cmd' => '_connect', 'output' => 'SSH connected'];
            $log[] = ['cmd' => '_tr069_vlan', 'output' => "TR-069 VLAN {$tr069Vlan}"];
            $log[] = ['cmd' => '_vlan_map', 'output' => "PON {$port} → VLAN {$vlan} (dari mapping DB, tanpa fallback)"];

            // Phase 1: delete
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 1: Deleting ONU...'];
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-olt_{$port}");
            $out = $this->execFast($ssh, "no onu {$onuId}");
            $log[] = ['cmd' => "no onu {$onuId}", 'output' => substr($this->stripAnsi($out), 0, 200)];
            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');
            $this->saveConfig($ssh);
            $log[] = ['cmd' => 'write', 'output' => 'config saved'];

            // Phase 2: verify SN reappears in uncfg (retry up to ~10s)
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 2: Verifying SN in uncfg...'];
            $found = false;
            $foundOnuId = $onuId; // default back to original ONU id
            for ($attempt = 1; $attempt <= 6; $attempt++) {
                usleep(1500000 * $attempt); // 1.5s, 3s, 4.5s, 6s, 7.5s, 9s
                $uncfg = $this->exec($ssh, 'show gpon onu uncfg', 30);
                if (preg_match('/gpon-onu_'.preg_quote($port, '/').':('.$onuId.')/i', $uncfg)) {
                    if (stripos(strtoupper($uncfg), $sn) !== false) {
                        $found = true;
                        $foundOnuId = $onuId;
                        break;
                    }
                }
                $log[] = ['cmd' => "_poll {$attempt}/6", 'output' => 'waiting for uncfg...'];
            }

            if (! $found) {
                // broader broad-match inside the uncfg dump
                $uncfgFinal = $this->exec($ssh, 'show gpon onu uncfg', 30);
                $snClean = preg_replace('/[^A-Z0-9]/', '', $sn);
                foreach (explode("\n", $uncfgFinal) as $line) {
                    if (preg_match('/gpon-onu_('.preg_quote($port, '/').'):(\d+)/i', $line, $m)) {
                        $lineSn = '';
                        if (preg_match('/([A-Z]{4}[A-Z0-9]{8})/i', $line, $s)) {
                            $lineSn = strtoupper(preg_replace('/[^A-Z0-9]/', '', $s[1]));
                        }
                        if ($lineSn === $snClean) {
                            $found = true;
                            $foundOnuId = (int) $m[2];
                            break;
                        }
                    }
                }
            }

            if (! $found) {
                $ssh->disconnect();

                return response()->json([
                    'success' => false,
                    'error' => "SN {$sn} tidak muncul di uncfg setelah delete — ONU belum released oleh OLT. Tunggu 30 detik lalu coba lagi.",
                    'log' => $log,
                ], 400);
            }
            $log[] = ['cmd' => '_note', 'output' => "SN {$sn} confirmed in uncfg (ONU ID: {$foundOnuId})"];

            // Phase 3: register
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 3: Re-registering...'];
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-olt_{$port}");
            $out = $this->execFast($ssh, "onu {$foundOnuId} type {$onuType} sn {$sn}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "onu {$foundOnuId} type {$onuType} sn {$sn}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $foundOnuId);

                return response()->json([
                    'success' => false,
                    'error' => "Re-register failed: {$sn} (type={$onuType})",
                    'log' => $log,
                    'rollback' => true,
                ], 500);
            }
            $this->execFast($ssh, 'exit');

            // Phase 4: provision (same as activate)
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 4: Provisioning...'];
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-onu_{$port}:{$foundOnuId}");
            $this->execFast($ssh, "name {$name}");
            $this->execFast($ssh, 'tcont 1 profile 1G');
            $this->execFast($ssh, 'gemport 1 tcont 1');
            $out = $this->execFast($ssh, "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}");
            if ($this->isZteError($this->stripAnsi($out))) {
                $this->rollback($ssh, $port, $foundOnuId);

                return response()->json(['success' => false, 'error' => 'service-port 1 gagal', 'log' => $log, 'rollback' => true], 500);
            }
            $this->execFast($ssh, "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}");
            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "pon-onu-mng gpon-onu_{$port}:{$foundOnuId}");
            $this->execFast($ssh, "service 1 gemport 1 vlan {$vlan}");
            $this->execFast($ssh, "service 2 gemport 1 vlan {$tr069Vlan}");
            $out = $this->execFast($ssh, "wan-ip 1 mode pppoe username {$name} password {$password} vlan-profile PPPoE{$vlan} host 1");
            if ($this->isZteError($this->stripAnsi($out))) {
                $this->rollback($ssh, $port, $foundOnuId);

                return response()->json(['success' => false, 'error' => 'PPPoE config gagal', 'log' => $log, 'rollback' => true], 500);
            }
            $this->execFast($ssh, "wan-ip 2 mode tr069 vlan-profile {$tr069Vlan} host 2");
            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            $elapsed = round((microtime(true) - $start) * 1000);
            $this->saveConfig($ssh);
            $log[] = ['cmd' => '_stats', 'output' => "Total: {$elapsed}ms"];

            // Verify
            $vi = ['status' => null, 'rx_power' => null, 'tx_power' => null, 'distance' => null, 'configured' => true];
            try {
                $detail = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$foundOnuId}", 15);
                if (preg_match('/operational|working|online|active/i', $detail)) {
                    $vi['status'] = 'online';
                } elseif (preg_match('/dying.gasp|fault/i', $detail)) {
                    $vi['status'] = 'dying-gasp';
                } elseif (preg_match('/offline|los\b|losing/i', $detail)) {
                    $vi['status'] = 'offline';
                }
                $vi['rx_power'] = $this->parsePowerValue($detail, 'rx');
                $vi['tx_power'] = $this->parsePowerValue($detail, 'tx');
                if (preg_match('/distance[\s:.-]*(\d+)\s*m/i', $detail, $m)) {
                    $vi['distance'] = (int) $m[1];
                }
            } catch (\Throwable) {
            }

            $ssh->disconnect();

            return response()->json([
                'success' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $foundOnuId,
                'name' => $name,
                'vlan' => $vlan,
                'log' => $log,
                'verification' => $vi,
                'elapsed_ms' => $elapsed,
            ]);
        } catch (\Throwable $e) {
            if ($ssh) {
                try {
                    $this->rollback($ssh, $port ?? '', $onuId ?? 0);
                } catch (\Throwable) {
                }
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $log,
            ], 500);
        }
    }

    /**
     * RECONFIGURE - Delete existing ONU and re-register with new SN + provision.
     * Used when swapping ONU hardware on the same PON port/slot.
     *
     * @route POST /api/olt-c300/reconfigure
     */
    public function reconfigure(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'port' => 'required|string|max:32',
            'onu_id' => 'required|integer|min:1|max:128',
            'sn' => 'required|string|max:64',
            'name' => 'required|string|max:64',
            'password' => 'required|string|max:255',
            'onu_type' => 'nullable|string|max:64',  // ZTE-F660, ZTE, ALL, Fiberhome, etc
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $port = self::normalizePonPort(trim($request->port));
        $onuId = (int) $request->onu_id;
        $sn = strtoupper(trim($request->sn));
        $name = strtoupper(str_replace(' ', '_', trim($request->name)));
        $password = $request->password;
        $onuType = strtoupper(trim($request->onu_type ?? 'ALL')); // ZTE C300 (RA+Krian) terbukti pakai 'ALL' untuk ONT FiberHome; default lama ZTE-F660 -> ONU tipe salah -> tidak pernah online
        $vlan = self::vlanForPort((int) $olt->id, $port);
        $tr069Vlan = self::tr069Vlan((int) $olt->id, $olt);

        if ($vlan === null) {
            return response()->json([
                'success' => false,
                'error' => self::missingVlanMappingError((int) $olt->id, $port),
                'phase' => 'vlan_map',
                'port' => $port,
                'log' => [],
            ], 422);
        }

        if ($blocked = $this->provisioningGate($olt, $request, 'reconfigure')) {
            return $blocked;
        }

        $log = [];
        $ssh = null;
        $totalStart = microtime(true);

        try {
            // === PHASE 1: Connect ===
            $ssh = $this->sshWithRetry($olt);
            $log[] = ['cmd' => '_connect', 'output' => 'SSH connected'];

            // === PHASE 2: Delete existing ONU ===
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 1: Deleting existing ONU...'];

            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-olt_{$port}");

            $out = $this->execFast($ssh, "no onu {$onuId}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "no onu {$onuId}", 'output' => substr($cleanOut, 0, 200)];

            if ($this->isZteError($cleanOut)) {
                $log[] = ['cmd' => '_warn', 'output' => 'Delete ONU returned error (may not exist) — continuing'];
            }

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            // Save config after deletion
            $this->saveConfig($ssh);

            // Wait for OLT to process deletion
            usleep(1000000); // 1 second
            $log[] = ['cmd' => '_wait', 'output' => 'Waited 1s after deletion'];

            // === PHASE 3: Re-register ONU with new SN ===
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 2: Re-registering ONU with new SN...'];

            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-olt_{$port}");

            $out = $this->execFast($ssh, "onu {$onuId} type {$onuType} sn {$sn}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "onu {$onuId} type {$onuType} sn {$sn}", 'output' => substr($cleanOut, 0, 200)];

            if ($this->isZteError($cleanOut)) {
                // Rollback: try to remove the failed ONU
                $this->execFast($ssh, "no onu {$onuId}");
                $this->execFast($ssh, 'exit');
                $this->execFast($ssh, 'exit');

                return response()->json([
                    'success' => false,
                    'error' => "Registrasi ONU gagal: {$sn}",
                    'log' => $log,
                    'rollback' => true,
                    'phase' => 'register',
                ], 500);
            }

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            // === PHASE 4: Provision (same as activate phase 2) ===
            $log[] = ['cmd' => '_phase', 'output' => 'Phase 3: Provisioning ONU...'];

            // -- Configure gpon-onu interface --
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "interface gpon-onu_{$port}:{$onuId}");

            $out = $this->execFast($ssh, "name {$name}");
            $log[] = ['cmd' => "name {$name}", 'output' => substr($this->stripAnsi($out), 0, 200)];

            $this->execFast($ssh, 'tcont 1 profile 1G');
            $this->execFast($ssh, 'gemport 1 tcont 1');

            $out = $this->execFast($ssh, "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "service-port 1 vlan {$vlan}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "service-port 1 gagal (vlan {$vlan})",
                    'log' => $log,
                    'rollback' => true,
                    'phase' => 'provision',
                ], 500);
            }

            $out = $this->execFast($ssh, "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}");
            $log[] = ['cmd' => "service-port 2 vlan {$tr069Vlan}", 'output' => $this->isZteError($this->stripAnsi($out)) ? 'non-fatal' : 'OK'];

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            // -- ONU Management --
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "pon-onu-mng gpon-onu_{$port}:{$onuId}");

            $this->execFast($ssh, "service 1 gemport 1 vlan {$vlan}");
            $log[] = ['cmd' => "service 1 vlan {$vlan}", 'output' => 'OK'];

            $this->execFast($ssh, "service 2 gemport 1 vlan {$tr069Vlan}");
            $log[] = ['cmd' => "service 2 vlan {$tr069Vlan}", 'output' => 'OK'];

            $out = $this->execFast($ssh, "wan-ip 1 mode pppoe username {$name} password {$password} vlan-profile PPPoE{$vlan} host 1");
            $cleanOut = $this->stripAnsi($out);
            $log[] = ['cmd' => "wan-ip pppoe {$name}", 'output' => substr($cleanOut, 0, 200)];
            if ($this->isZteError($cleanOut)) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "PPPoE config gagal — vlan-profile PPPoE{$vlan} belum ada",
                    'log' => $log,
                    'rollback' => true,
                    'phase' => 'provision',
                ], 500);
            }

            $out = $this->execFast($ssh, "wan-ip 2 mode tr069 vlan-profile {$tr069Vlan} host 2");
            if ($this->isZteError($this->stripAnsi($out))) {
                $log[] = ['cmd' => 'wan-ip 2 tr069', 'note' => "non-fatal — vlan-profile {$tr069Vlan} belum ada"];
            }

            $this->execFast($ssh, 'exit');
            $this->execFast($ssh, 'exit');

            // === PHASE 5: Verify ===
            $vi = ['status' => null, 'rx_power' => null, 'tx_power' => null, 'distance' => null, 'configured' => true];
            try {
                $detail = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$onuId}", 15);
                if (preg_match('/operational|working|online|active/i', $detail)) {
                    $vi['status'] = 'online';
                } elseif (preg_match('/dying.gasp|fault/i', $detail)) {
                    $vi['status'] = 'dying-gasp';
                } elseif (preg_match('/offline|los\b|losing/i', $detail)) {
                    $vi['status'] = 'offline';
                }
                $vi['rx_power'] = $this->parsePowerValue($detail, 'rx');
                $vi['tx_power'] = $this->parsePowerValue($detail, 'tx');
                if (preg_match('/distance[\s:-]*(\d+)\s*m/i', $detail, $m)) {
                    $vi['distance'] = (int) $m[1];
                }
            } catch (\Throwable) {
            }

            $totalElapsed = round((microtime(true) - $totalStart) * 1000);
            $log[] = ['cmd' => '_stats', 'output' => "Total: {$totalElapsed}ms"];

            // Save running-config to NVRAM so changes persist after reboot
            try {
                $this->saveConfig($ssh);
            } catch (\Throwable) {
            }

            $ssh->disconnect();

            return response()->json([
                'success' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'name' => $name,
                'vlan' => $vlan,
                'log' => $log,
                'verification' => $vi,
                'elapsed_ms' => $totalElapsed,
            ]);
        } catch (\Throwable $e) {
            Log::error('OLT reconfigure failed', ['port' => $port, 'onuId' => $onuId, 'error' => SensitiveDataRedactor::redact($e->getMessage())]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $log,
            ], 500);

        }
    }

    /**
     * Build FiberHome provision CLI plan (for dry-run preview / response log).
     *
     * @return list<array{label: string, cmd: string|null}>
     */
    /**
     * ONU type candidates for C300 RA + Krian.
     *
     * Policy (NOC): selalu type ALL dulu — sama manual PSB.
     * JANGAN utamakan FiberHome: profile itu sering "%Code 63904 Not support this ONU"
     * padahal ONU FHTT jalan normal dengan type ALL.
     *
     * Fallback type lain hanya jika profile ALL benar-benar tidak ada di chassis.
     *
     * @return list<string>
     */
    private function fiberhomeOnuTypeCandidates(string $sn, ?string $preferred = null): array
    {
        // Always ALL first (RA + Krian). Ignore SN-based FiberHome preference.
        $list = ['ALL'];
        $pref = strtoupper(trim((string) ($preferred ?? '')));
        if ($pref !== '' && $pref !== 'ALL') {
            // explicit override from API only — still after ALL
            $list[] = $pref;
        }
        // Emergency fallbacks only if ALL profile missing on OLT (rare)
        $list = array_merge($list, [
            'FiberHome', 'FIBERHOME', 'FHTT',
            'ZTEA', 'ZTE-F660', 'ZTE', 'F670L', 'F6600P', 'HG8310M',
        ]);

        $out = [];
        $seen = [];
        foreach ($list as $t) {
            $t = trim((string) $t);
            if ($t === '' || isset($seen[strtoupper($t)])) {
                continue;
            }
            $seen[strtoupper($t)] = true;
            $out[] = $t;
        }

        return $out;
    }

    /**
     * Register SN on gpon-olt with type fallback (ALL may be missing on new OLT).
     * Reject %Code / "Not support this ONU" — never treat as success.
     *
     * @return array{ok: bool, type: ?string, output: string, tried: list<string>}
     */
    private function registerOnuSnWithTypeFallback($ssh, int $onuId, string $sn, ?string $preferredType = null): array
    {
        $tried = [];
        $lastOut = '';
        foreach ($this->fiberhomeOnuTypeCandidates($sn, $preferredType) as $type) {
            $cmd = "onu {$onuId} type {$type} sn {$sn}";
            $out = $this->execFast($ssh, $cmd);
            $clean = $this->stripAnsi($out);
            $tried[] = $type;
            $lastOut = $clean;
            if ($this->isZteError($clean)) {
                $u = strtoupper($clean);
                // ID/SN conflict — no point trying other types with same ID
                if (preg_match('/ALREADY|EXIST|CONFLICT|OCCUPIED|IN USE|DUPLICATE/i', $clean)
                    && ! preg_match('/NOT SUPPORT|TYPE/i', $clean)) {
                    return ['ok' => false, 'type' => null, 'output' => $clean, 'tried' => $tried];
                }

                // type not supported — try next type (keep same ONU ID)
                continue;
            }

            // Soft success: no %Error/%Code. Still require not-empty prompt-ish output.
            return ['ok' => true, 'type' => $type, 'output' => $clean, 'tried' => $tried];
        }

        return ['ok' => false, 'type' => null, 'output' => $lastOut, 'tried' => $tried];
    }

    /**
     * @return list<array{label: string, cmd: string|null}>
     */
    private function buildFiberhomeProvisionPlan(
        string $port,
        int $onuId,
        string $sn,
        string $name,
        int $vlan,
        string $trafficUp,
        string $trafficDown,
        int $tr069Vlan = 69,
        string $onuType = 'ALL',
    ): array {
        return [
            ['label' => 'Connect SSH', 'cmd' => null],
            ['label' => 'Enter config mode', 'cmd' => 'configure terminal'],
            ['label' => "Register ONU on PON {$port}", 'cmd' => "interface gpon-olt_{$port}"],
            ['label' => "Bind SN to ONU ID {$onuId}", 'cmd' => "onu {$onuId} type {$onuType} sn {$sn}"],
            ['label' => 'Exit PON interface', 'cmd' => 'exit'],
            ['label' => 'Exit config (clean mode)', 'cmd' => 'exit'],
            ['label' => 'Enter config mode (sama manual)', 'cmd' => 'configure terminal'],
            ['label' => "Configure ONU {$port}:{$onuId}", 'cmd' => "interface gpon-onu_{$port}:{$onuId}"],
            ['label' => 'Set name', 'cmd' => "name {$name}"],
            ['label' => 'Set description', 'cmd' => "description {$sn}"],
            ['label' => 'Set T-CONT profile', 'cmd' => 'tcont 1 profile 1G'],
            ['label' => 'Create GEM port', 'cmd' => 'gemport 1 tcont 1'],
            ['label' => "Set traffic-limit {$trafficUp}/{$trafficDown}", 'cmd' => "gemport 1 traffic-limit upstream {$trafficUp} downstream {$trafficDown}"],
            ['label' => "Map VLAN {$vlan} (internet)", 'cmd' => "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}"],
            ['label' => "Map VLAN {$tr069Vlan} (TR-069 management)", 'cmd' => "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}"],
            ['label' => 'Exit ONU interface', 'cmd' => 'exit'],
            ['label' => 'Exit config mode', 'cmd' => 'exit'],
            ['label' => 'Configure ONT management', 'cmd' => 'configure terminal'],
            ['label' => 'Enter ONT mgmt context', 'cmd' => "pon-onu-mng gpon-onu_{$port}:{$onuId}"],
            ['label' => "Service 1 → VLAN {$vlan} (internet)", 'cmd' => "service 1 gemport 1 vlan {$vlan}"],
            ['label' => "Service 2 → VLAN {$tr069Vlan} (TR-069)", 'cmd' => "service 2 gemport 1 vlan {$tr069Vlan}"],
            ['label' => 'Set VEIP hybrid mode', 'cmd' => 'vlan port veip_1 mode hybrid'],
            ['label' => "Allow VLAN {$tr069Vlan} on VEIP", 'cmd' => "vlan port veip_1 vlan {$tr069Vlan}"],
            ['label' => 'Enable DHCP host + ping response on management', 'cmd' => 'ip-host 1 dhcp enable ping-response enable traceroute-response enable'],
            ['label' => 'Exit ONT mgmt', 'cmd' => 'exit'],
            ['label' => 'Exit config mode', 'cmd' => 'end'],
            ['label' => 'Save config to OLT', 'cmd' => 'write'],
        ];
    }

    /**
     * ONU names must follow the customer PPPoE. Warn (never block) when a
     * PSB-new name is within two characters of an existing customer PPPoE
     * token, e.g. `USERAB7` typed for `USERAB27_...`.
     */
    private function fiberhomeNameWarning(string $name, string $purpose): ?string
    {
        if (in_array($purpose, ['migration', 'migration_wa'], true)) {
            return null;
        }
        $token = strtoupper((string) preg_split('/[_\s-]+/', trim($name), 2)[0]);
        if (strlen($token) < 4) {
            return null;
        }

        try {
            if (Customer::query()->whereRaw('LOWER(pppoe_user) = ?', [strtolower($name)])->exists()) {
                return null;
            }
            $similar = Customer::query()
                ->whereRaw('UPPER(pppoe_user) LIKE ?', [str_replace(['%', '_'], ['\%', '\_'], substr($token, 0, 4)).'%'])
                ->limit(300)
                ->pluck('pppoe_user')
                ->first(function ($pppoe) use ($token): bool {
                    $candidate = strtoupper((string) preg_split('/[_\s-]+/', trim((string) $pppoe), 2)[0]);

                    return $candidate !== $token && levenshtein($candidate, $token) <= 2;
                });
        } catch (\Throwable) {
            return null;
        }

        return $similar
            ? "Nama ONU '{$name}' mirip PPPoE pelanggan '{$similar}'. Jika ini pelanggan lama/migrasi, pakai nama = PPPoE lengkap '{$similar}' dan Jenis data: Migrasi."
            : null;
    }

    /**
     * ZTE provision/activate (no PSB draft): complete an exact open migration,
     * warn on a near one. DB only, never a device write; never throws.
     *
     * @return array<string, mixed>
     */
    private function manualProvisionMigrationLink(Olt $olt, string $port, int $onuId, string $sn, string $name, ?int $vlan): array
    {
        return app(ManualProvisionMigrationLinker::class)->recordAfterProvision([
            'olt_id' => $olt->id,
            'router_id' => null,
            'sn' => $sn,
            'port' => self::normalizePonPort($port),
            'onu_id' => $onuId,
            'name' => $name,
            'vlan' => $vlan,
        ], null, true, auth()->user()?->name);
    }

    /**
     * Explicit technician confirmation of a near-match migration shown after
     * a manual provision ("Ada migrasi terbuka #ID ... Tautkan?"). DB only:
     * re-checks the match and ownership guards, then records the activation
     * on that migration (synced) and links the PSB draft.
     *
     * @route POST /api/olt-c300/link-migration
     */
    public function linkMigration(Request $request): JsonResponse
    {
        $data = $request->validate([
            'migration_id' => 'required|integer|exists:customer_migrations,id',
            'psb_draft_id' => 'nullable|integer|exists:psb_drafts,id',
            'olt_id' => 'required|integer|exists:olts,id',
            'router_id' => 'nullable|integer|exists:routers,id',
            'sn' => 'required|string|max:64',
            'port' => 'required|string|max:32',
            'onu_id' => 'required|integer|min:1|max:128',
            'name' => 'nullable|string|max:64',
        ]);

        $olt = Olt::findOrFail((int) $data['olt_id']);
        $sn = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $data['sn']) ?? '');
        $port = self::normalizePonPort((string) $data['port']);
        $onuId = (int) $data['onu_id'];
        $ref = strtolower("{$port}:{$onuId}");
        $draft = ! empty($data['psb_draft_id']) ? PsbDraft::query()->find((int) $data['psb_draft_id']) : null;

        // Evidence that this SN really was provisioned at that ref: the PSB
        // draft of the provision, or the local OLT inventory row it wrote.
        $draftMatches = $draft
            && strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $draft->sn) ?? '') === $sn
            && (! $draft->olt_id || (int) $draft->olt_id === (int) $olt->id);
        if ($draft && ! $draftMatches) {
            return response()->json(['success' => false, 'error' => "PSB draft #{$draft->id} bukan provision SN {$sn} di {$olt->name}."], 422);
        }
        $inventoryMatches = Schema::hasTable('olt_onus') && OltOnu::query()
            ->where('olt_id', $olt->id)
            ->whereRaw('LOWER(onu_ref) = ?', [$ref])
            ->whereRaw('UPPER(REPLACE(REPLACE(COALESCE(serial_number, ""), "-", ""), " ", "")) = ?', [$sn])
            ->exists();
        if (! $draftMatches && ! $inventoryMatches) {
            return response()->json(['success' => false, 'error' => "SN {$sn} belum tercatat ter-provision di {$olt->name} {$ref}."], 422);
        }

        $migration = CustomerMigration::query()->findOrFail((int) $data['migration_id']);
        $payload = [
            'olt_id' => $olt->id,
            'router_id' => $data['router_id'] ?? $draft?->router_id,
            'sn' => $sn,
            'port' => $port,
            'onu_id' => $onuId,
            'name' => (string) ($data['name'] ?? $draft?->pppoe_user ?? ''),
            'vlan' => $draft?->vlan,
            'tr069_vlan' => $draft?->tr069_vlan,
        ];
        $result = app(ManualProvisionMigrationLinker::class)->confirm($migration, $payload, $draft, auth()->user()?->name);

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'],
            'error' => $result['ok'] ? null : $result['message'],
            'migration_link' => $result['migration_link'] ?? null,
            'blocked_reasons' => $result['blocked_reasons'] ?? [],
        ], $result['status']);
    }

    /**
     * Provision a Fiberhome ONT on C300 OLT.
     *
     * Concurrent-safe:
     *  - Port lock: same PON port serializes (queue wait) so ONU ID tidak tabrak
     *  - Different ports / OLTs run in parallel
     *  - Under lock: re-read running-config + gap-fill / reassign ONU ID if taken
     *  - OLT write lock: serialize `write` across ports on same chassis
     *
     * Differences from provision() (ZTE):
     *  - Uses "type ALL" instead of "type ZTE" so OLT accepts any vendor SN
     *  - Adds gemport 1 traffic-limit upstream/downstream (ZTE handles in OMCI; Fiberhome needs explicit limit)
     *  - Uses vlan port veip_1 mode hybrid in pon-onu-mng (Fiberhome standard)
     *  - Adds TR-069 service-port 2 + service 2 + veip_1 (VLAN 69 default; Migrasi uplink → 68)
     *  - Adds ip-host 1 dhcp enable ping-response enable traceroute-response enable
     *    (`dhcp` is the OLT-accepted short form of `dhcp-enable`; the OLT
     *    normalises it to `dhcp-enable` in running-config)
     *  - No wan-ip pppoe configured at OLT level (PPPoE input manually at ONT router per operator decision)
     *  - ACS URL set via Mikrotik DHCP Option 43 (FiberHome firmware ignores OLT OMCI acs-url)
     *
     * @route POST /api/olt-c300/provision-fiberhome
     */
    public function provisionFiberhome(Request $request)
    {
        $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
            'port' => 'required|string|max:32',
            'onu_id' => 'nullable|integer|min:1|max:128',  // ignored for new SN (gap-fill); reuse only if same SN
            'name' => 'required|string|max:64',
            'paket' => 'nullable|string|max:16',  // e.g. "100M", "50M", "200M" → maps to UP100M/DW100M
            'dry_run' => 'nullable|boolean',        // if true: build command list only, no SSH exec, no write
            // Dual MikroTik path on same C300: migrasi → TR-069 68, randuagung → 69
            'mikrotik' => 'nullable|string|max:64',
            'router_id' => 'nullable|integer|exists:routers,id',
            'onu_type' => 'nullable|string|max:64', // default ALL (RA+Krian); fallback only if ALL missing
            'purpose' => 'nullable|string|in:psb_new,migration,psb_wa,migration_wa',
        ]);

        $olt = Olt::findOrFail($request->olt_id);
        $sn = strtoupper(preg_replace('/\s+/', '', trim($request->sn)) ?? trim($request->sn));
        $port = trim($request->port);
        $onuIdRequested = (int) ($request->input('onu_id') ?? 0);
        $name = strtoupper(str_replace(' ', '_', trim($request->name)));
        $paket = strtoupper(trim($request->paket ?: '100M'));
        $dryRun = (bool) $request->input('dry_run', false);
        $onuId = $onuIdRequested;
        $mikrotikKey = trim((string) $request->input('mikrotik', ''));
        $routerId = $request->filled('router_id') ? (int) $request->input('router_id') : null;
        $purpose = trim((string) $request->input('purpose', 'psb_new')) ?: 'psb_new';

        if (in_array($purpose, ['migration', 'migration_wa'], true) && ! Customer::query()
            ->whereRaw('LOWER(pppoe_user) = ?', [strtolower($name)])
            ->exists()) {
            return response()->json([
                'success' => false,
                'error_code' => 'migration_customer_not_found',
                'error' => "Migrasi wajib memakai PPPoE pelanggan existing. '{$name}' belum ditemukan; pilih PSB Baru bila ini pelanggan baru.",
            ], 422);
        }

        // Non-blocking: a PSB-new name that looks like a truncated/typo PPPoE of
        // an existing customer (USERAB7 vs USERAB27_...) is usually a migration
        // whose ONU name must follow the full PPPoE.
        $nameWarning = $this->fiberhomeNameWarning($name, $purpose);

        // RA + Krian policy: selalu ALL. FiberHome type profile sering reject ONU FHTT.
        $onuTypePreferred = strtoupper(trim((string) $request->input('onu_type', 'ALL')));
        if ($onuTypePreferred === '' || in_array($onuTypePreferred, ['FIBERHOME', 'FHTT'], true)) {
            $onuTypePreferred = 'ALL';
        }

        // Validate paket format (must be digits followed by M)
        if (! preg_match('/^(\d+)M$/', $paket, $pm)) {
            return response()->json([
                'success' => false,
                'error' => "Paket tidak valid: '{$paket}'. Contoh valid: 25M, 50M, 100M, 150M, 200M, 300M, 375M",
            ], 422);
        }
        $paketNum = (int) $pm[1];
        // STEP 2026-10-01 (owner): C300 traffic limits are FIXED per OLT, not per
        // paket — per-user speed is controlled by the MikroTik profile.
        //   OLT RA (id 1)    -> UP500M / DW500M (profile dibuat manual di OLT bila belum ada)
        //   OLT KRIAN (id 9) -> UP375M / DW375M (profile sudah ada di OLT)
        // Paket dari WA (35MB/100MB/...) tetap diproses untuk data/billing saja.
        $fixedLimit = self::c300FixedTrafficLimit((int) $olt->id);
        if ($fixedLimit !== null) {
            $trafficUp = 'UP'.$fixedLimit.'M';
            $trafficDown = 'DW'.$fixedLimit.'M';
        } else {
            $trafficUp = 'UP'.$paketNum.'M';
            $trafficDown = 'DW'.$paketNum.'M';
        }

        $port = self::normalizePonPort((string) $port);
        $vlan = self::vlanForPort((int) $olt->id, $port);
        if ($vlan === null) {
            return response()->json([
                'success' => false,
                'error' => self::missingVlanMappingError((int) $olt->id, $port),
                'phase' => 'vlan_map',
                'port' => $port,
                'olt_id' => (int) $olt->id,
            ], 422);
        }
        $tr069Vlan = self::tr069Vlan(
            (int) $olt->id,
            $olt,
            $mikrotikKey !== '' ? $mikrotikKey : null,
            $routerId,
        );

        if ($invalidCliValue = $this->validateFiberhomeCliValues(
            $sn,
            $port,
            $name,
            $onuTypePreferred,
            $trafficUp,
            $trafficDown,
            $vlan,
            $tr069Vlan,
        )) {
            return $invalidCliValue;
        }

        if (! $dryRun && ($blocked = $this->provisioningGate($olt, $request, 'provision-fiberhome'))) {
            return $blocked;
        }

        $mikrotikLabel = $mikrotikKey !== ''
            ? $mikrotikKey
            : ($routerId ? (string) (Router::query()->whereKey($routerId)->value('name') ?? 'router#'.$routerId) : 'default');

        // Dry-run: optional auto ONU ID (no lock — preview only)
        if ($dryRun) {
            if ($onuId < 1) {
                try {
                    $sshTmp = $this->sshWithRetry($olt);
                    $cfgOut = $this->exec($sshTmp, "show running-config interface gpon-olt_{$port}", 15);
                    $sshTmp->disconnect();
                } catch (\Throwable $e) {
                    return response()->json([
                        'success' => false,
                        'error' => "Gagal membaca running-config port {$port} untuk auto-compute ONU ID: ".$e->getMessage(),
                    ], 500);
                }
                $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                $lastId = $usedIds ? max($usedIds) : 0;
                $onuId = $this->nextOnuIdGapFill($usedIds) ?? 0;
                if ($onuId < 1) {
                    return response()->json([
                        'success' => false,
                        'error' => "Port {$port} penuh (ONU ID terakhir: {$lastId}, max 128). Hapus ONU lama dulu.",
                    ], 400);
                }
            }

            $plan = $this->buildFiberhomeProvisionPlan($port, $onuId, $sn, $name, $vlan, $trafficUp, $trafficDown, $tr069Vlan, $onuTypePreferred);

            return response()->json([
                'success' => true,
                'dry_run' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'name' => $name,
                'vlan' => $vlan,
                'tr069_vlan' => $tr069Vlan,
                'mikrotik' => $mikrotikLabel,
                'router_id' => $routerId,
                'purpose' => $purpose,
                'recorded_as' => in_array($purpose, ['migration', 'migration_wa'], true) ? 'migration_review' : 'psb_new',
                'onu_type' => $onuTypePreferred,
                'onu_type_fallback' => $this->fiberhomeOnuTypeCandidates($sn, $onuTypePreferred),
                'paket' => $paket,
                'traffic_up' => $trafficUp,
                'traffic_down' => $trafficDown,
                'plan' => $plan,
                'command_count' => count(array_filter($plan, fn ($s) => ! empty($s['cmd']))),
                'name_warning' => $nameWarning,
                'note' => "Dry run — MikroTik {$mikrotikLabel} → TR-069 VLAN {$tr069Vlan}. Type fallback jika ALL belum ada di OLT. Tidak ada write.",
                'concurrent_safe' => true,
            ]);
        }

        // === Live run: concurrent by SN (PON sama OK) ===
        // - SN lock: cegah 2 NOC aktivasi SN yang SAMA
        // - Claim lock (port): SANGAT pendek — hanya klaim ONU ID + register SN di OLT
        // - Setelah claim: config gpon-onu_{port}:{id} paralel (dibedakan SN + ONU ID)
        $snLockKey = 'olt-c300-fh-sn:'.$olt->id.':'.$sn;
        $snLock = Cache::lock($snLockKey, 180);

        try {
            $snLock->block(45);
        } catch (LockTimeoutException $e) {
            return response()->json([
                'success' => false,
                'error' => "SN {$sn} sedang diaktivasi teknisi lain. Tunggu selesai atau cek SN — concurrent dibedakan per SN.",
                'concurrent_busy' => true,
                'concurrent_scope' => 'sn',
                'sn' => $sn,
                'port' => $port,
            ], 429);
        }

        $log = [];
        $onuRegistered = false;
        $oltProvisionCommitted = false; // true after write — never rollback / never report total failure
        $onuIdReassigned = false;
        $ssh = null;
        $plan = [];
        $startMs = microtime(true);
        $claimLock = null;
        // Keep $onuId defined for catch/rollback even if claim fails early
        $onuId = $onuIdRequested > 0 ? $onuIdRequested : 0;

        try {
            $ssh = $this->sshWithRetry($olt);
            $log[] = [
                'cmd' => '_connect',
                'output' => "SSH connected — concurrent by SN={$sn} (PON sama boleh paralel setelah claim ID)",
            ];

            // --- Micro claim: only allocate ONU ID + register SN on gpon-olt (not full provision) ---
            $claimLockKey = 'olt-c300-fh-claim:'.$olt->id.':'.str_replace('/', '-', $port);
            $claimLock = Cache::lock($claimLockKey, 60);
            try {
                $claimLock->block(45);
            } catch (LockTimeoutException $e) {
                return response()->json([
                    'success' => false,
                    'error' => "Port {$port} sedang klaim ONU ID (sebentar). SN beda tetap aman — coba lagi ~10 detik.",
                    'concurrent_busy' => true,
                    'concurrent_scope' => 'claim',
                    'port' => $port,
                    'sn' => $sn,
                    'log' => $log,
                ], 429);
            }

            try {
                // Fresh ONU ID under claim lock.
                // SAMA manual FiberHome: gap-fill dari running-config. JANGAN percaya uncfg :1.
                $cfgOut = $this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15);
                $usedIds = $this->parseConfiguredOnuIds($cfgOut);
                $lastId = $usedIds ? max($usedIds) : 0;

                // Re-provision: SN sudah terdaftar di port ini (cari di mana pun ID-nya)
                $sameSnId = null;
                if (preg_match_all('/onu\s+(\d+)\s+type\s+\S+\s+sn\s+([A-Z0-9]+)/i', $cfgOut, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $row) {
                        if (strtoupper($row[2]) === strtoupper($sn)) {
                            $sameSnId = (int) $row[1];
                            break;
                        }
                    }
                }

                if ($sameSnId !== null) {
                    $onuId = $sameSnId;
                    $log[] = [
                        'cmd' => '_onu_id',
                        'output' => "Reuse ONU ID {$onuId} — SN {$sn} sudah terdaftar di port {$port} (re-provision)",
                    ];
                } else {
                    $onuId = $this->nextOnuIdGapFill($usedIds) ?? 0;
                    if ($onuId < 1) {
                        return response()->json([
                            'success' => false,
                            'error' => "Port {$port} penuh (ONU ID terakhir: {$lastId}, max 128). Hapus ONU lama dulu.",
                            'log' => $log,
                        ], 400);
                    }
                    if ($onuIdRequested >= 1 && $onuIdRequested !== $onuId) {
                        $onuIdReassigned = true;
                        $log[] = [
                            'cmd' => '_onu_id_reassign',
                            'output' => "Abaikan uncfg/index ONU {$onuIdRequested} → gap-fill = {$onuId} "
                                ."(sama manual; last_used={$lastId}, used=".count($usedIds).") SN {$sn}",
                        ];
                    } else {
                        $log[] = [
                            'cmd' => '_onu_id',
                            'output' => "ONU ID gap-fill → {$onuId} (sama manual; last_used={$lastId}, used=".count($usedIds).") SN {$sn}",
                        ];
                    }
                }

                $usedOnuType = $onuTypePreferred;
                $plan = $this->buildFiberhomeProvisionPlan($port, $onuId, $sn, $name, $vlan, $trafficUp, $trafficDown, $tr069Vlan, $usedOnuType);
                $log[] = ['cmd' => '_tr069_vlan', 'output' => "MikroTik {$mikrotikLabel} → TR-069 VLAN {$tr069Vlan} (OLT {$olt->name})"];
                $log[] = ['cmd' => '_onu_type_candidates', 'output' => implode(', ', $this->fiberhomeOnuTypeCandidates($sn, $onuTypePreferred))];

                // Step 1: register ONU (claim slot on OLT) — still under claim lock
                $this->execFast($ssh, 'configure terminal');
                $this->execFast($ssh, "interface gpon-olt_{$port}");

                $reg = $this->registerOnuSnWithTypeFallback($ssh, $onuId, $sn, $onuTypePreferred);
                $onuRegistered = $reg['ok'];
                $log[] = [
                    'cmd' => "onu {$onuId} type … sn {$sn}",
                    'output' => 'tried=['.implode(',', $reg['tried']).'] type='.($reg['type'] ?? 'FAIL').' '.substr($reg['output'], 0, 160),
                ];
                if (! $reg['ok']) {
                    $this->rollback($ssh, $port, $onuId);
                    $onuRegistered = false;
                    $cfgOut2 = $this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15);
                    $used2 = $this->parseConfiguredOnuIds($cfgOut2);
                    $retryId = $this->nextOnuIdGapFill($used2);
                    if ($retryId === null || $retryId === $onuId) {
                        return response()->json([
                            'success' => false,
                            'error' => "ONU registration gagal: {$sn} — type ".implode('/', $reg['tried'])." ditolak ATAU ONU ID {$onuId}/SN sudah terisi. Di OLT baru pastikan profile type ALL atau FiberHome ada (show gpon onu type).",
                            'olt' => $olt->name,
                            'tried_types' => $reg['tried'],
                            'log' => $log, 'plan' => $plan, 'rollback' => true,
                        ], 500);
                    }
                    $log[] = ['cmd' => '_onu_id_retry', 'output' => "Register gagal di {$onuId}, retry gap-fill → {$retryId} (SN {$sn})"];
                    $onuId = $retryId;
                    $onuIdReassigned = true;
                    $this->execFast($ssh, 'configure terminal');
                    $this->execFast($ssh, "interface gpon-olt_{$port}");
                    $reg = $this->registerOnuSnWithTypeFallback($ssh, $onuId, $sn, $onuTypePreferred);
                    $onuRegistered = $reg['ok'];
                    $log[] = [
                        'cmd' => "onu {$onuId} type … sn {$sn}",
                        'output' => 'retry tried=['.implode(',', $reg['tried']).'] type='.($reg['type'] ?? 'FAIL').' '.substr($reg['output'], 0, 160),
                    ];
                    if (! $reg['ok']) {
                        $this->rollback($ssh, $port, $onuId);
                        $onuRegistered = false;

                        return response()->json([
                            'success' => false,
                            'error' => "ONU registration gagal setelah retry: {$sn}. Types dicoba: ".implode(', ', $reg['tried']).'. Buat profile type di OLT (ALL/FiberHome) seperti OLT RA.',
                            'tried_types' => $reg['tried'],
                            'log' => $log, 'plan' => $plan, 'rollback' => true,
                        ], 500);
                    }
                }
                $usedOnuType = (string) ($reg['type'] ?? $onuTypePreferred);
                $plan = $this->buildFiberhomeProvisionPlan($port, $onuId, $sn, $name, $vlan, $trafficUp, $trafficDown, $tr069Vlan, $usedOnuType);

                // HARD verify: SN must appear on claimed ONU ID — else gpon-onu_X:Y Invalid parameter
                $this->leaveToEnable($ssh);
                $cfgVerify = $this->stripAnsi($this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15));
                $claimVisible = (bool) preg_match(
                    '/onu\s+'.$onuId.'\s+type\s+\S+\s+sn\s+'.preg_quote(strtoupper($sn), '/').'\b/i',
                    $cfgVerify
                );
                if (! $claimVisible) {
                    // Registration was a false positive (e.g. old bug: %Code Not support treated as OK).
                    // Retry once: re-enter gpon-olt + type fallback again on same gap ID.
                    $log[] = ['cmd' => '_claim_verify', 'output' => "FAIL SN {$sn} not on onu {$onuId} — re-register with type fallback"];
                    $this->execFast($ssh, 'configure terminal');
                    $this->execFast($ssh, "interface gpon-olt_{$port}");
                    // Clear any partial ghost ID then re-register
                    $this->execFast($ssh, "no onu {$onuId}");
                    $reg = $this->registerOnuSnWithTypeFallback($ssh, $onuId, $sn, $onuTypePreferred);
                    $onuRegistered = $reg['ok'];
                    $usedOnuType = (string) ($reg['type'] ?? $onuTypePreferred);
                    $log[] = [
                        'cmd' => "onu {$onuId} type … sn {$sn} (reclaim)",
                        'output' => 'tried=['.implode(',', $reg['tried']).'] type='.($reg['type'] ?? 'FAIL').' '.substr($reg['output'], 0, 160),
                    ];
                    $this->leaveToEnable($ssh);
                    $cfgVerify = $this->stripAnsi($this->exec($ssh, "show running-config interface gpon-olt_{$port}", 15));
                    $claimVisible = (bool) preg_match(
                        '/onu\s+'.$onuId.'\s+type\s+\S+\s+sn\s+'.preg_quote(strtoupper($sn), '/').'\b/i',
                        $cfgVerify
                    );
                }
                if (! $claimVisible) {
                    $this->rollback($ssh, $port, $onuId);
                    $onuRegistered = false;

                    return response()->json([
                        'success' => false,
                        'error' => "ONU claim gagal: SN {$sn} tidak terdaftar di gpon-olt_{$port} onu {$onuId} "
                            .'(type ditolak OLT / ID ghost). Types dicoba: '.implode(', ', $reg['tried'] ?? []).'. '
                            .'Pastikan profile type ALL ada di OLT (show gpon onu type).',
                        'error_code' => 'claim_verify_failed',
                        'tried_types' => $reg['tried'] ?? [],
                        'port' => $port,
                        'onu_id' => $onuId,
                        'log' => $log,
                        'plan' => $plan,
                        'rollback' => true,
                    ], 422);
                }
                $log[] = ['cmd' => '_claim_verify', 'output' => "OK SN {$sn} on onu {$onuId} type={$usedOnuType}"];
                $plan = $this->buildFiberhomeProvisionPlan($port, $onuId, $sn, $name, $vlan, $trafficUp, $trafficDown, $tr069Vlan, $usedOnuType);

                $log[] = [
                    'cmd' => '_claim_done',
                    'output' => "Claim OK SN={$sn} type={$usedOnuType} → {$port}:{$onuId} — next: conf t + gpon-onu (wajib config-if)",
                ];
            } finally {
                // Release ASAP so other NOCs on same PON (different SN) can claim next ID
                try {
                    $claimLock?->release();
                } catch (\Throwable) {
                }
                $claimLock = null;
            }

            // Step 2: gpon-onu — HARUS di (config-if)# (sama ONU 39 yang live sukses)
            // Stay in config-if for whole block; only re-enter if prompt lost.
            [$ifOk, $ifLog, $ifOut] = $this->enterGponOnuInterface($ssh, $port, $onuId);
            foreach ($ifLog as $row) {
                $log[] = $row;
            }
            if (! $ifOk) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "Gagal masuk interface gpon-onu_{$port}:{$onuId} (CLI belum (config-if)#). "
                        ."Cek ONU sudah terdaftar: show running-config interface gpon-olt_{$port}. "
                        .'Output: '.substr(preg_replace('/\s+/', ' ', $ifOut), 0, 180),
                    'error_code' => 'enter_gpon_onu_failed',
                    'port' => $port,
                    'onu_id' => $onuId,
                    'log' => $log,
                    'rollback' => true,
                ], 422);
            }

            $lastIf = $ifOut;

            // Mirror ONU 39 running-config: name → desc → tcont → gemport → traffic-limit 1-line → SP
            $nm = $this->stripAnsi($this->execFast($ssh, "name {$name}"));
            $log[] = ['cmd' => "name {$name}", 'output' => $this->isZteError($nm) ? substr($nm, 0, 100) : 'OK'];
            $lastIf = $nm;
            if ($this->isZteError($nm) || ! $this->isConfigIfPrompt($nm)) {
                [$okRe, $lgRe, $lastIf] = $this->enterGponOnuInterface($ssh, $port, $onuId);
                foreach ($lgRe as $row) {
                    $log[] = $row;
                }
                if ($okRe) {
                    $nm = $this->stripAnsi($this->execFast($ssh, "name {$name}"));
                    $lastIf = $nm;
                    $log[] = ['cmd' => "name {$name} (retry)", 'output' => $this->isZteError($nm) ? substr($nm, 0, 100) : 'OK'];
                }
            }

            $desc = $this->stripAnsi($this->execFast($ssh, "description {$sn}"));
            $lastIf = $desc !== '' ? $desc : $lastIf;
            if ($this->isZteError($desc)) {
                $log[] = ['cmd' => "description {$sn}", 'note' => 'non-fatal'];
            }

            $tc = $this->stripAnsi($this->execFast($ssh, 'tcont 1 profile 1G'));
            $lastIf = $tc;
            $log[] = ['cmd' => 'tcont 1 profile 1G', 'output' => $this->isZteError($tc) ? substr($tc, 0, 120) : 'OK'];
            if ($this->isZteError($tc) || ! $this->isConfigIfPrompt($tc)) {
                [$okRe, $lgRe, $lastIf] = $this->ensureGponOnuInterface($ssh, $port, $onuId, $tc);
                foreach ($lgRe as $row) {
                    $log[] = $row;
                }
                if ($okRe) {
                    $tc = $this->stripAnsi($this->execFast($ssh, 'tcont 1 profile 1G'));
                    $lastIf = $tc;
                    $log[] = ['cmd' => 'tcont 1 profile 1G (retry)', 'output' => $this->isZteError($tc) ? substr($tc, 0, 120) : 'OK'];
                }
            }

            $gp = $this->stripAnsi($this->execFast($ssh, 'gemport 1 tcont 1'));
            $lastIf = $gp;
            $log[] = ['cmd' => 'gemport 1 tcont 1', 'output' => $this->isZteError($gp) ? substr($gp, 0, 120) : 'OK'];

            // ONU 39 style: single-line traffic-limit
            $tlCmd = "gemport 1 traffic-limit upstream {$trafficUp} downstream {$trafficDown}";
            $tl = $this->stripAnsi($this->execFast($ssh, $tlCmd));
            $lastIf = $tl !== '' ? $tl : $lastIf;
            if ($this->isZteError($tl)) {
                $tlUp = $this->stripAnsi($this->execFast($ssh, "gemport 1 traffic-limit upstream {$trafficUp}"));
                $tlDown = $this->stripAnsi($this->execFast($ssh, "gemport 1 traffic-limit downstream {$trafficDown}"));
                $lastIf = $tlDown !== '' ? $tlDown : $tlUp;
                $log[] = [
                    'cmd' => $tlCmd,
                    'note' => 'combined skip → split '
                        .($this->isZteError($tlUp) ? 'up_fail ' : 'up_ok ')
                        .($this->isZteError($tlDown) ? 'down_fail' : 'down_ok'),
                ];
            } else {
                $log[] = ['cmd' => $tlCmd, 'output' => 'OK'];
            }

            // Ensure still (config-if)# before service-port — only re-enter if mode lost
            [$okSpMode, $lgSp, $lastIf] = $this->ensureGponOnuInterface($ssh, $port, $onuId, $lastIf);
            foreach ($lgSp as $row) {
                $log[] = $row;
            }
            if (! $okSpMode) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "Gagal pastikan (config-if)# sebelum service-port gpon-onu_{$port}:{$onuId}. "
                        .substr(preg_replace('/\s+/', ' ', $lastIf), 0, 160),
                    'error_code' => 'enter_gpon_onu_before_sp_failed',
                    'port' => $port,
                    'onu_id' => $onuId,
                    'log' => $log,
                    'rollback' => true,
                ], 422);
            }

            $spCmd = "service-port 1 vport 1 user-vlan {$vlan} vlan {$vlan}";
            $sp = $this->stripAnsi($this->execFast($ssh, $spCmd));
            if (! $this->isServicePortOk($sp)) {
                $log[] = ['cmd' => $spCmd, 'output' => substr($sp, 0, 180), 'note' => 'retry after re-enter'];
                [$okRe, $lgRe] = $this->enterGponOnuInterface($ssh, $port, $onuId);
                foreach ($lgRe as $row) {
                    $log[] = $row;
                }
                if ($okRe) {
                    // re-apply tcont/gemport then SP (idempotent)
                    $this->execFast($ssh, 'tcont 1 profile 1G');
                    $this->execFast($ssh, 'gemport 1 tcont 1');
                    $sp = $this->stripAnsi($this->execFast($ssh, $spCmd));
                }
            }
            if (! $this->isServicePortOk($sp)) {
                $this->rollback($ssh, $port, $onuId);
                $log[] = ['cmd' => $spCmd, 'output' => substr($sp, 0, 240), 'note' => 'ZTE reject'];
                $hint = $this->explainServicePortFailure($sp, $port, $vlan, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => "service-port gagal: gpon-onu_{$port}:{$onuId} VLAN {$vlan}. {$hint}",
                    'error_code' => 'service_port_reject',
                    'port' => $port,
                    'onu_id' => $onuId,
                    'vlan' => $vlan,
                    'olt_output' => substr($sp, 0, 400),
                    'log' => $log,
                    'plan' => $plan,
                    'rollback' => true,
                ], 422);
            }
            $spNote = preg_match('/already\s+exist/i', $sp) ? 'OK (already existed)' : 'OK';
            $log[] = ['cmd' => $spCmd, 'output' => $spNote];

            $sp2 = $this->stripAnsi($this->execFast($ssh, "service-port 2 vport 1 user-vlan {$tr069Vlan} vlan {$tr069Vlan}"));
            if (! $this->isServicePortOk($sp2) && $this->isZteError($sp2)) {
                $log[] = ['cmd' => "service-port 2 vlan {$tr069Vlan}", 'note' => 'TR-069 non-fatal'];
            } else {
                $log[] = ['cmd' => "service-port 2 vlan {$tr069Vlan}", 'output' => 'OK'];
            }

            $this->leaveToEnable($ssh);

            // Step 3: pon-onu-mng
            // Each command is verified: the OLT silently rejects wrong syntax, so
            // fire-and-forget here produced a false "success" (root cause: the
            // DHCP host keyword must be `dhcp-enable`, not `dhcp`).
            $this->execFast($ssh, 'configure terminal');
            $this->execFast($ssh, "pon-onu-mng gpon-onu_{$port}:{$onuId}");
            $step3 = [];
            foreach ([
                "service 1 gemport 1 vlan {$vlan}",
                "service 2 gemport 1 vlan {$tr069Vlan}",
                'vlan port veip_1 mode hybrid',
                "vlan port veip_1 vlan {$tr069Vlan}",
                'ip-host 1 dhcp enable ping-response enable traceroute-response enable',
            ] as $step3Cmd) {
                $step3Out = $this->stripAnsi($this->execFast($ssh, $step3Cmd));
                $step3[] = [
                    'cmd' => $step3Cmd,
                    'ok' => ! $this->isZteError($step3Out),
                    'out' => $step3Out,
                ];
                $log[] = ['cmd' => $step3Cmd, 'output' => $this->isZteError($step3Out) ? substr($step3Out, 0, 180) : 'OK'];
            }

            // service 1/2 are the internet + TR-069 mappings: a reject means the
            // client cannot pass traffic, so fail + rollback instead of claiming
            // success.
            $step3Critical = array_values(array_filter(
                $step3,
                fn (array $row): bool => ! $row['ok'] && str_starts_with($row['cmd'], 'service '),
            ));
            if ($step3Critical !== []) {
                $this->rollback($ssh, $port, $onuId);

                return response()->json([
                    'success' => false,
                    'error' => 'pon-onu-mng service gagal: '.implode('; ', array_column($step3Critical, 'cmd')),
                    'error_code' => 'pon_onu_mng_service_reject',
                    'port' => $port,
                    'onu_id' => $onuId,
                    'olt_output' => substr($step3Critical[0]['out'], 0, 300),
                    'log' => $log,
                    'plan' => $plan,
                    'rollback' => true,
                ], 422);
            }
            // veip/ip-host rejects are non-fatal for internet, but must be visible.
            $step3Warnings = array_values(array_filter($step3, fn (array $row): bool => ! $row['ok']));
            $this->leaveToEnable($ssh);

            // Step 4: write (serialize across ports on same OLT chassis)
            $writeLock = Cache::lock('olt-c300-write:'.$olt->id, 90);
            try {
                try {
                    $writeLock->block(60);
                } catch (LockTimeoutException $e) {
                    $log[] = ['cmd' => 'write', 'note' => 'write lock timeout — mencoba write tanpa antre'];
                }
                $wr = $this->stripAnsi($this->saveConfig($ssh));
                $log[] = ['cmd' => 'write', 'output' => substr($wr, 0, 200)];
            } finally {
                try {
                    $writeLock->release();
                } catch (\Throwable) {
                }
            }

            // OLT config is live + written. From here: NEVER rollback, ALWAYS success to UI
            // even if verify / customer / inventory / ACS throws (e.g. DecryptException payload invalid).
            $oltProvisionCommitted = true;

            $elapsed = round((microtime(true) - $startMs) * 1000);
            $log[] = ['cmd' => '_stats', 'output' => "Provisioning OLT OK dalam {$elapsed}ms (concurrent by SN) — post-steps non-fatal"];

            $vi = [
                'status' => null, 'rx_power' => null, 'tx_power' => null,
                'distance' => null, 'serial' => $sn, 'configured' => true,
                'elapsed_ms' => $elapsed,
            ];
            $oltInventory = ['ok' => false, 'message' => 'not run'];
            $genieacsTag = ['pushed' => false, 'message' => 'not run'];
            $customer = null;
            $postWarnings = [];

            // Step 5+: all non-fatal for frontend (OLT already OK)
            try {
                $verify = $this->exec($ssh, "show gpon onu detail-info gpon-onu_{$port}:{$onuId}", 15);
                $log[] = ['cmd' => 'detail-info verify', 'output' => substr($verify, 0, 300)];

                if (preg_match('/operational|working|online|active/i', $verify)) {
                    $vi['status'] = 'online';
                } elseif (preg_match('/dying.gasp|fault/i', $verify)) {
                    $vi['status'] = 'dying-gasp';
                } elseif (preg_match('/offline|los\b|losing/i', $verify)) {
                    $vi['status'] = 'offline';
                }

                $vi['rx_power'] = $this->parsePowerValue($verify, 'rx');
                $vi['tx_power'] = $this->parsePowerValue($verify, 'tx');
                if (preg_match('/distance[\s:-]*(\d+)\s*m/i', $verify, $m)) {
                    $vi['distance'] = (int) $m[1];
                }
            } catch (\Throwable $e) {
                $postWarnings[] = 'verify: '.$e->getMessage();
                $log[] = ['cmd' => 'detail-info verify', 'note' => 'skipped: '.$e->getMessage()];
            }

            try {
                $customer = $this->findCustomerByOnuSerial($sn);
                if ($customer) {
                    $customerFill = [
                        'olt_id' => $olt->id,
                        'olt_port_label' => "{$port}:{$onuId}",
                        'onu_serial' => $sn,
                        'olt_status' => $vi['status'] ?? $customer->olt_status,
                        'olt_last_synced_at' => now(),
                    ];
                    if (isset($vi['rx_power']) && is_numeric($vi['rx_power'])) {
                        $customerFill['onu_rx_power_dbm'] = (float) $vi['rx_power'];
                    }
                    if (isset($vi['distance']) && is_numeric($vi['distance'])) {
                        $customerFill['fiber_distance_m'] = (int) $vi['distance'];
                    }
                    $customer->forceFill($customerFill)->save();
                }
            } catch (\Throwable $e) {
                $postWarnings[] = 'customer: '.$e->getMessage();
                Log::warning('FiberHome post-provision customer save skipped', [
                    'sn' => $sn,
                    'error' => SensitiveDataRedactor::redact($e->getMessage()),
                ]);
                $log[] = ['cmd' => '_customer', 'note' => 'skipped: '.$e->getMessage()];
            }

            try {
                $oltInventory = $this->upsertOltRaInventory($olt, (string) $port, (int) $onuId, $sn, $name, $vi);
                $log[] = [
                    'cmd' => '_olt_inventory',
                    'output' => ($oltInventory['ok'] ?? false)
                        ? 'OLT RA inventory direct: '.($oltInventory['onu_ref'] ?? "{$port}:{$onuId}")
                            .' name='.($oltInventory['onu_name'] ?? $name)
                            .' rx='.(isset($oltInventory['rx_power_dbm']) ? $oltInventory['rx_power_dbm'] : ($vi['rx_power'] ?? 'n/a'))
                            .(! empty($oltInventory['pon_reconcile_queued']) ? ' (PON sibling queued)' : '')
                        : ($oltInventory['message'] ?? 'inventory upsert skipped'),
                ];
            } catch (\Throwable $e) {
                $postWarnings[] = 'inventory: '.$e->getMessage();
                $oltInventory = ['ok' => false, 'message' => $e->getMessage()];
                $log[] = ['cmd' => '_olt_inventory', 'note' => 'skipped: '.$e->getMessage()];
            }

            try {
                $genieacsTag = $this->pushGenieacsCustomerTag($sn, $name);
                $log[] = [
                    'cmd' => '_genieacs_tag',
                    'output' => ($genieacsTag['pushed'] ?? false)
                        ? 'Tag '.($genieacsTag['tag'] ?? $name).' ditambahkan ke GenieACS'
                        : ($genieacsTag['message'] ?? 'Tag GenieACS pending/queued'),
                ];
            } catch (\Throwable $e) {
                $postWarnings[] = 'genieacs: '.$e->getMessage();
                $genieacsTag = ['pushed' => false, 'pending' => true, 'message' => $e->getMessage()];
                $log[] = ['cmd' => '_genieacs_tag', 'note' => 'skipped: '.$e->getMessage()];
            }

            $elapsedTotal = round((microtime(true) - $startMs) * 1000);
            $log[] = ['cmd' => '_stats_total', 'output' => "Total HTTP path {$elapsedTotal}ms (OLT CLI {$elapsed}ms)"];
            if ($postWarnings !== []) {
                $log[] = ['cmd' => '_post_warnings', 'output' => implode(' | ', $postWarnings)];
            }

            $response = [
                'success' => true,
                'dry_run' => false,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'onu_id_requested' => $onuIdRequested > 0 ? $onuIdRequested : null,
                'onu_id_reassigned' => $onuIdReassigned,
                'concurrent_safe' => true,
                'concurrent_mode' => 'by_sn_claim',
                'name' => $name,
                'vlan' => $vlan,
                'tr069_vlan' => $tr069Vlan,
                'mikrotik' => $mikrotikLabel,
                'router_id' => $routerId,
                'purpose' => $purpose,
                'paket' => $paket,
                'traffic_up' => $trafficUp,
                'traffic_down' => $trafficDown,
                'log' => $log,
                'plan' => $plan,
                'verification' => $vi,
                'elapsed_ms' => $elapsed,
                'elapsed_total_ms' => $elapsedTotal,
                'olt_inventory' => $oltInventory,
                'genieacs_tag' => $genieacsTag,
                'post_warnings' => $postWarnings,
                'step3_warnings' => array_map(fn (array $row): string => $row['cmd'], $step3Warnings),
                'olt_committed' => true,
                'name_warning' => $nameWarning,
            ];

            // KPI sejalur: manual FiberHome → psb_drafts olt_ok (RA/Krian)
            try {
                $recordPayload = [
                    'olt_id' => $olt->id,
                    'router_id' => $routerId,
                    'sn' => $sn,
                    'port' => $port,
                    'onu_id' => $onuId,
                    'name' => $name,
                    'vlan' => $vlan,
                    'tr069_vlan' => $tr069Vlan,
                    'purpose' => $purpose,
                ];
                // Open migration of this SN/PPPoE is completed in place (#1106/#1102).
                $response = array_merge($response, app(ManualProvisionMigrationLinker::class)
                    ->recordFiberhomeProvision($recordPayload, $purpose, auth()->user()?->name));
            } catch (\Throwable $e) {
                Log::warning('[PSB KPI] fiberhome record failed', ['error' => SensitiveDataRedactor::redact($e->getMessage())]);
            }

            try {
                $customerPayload = $this->customerLookupPayload($customer);
                if ($customerPayload) {
                    $response['customer'] = $customerPayload;
                }
            } catch (\Throwable $e) {
                $response['post_warnings'][] = 'customer_payload: '.$e->getMessage();
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            // Only roll back if OLT config was NOT successfully written yet.
            if ($onuRegistered && $ssh && ! $oltProvisionCommitted) {
                try {
                    $this->rollback($ssh, $port, $onuId);
                } catch (\Throwable) {
                }
            }

            // If OLT already committed, still report success so frontend doesn't red-error a live ONU.
            if ($oltProvisionCommitted) {
                Log::warning('FiberHome post-commit exception (OLT already OK)', [
                    'sn' => $sn,
                    'port' => $port,
                    'onu_id' => $onuId,
                    'error' => SensitiveDataRedactor::redact($e->getMessage()),
                ]);
                $log[] = ['cmd' => '_post_error', 'note' => $e->getMessage()];

                return response()->json([
                    'success' => true,
                    'olt_committed' => true,
                    'sn' => $sn,
                    'port' => $port,
                    'onu_id' => $onuId,
                    'name' => $name,
                    'vlan' => $vlan,
                    'log' => $log,
                    'post_warnings' => [$e->getMessage()],
                    'warning' => 'OLT sudah ter-provision; langkah eBilling/ACS gagal: '.$e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $log,
                'plan' => $plan,
                'rollback' => $onuRegistered && ! $oltProvisionCommitted,
            ], 500);
        } finally {
            try {
                $claimLock?->release();
            } catch (\Throwable) {
            }
            try {
                $snLock->release();
            } catch (\Throwable) {
            }
            if ($ssh) {
                try {
                    $ssh->disconnect();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * Test SNMP connectivity to OLT.
     *
     * @route POST /api/olt-c300/snmp-test
     */
    public function snmpTest(Request $request)
    {
        $request->validate(['olt_id' => 'required|integer|exists:olts,id']);
        $olt = Olt::findOrFail($request->olt_id);

        $host = $olt->management_ip;
        $community = $olt->snmp_community ?: OltSnmpCommunity::fallback();

        if (! $host) {
            return response()->json([
                'reachable' => false,
                'error' => 'OLT has no management_ip configured',
            ]);
        }

        // Quick SNMP test with 3s timeout
        $script = base_path('app/Support/zte_c300_snmp_rxpower.py');
        if (! file_exists($script)) {
            return response()->json([
                'reachable' => false,
                'error' => 'SNMP collector script not found',
            ]);
        }

        try {
            $process = new Process(
                ['python3', $script],
                base_path(),
                [
                    'OLT_HOST' => $host,
                    'OLT_COMMUNITY' => $community,
                    'SNMP_TIMEOUT' => '3',
                ],
                8
            );
            $process->run();

            $output = $process->getOutput();
            $data = json_decode($output, true);

            if (! empty($data['ok'])) {
                return response()->json([
                    'reachable' => true,
                    'onu_count' => $data['count'] ?? 0,
                    'community' => $community,
                ]);
            }

            return response()->json([
                'reachable' => false,
                'error' => $data['error'] ?? 'No SNMP response (UDP port 161 may be blocked)',
                'hint' => 'Ensure UDP port 161 is open from this server to '.$host,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'reachable' => false,
                'error' => 'SNMP test failed: '.$e->getMessage(),
            ]);
        }
    }

    public function rxPowerRefresh(Request $request)
    {
        $request->validate(['olt_id' => 'required|integer|exists:olts,id']);
        $olt = Olt::findOrFail($request->olt_id);

        if (OltVendor::isEnterprise($olt) && Schema::hasTable('olt_onus')) {
            $status = (string) ($olt->sync_status ?? 'idle');
            if (in_array($status, ['queued', 'running'], true)) {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'SNMP live sync already in progress',
                    'via' => 'snmp',
                ]);
            }

            if (Schema::hasColumn('olts', 'sync_status')) {
                $olt->forceFill(['sync_status' => 'queued', 'last_sync_error' => null])->save();
            }

            RefreshOltSnapshotJob::dispatch($olt->id);

            return response()->json([
                'status' => 'processing',
                'message' => 'Pengambilan redaman, jarak, dan SN diantrikan',
                'via' => 'snmp',
                'phase' => 'snmp_walk',
            ]);
        }

        // Check if already processing
        $lockFile = storage_path('framework/rxpower_'.$olt->id.'.lock');
        $outputFile = storage_path('framework/rxpower_'.$olt->id.'.json');

        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            if ($this->isRxPowerCollectorRunning($pid)) {
                return response()->json([
                    'status' => 'processing',
                    'message' => 'RX power collection already in progress',
                ]);
            }
            // Stale lock — clean up
            @unlink($lockFile);
        }

        // Get existing snapshot to extract ONU refs
        $snap = $olt->last_gpon_snapshot;
        if (empty($snap['onus'])) {
            return response()->json([
                'success' => false,
                'error' => 'No snapshot data — collect snapshot first',
            ], 404);
        }

        $onuRefs = array_values(array_filter(array_map(function ($onu) {
            $ref = $onu['onu_ref'] ?? '';
            if ($ref === '') {
                return '';
            }

            $state = strtolower((string) ($onu['state'] ?? $onu['phase_state'] ?? ''));
            $isOnline = $state === 'online' || str_contains($state, 'working') || str_contains($state, 'up');
            $serial = strtoupper((string) ($onu['serial_number'] ?? ''));

            if ($isOnline || str_starts_with($serial, 'FHTT')) {
                return $ref;
            }

            return '';
        }, $snap['onus'] ?? [])));

        if (empty($onuRefs)) {
            return response()->json([
                'success' => false,
                'error' => 'No ONU refs found in snapshot',
            ], 404);
        }

        // Start Python script in background
        $script = base_path('app/Support/zte_c300_rxpower_fast.py');
        if (! file_exists($script)) {
            return response()->json([
                'success' => false,
                'error' => 'RX power collector script not found',
            ], 500);
        }

        // Clean up previous output
        @unlink($outputFile);
        $refsFile = storage_path('framework/rxpower_'.$olt->id.'.refs.json');
        @unlink($refsFile);

        $onuRefsJson = json_encode($onuRefs);
        file_put_contents($refsFile, $onuRefsJson);
        $host = $olt->management_ip;
        $port = $olt->management_port ?: 22;
        $username = $olt->username ?: '';
        $password = $olt->password ?: '';

        // Build the command — redirect stdout to output file, run in background
        $cmd = sprintf(
            'timeout 3600 python3 %s %s %s %s %s %s > %s 2>&1 & echo $!',
            escapeshellarg($script),
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg('@'.$refsFile),
            escapeshellarg($outputFile)
        );

        $pid = (int) trim(shell_exec($cmd) ?: '0');

        if ($pid > 0) {
            file_put_contents($lockFile, $pid);
        }

        return response()->json([
            'status' => 'processing',
            'pid' => $pid,
            'total_onus' => count($onuRefs),
            'skipped_offline' => count($snap['onus']) - count($onuRefs),
        ]);
    }

    /**
     * Check rx-power collection status and merge results when done.
     *
     * @route GET /api/olt-c300/rx-power-status
     */
    public function rxPowerStatus(Request $request)
    {
        $request->validate(['olt_id' => 'required|integer|exists:olts,id']);
        $olt = Olt::findOrFail($request->olt_id);

        if (OltVendor::isEnterprise($olt) && Schema::hasTable('olt_onus')) {
            $olt->refresh();
            $status = (string) ($olt->sync_status ?? 'idle');

            if (in_array($status, ['queued', 'running'], true)) {
                return response()->json(['status' => 'processing', 'via' => 'snmp']);
            }

            if ($status === 'failed') {
                return response()->json([
                    'status' => 'error',
                    'via' => 'snmp',
                    'error' => $olt->last_sync_error ?: 'SNMP sync failed',
                ]);
            }

            $onlineNoRx = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('state', 'online')
                ->whereNull('rx_power_dbm')
                ->count();
            $onlineWithRx = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->where('state', 'online')
                ->whereNotNull('rx_power_dbm')
                ->count();
            $lastSnmp = OltCollectRun::query()
                ->where('olt_id', $olt->id)
                ->where('tier', OltCollectRun::TIER_SNMP)
                ->orderByDesc('id')
                ->first();

            return response()->json([
                'status' => 'done',
                'via' => 'snmp',
                'phase' => 'done',
                'synced_at' => $olt->last_gpon_synced_at?->toISOString(),
                'online_with_rx' => $onlineWithRx,
                'online_no_rx' => $onlineNoRx,
                'snmp_gap_filled' => $lastSnmp?->meta['snmp_gap_filled'] ?? null,
                'snmp_mapped_null_rx' => $lastSnmp?->meta['snmp_mapped_null_rx'] ?? null,
            ]);
        }

        $lockFile = storage_path('framework/rxpower_'.$olt->id.'.lock');
        $outputFile = storage_path('framework/rxpower_'.$olt->id.'.json');
        $refsFile = storage_path('framework/rxpower_'.$olt->id.'.refs.json');

        // Check if still processing
        if (file_exists($lockFile)) {
            $pid = (int) file_get_contents($lockFile);
            if ($this->isRxPowerCollectorRunning($pid)) {
                $ageSeconds = time() - (filemtime($lockFile) ?: time());
                if ($ageSeconds > 3900) {
                    @exec('kill '.(int) $pid.' 2>/dev/null');
                    @unlink($lockFile);

                    return response()->json([
                        'status' => 'error',
                        'error' => 'Collector redaman melewati batas waktu 65 menit. Silakan refresh ulang.',
                    ]);
                }

                return response()->json(['status' => 'processing']);
            }
            // Process finished — clean lock
            @unlink($lockFile);
        }

        // Check for output file
        if (! file_exists($outputFile)) {
            return response()->json(['status' => 'idle']);
        }

        $raw = file_get_contents($outputFile);
        @unlink($outputFile); // Clean up
        @unlink($refsFile);

        if (trim((string) $raw) === '') {
            return response()->json([
                'status' => 'error',
                'error' => 'Collector output is empty',
            ]);
        }

        $result = json_decode($raw, true);

        if (empty($result) || ! isset($result['onus'])) {
            return response()->json([
                'status' => 'error',
                'error' => 'Invalid collector output',
            ]);
        }

        if (! empty($result['error'])) {
            return response()->json([
                'status' => 'error',
                'error' => $result['error'],
            ]);
        }

        // Merge rx_power into existing snapshot
        $snap = $olt->last_gpon_snapshot;
        $rxData = [];
        foreach (($result['onus'] ?? []) as $ref => $info) {
            $rxData[$this->normalizeOnuRefKey($ref)] = $info;
        }
        $updated = 0;

        if (! empty($snap['onus'])) {
            $offline = 0;
            foreach ($snap['onus'] as $i => $onu) {
                $key = $this->normalizeOnuRefKey($onu['onu_ref'] ?? '');
                if (isset($rxData[$key])) {
                    $rx = $this->extractRxPowerValue($rxData[$key]);
                    if ($rx !== null && $rx <= 0) {
                        $snap['onus'][$i]['rx_power_dbm'] = $rx;
                        $updated++;
                    }
                } else {
                    // Keep existing rx — bulk collector often skips FiberHome (FHTT) ONUs.
                    $offline++;
                }
            }
            // Sync rx_power to matched customers. Bulk-load customers first; querying
            // per ONU makes the status endpoint slow enough to hit gateway timeouts.
            $customersUpdated = 0;
            $refs = collect($snap['onus'])->pluck('onu_ref')->filter()->unique()->values();
            $onuNames = collect($snap['onus'])->pluck('onu_name')->filter()->unique()->values();
            $customers = Customer::where('olt_id', $olt->id)
                ->where(function ($q) use ($refs, $onuNames) {
                    if ($refs->isNotEmpty()) {
                        $q->whereIn('olt_port_label', $refs);
                    }
                    if ($onuNames->isNotEmpty()) {
                        $method = $refs->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $q->{$method}('pppoe_user', $onuNames);
                    }
                })
                ->get();
            $customersByRef = $customers
                ->filter(fn ($c) => $this->normalizeOnuRefKey($c->olt_port_label) !== '')
                ->keyBy(fn ($c) => $this->normalizeOnuRefKey($c->olt_port_label));
            $customersByUser = $customers->whereNotNull('pppoe_user')->keyBy('pppoe_user');

            // Restore missing FHTT rx in snapshot from DB before syncing — bulk collector often skips them.
            $snap = app(OltController::class)->prepareSnapshotRx($olt, $snap);

            foreach ($snap['onus'] as $onu) {
                $ref = $onu['onu_ref'] ?? '';
                $rxPower = $onu['rx_power_dbm'] ?? null;
                if (empty($ref)) {
                    continue;
                }

                // Filter: GPON ONU RX must be negative; positive = parsing error
                if ($rxPower !== null && $rxPower > 0) {
                    $rxPower = null;
                }

                $onuName = $onu['onu_name'] ?? '';
                $refKey = $this->normalizeOnuRefKey($ref);
                $customer = $customersByRef->get($refKey) ?: ($onuName ? $customersByUser->get($onuName) : null);

                if ($customer) {
                    $old = $customer->onu_rx_power_dbm;
                    // Never clear stored rx when collector did not return a fresh reading (common for FHTT).
                    if ($rxPower !== null && $rxPower <= 0
                        && ($old === null || (float) $old !== (float) $rxPower)) {
                        $customer->onu_rx_power_dbm = $rxPower;
                        $customer->save();
                        $customersUpdated++;
                    }
                }
            }

            $snap['meta']['rxpower_synced_at'] = now()->toISOString();
            $snap['meta']['rxpower_stats'] = $result['stats'] ?? [];
            $snap['meta']['rxpower_updated'] = $updated;
            $snap['meta']['rxpower_offline'] = $offline;
            $snap['meta']['rxpower_customers_updated'] = $customersUpdated;

            $olt->last_gpon_snapshot = $snap;
            $olt->last_gpon_synced_at = now();
            $olt->save();
        }

        $stats = $result['stats'] ?? [];

        return response()->json([
            'status' => 'done',
            'updated' => $updated,
            'customers_updated' => $customersUpdated ?? 0,
            'offline' => $offline ?? 0,
            'total_onus' => count($snap['onus'] ?? []),
            'stats' => $stats,
        ]);
    }

    /**
     * @route POST /api/olt-c300/ocr
     */
    public function ocr(Request $request)
    {
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
        ]);

        $tempCheck = $request->file('image')->getPathname();
        $imgInfo = @getimagesize($tempCheck);
        if ($imgInfo && ($imgInfo[0] < 100 || $imgInfo[1] < 100)) {
            return response()->json([
                'sn' => null,
                'raw_text' => 'Image too small. Please use a clearer, zoomed-in photo of the ONU label.',
                'source' => 'validation',
            ]);
        }

        $fileSize = filesize($tempCheck);
        if ($fileSize < 5000) {
            return response()->json([
                'sn' => null,
                'raw_text' => 'Image file appears corrupted. Please re-take the photo and upload again.',
                'source' => 'validation',
            ]);
        }

        $file = $request->file('image');
        $path = $file->store('temp/ocr', 'local');
        $fullPath = storage_path('app/private/'.$path);

        $sn = null;
        $rawText = '';
        $usedFallback = false;

        $processed = storage_path('app/temp/ocr/'.uniqid().'.png');
        $processedB = storage_path('app/temp/ocr/'.uniqid().'.png');

        // Pipeline A: grayscale + resize
        $cmdA = sprintf(
            'magick %s -colorspace Gray -resize 2000x %s 2>/dev/null',
            escapeshellarg($fullPath),
            escapeshellarg($processed)
        );
        // Pipeline B: negate + resize
        $cmdB = sprintf(
            'magick %s -colorspace Gray -negate -resize 2000x %s 2>/dev/null',
            escapeshellarg($fullPath),
            escapeshellarg($processedB)
        );
        exec($cmdA, $_, $codeA);
        exec($cmdB, $_, $codeB);

        Log::info("OCR debug: path=$fullPath size=".(file_exists($fullPath) ? filesize($fullPath) : 0)." gdA=$codeA gdB=$codeB");

        $allResults = [];

        if ($codeA === 0) {
            foreach ([6, 3, 4, 8] as $psm) {
                $text = trim(shell_exec("tesseract {$processed} stdout -l chi_sim+eng --psm {$psm} 2>&1") ?? '');
                if ($text) {
                    $allResults[] = ['text' => $text, 'score' => strlen($text), 'src' => 'A_psm'.$psm];
                }
            }
            @unlink($processed);
        }

        if ($codeB === 0) {
            foreach ([6, 3, 4, 8] as $psm) {
                $text = trim(shell_exec("tesseract {$processedB} stdout -l chi_sim+eng --psm {$psm} 2>&1") ?? '');
                if ($text) {
                    $allResults[] = ['text' => $text, 'score' => strlen($text), 'src' => 'B_psm'.$psm];
                }
            }
            @unlink($processedB);
        }

        $ispPrefixes = ['HWTC', 'CMCC', 'CMDC', 'HW', '3M', 'ALCL', 'SCSC', 'TW', 'BDCOM', 'VSOL', 'ZTE', 'H3C', 'UBNT', 'CAF', 'HINA', 'NTC', 'TOTOLINK', 'Gpon'];
        $garbageTerms = ['IMAGETOOSMALL', 'TOOSMALL', 'CANNOTBERECOGNIZED', 'OPENINGDATAFILE', 'ESTIMATINGRESOLUTION', 'TESSERACT', 'LINECANNOT', 'MINWIDTH', 'IMAGEMAYBE', 'ESTIMATING', 'MOBILE', 'ROUTER', 'PON', 'ONU', 'DEVICE', 'MODEL', 'SERIAL', 'MOBILE1SAREARPRO', 'ROUTER1SAREARPRO'];

        $bestSn = null;
        $bestText = '';
        $bestLen = 0;

        foreach ($allResults as $result) {
            $text = trim($result['text'] ?? '');
            $isGarbage = false;
            foreach ($garbageTerms as $g) {
                if (stripos($text, $g) !== false) {
                    $isGarbage = true;
                    break;
                }
            }
            if ($isGarbage) {
                continue;
            }

            $clean = strtoupper(preg_replace('/[\s\-_\.]+/', '', $text));

            foreach ($ispPrefixes as $prefix) {
                $pos = strpos($clean, $prefix);
                if ($pos === false) {
                    continue;
                }

                $after = substr($clean, $pos + strlen($prefix));
                $after = preg_replace('/[^A-Z0-9]+$/', '', $after);

                if (preg_match('/^[A-Z0-9]{6,14}/', $after, $m)) {
                    $candidate = $prefix.$m[0];
                    $hasLetter = preg_match('/[A-Z]/', $candidate);
                    $hasDigit = preg_match('/[0-9]/', $candidate);
                    if (! $hasLetter || ! $hasDigit) {
                        continue;
                    }
                    if (preg_match('/^(.)\1{5,}$/', $candidate)) {
                        continue;
                    }
                    if (substr_count($candidate, $candidate[0]) > strlen($candidate) * 0.65) {
                        continue;
                    }
                    if (strlen($candidate) < 8 || strlen($candidate) > 20) {
                        continue;
                    }

                    if (strlen($candidate) > $bestLen) {
                        $bestLen = strlen($candidate);
                        $bestSn = $candidate;
                        $bestText = $text;
                    }
                }
            }

            if (! $bestSn && preg_match_all('/[A-Z0-9]{10,16}/', $clean, $m)) {
                foreach ($m[0] as $c) {
                    if (! preg_match('/[A-Z]/', $c) || ! preg_match('/[0-9]/', $c)) {
                        continue;
                    }
                    if (preg_match('/^(.)\1{5,}$/', $c)) {
                        continue;
                    }
                    if (substr_count($c, $c[0]) > strlen($c) * 0.6) {
                        continue;
                    }
                    if (strlen($c) > $bestLen) {
                        $bestLen = strlen($c);
                        $bestSn = $c;
                        $bestText = $text;
                    }
                }
            }
        }

        $rawText = $bestText;
        $sn = $bestSn ?: null;

        Log::info("OCR final: sn=[$sn] bestLen=$bestLen");
        @unlink($fullPath);

        if ($sn) {
            return response()->json([
                'sn' => $sn,
                'raw_text' => $rawText,
                'source' => $usedFallback ? 'tesseract' : 'ocr.space',
            ]);
        } else {
            return response()->json([
                'sn' => null,
                'raw_text' => $rawText ?: 'No serial number found in image. Please ensure the photo shows the ONU label clearly.',
                'source' => $usedFallback ? 'tesseract' : 'ocr.space',
            ]);
        }
    }

    private function isRxPowerCollectorRunning(int $pid): bool
    {
        if ($pid <= 0 || ! file_exists('/proc/'.$pid)) {
            return false;
        }

        $cmdline = @file_get_contents('/proc/'.$pid.'/cmdline');
        if (! is_string($cmdline) || $cmdline === '') {
            return false;
        }

        return str_contains($cmdline, 'zte_c300_rxpower_fast.py')
            || str_contains($cmdline, 'zte_c300_rxpower.py');
    }

    private function extractRxPowerValue(mixed $value): ?float
    {
        if (is_numeric($value)) {
            $rx = (float) $value;

            return $rx <= 0 ? $rx : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['rx_dbm', 'rx_power_dbm', 'onu_rx_dbm'] as $key) {
            if (array_key_exists($key, $value) && is_numeric($value[$key])) {
                $rx = (float) $value[$key];
                if ($rx <= 0) {
                    return $rx;
                }
            }
        }

        return null;
    }

    /**
     * VLAN map + ONU types - single source of truth for frontend.
     *
     * @route GET /api/olt-c300/vlan-map
     */
    public function vlanMapApi(Request $request)
    {
        $oltId = $request->integer('olt_id') ?: null;
        $vlanMap = self::vlanMap($oltId);
        $mikrotikOptions = self::mikrotikTr069Options();
        $mikrotik = trim((string) $request->input('mikrotik', ''));
        $routerId = $request->filled('router_id') ? (int) $request->input('router_id') : null;
        $tr069Vlan = self::tr069Vlan($oltId, null, $mikrotik !== '' ? $mikrotik : null, $routerId);
        $ports = [];
        foreach ($vlanMap as $port => $vlan) {
            $ports[] = ['value' => $port, 'label' => "{$port} (VLAN {$vlan})", 'vlan' => $vlan];
        }

        return response()->json([
            'ports' => $ports,
            'tr069_vlan' => $tr069Vlan,
            'mikrotik_options' => $mikrotikOptions,
            'onu_types' => ['ZTE-F660', 'ZTE', 'ALL', 'Fiberhome', 'Huawei'],
            // Source = DB only (olt_vlan_mappings). Tidak ada hardcode/fallback VLAN.
            'source' => 'olt_vlan_mappings',
            'note' => $ports === []
                ? 'Belum ada VLAN mapping di DB untuk OLT ini. Isi di form VLAN Mapping OLT.'
                : null,
        ]);
    }

    /**
     * @route GET /api/olt-c300/genieacs-tags
     */
    public function genieacsTags()
    {
        if (! class_exists(FhttEnrollmentService::class)) {
            return response()->json([
                'tags' => [],
                'count' => 0,
                'available' => false,
            ]);
        }

        $tags = app(FhttEnrollmentService::class)->tagMap();

        return response()->json([
            'tags' => $tags,
            'count' => count($tags),
        ]);
    }

    /**
     * @route POST /api/olt-c300/auto-genieacs-provision
     */
    public function autoGenieacsProvision(Request $request)
    {
        $validated = $request->validate([
            'serial_number' => ['required', 'string', 'max:64'],
            'pppoe_user' => ['nullable', 'string', 'max:191'],
        ]);

        $serial = strtoupper(trim($validated['serial_number']));
        $cwmpUrl = Setting::get('genieacs_cwmp_url', config('site.genieacs_cwmp_url'));
        $oltId = $request->integer('olt_id') ?: null;
        $tr069Vlan = self::tr069Vlan($oltId);

        return response()->json([
            'success' => true,
            'serial_number' => $serial,
            'tr069_vlan' => $tr069Vlan,
            'checklist' => [
                "Buat WAN TR-069 DHCP di router (VLAN {$tr069Vlan}).",
                'Jangan isi ACS URL manual jika DHCP Option 43 sudah aktif di Mikrotik.',
                'Reboot router lalu tunggu device muncul di GenieACS.',
                'Tag device dengan pppoe_user: '.($validated['pppoe_user'] ?? '(isi manual)'),
                'Catatan: MikroTik Migrasi (Skynet-Migrasi) pakai VLAN 68; Randuagung/default VLAN 69.',
            ],
            'cwmp_url' => $cwmpUrl,
            'dhcp_option_43_hint' => "Set sekali di pool DHCP VLAN {$tr069Vlan} Mikrotik agar ACS URL ter-push otomatis.",
        ]);
    }
}
