<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\ManualLifecycleGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketCustomerLifecycleService
{
    public const REQUESTED = 'customer_lifecycle_requested';

    public const APPLIED = 'customer_lifecycle_applied';

    public function pendingFor(SupportTicket $ticket): array
    {
        $applied = $ticket->activities()
            ->where('action', self::APPLIED)
            ->get()
            ->map(fn (TicketActivity $activity) => $activity->new_value['idempotency_key'] ?? null)
            ->filter()
            ->all();

        return $ticket->activities()
            ->where('action', self::REQUESTED)
            ->oldest()
            ->get()
            ->filter(fn (TicketActivity $activity) => ! in_array($activity->new_value['idempotency_key'] ?? null, $applied, true))
            ->map(fn (TicketActivity $activity) => [
                'id' => $activity->id,
                'lifecycle_action' => $activity->new_value['lifecycle_action'] ?? null,
                'label' => $this->label((string) ($activity->new_value['lifecycle_action'] ?? '')),
                'reason' => $activity->new_value['reason'] ?? '',
                'requested_by' => $activity->user?->name,
                'requested_at' => optional($activity->created_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
                'before' => $activity->old_value['customer'] ?? [],
                'after' => $activity->new_value['after'] ?? [],
                'rollback_hint' => $activity->new_value['rollback_hint'] ?? '',
            ])
            ->values()
            ->all();
    }

    public function request(SupportTicket $ticket, User $actor, array $data): TicketActivity
    {
        $customer = $this->customer($ticket);
        $action = (string) $data['lifecycle_action'];
        $reason = trim((string) $data['reason']);
        $extra = [
            'new_odp_name' => trim((string) ($data['new_odp_name'] ?? '')),
            'new_address' => trim((string) ($data['new_address'] ?? '')),
        ];

        $after = $this->afterSnapshot($customer, $action, $extra);
        $key = $this->key($ticket, $customer, $action);

        $existing = $ticket->activities()
            ->whereIn('action', [self::REQUESTED, self::APPLIED])
            ->where('new_value->idempotency_key', $key)
            ->first();

        if ($existing) {
            return $existing;
        }

        return TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => self::REQUESTED,
            'old_value' => ['customer' => $this->snapshot($customer)],
            'new_value' => [
                'idempotency_key' => $key,
                'lifecycle_action' => $action,
                'reason' => $reason,
                'after' => $after,
                'rollback_hint' => 'Restore customer fields from old_value.customer for key '.$key,
            ],
            'description' => 'Pending approval lifecycle pelanggan: '.$this->label($action),
        ]);
    }

    /**
     * Roadmap 1.3: a dismantle approval terminates the customer, so it passes
     * the lifecycle guard. On deny/unknown the approval is REFUSED (the
     * request stays pending, nothing changes, the ticket is not lost); only a
     * superadmin with a written reason may override, logged with the evidence.
     */
    public function approve(SupportTicket $ticket, TicketActivity $requestActivity, User $actor, bool $override = false, string $overrideReason = ''): TicketActivity
    {
        if ($requestActivity->ticket_id !== $ticket->id || $requestActivity->action !== self::REQUESTED) {
            abort(404);
        }

        // The guard may call Grafana (seconds): evaluate it BEFORE the transaction opens.
        $verdict = $this->guardVerdict($ticket, $requestActivity, $actor, $override, $overrideReason);

        return DB::transaction(function () use ($ticket, $requestActivity, $actor, $verdict) {
            $ticket->refresh();
            $customer = $this->customer($ticket);
            $newValue = $requestActivity->new_value ?? [];
            $key = (string) ($newValue['idempotency_key'] ?? '');
            $action = (string) ($newValue['lifecycle_action'] ?? '');

            $existing = $ticket->activities()
                ->where('action', self::APPLIED)
                ->where('new_value->idempotency_key', $key)
                ->first();

            if ($existing) {
                return $existing;
            }

            if ($action === 'dismantle' && ! in_array($ticket->status, ['resolved', 'closed'], true)) {
                throw ValidationException::withMessages([
                    'lifecycle' => 'Dismantle hanya boleh di-apply setelah ticket resolved/closed.',
                ]);
            }

            $after = $newValue['after'] ?? [];
            if ($verdict !== null && $verdict['overridden']) {
                app(ManualLifecycleGate::class)->logOverride(ManualLifecycleGate::ACTION_TERMINATE, $customer, $actor, 'ticket_dismantle_approve', $verdict['decision'], $verdict['reason']);
            }

            $before = $this->snapshot($customer);
            // A10: a dismantle (-> terminated) was gated above (guardVerdict); allow only then.
            $terminating = ($after['status'] ?? null) === 'terminated';
            LifecycleBackstop::allow(
                $terminating && $verdict !== null ? [(int) $customer->getKey()] : [],
                'ticket_lifecycle_approve',
                fn () => $customer->forceFill(array_intersect_key($after, array_flip(['status', 'odp_name', 'address'])))->save(),
            );

            return TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor->id,
                'action' => self::APPLIED,
                'old_value' => ['customer' => $before],
                'new_value' => [
                    'idempotency_key' => $key,
                    'lifecycle_action' => $action,
                    'reason' => $newValue['reason'] ?? '',
                    'after' => $this->snapshot($customer->fresh()),
                    'requested_activity_id' => $requestActivity->id,
                    'rollback_hint' => 'Restore customer fields from old_value.customer for key '.$key,
                ],
                'description' => 'Lifecycle pelanggan di-approve: '.$this->label($action),
            ]);
        });
    }

    /**
     * Guard verdict for an approval that would terminate the customer; null when
     * the guard does not apply (already applied, not yet resolved/closed, not a
     * terminate, customer already terminated). Throws on deny / forbidden.
     *
     * @return array{allowed: bool, overridden: bool, decision: GuardDecision, message: ?string, field_errors: array<string, string>, forbidden: bool, reason: string}|null
     */
    private function guardVerdict(SupportTicket $ticket, TicketActivity $requestActivity, User $actor, bool $override, string $overrideReason): ?array
    {
        $ticket->refresh();
        $customer = $this->customer($ticket);
        $newValue = $requestActivity->new_value ?? [];
        $key = (string) ($newValue['idempotency_key'] ?? '');
        $action = (string) ($newValue['lifecycle_action'] ?? '');
        $after = $newValue['after'] ?? [];

        if (($after['status'] ?? null) !== 'terminated' || (string) $customer->status === 'terminated') {
            return null;
        }
        if ($ticket->activities()->where('action', self::APPLIED)->where('new_value->idempotency_key', $key)->exists()) {
            return null;
        }
        if ($action === 'dismantle' && ! in_array($ticket->status, ['resolved', 'closed'], true)) {
            return null; // the transaction refuses this with its own message
        }

        $verdict = app(ManualLifecycleGate::class)->gate(ManualLifecycleGate::ACTION_TERMINATE, $customer, $actor, 'ticket_dismantle_approve', $override, $overrideReason);
        if ($verdict['forbidden']) {
            abort(403, 'Hanya superadmin yang boleh override pagar pelanggan.');
        }
        if (! $verdict['allowed']) {
            $errors = ['lifecycle_guard' => (string) $verdict['message']];
            if (isset($verdict['field_errors']['override_reason'])) {
                $errors['override_reason'] = $verdict['field_errors']['override_reason'];
            }

            throw ValidationException::withMessages($errors);
        }

        return $verdict;
    }

    private function customer(SupportTicket $ticket): Customer
    {
        $ticket->loadMissing('customer');
        if (! $ticket->customer) {
            throw ValidationException::withMessages(['customer_id' => 'Ticket harus terhubung ke customer.']);
        }

        return $ticket->customer;
    }

    private function afterSnapshot(Customer $customer, string $action, array $extra): array
    {
        return match ($action) {
            'dismantle' => ['status' => 'terminated', 'odp_name' => $customer->odp_name, 'address' => $customer->address],
            'isolir' => ['status' => 'isolated', 'odp_name' => $customer->odp_name, 'address' => $customer->address],
            'reconnect' => ['status' => 'active', 'odp_name' => $customer->odp_name, 'address' => $customer->address],
            'pindah_odp' => [
                'status' => $customer->status,
                'odp_name' => $extra['new_odp_name'] ?: throw ValidationException::withMessages(['new_odp_name' => 'ODP baru wajib diisi.']),
                'address' => $extra['new_address'] ?: $customer->address,
            ],
            default => throw ValidationException::withMessages(['lifecycle_action' => 'Action lifecycle tidak valid.']),
        };
    }

    private function snapshot(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'status' => $customer->status,
            'odp_name' => $customer->odp_name,
            'address' => $customer->address,
        ];
    }

    private function key(SupportTicket $ticket, Customer $customer, string $action): string
    {
        return "ticket:{$ticket->id}:customer:{$customer->id}:lifecycle:{$action}";
    }

    private function label(string $action): string
    {
        return match ($action) {
            'dismantle' => 'Dismantle',
            'isolir' => 'Isolir',
            'reconnect' => 'Reconnect',
            'pindah_odp' => 'Pindah ODP',
            default => $action,
        };
    }
}
