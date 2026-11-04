<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Enums\OutageRootCause;
use App\Jobs\DeleteDismantledOnuJob;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Support\DismantleReason;
use App\Support\OpsAlertSource;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DeviceRecoveryService
{
    public const STATUS_WAITING_ASSIGNMENT = 'waiting_assignment';

    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PICKUP_STARTED = 'pickup_started';

    public const STATUS_CARRIED = 'carried';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_EXCEPTION = 'exception';

    public const STATUS_EXCEPTION_ACCEPTED = 'exception_accepted';

    public const STATUS_CANCELLED = 'cancelled';

    /** Final: the device was reinstalled at another live customer (not collected). */
    public const STATUS_REUSED = 'reused';

    /** Before the technician starts the pickup: the assignee may still change. */
    public const PRE_START_STATUSES = [
        self::STATUS_WAITING_ASSIGNMENT,
        self::STATUS_ASSIGNED,
        self::STATUS_SCHEDULED,
    ];

    public const TICKET_ASSIGNEE_REASON = 'Otomatis mengikuti teknisi tiket';

    /**
     * Owner 2026-10-10: a dismantle ticket filed for a customer whose device
     * was already carried/received within this many days is linked to that
     * case instead of opening a new pickup. Override with
     * `device-recovery.duplicate_window_days` (0 disables the check).
     */
    public const DUPLICATE_WINDOW_DAYS = 30;

    /** ticket_activities.action written when a ticket is linked to an earlier case. */
    public const TICKET_LINKED_ACTION = 'recovery_linked';

    /** support_tickets.meta key holding the linked recovery case id. */
    public const TICKET_LINKED_META = 'recovery_linked_case_id';

    /** Cases in these statuses no longer hold an open pickup. */
    public const FINAL_STATUSES = [
        self::STATUS_RECEIVED,
        self::STATUS_EXCEPTION_ACCEPTED,
        self::STATUS_CANCELLED,
        self::STATUS_REUSED,
    ];

    /** Ticket subject (also the group WA "Gangguan" line) for a technician report. */
    public const TECHNICIAN_REPORT_SUBJECT = 'LAPOR PENGAMBILAN TANPA TIKET: perangkat sudah diambil teknisi dan dibawa ke kantor. Admin wajib konfirmasi "Diterima gudang" di Pengambilan Perangkat.';

    public const ITEM_PENDING = 'pending';

    public const ITEM_CARRIED = 'carried';

    public const ITEM_RECEIVED = 'received';

    public const ITEM_EXCEPTION = 'exception';

    public const CONDITION_GOOD = 'good';

    public const CONDITION_DAMAGED = 'damaged';

    public const CONDITION_LOST = 'lost';

    public const CONDITION_UNKNOWN = 'unknown';

    public const CONDITION_MISMATCH = 'mismatch';

    /** Photo kinds, stored per index in device_recovery_items.meta.photo_kinds. */
    public const PHOTO_KIND_LABEL_SN = 'label_sn';

    public const PHOTO_KIND_DEVICE = 'perangkat_utuh';

    public const PHOTO_KIND_RECEIPT = 'penerimaan';

    public const PHOTO_KIND_OTHER = 'lainnya';

    public const PHOTO_KINDS = [
        self::PHOTO_KIND_LABEL_SN,
        self::PHOTO_KIND_DEVICE,
        self::PHOTO_KIND_RECEIPT,
        self::PHOTO_KIND_OTHER,
    ];

    /**
     * Customer statuses that no longer hold their device. Mirrors the "live
     * customer" definition used by DeviceRecoveryOltCleanupService.
     */
    public const RELEASED_CUSTOMER_STATUSES = [CustomerStatus::Terminated->value];

    /** support_tickets.type value for a Dismantle ticket. */
    public const DISMANTLE_TICKET_TYPE = 'dismantle';

    /** Customer statuses that become `terminated` once the device is received. */
    public const TERMINATE_ON_RECEIVE_FROM = ['active', 'isolated', 'inactive'];

    public const TERMINATE_ON_RECEIVE_REASON = 'auto: perangkat diterima gudang (Pengambilan Perangkat) -> pelanggan terminated';

    private const DEVICE_TYPES = ['onu', 'ont', 'router', 'modem', 'adapter', 'other'];

    private const CONDITIONS = [
        self::CONDITION_GOOD,
        self::CONDITION_DAMAGED,
        self::CONDITION_LOST,
        self::CONDITION_UNKNOWN,
    ];

    public function createFromApprovedDismantle(
        SupportTicket $ticket,
        User $actor,
        string $deviceType = 'other',
        ?string $idempotencyKey = null,
    ): DeviceRecoveryCase {
        if (! in_array($deviceType, self::DEVICE_TYPES, true)) {
            $this->invalid('device_type', 'Jenis perangkat tidak valid.');
        }

        $key = $this->operationKey($idempotencyKey ?: 'recovery:create:ticket:'.$ticket->id);

        return DB::transaction(function () use ($ticket, $actor, $deviceType, $key): DeviceRecoveryCase {
            $lockedTicket = SupportTicket::query()
                ->with('customer')
                ->lockForUpdate()
                ->find($ticket->id);

            if (! $lockedTicket) {
                throw (new ModelNotFoundException)->setModel(SupportTicket::class, [$ticket->id]);
            }

            $customer = $lockedTicket->customer;
            if (! $customer) {
                $this->invalid('customer_id', 'Tiket dismantle harus terhubung ke customer.');
            }

            $activity = $lockedTicket->activities()
                ->where('action', TicketCustomerLifecycleService::APPLIED)
                ->get()
                ->first(fn (TicketActivity $row): bool => ($row->new_value['lifecycle_action'] ?? null) === 'dismantle');

            // Eligible when the dismantle lifecycle was approved on this ticket,
            // or when the ticket itself is a Dismantle ticket (type=dismantle).
            // Creating a recovery case never mutates the customer or ticket.
            // Roadmap 1.3: a dismantle request still PENDING (the lifecycle guard
            // refused its approval because the customer is still in use) also
            // qualifies, so technicians can collect the device that keeps the
            // customer "in use". Otherwise the refusal could never be resolved.
            $pendingDismantle = ! $activity && $lockedTicket->activities()
                ->where('action', TicketCustomerLifecycleService::REQUESTED)
                ->get()
                ->contains(fn (TicketActivity $row): bool => ($row->new_value['lifecycle_action'] ?? null) === 'dismantle');
            $isDismantleTicket = (string) $lockedTicket->type === self::DISMANTLE_TICKET_TYPE;
            if (! $activity && ! $isDismantleTicket && ! $pendingDismantle) {
                $this->invalid('lifecycle', 'Recovery hanya boleh dibuat dari tiket Dismantle atau dismantle yang sudah di-approve.');
            }

            $existing = DeviceRecoveryCase::query()
                ->where('ticket_id', $lockedTicket->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $linkedCaseId = data_get($lockedTicket->meta, self::TICKET_LINKED_META);
            if ($linkedCaseId && ($linked = DeviceRecoveryCase::query()->find($linkedCaseId))) {
                return $linked->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            // Owner 2026-10-10: the device is already at the office (or on its
            // way): link this ticket to that case and close it, no new pickup.
            $recent = $this->recentRecoveryForCustomer($customer, (int) $lockedTicket->id);
            if ($recent) {
                return $this->linkTicketToRecentCase($lockedTicket, $recent, $actor);
            }

            $existingEvent = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $key)
                ->first();
            if ($existingEvent) {
                $existingCase = DeviceRecoveryCase::query()
                    ->whereKey($existingEvent->recovery_case_id)
                    ->where('ticket_id', $lockedTicket->id)
                    ->first();
                if (! $existingCase) {
                    $this->invalid('idempotency_key', 'Idempotency key sudah dipakai oleh tiket lain.');
                }

                return $existingCase->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $case = DeviceRecoveryCase::create([
                'ticket_id' => $lockedTicket->id,
                'customer_id' => $customer->id,
                'area_id' => $customer->area_id,
                'status' => self::STATUS_WAITING_ASSIGNMENT,
                'created_by' => $actor->id,
                'version' => 0,
                'meta' => [
                    'source' => $activity ? 'approved_dismantle' : ($isDismantleTicket ? 'dismantle_ticket' : 'pending_dismantle'),
                    'lifecycle_activity_id' => $activity?->id,
                ],
            ]);

            $expectedSerial = $this->normalizeSerial($customer->onu_serial);
            $item = $case->items()->create([
                'device_type' => $expectedSerial ? 'onu' : $deviceType,
                'expected_serial_number' => $expectedSerial,
                'expected_source' => $expectedSerial ? 'customer' : null,
                'status' => self::ITEM_PENDING,
                'condition' => self::CONDITION_UNKNOWN,
            ]);

            $this->appendEvent($case, $actor, 'case_created', null, self::STATUS_WAITING_ASSIGNMENT, null, $key, [
                'ticket_id' => $lockedTicket->id,
                'customer_id' => $customer->id,
                'item_id' => $item->id,
            ]);

            // Owner 2026-10-09: the pickup goes straight to the ticket's technician.
            $ticketAssignee = $this->ticketTechnician($lockedTicket);
            if ($ticketAssignee) {
                $this->applyAssignment($case, $actor, $ticketAssignee, null, $key.':assign', self::TICKET_ASSIGNEE_REASON, [
                    'source' => 'ticket_assignee',
                    'ticket_id' => $lockedTicket->id,
                ]);
            }

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    /**
     * Best-effort hook for ticket/lifecycle flows: make sure an eligible
     * dismantle ticket has its pickup (recovery) case. Never throws, so it can
     * not break ticket creation/approval; skipped in read-only recovery mode.
     */
    public function ensureForDismantleTicket(SupportTicket $ticket, User $actor, bool $notifyAssignee = true): ?DeviceRecoveryCase
    {
        if (config('device-recovery.mode') !== 'write' || ! $ticket->customer_id || ! $actor->isAdminLike()) {
            return null;
        }

        try {
            $existed = DeviceRecoveryCase::query()->where('ticket_id', $ticket->id)->exists();
            $case = $this->createFromApprovedDismantle($ticket, $actor);
        } catch (ValidationException) {
            return null;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if (! $existed && $notifyAssignee && $case->assigned_to && (int) $case->ticket_id === (int) $ticket->id) {
            $this->notifyAssignee($case);
        }

        return $case;
    }

    /**
     * Owner 2026-10-09: until pickup starts, the case follows the dismantle
     * ticket's technician (reassign moves it, unassign returns it to the
     * queue). Started/final cases are never touched. Returns the case only
     * when its assignee changed. Best-effort: never throws.
     */
    public function syncAssigneeFromTicket(SupportTicket $ticket, User $actor, bool $notifyAssignee = false): ?DeviceRecoveryCase
    {
        if (config('device-recovery.mode') !== 'write') {
            return null;
        }

        try {
            $case = DB::transaction(function () use ($ticket, $actor): ?DeviceRecoveryCase {
                $case = DeviceRecoveryCase::query()->where('ticket_id', $ticket->id)->lockForUpdate()->first();
                if (! $case || ! in_array($case->status, self::PRE_START_STATUSES, true)) {
                    return null;
                }

                $currentTicket = SupportTicket::query()->find($ticket->id);
                $targetId = $currentTicket?->assigned_to ? (int) $currentTicket->assigned_to : null;
                if ($targetId === ($case->assigned_to ? (int) $case->assigned_to : null)) {
                    return null;
                }

                $key = 'recovery:ticket-assignee:case:'.$case->id.':v'.$case->version.':to:'.($targetId ?? 'none');

                if ($targetId === null) {
                    $from = $case->status;
                    $previous = $case->assigned_to;
                    $previousSchedule = $case->scheduled_at?->toIso8601String();
                    $case->forceFill([
                        'assigned_to' => null,
                        'scheduled_at' => null,
                        'status' => self::STATUS_WAITING_ASSIGNMENT,
                        'updated_by' => $actor->id,
                        'version' => $case->version + 1,
                    ])->save();
                    $this->appendEvent($case, $actor, 'unassigned', $from, $case->status, 'Teknisi tiket dilepas', $key, [
                        'source' => 'ticket_assignee',
                        'ticket_id' => $ticket->id,
                        'previous_assigned_to' => $previous,
                        'previous_scheduled_at' => $previousSchedule,
                    ]);

                    return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
                }

                // A non-technician ticket assignee (admin/NOC) does not do the pickup.
                $assignee = $this->ticketTechnician($currentTicket);
                if (! $assignee) {
                    return null;
                }

                $this->applyAssignment($case, $actor, $assignee, $case->scheduled_at, $key, self::TICKET_ASSIGNEE_REASON, [
                    'source' => 'ticket_assignee',
                    'ticket_id' => $ticket->id,
                    'previous_assigned_to' => $case->assigned_to,
                ]);

                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            });
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        if ($case && $notifyAssignee && $case->assigned_to) {
            $this->notifyAssignee($case);
        }

        return $case;
    }

    /**
     * Backfill: assign a `waiting_assignment` case to its ticket's technician
     * (cases created before auto-assign). Same audit path as the live flow.
     *
     * @return string assigned|skipped_status|skipped_no_ticket_assignee|skipped_not_technician
     */
    public function assignFromTicket(DeviceRecoveryCase $recoveryCase, User $actor): string
    {
        return DB::transaction(function () use ($recoveryCase, $actor): string {
            $case = $this->lockedCase($recoveryCase);
            if ($case->status !== self::STATUS_WAITING_ASSIGNMENT) {
                return 'skipped_status';
            }

            $ticket = $case->ticket_id ? SupportTicket::query()->find($case->ticket_id) : null;
            if (! $ticket?->assigned_to) {
                return 'skipped_no_ticket_assignee';
            }
            $assignee = $this->ticketTechnician($ticket);
            if (! $assignee) {
                return 'skipped_not_technician';
            }

            $key = 'recovery:ticket-assignee:case:'.$case->id.':v'.$case->version.':to:'.$assignee->id;
            $this->applyAssignment($case, $actor, $assignee, null, $key, self::TICKET_ASSIGNEE_REASON, [
                'source' => 'ticket_assignee_backfill',
                'ticket_id' => $ticket->id,
            ]);

            return 'assigned';
        });
    }

    public function duplicateWindowDays(): int
    {
        return max(0, (int) config('device-recovery.duplicate_window_days', self::DUPLICATE_WINDOW_DAYS));
    }

    /**
     * The case that already holds this customer's device: carried or received
     * for this customer within the window, or the customer's ONU SN received
     * on any case within the window. Null when none (or the window is 0).
     */
    public function recentRecoveryForCustomer(Customer $customer, ?int $exceptTicketId = null): ?DeviceRecoveryCase
    {
        $days = $this->duplicateWindowDays();
        if ($days === 0) {
            return null;
        }
        $cutoff = now()->subDays($days);
        $base = fn () => DeviceRecoveryCase::query()
            ->when($exceptTicketId, fn ($query) => $query->where('ticket_id', '!=', $exceptTicketId));

        $byCustomer = $base()
            ->where('customer_id', $customer->id)
            ->where(fn ($query) => $query
                ->where(fn ($received) => $received
                    ->where('status', self::STATUS_RECEIVED)
                    ->where('received_at', '>=', $cutoff))
                ->orWhere(fn ($carried) => $carried
                    ->where('status', self::STATUS_CARRIED)
                    ->where('updated_at', '>=', $cutoff)))
            ->orderByDesc('id')
            ->first();
        if ($byCustomer) {
            return $byCustomer;
        }

        $serial = $this->normalizeSerial($customer->onu_serial);
        if (! $serial) {
            return null;
        }

        return $base()
            ->where('status', self::STATUS_RECEIVED)
            ->where('received_at', '>=', $cutoff)
            ->whereHas('items', fn ($query) => $query
                ->where('captured_serial_number', $serial)
                ->where('status', self::ITEM_RECEIVED))
            ->orderByDesc('id')
            ->first();
    }

    /** The earlier case a ticket was linked to by the duplicate check, if any. */
    public function linkedCaseForTicket(SupportTicket $ticket): ?DeviceRecoveryCase
    {
        $caseId = data_get($ticket->meta, self::TICKET_LINKED_META);

        return $caseId ? DeviceRecoveryCase::query()->with('ticket:id,code')->find($caseId) : null;
    }

    /**
     * Link a new dismantle ticket to the case that already holds the device:
     * note it on the ticket (meta + activity), audit it on the case, and close
     * the ticket through the same auto-close path. No assignment, no WA.
     * Runs inside the caller's transaction (ticket already locked).
     */
    private function linkTicketToRecentCase(SupportTicket $ticket, DeviceRecoveryCase $recent, User $actor): DeviceRecoveryCase
    {
        $case = $this->lockedCase($recent);
        $case->loadMissing('ticket:id,code');
        $state = $case->status === self::STATUS_RECEIVED ? 'sudah diterima gudang' : 'sedang dibawa teknisi';
        $sourceCode = $case->ticket?->code ?? ('#'.$case->ticket_id);
        $days = $this->duplicateWindowDays();

        $meta = is_array($ticket->meta) ? $ticket->meta : [];
        $meta[self::TICKET_LINKED_META] = $case->id;
        $ticket->forceFill(['meta' => $meta])->save();

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => self::TICKET_LINKED_ACTION,
            'new_value' => [
                'recovery_case_id' => $case->id,
                'recovery_ticket_id' => $case->ticket_id,
                'recovery_status' => $case->status,
                'window_days' => $days,
            ],
            'description' => mb_substr("Perangkat pelanggan {$state} (Pengambilan Perangkat #{$case->id}, tiket {$sourceCode}, dalam {$days} hari terakhir); pengambilan baru tidak dibuat.", 0, 255),
        ]);

        $close = $this->autoCloseTicket($case, $actor, 'perangkat '.$state.' pada pengambilan #'.$case->id, (int) $ticket->id);

        $this->appendEvent($case, $actor, 'duplicate_ticket_linked', $case->status, $case->status, null, 'recovery:link-ticket:'.$ticket->id, [
            'linked_ticket_id' => $ticket->id,
            'window_days' => $days,
            ...($close ?? []),
        ]);

        return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
    }

    /** Best-effort WA to the case's technician; never throws. */
    public function notifyAssignee(DeviceRecoveryCase $case): bool
    {
        try {
            return app(NotificationService::class)->sendRecoveryAssignment(
                $case->fresh(['ticket', 'customer', 'assignee', 'items']) ?? $case
            );
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    public function addItem(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        string $deviceType,
        ?string $expectedSerial,
        string $idempotencyKey,
    ): DeviceRecoveryItem {
        if (! in_array($deviceType, self::DEVICE_TYPES, true)) {
            $this->invalid('device_type', 'Jenis perangkat tidak valid.');
        }

        $key = $this->operationKey($idempotencyKey);
        $expected = $this->normalizeSerial($expectedSerial);

        return DB::transaction(function () use ($recoveryCase, $actor, $deviceType, $expected, $key): DeviceRecoveryItem {
            $case = $this->lockedCase($recoveryCase);
            $existingEvent = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $key)
                ->first();
            if ($existingEvent) {
                if ((int) $existingEvent->recovery_case_id !== (int) $case->id) {
                    $this->invalid('idempotency_key', 'Idempotency key sudah dipakai oleh recovery case lain.');
                }
                $itemId = (int) data_get($existingEvent->metadata, 'item_id', 0);
                if ($itemId <= 0) {
                    $this->invalid('idempotency_key', 'Idempotency event tidak memiliki item.');
                }

                return DeviceRecoveryItem::query()
                    ->where('recovery_case_id', $case->id)
                    ->findOrFail($itemId);
            }

            if (! in_array($case->status, [
                self::STATUS_WAITING_ASSIGNMENT,
                self::STATUS_ASSIGNED,
                self::STATUS_SCHEDULED,
            ], true)) {
                $this->invalid('status', 'Perangkat tambahan hanya dapat dimasukkan sebelum pengambilan dimulai.');
            }
            if ($expected && $case->items()->where('expected_serial_number', $expected)->exists()) {
                $this->invalid('expected_serial_number', 'SN expected sudah ada pada recovery case ini.');
            }

            $item = $case->items()->create([
                'device_type' => $deviceType,
                'expected_serial_number' => $expected,
                'expected_source' => $expected ? 'manual' : null,
                'status' => self::ITEM_PENDING,
                'condition' => self::CONDITION_UNKNOWN,
            ]);

            $case->forceFill([
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'item_added', $case->status, $case->status, null, $key, [
                'item_id' => $item->id,
                'device_type' => $deviceType,
                'has_expected_serial' => (bool) $expected,
            ]);

            return $item->fresh();
        });
    }

    public function updateItem(
        DeviceRecoveryCase $recoveryCase,
        DeviceRecoveryItem $item,
        User $actor,
        string $deviceType,
        ?string $expectedSerial,
        string $idempotencyKey,
    ): DeviceRecoveryItem {
        if (! in_array($deviceType, self::DEVICE_TYPES, true)) {
            $this->invalid('device_type', 'Jenis perangkat tidak valid.');
        }

        $key = $this->operationKey($idempotencyKey);
        $expected = $this->normalizeSerial($expectedSerial);

        return DB::transaction(function () use ($recoveryCase, $item, $actor, $deviceType, $expected, $key): DeviceRecoveryItem {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'item_updated')) {
                $event = DeviceRecoveryEvent::query()->where('idempotency_key', $key)->first();
                $itemId = (int) data_get($event?->metadata, 'item_id', 0);
                if ($itemId <= 0) {
                    $this->invalid('idempotency_key', 'Idempotency event tidak memiliki item.');
                }

                return DeviceRecoveryItem::query()
                    ->where('recovery_case_id', $case->id)
                    ->findOrFail($itemId);
            }

            $lockedItem = DeviceRecoveryItem::query()
                ->where('recovery_case_id', $case->id)
                ->lockForUpdate()
                ->find($item->id);
            if (! $lockedItem) {
                throw (new ModelNotFoundException)->setModel(DeviceRecoveryItem::class, [$item->id]);
            }
            if ($lockedItem->status !== self::ITEM_PENDING) {
                $this->invalid('status', 'Hasil perangkat yang sudah dicatat tidak dapat ditimpa.');
            }
            if (! in_array($case->status, [
                self::STATUS_WAITING_ASSIGNMENT,
                self::STATUS_ASSIGNED,
                self::STATUS_SCHEDULED,
            ], true)) {
                $this->invalid('status', 'Perangkat hanya dapat diedit sebelum pengambilan dimulai.');
            }
            if ($expected && $case->items()
                ->whereKeyNot($lockedItem->id)
                ->where('expected_serial_number', $expected)
                ->exists()) {
                $this->invalid('expected_serial_number', 'SN expected sudah ada pada recovery case ini.');
            }

            $fromDeviceType = $lockedItem->device_type;
            $fromExpected = $lockedItem->expected_serial_number;
            $lockedItem->forceFill([
                'device_type' => $deviceType,
                'expected_serial_number' => $expected,
                'expected_source' => $expected ? 'manual' : null,
            ])->save();
            $case->forceFill([
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'item_updated', $case->status, $case->status, null, $key, [
                'item_id' => $lockedItem->id,
                'from_device_type' => $fromDeviceType,
                'to_device_type' => $deviceType,
                'had_expected_serial' => (bool) $fromExpected,
                'has_expected_serial' => (bool) $expected,
            ]);

            return $lockedItem->fresh();
        });
    }

    public function assign(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        int $assigneeId,
        ?CarbonInterface $scheduledAt,
        string $idempotencyKey,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);

        return DB::transaction(function () use ($recoveryCase, $actor, $assigneeId, $scheduledAt, $key): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'assigned')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $assignee = User::query()->whereKey($assigneeId)->first();
            if (! $assignee || ! $assignee->isTeknisi()) {
                $this->invalid('assigned_to', 'Recovery hanya dapat ditugaskan kepada teknisi.');
            }

            if (! in_array($case->status, [
                self::STATUS_WAITING_ASSIGNMENT,
                self::STATUS_ASSIGNED,
                self::STATUS_SCHEDULED,
            ], true)) {
                $this->invalid('status', 'Penugasan hanya dapat diubah sebelum pengambilan dimulai.');
            }
            if ($scheduledAt?->isPast()) {
                $this->invalid('scheduled_at', 'Jadwal pengambilan harus berada di masa depan.');
            }

            $this->applyAssignment($case, $actor, $assignee, $scheduledAt, $key);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function schedule(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        CarbonInterface $scheduledAt,
        string $idempotencyKey,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        if ($scheduledAt->isPast()) {
            $this->invalid('scheduled_at', 'Jadwal pengambilan harus berada di masa depan.');
        }

        return DB::transaction(function () use ($recoveryCase, $actor, $scheduledAt, $key): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'scheduled')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $this->assertMutable($case);
            if (! $case->assigned_to || ! in_array($case->status, [self::STATUS_ASSIGNED, self::STATUS_SCHEDULED], true)) {
                $this->invalid('status', 'Jadwal hanya dapat dibuat setelah teknisi ditugaskan.');
            }

            $from = $case->status;
            $case->forceFill([
                'scheduled_at' => $scheduledAt,
                'status' => self::STATUS_SCHEDULED,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'scheduled', $from, $case->status, null, $key, [
                'scheduled_at' => $scheduledAt->toIso8601String(),
            ]);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function start(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        string $idempotencyKey,
        ?string $overrideReason = null,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $overrideReason = $this->safeReason($overrideReason, 'reason');

        return DB::transaction(function () use ($recoveryCase, $actor, $key, $overrideReason): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'pickup_started')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $this->assertMutable($case);
            if (! in_array($case->status, [self::STATUS_ASSIGNED, self::STATUS_SCHEDULED], true)) {
                $this->invalid('status', 'Pengambilan hanya dapat dimulai setelah teknisi ditugaskan.');
            }
            $isSuperAdminOverride = $actor->isSuperAdmin() && (int) $case->assigned_to !== (int) $actor->id;
            if (! $actor->isSuperAdmin() && (int) $case->assigned_to !== (int) $actor->id) {
                abort(403);
            }
            if ($isSuperAdminOverride && mb_strlen(trim((string) $overrideReason)) < 5) {
                $this->invalid('reason', 'Alasan override superadmin wajib diisi minimal 5 karakter.');
            }

            $from = $case->status;
            $case->forceFill([
                'status' => self::STATUS_PICKUP_STARTED,
                'started_at' => $case->started_at ?: now(),
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();

            $this->appendEvent($case, $actor, 'pickup_started', $from, $case->status, $isSuperAdminOverride ? trim((string) $overrideReason) : null, $key, $isSuperAdminOverride ? ['superadmin_override' => true] : []);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function capture(
        DeviceRecoveryCase $recoveryCase,
        DeviceRecoveryItem $item,
        User $actor,
        ?string $serialNumber,
        string $condition,
        ?string $reason,
        array $photoPaths,
        string $idempotencyKey,
        ?string $captureToken = null,
        array $photoKinds = [],
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');

        return DB::transaction(function () use ($recoveryCase, $item, $actor, $serialNumber, $condition, $reason, $photoPaths, $key, $captureToken, $photoKinds): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, ['serial_captured', 'pickup_exception'])) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $lockedItem = DeviceRecoveryItem::query()
                ->where('recovery_case_id', $case->id)
                ->lockForUpdate()
                ->find($item->id);
            if (! $lockedItem) {
                throw (new ModelNotFoundException)->setModel(DeviceRecoveryItem::class, [$item->id]);
            }
            if ($lockedItem->status !== self::ITEM_PENDING) {
                $this->invalid('status', 'Hasil perangkat ini sudah dicatat dan tidak dapat ditimpa.');
            }

            if (! $actor->isSuperAdmin() && (int) $case->assigned_to !== (int) $actor->id) {
                abort(403);
            }
            $isSuperAdminOverride = $actor->isSuperAdmin() && (int) $case->assigned_to !== (int) $actor->id;
            if ($isSuperAdminOverride && mb_strlen(trim((string) $reason)) < 5) {
                $this->invalid('reason', 'Alasan override superadmin wajib diisi minimal 5 karakter.');
            }
            if (! in_array($case->status, [self::STATUS_PICKUP_STARTED, self::STATUS_EXCEPTION], true)) {
                $this->invalid('status', 'SN hanya dapat dicatat setelah pengambilan dimulai.');
            }
            if (! in_array($condition, self::CONDITIONS, true)) {
                $this->invalid('condition', 'Kondisi perangkat tidak valid.');
            }

            $capturedSerial = $this->normalizeSerial($serialNumber);
            if ($condition === self::CONDITION_GOOD && ! $capturedSerial) {
                $this->invalid('serial_number', 'SN wajib diisi untuk perangkat yang cocok dan baik.');
            }
            if ($lockedItem->expected_serial_number && $capturedSerial && $lockedItem->expected_serial_number !== $capturedSerial) {
                $condition = self::CONDITION_MISMATCH;
            }
            if ($capturedSerial && $this->serialIsActiveElsewhere($capturedSerial, $lockedItem->id, $case->id)) {
                $this->invalid('serial_number', 'SN sedang tercatat pada recovery case lain yang masih aktif.');
            }

            $exception = $condition !== self::CONDITION_GOOD;
            if ($exception && mb_strlen(trim((string) $reason)) < 5) {
                $this->invalid('reason', 'Alasan wajib diisi untuk perangkat exception.');
            }
            if (count($photoPaths) > 5) {
                $this->invalid('photos', 'Maksimal 5 foto recovery.');
            }

            $itemMeta = is_array($lockedItem->meta) ? $lockedItem->meta : [];
            unset($itemMeta['photo_kinds']);
            if ($photoKinds !== []) {
                $itemMeta['photo_kinds'] = array_values($photoKinds);
            }
            $lockedItem->forceFill([
                'captured_serial_number' => $capturedSerial,
                'status' => $exception ? self::ITEM_EXCEPTION : self::ITEM_CARRIED,
                'condition' => $condition,
                'captured_by' => $actor->id,
                'captured_at' => now(),
                'photo_paths' => array_values($photoPaths),
                'notes' => $reason ? trim($reason) : null,
                'meta' => $itemMeta === [] ? null : $itemMeta,
            ])->save();

            $items = $case->items()->lockForUpdate()->get();
            $hasException = $items->contains(fn (DeviceRecoveryItem $item): bool => $item->status === self::ITEM_EXCEPTION);
            $allCarried = $items->isNotEmpty() && $items->every(fn (DeviceRecoveryItem $item): bool => $item->status === self::ITEM_CARRIED);

            $from = $case->status;
            $to = $hasException
                ? self::STATUS_EXCEPTION
                : ($allCarried ? self::STATUS_CARRIED : self::STATUS_PICKUP_STARTED);
            $exceptionReason = $hasException
                ? ($reason ?: $case->exception_reason)
                : null;
            $case->forceFill([
                'status' => $to,
                'exception_reason' => $exceptionReason,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();

            $this->appendEvent(
                $case,
                $actor,
                $exception ? 'pickup_exception' : 'serial_captured',
                $from,
                $to,
                $reason,
                $key,
                [
                    'item_id' => $lockedItem->id,
                    'condition' => $condition,
                    'has_serial' => (bool) $capturedSerial,
                    ...($isSuperAdminOverride ? ['superadmin_override' => true] : []),
                    ...($captureToken ? ['capture_token' => $captureToken] : []),
                ],
            );

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    /**
     * Owner 2026-10-09: the technician records the pickup in ONE form (no
     * separate "Mulai pengambilan" step). From assigned/scheduled the start and
     * the capture run in one transaction and both audit events are kept.
     *
     * Photos are required: >= 1 `label_sn` and >= 1 `perangkat_utuh`. Only for
     * condition `lost` (no device to photograph) they may be absent; the reason
     * is then mandatory and the case goes to exception review as before.
     *
     * @param  list<array{path: string, kind: string}>  $photos
     */
    public function recordPickup(
        DeviceRecoveryCase $recoveryCase,
        DeviceRecoveryItem $item,
        User $actor,
        ?string $serialNumber,
        string $condition,
        ?string $reason,
        array $photos,
        string $idempotencyKey,
        ?string $captureToken = null,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $this->assertPickupPhotos($condition, $photos);

        return DB::transaction(function () use ($recoveryCase, $item, $actor, $serialNumber, $condition, $reason, $photos, $key, $captureToken): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, ['serial_captured', 'pickup_exception'])) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            if (in_array($case->status, [self::STATUS_ASSIGNED, self::STATUS_SCHEDULED], true)) {
                $case = $this->start($case, $actor, 'pickup-start:'.hash('sha256', $key), $reason);
            }

            return $this->capture(
                $case,
                $item,
                $actor,
                $serialNumber,
                $condition,
                $reason,
                array_column($photos, 'path'),
                $key,
                $captureToken,
                array_column($photos, 'kind'),
            );
        });
    }

    /**
     * Owner 2026-10-10: "Lapor pengambilan tanpa tiket". A technician who
     * already collected a device (emergency, no admin ticket yet) reports it:
     * one transaction creates the Dismantle ticket (created by + assigned to
     * the technician, no assignment WA) and its case straight in `carried`
     * (or `exception` for mismatch/damaged/unknown/lost) with the captured
     * item. The admin then confirms with the normal warehouse receipt.
     *
     * Rejected when the customer already has an unfinished case, or when the
     * duplicate window applies (device already carried/received recently).
     *
     * @param  list<array{path: string, kind: string}>  $photos
     * @param  array<string, mixed>  $metadata  extra event metadata
     */
    public function reportWithoutTicket(
        User $technician,
        Customer $customer,
        ?string $serialNumber,
        string $condition,
        ?string $notes,
        string $emergencyReason,
        array $photos,
        string $idempotencyKey,
        array $metadata = [],
    ): DeviceRecoveryCase {
        if (! $technician->isTeknisi()) {
            abort(403);
        }
        $key = $this->operationKey($idempotencyKey);
        $notes = $this->safeReason($notes, 'reason');
        $emergencyReason = $this->safeReason($emergencyReason, 'emergency_reason');
        if (mb_strlen((string) $emergencyReason) < 10) {
            $this->invalid('emergency_reason', 'Alasan darurat wajib diisi minimal 10 karakter.');
        }
        if (! in_array($condition, self::CONDITIONS, true)) {
            $this->invalid('condition', 'Kondisi perangkat tidak valid.');
        }
        $this->assertPickupPhotos($condition, $photos);

        return DB::transaction(function () use ($technician, $customer, $serialNumber, $condition, $notes, $emergencyReason, $photos, $key, $metadata): DeviceRecoveryCase {
            $replay = DeviceRecoveryEvent::query()->where('idempotency_key', $key)->first();
            if ($replay) {
                $case = DeviceRecoveryCase::query()->find($replay->recovery_case_id);
                if (! $case || (int) $case->created_by !== (int) $technician->id || ! in_array($replay->event_type, ['serial_captured', 'pickup_exception'], true)) {
                    $this->invalid('idempotency_key', 'Idempotency key sudah dipakai untuk aksi recovery yang berbeda.');
                }

                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            // Serialise reports for the same customer.
            $lockedCustomer = Customer::query()->lockForUpdate()->find($customer->id);
            if (! $lockedCustomer) {
                throw (new ModelNotFoundException)->setModel(Customer::class, [$customer->id]);
            }

            $open = DeviceRecoveryCase::query()
                ->where('customer_id', $lockedCustomer->id)
                ->whereNotIn('status', self::FINAL_STATUSES)
                ->orderByDesc('id')
                ->first();
            if ($open) {
                $this->invalid('customer_id', 'Pelanggan ini sudah punya Pengambilan Perangkat yang masih berjalan (#'.$open->id.'). Catat lewat kasus itu atau hubungi admin.');
            }
            $recent = $this->recentRecoveryForCustomer($lockedCustomer);
            if ($recent) {
                $this->invalid('customer_id', 'Perangkat pelanggan ini sudah tercatat dibawa/diterima gudang (#'.$recent->id.') dalam '.$this->duplicateWindowDays().' hari terakhir. Hubungi admin.');
            }

            $capturedSerial = $this->normalizeSerial($serialNumber);
            if ($condition === self::CONDITION_GOOD && ! $capturedSerial) {
                $this->invalid('serial_number', 'SN wajib diisi untuk perangkat yang baik.');
            }
            $expectedSerial = $this->normalizeSerial($lockedCustomer->onu_serial);
            if ($expectedSerial && $capturedSerial && ! $this->serialsMatch($expectedSerial, $capturedSerial)) {
                $condition = self::CONDITION_MISMATCH;
            }
            if ($capturedSerial && $this->serialIsActiveElsewhere($capturedSerial, 0, 0)) {
                $this->invalid('serial_number', 'SN sedang tercatat pada recovery case lain yang masih aktif.');
            }
            $exception = $condition !== self::CONDITION_GOOD;
            if ($exception && mb_strlen(trim((string) $notes)) < 5) {
                $this->invalid('reason', $condition === self::CONDITION_MISMATCH
                    ? 'SN tidak cocok dengan SN pelanggan ('.$expectedSerial.'): isi catatan minimal 5 karakter.'
                    : 'Catatan wajib diisi untuk perangkat rusak, hilang, atau SN tidak terbaca.');
            }

            $now = now();
            $ticket = SupportTicket::create([
                'customer_id' => $lockedCustomer->id,
                'customer_name' => $lockedCustomer->name,
                'customer_phone' => $lockedCustomer->phone,
                'customer_coords' => $lockedCustomer->geo_lat && $lockedCustomer->geo_long
                    ? $lockedCustomer->geo_lat.','.$lockedCustomer->geo_long
                    : null,
                'gangguan' => self::TECHNICIAN_REPORT_SUBJECT,
                'subject' => self::TECHNICIAN_REPORT_SUBJECT,
                'keterangan' => 'Alasan darurat (teknisi): '.$emergencyReason,
                'type' => self::DISMANTLE_TICKET_TYPE,
                'priority' => 'normal',
                'status' => 'in_progress',
                'created_by' => $technician->id,
                'assigned_to' => $technician->id,
                'assigned_at' => $now,
                'dismantle_reason' => DismantleReason::OTHER,
                'dismantle_reason_note' => mb_substr('Lapor teknisi tanpa tiket: '.$emergencyReason, 0, DismantleReason::NOTE_MAX),
                'meta' => ['technician_report' => true],
            ]);
            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $technician->id,
                'action' => 'created',
                'new_value' => ['technician_report' => true, 'assignee_id' => $technician->id],
                'description' => mb_substr('Tiket dibuat dari "Lapor pengambilan tanpa tiket" oleh teknisi '.$technician->name.'; menunggu konfirmasi admin (Diterima gudang).', 0, 255),
            ]);
            DismantleReason::recordChange($ticket, null, null, $technician, 'technician_report');

            $status = $exception ? self::STATUS_EXCEPTION : self::STATUS_CARRIED;
            $case = DeviceRecoveryCase::create([
                'ticket_id' => $ticket->id,
                'customer_id' => $lockedCustomer->id,
                'area_id' => $lockedCustomer->area_id,
                'status' => $status,
                'assigned_to' => $technician->id,
                'created_by' => $technician->id,
                'updated_by' => $technician->id,
                'started_at' => $now,
                'exception_reason' => $exception ? $notes : null,
                'version' => 1,
                'meta' => [
                    'source' => 'technician_report',
                    'emergency_reason' => $emergencyReason,
                ],
            ]);
            $item = $case->items()->create([
                'device_type' => 'onu',
                'expected_serial_number' => $expectedSerial,
                'expected_source' => $expectedSerial ? 'customer' : null,
                'captured_serial_number' => $capturedSerial,
                'status' => $exception ? self::ITEM_EXCEPTION : self::ITEM_CARRIED,
                'condition' => $condition,
                'captured_by' => $technician->id,
                'captured_at' => $now,
                'photo_paths' => array_column($photos, 'path'),
                'notes' => $notes,
                'meta' => [
                    'photo_kinds' => array_column($photos, 'kind'),
                    'technician_report' => true,
                ],
            ]);

            $this->appendEvent($case, $technician, 'case_created', null, $status, $emergencyReason, 'report-create:'.hash('sha256', $key), [
                'ticket_id' => $ticket->id,
                'customer_id' => $lockedCustomer->id,
                'item_id' => $item->id,
                'source' => 'technician_report',
            ]);
            $this->appendEvent($case, $technician, $exception ? 'pickup_exception' : 'serial_captured', null, $status, $notes, $key, [
                ...$metadata,
                'item_id' => $item->id,
                'condition' => $condition,
                'has_serial' => (bool) $capturedSerial,
                'technician_report' => true,
            ]);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function receive(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        string $idempotencyKey,
        ?string $reason = null,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');

        // Roadmap 1.3 PR-4b: guard verdict BEFORE the transaction (Grafana may take seconds).
        $decision = $this->receiptPreVerdict($recoveryCase, [self::STATUS_CARRIED]);

        $case = DB::transaction(fn (): DeviceRecoveryCase => $this->receiveLocked($this->lockedCase($recoveryCase), $actor, $reason, $key, [], $decision));
        $this->afterReceive($case, $key);

        return $case;
    }

    /**
     * Owner 2026-10-09: "Diterima gudang" requires the physical SN of every
     * carried item (typed or scanned) to match the SN captured by the
     * technician, plus >= 1 receipt photo (kind `penerimaan`).
     *
     * A mismatch is rejected with a clear error (a typo must not push the case
     * into exception review). Only when the admin explicitly submits it as an
     * exception does the case move to `exception` ("SN gudang tidak cocok").
     *
     * @param  array<int|string, string|null>  $physicalSerials  item id => SN
     * @param  list<string>  $receiptPhotoPaths
     * @param  array<string, mixed>  $metadata  extra event metadata
     */
    public function receiveVerified(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        array $physicalSerials,
        array $receiptPhotoPaths,
        bool $submitMismatchAsException,
        string $idempotencyKey,
        ?string $reason = null,
        array $metadata = [],
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');
        if ($receiptPhotoPaths === []) {
            $this->invalid('receipt_photos', 'Foto penerimaan gudang wajib diunggah minimal 1.');
        }
        if (count($receiptPhotoPaths) > 3) {
            $this->invalid('receipt_photos', 'Foto penerimaan gudang maksimal 3.');
        }

        $decision = $this->receiptPreVerdict($recoveryCase, [self::STATUS_CARRIED]);

        $case = DB::transaction(function () use ($recoveryCase, $actor, $physicalSerials, $receiptPhotoPaths, $submitMismatchAsException, $key, $reason, $metadata, $decision): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, ['warehouse_received', 'warehouse_serial_mismatch'])) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $this->assertMutable($case);
            $items = $case->items()->lockForUpdate()->orderBy('id')->get();
            if ($case->status !== self::STATUS_CARRIED || $items->isEmpty() || $items->contains(fn (DeviceRecoveryItem $item): bool => $item->status !== self::ITEM_CARRIED)) {
                $this->invalid('status', 'Perangkat belum berada pada status dibawa teknisi.');
            }

            $physical = [];
            $mismatches = [];
            foreach ($items as $item) {
                $serial = $this->normalizeSerial($physicalSerials[$item->id] ?? $physicalSerials[(string) $item->id] ?? null);
                if ($serial === null) {
                    $this->invalid('serials.'.$item->id, 'SN fisik perangkat wajib diketik atau di-scan.');
                }
                $physical[$item->id] = $serial;
                if (! $this->serialsMatch($serial, $item->captured_serial_number)) {
                    $mismatches[$item->id] = $serial;
                }
            }

            if ($mismatches !== [] && ! $submitMismatchAsException) {
                $firstMismatch = $items->firstWhere('id', array_key_first($mismatches));
                $this->invalid('serials', 'SN fisik ('.reset($mismatches).') tidak cocok dengan SN yang dicatat teknisi ('
                    .($firstMismatch?->captured_serial_number ?: '-').'). Periksa ulang, atau ajukan sebagai exception.');
            }

            // Receipt photos are case-level evidence; kept on the first item so
            // the existing signed photo route serves them.
            $first = $items->first();
            $this->appendItemPhotos($first, $receiptPhotoPaths, self::PHOTO_KIND_RECEIPT);
            foreach ($items as $item) {
                $item->refresh();
                $itemMeta = is_array($item->meta) ? $item->meta : [];
                $itemMeta['warehouse_serial'] = $physical[$item->id];
                $item->forceFill(['meta' => $itemMeta])->save();
            }

            $eventMetadata = [
                ...$metadata,
                'warehouse_serial_verified' => $mismatches === [],
                'receipt_photo_count' => count($receiptPhotoPaths),
            ];

            if ($mismatches === []) {
                return $this->receiveLocked($case, $actor, $reason, $key, $eventMetadata, $decision);
            }

            foreach ($items as $item) {
                if (! array_key_exists($item->id, $mismatches)) {
                    continue;
                }
                $item->forceFill([
                    'status' => self::ITEM_EXCEPTION,
                    'condition' => self::CONDITION_MISMATCH,
                ])->save();
            }
            $exceptionReason = 'SN gudang tidak cocok: '.collect($mismatches)
                ->map(fn (string $serial, int $itemId): string => 'fisik '.$serial.', dicatat '.($items->firstWhere('id', $itemId)?->captured_serial_number ?: '-'))
                ->implode('; ');
            if ($reason) {
                $exceptionReason .= ' - '.$reason;
            }
            $from = $case->status;
            $case->forceFill([
                'status' => self::STATUS_EXCEPTION,
                'exception_reason' => $exceptionReason,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'warehouse_serial_mismatch', $from, $case->status, $exceptionReason, $key, [
                ...$eventMetadata,
                'mismatched_item_ids' => array_keys($mismatches),
            ]);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });

        if ($case->status === self::STATUS_RECEIVED) {
            $this->afterReceive($case, $key);
        }

        return $case;
    }

    /**
     * Owner 2026-10-09: admin records a device that was already collected
     * (technician did not use the app) and received in one step. Same photo
     * rules as the technician form. The audit trail keeps captured_by = the
     * chosen technician and verified_by/received_by = the admin, then the
     * normal post-receive effects run (terminate unless keep flag, auto-close
     * ticket, OLT cleanup dispatch). A non-good condition or an SN that does
     * not match the expected SN goes to exception instead of received.
     *
     * Single-device cases only; multi-device cases use the technician flow.
     *
     * @param  list<array{path: string, kind: string}>  $photos
     * @param  array<string, mixed>  $metadata  extra event metadata
     */
    public function quickRecord(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        int $technicianId,
        ?string $serialNumber,
        string $condition,
        ?string $reason,
        array $photos,
        string $idempotencyKey,
        array $metadata = [],
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');
        if (! in_array($condition, self::CONDITIONS, true)) {
            $this->invalid('condition', 'Kondisi perangkat tidak valid.');
        }
        $this->assertPickupPhotos($condition, $photos);

        // Owner Q4 (2026-10-15): the guard is evaluated regardless of device
        // condition; a bad device follows the same rule (termination depends on
        // service use, not on device condition).
        $decision = $this->receiptPreVerdict($recoveryCase, self::PRE_START_STATUSES);

        $case = DB::transaction(function () use ($recoveryCase, $actor, $technicianId, $serialNumber, $condition, $reason, $photos, $key, $metadata, $decision): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, ['warehouse_received', 'pickup_exception'])) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $this->assertMutable($case);
            if (! in_array($case->status, [
                self::STATUS_WAITING_ASSIGNMENT,
                self::STATUS_ASSIGNED,
                self::STATUS_SCHEDULED,
            ], true)) {
                $this->invalid('status', 'Catat langsung hanya untuk case yang belum dicatat teknisi (menunggu penugasan, ditugaskan, atau dijadwalkan).');
            }

            $technician = User::query()->whereKey($technicianId)->first();
            if (! $technician || ! $technician->isTeknisi()) {
                $this->invalid('technician_id', 'Pilih teknisi yang mengambil perangkat.');
            }

            $items = $case->items()->lockForUpdate()->get();
            if ($items->count() !== 1) {
                $this->invalid('status', 'Catat langsung hanya untuk case dengan 1 perangkat; gunakan form teknisi untuk multi-perangkat.');
            }
            $item = $items->first();
            if ($item->status !== self::ITEM_PENDING) {
                $this->invalid('status', 'Hasil perangkat ini sudah dicatat dan tidak dapat ditimpa.');
            }

            $capturedSerial = $this->normalizeSerial($serialNumber);
            if ($condition === self::CONDITION_GOOD && ! $capturedSerial) {
                $this->invalid('serial_number', 'SN wajib diisi untuk perangkat yang baik.');
            }
            if ($item->expected_serial_number && $capturedSerial && ! $this->serialsMatch($item->expected_serial_number, $capturedSerial)) {
                $condition = self::CONDITION_MISMATCH;
            }
            if ($capturedSerial && $this->serialIsActiveElsewhere($capturedSerial, $item->id, $case->id)) {
                $this->invalid('serial_number', 'SN sedang tercatat pada recovery case lain yang masih aktif.');
            }
            $exception = $condition !== self::CONDITION_GOOD;
            if ($exception && mb_strlen(trim((string) $reason)) < 5) {
                $this->invalid('reason', $condition === self::CONDITION_MISMATCH
                    ? 'SN tidak cocok dengan SN expected ('.$item->expected_serial_number.'): isi alasan minimal 5 karakter untuk mengajukan exception.'
                    : 'Alasan wajib diisi untuk perangkat exception.');
            }

            $now = now();
            $itemMeta = is_array($item->meta) ? $item->meta : [];
            $itemMeta['photo_kinds'] = array_column($photos, 'kind');
            $itemMeta['admin_quick_record'] = true;
            $item->forceFill([
                'captured_serial_number' => $capturedSerial,
                'status' => $exception ? self::ITEM_EXCEPTION : self::ITEM_CARRIED,
                'condition' => $condition,
                'captured_by' => $technician->id,
                'captured_at' => $now,
                'photo_paths' => array_column($photos, 'path'),
                'notes' => $reason,
                'meta' => $itemMeta,
            ])->save();

            $from = $case->status;
            $previousAssignee = $case->assigned_to;
            $to = $exception ? self::STATUS_EXCEPTION : self::STATUS_CARRIED;
            $case->forceFill([
                'assigned_to' => $technician->id,
                'started_at' => $case->started_at ?: $now,
                'status' => $to,
                'exception_reason' => $exception ? $reason : null,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            // Owner 2026-10-10: the dismantle ticket is credited to the same
            // technician (ticket assignee), without the assignment WA.
            $ticketAttribution = $this->attributeTicketToTechnician($case, $actor, $technician);
            $this->appendEvent(
                $case,
                $actor,
                $exception ? 'pickup_exception' : 'serial_captured',
                $from,
                $to,
                $reason,
                $exception ? $key : 'quick-capture:'.hash('sha256', $key),
                [
                    ...$metadata,
                    'item_id' => $item->id,
                    'condition' => $condition,
                    'has_serial' => (bool) $capturedSerial,
                    'admin_quick_record' => true,
                    'captured_by' => $technician->id,
                    'previous_assignee' => $previousAssignee,
                    ...($ticketAttribution ?? []),
                ],
            );

            if ($exception) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            return $this->receiveLocked($case, $actor, $reason, $key, [
                ...$metadata,
                'admin_quick_record' => true,
                'captured_by' => $technician->id,
            ], $decision, 'warehouse_quick_record');
        });

        if ($case->status === self::STATUS_RECEIVED) {
            $this->afterReceive($case, $key);
        }

        return $case;
    }

    /**
     * Same SN after normalization (uppercase, alnum only; covers MAC forms
     * like AA:BB:CC:DD:EE:FF). A GPON SN printed as vendor + 8 hex
     * (FHTT0000A001) also matches its 16-hex form (464854540000A001).
     */
    public function serialsMatch(?string $first, ?string $second): bool
    {
        $first = $this->normalizeSerial($first);
        $second = $this->normalizeSerial($second);
        if ($first === null || $second === null) {
            return false;
        }

        return $first === $second || $this->gponHexSerial($first) === $this->gponHexSerial($second);
    }

    private function gponHexSerial(string $serial): string
    {
        if (preg_match('/^([A-Z]{4})([0-9A-F]{8})$/', $serial, $matches) === 1) {
            return strtoupper(bin2hex($matches[1])).$matches[2];
        }

        return $serial;
    }

    /**
     * @param  list<string>  $paths
     */
    private function appendItemPhotos(DeviceRecoveryItem $item, array $paths, string $kind): void
    {
        $existing = is_array($item->photo_paths) ? array_values($item->photo_paths) : [];
        $meta = is_array($item->meta) ? $item->meta : [];
        $kinds = is_array($meta['photo_kinds'] ?? null) ? array_values($meta['photo_kinds']) : [];
        // Legacy photos have no kind: pad so indices stay aligned.
        $kinds = array_pad(array_slice($kinds, 0, count($existing)), count($existing), null);
        foreach ($paths as $path) {
            $existing[] = $path;
            $kinds[] = $kind;
        }
        $meta['photo_kinds'] = $kinds;
        $item->forceFill(['photo_paths' => $existing, 'meta' => $meta])->save();
    }

    /**
     * Body of a warehouse receipt; caller holds the case lock in a transaction.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function receiveLocked(DeviceRecoveryCase $case, User $actor, ?string $reason, string $key, array $metadata = [], ?GuardDecision $decision = null, string $source = 'warehouse_receive'): DeviceRecoveryCase
    {
        if ($this->eventExistsFor($key, $case, 'warehouse_received')) {
            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        }

        $this->assertMutable($case);
        $items = $case->items()->lockForUpdate()->get();
        if ($case->status !== self::STATUS_CARRIED || $items->isEmpty() || $items->contains(fn (DeviceRecoveryItem $item): bool => $item->status !== self::ITEM_CARRIED)) {
            $this->invalid('status', 'Perangkat belum berada pada status dibawa teknisi.');
        }

        $now = now();
        foreach ($items as $item) {
            $item->forceFill([
                'status' => self::ITEM_RECEIVED,
                'verified_by' => $actor->id,
                'verified_at' => $now,
            ])->save();
        }

        $from = $case->status;
        $case->forceFill([
            'status' => self::STATUS_RECEIVED,
            'received_at' => $now,
            'updated_by' => $actor->id,
            'version' => $case->version + 1,
        ])->save();
        // Owner 2026-10-09: device collected => customer terminated (unless
        // the case is flagged keep_customer_active). Same transaction.
        // Roadmap 1.3 PR-4b: only when the lifecycle guard allows; otherwise the
        // termination is deferred (pending row, billing hold) and the receipt still succeeds.
        $termination = $this->terminateCustomerAfterReceive($case, $actor, $decision, $source);
        // Owner 2026-10-09: the dismantle ticket closes itself once received.
        $ticketClose = $this->autoCloseTicket($case, $actor, 'perangkat diterima gudang');
        $this->appendEvent($case, $actor, 'warehouse_received', $from, $case->status, $reason, $key, [
            ...$metadata,
            ...($termination ?? []),
            ...($ticketClose ?? []),
        ]);

        return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
    }

    /**
     * One-off recap import (`device-recovery:import-recap`): run the normal
     * post-receive effects (customer -> terminated unless keep_customer_active,
     * dismantle ticket auto-close) for a case the caller has just put in
     * `received`. Never dispatches OLT cleanup and never sends a notification.
     * Caller holds the transaction.
     *
     * @return array<string, mixed> event metadata
     */
    public function applyRecapReceiveEffects(DeviceRecoveryCase $case, User $actor, ?GuardDecision $decision = null): array
    {
        return [
            ...($this->terminateCustomerAfterReceive($case, $actor, $decision, 'recap_receive') ?? []),
            ...($this->autoCloseTicket($case, $actor, 'impor rekap gudang') ?? []),
        ];
    }

    /**
     * Guard verdict for the customer of a case about to be received. Runs
     * OUTSIDE the receipt transaction. Null = nothing to evaluate (flagged
     * keep_customer_active, no customer, customer not terminable, case not in
     * a receivable status); a null verdict for a terminable customer later
     * defers fail-closed (guard:not_evaluated).
     *
     * @param  list<string>  $statuses  case statuses from which this receipt can run
     */
    private function receiptPreVerdict(DeviceRecoveryCase $recoveryCase, array $statuses): ?GuardDecision
    {
        try {
            $case = DeviceRecoveryCase::query()->find($recoveryCase->id);
            if (! $case || ! in_array($case->status, $statuses, true) || (bool) ($case->keep_customer_active ?? false) || ! $case->customer_id) {
                return null;
            }
            $customer = Customer::withTrashed()->find($case->customer_id);
            if (! $customer || ! in_array((string) $customer->status, self::TERMINATE_ON_RECEIVE_FROM, true)) {
                return null;
            }

            return app(LifecycleTerminationService::class)->preVerdict($customer, GuardContext::automated('device_recovery_receive'));
        } catch (\Throwable $e) {
            Log::warning('[device-recovery] receipt guard pre-check failed', ['case_id' => $recoveryCase->id, 'exception_class' => $e::class]);

            return null;
        }
    }

    /** After commit: ops WA for a deferral, else the optional OLT cleanup dispatch. */
    private function afterReceive(DeviceRecoveryCase $case, string $key): void
    {
        $event = DeviceRecoveryEvent::query()
            ->where('idempotency_key', $key)
            ->where('recovery_case_id', $case->id)
            ->where('event_type', 'warehouse_received')
            ->first();
        $meta = (array) ($event?->metadata ?? []);

        if (($meta['customer_termination_deferred'] ?? false) !== true) {
            $this->dispatchCleanupAfterReceive($case);

            return;
        }

        // Deferred: no ONU delete now (the sweeper dispatches it after the guard passes).
        $this->notifyTerminationDeferred($case, $meta);
    }

    /** Text T3 (owner approved), once per customer per day; never breaks the receipt. */
    private function notifyTerminationDeferred(DeviceRecoveryCase $case, array $meta): void
    {
        try {
            $customerId = (int) ($meta['customer_id'] ?? 0);
            $code = Customer::withTrashed()->whereKey($customerId)->value('code') ?: '#'.$customerId;
            $message = 'Terminate DITUNDA: pelanggan '.$code.' (Pengambilan #'.$case->id.') — perangkat sudah diterima gudang tapi masih terdeteksi dipakai: '
                .self::deferralReasonText($meta).' Tagihan baru ditahan, tidak diisolir. Dicek ulang otomatis tiap jam.';
            app(OpsAlertService::class)->send($message, 'deferred-termination:'.$customerId.':'.now()->setTimezone('Asia/Jakarta')->format('Ymd'), 86400, '', OpsAlertSource::DEFERRED_TERMINATION_CASE);
        } catch (\Throwable $e) {
            Log::warning('[device-recovery] deferred termination WA failed', ['case_id' => $case->id, 'exception_class' => $e::class]);
        }
    }

    /** "{alasan}" of T1/T3: the guard message, ending with a period; falls back to the reason labels. */
    public static function deferralReasonText(array $meta): string
    {
        $message = trim((string) ($meta['guard_message'] ?? ''));
        if ($message === '') {
            $message = LifecycleTerminationService::reasonLabel((array) ($meta['guard_reasons'] ?? []));
        }

        return rtrim($message, ". \t\n").'.';
    }

    private function dispatchCleanupAfterReceive(DeviceRecoveryCase $case): void
    {
        // Optional, fail-closed OLT ONU cleanup once the dismantle is complete.
        // Disabled by default; only dispatched when the kill switch + write mode
        // are both enabled, so the default behavior stays append-only.
        if (app(DeviceRecoveryOltCleanupService::class)->isEnabled()) {
            DeleteDismantledOnuJob::dispatch($case);
        }
    }

    /**
     * Flag a case so warehouse receipt does NOT terminate the customer (e.g. a
     * home with two devices where only the extra one is collected). Allowed
     * until the case is final; idempotent and audited.
     */
    public function setKeepCustomerActive(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        bool $keep,
        ?string $reason,
        string $idempotencyKey,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');

        return DB::transaction(function () use ($recoveryCase, $actor, $keep, $reason, $key): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'keep_customer_active_set')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }
            $this->assertMutable($case);

            $current = (bool) $case->keep_customer_active;
            if ($current === $keep) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }

            $case->forceFill([
                'keep_customer_active' => $keep,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'keep_customer_active_set', $case->status, $case->status, $reason, $key, [
                'from' => $current,
                'to' => $keep,
            ]);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function resolveException(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        string $resolution,
        string $reason,
        string $idempotencyKey,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');
        if (! in_array($resolution, ['accept', 'reopen'], true)) {
            $this->invalid('resolution', 'Resolusi exception tidak valid.');
        }
        if (mb_strlen($reason) < 5) {
            $this->invalid('reason', 'Alasan resolusi wajib diisi.');
        }

        return DB::transaction(function () use ($recoveryCase, $actor, $resolution, $reason, $key): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'exception_resolved')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }
            if ($case->status !== self::STATUS_EXCEPTION) {
                $this->invalid('status', 'Case ini tidak sedang menunggu tinjauan exception.');
            }

            $items = $case->items()->lockForUpdate()->get();
            if ($resolution === 'accept' && $items->contains(fn (DeviceRecoveryItem $item): bool => $item->status === self::ITEM_PENDING)) {
                $this->invalid('status', 'Semua perangkat harus memiliki hasil sebelum exception disetujui.');
            }
            $from = $case->status;
            $now = now();
            if ($resolution === 'accept') {
                $to = self::STATUS_EXCEPTION_ACCEPTED;
                $meta = is_array($case->meta) ? $case->meta : [];
                $meta['exception_resolution'] = [
                    'decision' => 'accepted',
                    'reason' => $reason,
                    'resolved_by' => $actor->id,
                    'resolved_at' => $now->toIso8601String(),
                ];
                $case->forceFill(['meta' => $meta, 'received_at' => null]);
            } else {
                foreach ($items as $item) {
                    $item->forceFill([
                        'captured_serial_number' => null,
                        'status' => self::ITEM_PENDING,
                        'condition' => self::CONDITION_UNKNOWN,
                        'captured_by' => null,
                        'captured_at' => null,
                    ])->save();
                }
                $to = $case->assigned_to ? self::STATUS_ASSIGNED : self::STATUS_WAITING_ASSIGNMENT;
                $meta = is_array($case->meta) ? $case->meta : [];
                unset($meta['exception_resolution']);
                $case->forceFill([
                    'started_at' => null,
                    'received_at' => null,
                    'exception_reason' => null,
                    'meta' => $meta,
                ]);
            }

            $case->forceFill([
                'status' => $to,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $ticketClose = $resolution === 'accept'
                ? $this->autoCloseTicket($case, $actor, 'exception pengambilan perangkat disetujui')
                : null;
            $this->appendEvent($case, $actor, 'exception_resolved', $from, $to, $reason, $key, [
                'resolution' => $resolution,
                ...($ticketClose ?? []),
            ]);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    public function cancel(
        DeviceRecoveryCase $recoveryCase,
        User $actor,
        string $reason,
        string $idempotencyKey,
    ): DeviceRecoveryCase {
        $key = $this->operationKey($idempotencyKey);
        $reason = $this->safeReason($reason, 'reason');
        if (mb_strlen($reason) < 5) {
            $this->invalid('reason', 'Alasan pembatalan wajib diisi.');
        }

        return DB::transaction(function () use ($recoveryCase, $actor, $reason, $key): DeviceRecoveryCase {
            $case = $this->lockedCase($recoveryCase);
            if ($this->eventExistsFor($key, $case, 'cancelled')) {
                return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
            }
            $this->assertMutable($case);
            if (! in_array($case->status, [
                self::STATUS_WAITING_ASSIGNMENT,
                self::STATUS_ASSIGNED,
                self::STATUS_SCHEDULED,
            ], true)) {
                $this->invalid('status', 'Recovery hanya dapat dibatalkan sebelum pengambilan dimulai.');
            }
            $from = $case->status;
            $case->forceFill([
                'status' => self::STATUS_CANCELLED,
                'exception_reason' => $reason,
                'updated_by' => $actor->id,
                'version' => $case->version + 1,
            ])->save();
            $this->appendEvent($case, $actor, 'cancelled', $from, $case->status, $reason, $key);

            return $case->fresh(['ticket', 'customer', 'area', 'assignee', 'items']);
        });
    }

    /**
     * Customers whose device with this serial has been physically returned
     * (recovery case `received`, item `received`, captured serial matches) and
     * who are no longer live. Their stale `onu_serial` / PSB history must not
     * block reusing the device for a new PSB or a migration "SN baru".
     *
     * Read-only. A serial on a live customer is never reported as released.
     *
     * @return list<int>
     */
    public function releasedCustomerIdsForSerial(?string $serial): array
    {
        $serial = $this->normalizeSerial($serial);
        if ($serial === null
            || ! Schema::hasTable('device_recovery_cases')
            || ! Schema::hasTable('device_recovery_items')) {
            return [];
        }

        return DeviceRecoveryCase::query()
            ->where('status', self::STATUS_RECEIVED)
            ->whereNotNull('customer_id')
            ->whereHas('items', fn ($query) => $query
                ->where('status', self::ITEM_RECEIVED)
                ->where('captured_serial_number', $serial))
            ->whereHas('customer', fn ($query) => $query
                ->whereIn('status', self::RELEASED_CUSTOMER_STATUSES))
            ->pluck('customer_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Lower-cased PPPoE usernames of the released customers for a serial.
     *
     * @return list<string>
     */
    public function releasedPppoeUsersForSerial(?string $serial): array
    {
        $ids = $this->releasedCustomerIdsForSerial($serial);
        if ($ids === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('id', $ids)
            ->pluck('pppoe_user')
            ->map(fn ($user): string => strtolower(trim((string) $user)))
            ->filter(fn (string $user): bool => $user !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function normalizeSerial(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($value)) ?? '');

        return $normalized !== '' ? $normalized : null;
    }

    public function statusPresentation(string $status): array
    {
        return match ($status) {
            self::STATUS_WAITING_ASSIGNMENT => ['group' => 'Menunggu penugasan', 'substatus' => 'Menunggu penugasan'],
            self::STATUS_ASSIGNED => ['group' => 'Perangkat sedang diambil', 'substatus' => 'Ditugaskan'],
            self::STATUS_SCHEDULED => ['group' => 'Perangkat sedang diambil', 'substatus' => 'Dijadwalkan'],
            self::STATUS_PICKUP_STARTED => ['group' => 'Perangkat sedang diambil', 'substatus' => 'Mulai pengambilan'],
            self::STATUS_CARRIED => ['group' => 'Perangkat sedang diambil', 'substatus' => 'Dibawa teknisi'],
            self::STATUS_RECEIVED => ['group' => 'Sudah diambil', 'substatus' => 'Diterima gudang'],
            self::STATUS_EXCEPTION => ['group' => 'Perlu tinjauan', 'substatus' => 'Exception'],
            self::STATUS_EXCEPTION_ACCEPTED => ['group' => 'Perlu tinjauan', 'substatus' => 'Exception disetujui'],
            self::STATUS_CANCELLED => ['group' => 'Dibatalkan', 'substatus' => 'Dibatalkan'],
            self::STATUS_REUSED => ['group' => 'Dipakai ulang', 'substatus' => 'Dipakai ulang'],
            default => ['group' => 'Tidak diketahui', 'substatus' => $status],
        };
    }

    public function assertTicketCanClose(SupportTicket $ticket, bool $lockForUpdate = false): void
    {
        $query = DeviceRecoveryCase::query()
            ->where('ticket_id', $ticket->id)
            ->with('items');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $case = $query->first();
        if (! $case || in_array($case->status, [self::STATUS_CANCELLED, self::STATUS_REUSED], true)) {
            return;
        }

        $items = $lockForUpdate
            ? $case->items()->lockForUpdate()->get()
            : $case->items;
        if ($case->status === self::STATUS_EXCEPTION_ACCEPTED) {
            if ($items->isEmpty() || $items->contains(fn (DeviceRecoveryItem $item): bool => ! in_array($item->status, [
                self::ITEM_CARRIED,
                self::ITEM_RECEIVED,
                self::ITEM_EXCEPTION,
            ], true))) {
                $this->invalid('status', 'Tiket dismantle belum dapat ditutup: masih ada perangkat tanpa hasil final.');
            }

            return;
        }
        $hasUnfinishedItem = $items->contains(fn (DeviceRecoveryItem $item): bool => $item->status !== self::ITEM_RECEIVED);
        if ($case->status !== self::STATUS_RECEIVED || $hasUnfinishedItem) {
            $this->invalid('status', 'Tiket dismantle belum dapat ditutup: perangkat belum diterima gudang atau exception belum diselesaikan.');
        }
    }

    private function applyAssignment(
        DeviceRecoveryCase $case,
        User $actor,
        User $assignee,
        ?CarbonInterface $scheduledAt,
        string $key,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $from = $case->status;
        $case->forceFill([
            'assigned_to' => $assignee->id,
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt ? self::STATUS_SCHEDULED : self::STATUS_ASSIGNED,
            'updated_by' => $actor->id,
            'version' => $case->version + 1,
        ])->save();

        $this->appendEvent($case, $actor, 'assigned', $from, $case->status, $reason, $key, [
            'assigned_to' => $assignee->id,
            'scheduled_at' => $scheduledAt?->toIso8601String(),
            ...$metadata,
        ]);
    }

    /**
     * Set the case's dismantle ticket assignee to the technician who collected
     * the device (quick record). Activity entry only; no WA. Null when the
     * ticket already had that assignee or is missing.
     *
     * @return array<string, mixed>|null event metadata when changed
     */
    private function attributeTicketToTechnician(DeviceRecoveryCase $case, User $actor, User $technician): ?array
    {
        if (! $case->ticket_id) {
            return null;
        }
        $ticket = SupportTicket::query()->lockForUpdate()->find($case->ticket_id);
        if (! $ticket || (int) $ticket->assigned_to === (int) $technician->id) {
            return null;
        }

        $previous = $ticket->assigned_to ? (int) $ticket->assigned_to : null;
        $ticket->forceFill([
            'assigned_to' => $technician->id,
            'assigned_at' => $ticket->assigned_at ?: now(),
            'status' => $ticket->status === 'open' ? 'assigned' : $ticket->status,
        ])->save();
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => 'assigned',
            'old_value' => ['assignee_id' => $previous],
            'new_value' => [
                'assignee_id' => $technician->id,
                'assignee_name' => $technician->name,
                'auto' => true,
                'recovery_case_id' => $case->id,
            ],
            'description' => mb_substr('Teknisi tiket diisi dari catat langsung Pengambilan Perangkat: '.$technician->name.' (tanpa notifikasi WA)', 0, 255),
        ]);

        return [
            'ticket_assignee_set' => true,
            'ticket_previous_assignee' => $previous,
        ];
    }

    private function ticketTechnician(?SupportTicket $ticket): ?User
    {
        if (! $ticket?->assigned_to) {
            return null;
        }
        $user = User::query()->whereKey($ticket->assigned_to)->first();

        return $user && $user->isTeknisi() ? $user : null;
    }

    private function lockedCase(DeviceRecoveryCase $recoveryCase): DeviceRecoveryCase
    {
        $case = DeviceRecoveryCase::query()->lockForUpdate()->find($recoveryCase->id);
        if (! $case) {
            throw (new ModelNotFoundException)->setModel(DeviceRecoveryCase::class, [$recoveryCase->id]);
        }

        return $case;
    }

    /**
     * Customer -> terminated once its device is received at the warehouse.
     * Eloquent save (activitylog `updated`) + explicit activity entry. Only the
     * status column changes: no PPPoE/router/RADIUS/invoice side effects (the
     * Customer::updated hook only bills transitions INTO active). Idempotent:
     * an already-terminated (or other non-listed status) customer is left as is.
     *
     * @return array<string, mixed>|null event metadata when terminated
     */
    private function terminateCustomerAfterReceive(DeviceRecoveryCase $case, User $actor, ?GuardDecision $decision = null, string $source = 'warehouse_receive'): ?array
    {
        if ((bool) ($case->keep_customer_active ?? false) || ! $case->customer_id) {
            return null;
        }

        $customer = Customer::withTrashed()->lockForUpdate()->find($case->customer_id);
        if (! $customer || ! in_array((string) $customer->status, self::TERMINATE_ON_RECEIVE_FROM, true)) {
            return null;
        }

        // Fail-closed: no verdict (race with a status change) = unknown, defer.
        $decision ??= GuardDecision::fromSignals('terminate', [
            GuardSignal::unknown(GuardSignal::PPPOE, 'not_evaluated', 'Pagar belum dievaluasi untuk penerimaan ini.'),
        ]);

        $from = (string) $customer->status;
        $outcome = app(LifecycleTerminationService::class)->terminateOrDefer($customer, $decision, $source, [
            'recovery_case_id' => $case->id,
            'ticket_id' => $case->ticket_id,
        ], $actor);

        if ($outcome['result'] === LifecycleTerminationService::RESULT_DEFERRED) {
            return [
                'customer_termination_deferred' => true,
                'customer_id' => $customer->id,
                'pending_termination_id' => $outcome['pending_termination_id'],
                'guard_verdict' => $outcome['guard_verdict'],
                'guard_reasons' => $outcome['guard_reasons'],
                'guard_message' => SensitiveDataRedactor::redact($decision->message()),
            ];
        }

        activity()
            ->causedBy($actor)
            ->performedOn($customer)
            ->event('terminated_device_recovered')
            ->withProperties([
                'reason' => self::TERMINATE_ON_RECEIVE_REASON,
                'from' => $from,
                'to' => 'terminated',
                'recovery_case_id' => $case->id,
                'ticket_id' => $case->ticket_id,
            ])
            ->log(self::TERMINATE_ON_RECEIVE_REASON);

        return [
            'customer_terminated' => true,
            'customer_id' => $customer->id,
            'customer_from_status' => $from,
        ];
    }

    /**
     * @param  list<array{path?: string, kind?: string}>  $photos
     */
    private function assertPickupPhotos(string $condition, array $photos): void
    {
        $kinds = [];
        foreach ($photos as $photo) {
            $kind = $photo['kind'] ?? null;
            if (! in_array($kind, [self::PHOTO_KIND_LABEL_SN, self::PHOTO_KIND_DEVICE, self::PHOTO_KIND_OTHER], true)
                || trim((string) ($photo['path'] ?? '')) === '') {
                $this->invalid('photos', 'Jenis foto pengambilan tidak valid.');
            }
            $kinds[] = $kind;
        }
        if (count($photos) > 5) {
            $this->invalid('photos', 'Maksimal 5 foto recovery.');
        }
        if ($condition === self::CONDITION_LOST) {
            return;
        }

        $messages = [];
        if (! in_array(self::PHOTO_KIND_LABEL_SN, $kinds, true)) {
            $messages['label_sn_photos'] = 'Foto label SN wajib diunggah minimal 1.';
        }
        if (! in_array(self::PHOTO_KIND_DEVICE, $kinds, true)) {
            $messages['device_photos'] = 'Foto perangkat utuh wajib diunggah minimal 1.';
        }
        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }

    /**
     * Owner 2026-10-09: close the linked dismantle ticket automatically once
     * the pickup is final. Skipped when the ticket is already closed/resolved.
     * Status column + activity entry only; no WA notification.
     *
     * @return array<string, mixed>|null event metadata when closed
     */
    private function autoCloseTicket(DeviceRecoveryCase $case, User $actor, string $why, ?int $ticketId = null): ?array
    {
        $ticketId ??= $case->ticket_id ? (int) $case->ticket_id : null;
        if (! $ticketId) {
            return null;
        }

        $ticket = SupportTicket::query()->lockForUpdate()->find($ticketId);
        // Owner 2026-10-10: technicians mark the dismantle ticket `resolved`
        // after the visit, so a resolved ticket still closes on receipt.
        if (! $ticket || in_array($ticket->status, ['closed', 'cancelled'], true)) {
            return null;
        }

        $from = (string) $ticket->status;
        $ticket->forceFill([
            'status' => 'closed',
            'resolved_at' => $ticket->resolved_at ?: now(),
        ])->save();
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'action' => 'status_changed',
            'old_value' => ['status' => $from],
            'new_value' => ['status' => 'closed', 'auto' => true, 'recovery_case_id' => $case->id],
            'description' => "Status diubah: {$from} -> closed (ditutup otomatis: {$why})",
        ]);
        // An incident ticket closed here (any type can be linked) still gets a cause: "Ditutup otomatis".
        // Dismantle tickets are not incidents and stay as they are.
        OutageRootCause::recordSystemCause($ticket, OutageRootCause::AutoClosed, $actor);

        return [
            'ticket_auto_closed' => true,
            'ticket_from_status' => $from,
        ];
    }

    private function assertMutable(DeviceRecoveryCase $case): void
    {
        if (in_array($case->status, [self::STATUS_RECEIVED, self::STATUS_EXCEPTION_ACCEPTED, self::STATUS_CANCELLED, self::STATUS_REUSED], true)) {
            $this->invalid('status', 'Recovery case sudah final dan tidak dapat diubah.');
        }
    }

    private function eventExistsFor(string $key, DeviceRecoveryCase $case, string|array $eventTypes): bool
    {
        $event = DeviceRecoveryEvent::query()->where('idempotency_key', $key)->first();
        if (! $event) {
            return false;
        }
        if ((int) $event->recovery_case_id !== (int) $case->id) {
            $this->invalid('idempotency_key', 'Idempotency key sudah dipakai oleh recovery case lain.');
        }
        if (! in_array($event->event_type, (array) $eventTypes, true)) {
            $this->invalid('idempotency_key', 'Idempotency key sudah dipakai untuk aksi recovery yang berbeda.');
        }

        return true;
    }

    private function appendEvent(
        DeviceRecoveryCase $case,
        User $actor,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        ?string $reason,
        string $idempotencyKey,
        array $metadata = [],
    ): DeviceRecoveryEvent {
        return DeviceRecoveryEvent::create([
            'recovery_case_id' => $case->id,
            'item_id' => $metadata['item_id'] ?? null,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'reason' => $reason,
            'source' => 'web',
            'idempotency_key' => $idempotencyKey,
            'metadata' => $metadata,
        ]);
    }

    private function serialIsActiveElsewhere(string $serial, int $itemId, int $caseId): bool
    {
        return DeviceRecoveryItem::query()
            ->where('captured_serial_number', $serial)
            ->whereKeyNot($itemId)
            ->whereHas('recoveryCase', fn ($query) => $query
                ->whereKeyNot($caseId)
                ->whereNotIn('status', [self::STATUS_RECEIVED, self::STATUS_CANCELLED, self::STATUS_REUSED]))
            ->exists();
    }

    private function operationKey(string $key): string
    {
        $key = trim($key);
        if ($key === '' || mb_strlen($key) > 191) {
            $this->invalid('idempotency_key', 'Idempotency key wajib diisi dan maksimal 191 karakter.');
        }

        return $key;
    }

    private function safeReason(?string $reason, string $field): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);
        if ($reason === '') {
            return null;
        }

        if (SensitiveDataRedactor::redact($reason) !== $reason) {
            $this->invalid($field, 'Catatan tidak boleh memuat password, token, secret, atau credential.');
        }

        return $reason;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
