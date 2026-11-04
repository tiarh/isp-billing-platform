<?php

namespace App\Services;

use App\Enums\OutageRootCause;
use App\Models\MonitoringAlertEvent;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Approval-gated Ticket/SLA actions for monitoring incidents only.
 * It never writes customer or device state and never sends WA notifications.
 */
class MonitoringTicketTriageService
{
    public function enabled(): bool
    {
        return filter_var(Setting::get('monitoring_ticket_triage_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public function acknowledge(SupportTicket $ticket, User $actor, ?string $reason = null): SupportTicket
    {
        return $this->mutate($ticket, $actor, 'acknowledged', [
            'monitoring_acknowledged_at' => now()->toISOString(),
            'monitoring_acknowledged_by' => $actor->id,
            'monitoring_acknowledged_reason' => $reason,
        ], $reason ?: 'Incident monitoring di-acknowledge operator.');
    }

    public function assign(SupportTicket $ticket, User $actor, User $assignee, ?string $reason = null): SupportTicket
    {
        $this->guard($ticket, $actor);

        return DB::transaction(function () use ($ticket, $actor, $assignee, $reason) {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $meta = $locked->meta ?? [];
            $idempotencyKey = 'monitoring-triage:'.$ticket->id.':assigned';
            if (($meta['monitoring_triage_idempotency']['assigned'] ?? null) === $idempotencyKey) {
                return $locked->fresh(['assignee']);
            }
            $oldAssignee = $locked->assigned_to;
            $locked->forceFill([
                'assigned_to' => $assignee->id,
                'assigned_at' => now(),
                'status' => 'assigned',
                'meta' => array_merge($meta, [
                    'monitoring_assigned_at' => now()->toISOString(),
                    'monitoring_assigned_by' => $actor->id,
                    'monitoring_assigned_reason' => $reason,
                    'monitoring_triage_idempotency' => array_merge($meta['monitoring_triage_idempotency'] ?? [], ['assigned' => $idempotencyKey]),
                ]),
            ])->save();

            TicketActivity::create([
                'ticket_id' => $locked->id,
                'user_id' => $actor->id,
                'action' => 'monitoring_assigned',
                'old_value' => ['assigned_to' => $oldAssignee],
                'new_value' => ['assigned_to' => $assignee->id, 'assignee_name' => $assignee->name],
                'description' => $reason ?: 'Incident monitoring di-assign tanpa notifikasi WA.',
            ]);

            return $locked->fresh(['assignee']);
        });
    }

    public function snooze(SupportTicket $ticket, User $actor, Carbon $until, string $reason): SupportTicket
    {
        if ($until->isPast()) {
            throw new \InvalidArgumentException('Waktu snooze harus di masa depan.');
        }

        return $this->mutate($ticket, $actor, 'snoozed', [
            'monitoring_snoozed_until' => $until->toISOString(),
            'monitoring_snoozed_by' => $actor->id,
            'monitoring_snoozed_reason' => $reason,
        ], $reason);
    }

    public function markFalsePositive(SupportTicket $ticket, User $actor, string $reason): SupportTicket
    {
        return $this->mutate($ticket, $actor, 'false_positive', [
            'monitoring_false_positive_at' => now()->toISOString(),
            'monitoring_false_positive_by' => $actor->id,
            'monitoring_false_positive_reason' => $reason,
        ], $reason, true);
    }

    public function verifyRecovery(SupportTicket $ticket, User $actor, string $reason): SupportTicket
    {
        $dedupeKey = (string) (($ticket->meta ?? [])['monitoring_alert_dedupe_key'] ?? '');
        $active = $dedupeKey !== '' && MonitoringAlertEvent::query()
            ->where('dedupe_key', $dedupeKey)
            ->where('status', 'active')
            ->exists();
        if ($active) {
            throw new \RuntimeException('Evidence monitoring masih active; recovery belum dapat diverifikasi.');
        }

        return $this->mutate($ticket, $actor, 'recovery_verified', [
            'monitoring_recovery_verified_at' => now()->toISOString(),
            'monitoring_recovery_verified_by' => $actor->id,
            'monitoring_recovery_verified_reason' => $reason,
        ], $reason, true);
    }

    private function mutate(SupportTicket $ticket, User $actor, string $action, array $changes, string $reason, bool $resolve = false): SupportTicket
    {
        $this->guard($ticket, $actor);
        $idempotencyKey = 'monitoring-triage:'.$ticket->id.':'.$action;

        return DB::transaction(function () use ($ticket, $actor, $action, $changes, $reason, $resolve, $idempotencyKey) {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
            $meta = $locked->meta ?? [];
            if (($meta['monitoring_triage_idempotency'][$action] ?? null) === $idempotencyKey) {
                return $locked;
            }

            $before = [
                'status' => $locked->status,
                'assigned_to' => $locked->assigned_to,
                'meta' => $meta,
            ];
            $meta = array_merge($meta, $changes, [
                'monitoring_triage_idempotency' => array_merge($meta['monitoring_triage_idempotency'] ?? [], [$action => $idempotencyKey]),
            ]);
            $update = ['meta' => $meta];
            if ($resolve) {
                $update['status'] = 'resolved';
                $update['resolved_at'] = now();
            }
            $locked->forceFill($update)->save();

            TicketActivity::create([
                'ticket_id' => $locked->id,
                'user_id' => $actor->id,
                'action' => 'monitoring_'.$action,
                'old_value' => $before,
                'new_value' => ['meta' => $changes, 'status' => $update['status'] ?? $locked->status],
                'description' => $reason,
            ]);

            // The triage close needs no technician form: fill the cause for an incident without one.
            if ($resolve) {
                $cause = $action === 'false_positive' ? OutageRootCause::NotFault : OutageRootCause::SelfRecovered;
                OutageRootCause::recordSystemCause($locked, $cause, $actor);
            }

            return $locked->fresh(['assignee']);
        });
    }

    private function guard(SupportTicket $ticket, User $actor): void
    {
        if (! $this->enabled()) {
            throw new \RuntimeException('Monitoring ticket triage masih OFF.');
        }
        abort_unless($actor->isAdminLike(), 403);
        $meta = $ticket->meta ?? [];
        abort_unless(filled($meta['monitoring_alert_dedupe_key'] ?? null), 404);
    }
}
