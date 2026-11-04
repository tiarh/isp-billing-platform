<?php

namespace App\Services\Olt;

use App\Exceptions\Olt\OltProvisioningBlockedException;
use App\Models\Olt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class OltProvisioningGuard
{
    public const LIVE_CONFIRMATION = 'PROVISION OLT';

    /**
     * S4: the phrase the operator must type, per action. Keys are both the
     * route names (middleware path) and the controller operation names
     * (OltC300Controller::provisioningGate). Anything not listed falls back
     * to LIVE_CONFIRMATION. The UI forwards exactly what was typed, so a
     * reboot can no longer be authorised with the provisioning phrase.
     *
     * @var array<string, string>
     */
    public const LIVE_CONFIRMATIONS = [
        'api.olts.onu.reboot' => 'REBOOT ONU',
        'reboot-onu' => 'REBOOT ONU',
        'api.olts.onu.delete' => 'DELETE ONU',
        'delete-onu' => 'DELETE ONU',
        'api.olts.onu.rename' => 'RENAME ONU',
        'rename-onu' => 'RENAME ONU',
        'api.olts.save-config' => 'SAVE OLT',
        'save-config' => 'SAVE OLT',
        'api.olt-c300.reconfigure' => 'RECONFIGURE OLT',
        'reconfigure' => 'RECONFIGURE OLT',
    ];

    public static function liveConfirmationFor(?string $action): string
    {
        return self::LIVE_CONFIRMATIONS[(string) $action] ?? self::LIVE_CONFIRMATION;
    }

    public static function matchesLiveConfirmation(mixed $typed, ?string $action): bool
    {
        return is_string($typed) && hash_equals(self::liveConfirmationFor($action), $typed);
    }

    /**
     * Issue a one-time server-side approval for an already-authorized workflow.
     * The signed bundle is kept inside the request and is never exposed to UI.
     *
     * @return array{approval: string, approved_at: int, signature: string}
     */
    public function issueInternalApproval(Olt $olt, string $operation, string $subject): array
    {
        $approval = sprintf(
            'internal:%s:olt-%d:%s:%s',
            $this->safeTokenPart($operation),
            (int) $olt->getKey(),
            $this->safeTokenPart($subject),
            bin2hex(random_bytes(16)),
        );
        $approvedAt = now()->timestamp;

        return [
            'approval' => $approval,
            'approved_at' => $approvedAt,
            'signature' => hash_hmac('sha256', $this->approvalPayload($approval, $approvedAt), (string) config('app.key', '')),
        ];
    }

    public function provision(Olt $olt, array $request): array
    {
        $commands = $request['commands'] ?? null;
        if (! is_array($commands) || ! array_is_list($commands) || $commands === []) {
            $this->block('command plan is missing or invalid');
        }

        foreach ($commands as $command) {
            if (! is_string($command) || trim($command) === '') {
                $this->block('command plan contains an invalid command');
            }
        }

        if ((bool) config('olt-provisioning.kill_switch', false)) {
            $this->block('kill switch is enabled');
        }

        if ((bool) config('olt-provisioning.dry_run', true)) {
            Log::notice('olt.provisioning.dry_run', [
                'olt_id' => (int) $olt->getKey(),
                'vendor' => (string) $olt->vendor,
                'command_count' => count($commands),
            ]);

            return [
                'dry_run' => true,
                'olt_id' => (int) $olt->getKey(),
                'commands' => $commands,
            ];
        }

        $this->assertApproval($request);

        return [
            'dry_run' => false,
            'olt_id' => (int) $olt->getKey(),
            'commands' => $commands,
        ];
    }

    private function assertApproval(array $request): void
    {
        $approval = trim((string) ($request['approval'] ?? ''));
        $timestamp = $request['approved_at'] ?? null;
        $signature = trim((string) ($request['signature'] ?? ''));
        $appKey = (string) config('app.key', '');

        if ($approval === '' || $signature === '' || $appKey === '') {
            $this->block('approval, signature, and APP_KEY are required');
        }

        if (! is_int($timestamp) && ! (is_string($timestamp) && preg_match('/^\d+$/', $timestamp) === 1)) {
            $this->block('approval timestamp is invalid');
        }

        $timestamp = (int) $timestamp;
        $now = now()->timestamp;
        $window = max(1, (int) config('olt-provisioning.approval_window_seconds', 900));

        if ($timestamp > $now || ($now - $timestamp) > $window) {
            $this->block('approval timestamp is outside the allowed window');
        }

        $payload = $this->approvalPayload($approval, $timestamp);
        $expected = hash_hmac('sha256', $payload, $appKey);
        if (! hash_equals($expected, $signature)) {
            $this->block('approval signature is invalid');
        }

        $fingerprint = hash('sha256', $signature);
        $lock = Cache::lock(
            'olt-provisioning-approval:'.$fingerprint,
            max(1, (int) config('olt-provisioning.approval_lock_seconds', 900)),
        );

        if (! $lock->get()) {
            $this->block('approval is already active');
        }
    }

    private function block(string $reason): never
    {
        throw new OltProvisioningBlockedException('OLT provisioning blocked: '.$reason);
    }

    private function approvalPayload(string $approval, int $timestamp): string
    {
        return "OLT_PROVISIONING_APPROVED={$approval}\nOLT_PROVISIONING_APPROVED_AT={$timestamp}";
    }

    private function safeTokenPart(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9_.:-]+/', '-', trim($value)) ?? '';

        return trim($value, '-:.') ?: 'unknown';
    }
}
