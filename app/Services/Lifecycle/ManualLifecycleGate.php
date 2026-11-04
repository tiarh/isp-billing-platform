<?php

namespace App\Services\Lifecycle;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roadmap 1.3 PR-3: the one place that turns a CustomerLifecycleGuard verdict
 * into a UI outcome for the MANUAL paths (customer archive, status ->
 * terminated, ticket dismantle approval). Fail-closed: a guard that throws is
 * an unknown verdict. Only a superadmin with a written reason may override,
 * and the override is written to the activity log with the guard evidence.
 * Automated callers (CLI, jobs) do not use this class: they never override.
 */
class ManualLifecycleGate
{
    public const ACTION_TERMINATE = 'terminate';

    public const ACTION_ARCHIVE = 'archive';

    public const EVENT_OVERRIDE = 'lifecycle_guard_override';

    public function __construct(private readonly CustomerLifecycleGuard $guard) {}

    public function evaluate(string $action, Customer $customer, string $source): GuardDecision
    {
        $context = GuardContext::manual($source);

        try {
            return $action === self::ACTION_ARCHIVE
                ? $this->guard->canArchive($customer, $context)
                : $this->guard->canTerminate($customer, $context);
        } catch (Throwable $e) {
            Log::warning('[LifecycleGuard] manual check failed', ['customer_id' => $customer->id, 'exception_class' => $e::class]);

            return GuardDecision::fromSignals($action, [
                GuardSignal::unknown(GuardSignal::PPPOE, 'guard_failed', 'Pagar gagal memeriksa status pelanggan.'),
            ]);
        }
    }

    /**
     * Full gate: verdict + optional override.
     *
     * @return array{allowed: bool, overridden: bool, decision: GuardDecision, message: ?string, field_errors: array<string, string>, forbidden: bool, reason: string}
     */
    public function gate(string $action, Customer $customer, ?User $actor, string $source, bool $overrideRequested, string $overrideReason): array
    {
        $decision = $this->evaluate($action, $customer, $source);
        $result = [
            'allowed' => true,
            'overridden' => false,
            'decision' => $decision,
            'message' => null,
            'field_errors' => [],
            'forbidden' => false,
            'reason' => '',
        ];
        if ($decision->allowed()) {
            return $result;
        }

        $result['allowed'] = false;
        $result['message'] = self::denyMessage($action, $decision);
        if (! $overrideRequested) {
            return $result;
        }

        $auth = $this->guard->authorizeOverride($actor, $overrideReason, $decision, GuardContext::manual($source));
        if (! $auth['ok']) {
            if ($auth['error'] === 'superadmin_required') {
                $result['forbidden'] = true;
                $result['field_errors']['override_reason'] = 'Hanya superadmin yang boleh override pagar pelanggan.';
            } elseif ($auth['error'] === 'reason_too_short') {
                $result['field_errors']['override_reason'] = 'Alasan override minimal '.(int) config('lifecycle_guard.override_reason_min', 20).' karakter.';
            } else {
                $result['field_errors']['override_reason'] = 'Override tidak diizinkan.';
            }

            return $result;
        }

        $result['allowed'] = true;
        $result['overridden'] = true;
        $result['message'] = null;
        $result['reason'] = $auth['reason'];

        return $result;
    }

    /** Written inside the same transaction as the action it permits. */
    public function logOverride(string $action, Customer $customer, User $actor, string $source, GuardDecision $decision, string $reason): void
    {
        activity()
            ->causedBy($actor)
            ->performedOn($customer)
            ->event(self::EVENT_OVERRIDE)
            ->withProperties([
                'action' => $action,
                'source' => $source,
                'reason' => $reason,
                'guard' => $decision->toArray(),
            ])
            ->log(($actor->isSuperAdmin() ? 'Superadmin' : ucfirst((string) $actor->role)).' override pagar pelanggan: '.$action.' '.$customer->code);
    }

    public static function denyMessage(string $action, GuardDecision $decision): string
    {
        $verb = $action === self::ACTION_ARCHIVE ? 'diarsip' : 'diterminate';

        return 'Pelanggan masih dipakai, tidak bisa '.$verb.': '.$decision->message()
            .' Pastikan perangkat sudah dicabut. Jika yakin, minta superadmin override dengan alasan tertulis.';
    }
}
