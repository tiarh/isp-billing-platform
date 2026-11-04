<?php

namespace App\Services\Lifecycle;

use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceGenerationService;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Roadmap 1.3 PR-4a: terminate a customer only when the lifecycle guard
 * allows it, otherwise park the request as a pending termination that holds
 * new billing ("tahan tagihan") until the guard passes or an admin cancels.
 *
 * PR-4a builds terminateOrDefer()/finalize() but no production caller uses
 * them yet (4b/4c wire them); only tests create pending rows.
 */
class LifecycleTerminationService
{
    public const SETTING_HOLD_BILLING = 'lifecycle_pending_hold_billing_enabled';

    public const RESULT_TERMINATED = 'customer_terminated';

    public const RESULT_DEFERRED = 'termination_deferred';

    public const EVENT_DEFERRED = 'termination_deferred';

    public const EVENT_CANCELLED = 'termination_deferral_cancelled';

    public const EVENT_FINALIZED = 'terminated_deferred';

    /** Statuses a termination may start from. */
    public const TERMINABLE_STATUSES = ['active', 'isolated', 'inactive'];

    public function __construct(
        private readonly CustomerLifecycleGuard $guard,
        private readonly InvoiceGenerationService $invoices,
    ) {}

    /**
     * Guard verdict for a termination. Call OUTSIDE any DB transaction (the
     * guard may wait on Grafana). Fail-closed: a throwing guard is unknown.
     */
    public function preVerdict(Customer $customer, GuardContext $context): GuardDecision
    {
        try {
            return $this->guard->canTerminate($customer, $context);
        } catch (Throwable $e) {
            Log::warning('[LifecycleTermination] guard check failed', [
                'customer_id' => $customer->getKey(),
                'exception_class' => $e::class,
            ]);

            return GuardDecision::fromSignals(ManualLifecycleGate::ACTION_TERMINATE, [
                GuardSignal::unknown(GuardSignal::PPPOE, 'guard_failed', 'Pagar gagal memeriksa status pelanggan.'),
            ]);
        }
    }

    /**
     * Terminate now (guard allowed) or defer. The caller holds a DB
     * transaction and a row lock on $locked.
     *
     * @param  array{recovery_case_id?: int|null, ticket_id?: int|null, takeover_customer_id?: int|null, meta?: array<string, mixed>}  $links
     * @return array{result: string, pending_termination_id: int|null, guard_verdict: string, guard_reasons: list<string>}
     */
    public function terminateOrDefer(Customer $locked, GuardDecision $decision, string $source, array $links = [], ?User $actor = null): array
    {
        $out = [
            'result' => self::RESULT_TERMINATED,
            'pending_termination_id' => null,
            'guard_verdict' => $decision->verdict,
            'guard_reasons' => $decision->reasonCodes(),
        ];

        if ($locked->status === 'terminated') {
            $this->resolveOpenRow($locked, CustomerPendingTermination::STATUS_CLOSED, 'already_terminated', $decision, $actor);

            return $out;
        }

        if ($decision->allowed()) {
            $this->markTerminated($locked, $decision, $source, $actor, 'guard_passed');
            $this->resolveOpenRow($locked, CustomerPendingTermination::STATUS_TERMINATED, 'guard_passed', $decision, $actor);

            return $out;
        }

        $now = now();
        $row = CustomerPendingTermination::query()
            ->where('customer_id', $locked->getKey())
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->lockForUpdate()
            ->first();

        if ($row) {
            $update = [
                'last_verdict' => $decision->verdict,
                'last_reasons' => $decision->reasonCodes(),
                'last_checked_at' => $now,
                'check_count' => (int) $row->check_count + 1,
            ];
            // A takeover onto an already-open row links it (source stays, audit trail);
            // the caller checked serial equality under lock.
            $newOwner = $links['takeover_customer_id'] ?? null;
            if ($newOwner !== null) {
                $update['meta'] = ((array) $row->meta) + [];
                $update['meta']['takeover'] = [
                    'customer_id' => (int) $newOwner,
                    'at' => $now->toDateTimeString(),
                    'previous_takeover_customer_id' => $row->takeover_customer_id,
                ];
                $update['takeover_customer_id'] = (int) $newOwner;
            }
            $row->forceFill($update)->save();
        } else {
            $row = CustomerPendingTermination::create([
                'customer_id' => $locked->getKey(),
                'source' => $source,
                'recovery_case_id' => $links['recovery_case_id'] ?? null,
                'ticket_id' => $links['ticket_id'] ?? null,
                'takeover_customer_id' => $links['takeover_customer_id'] ?? null,
                'status' => CustomerPendingTermination::STATUS_PENDING,
                'hold_billing' => true,
                'requested_by' => $actor?->getKey(),
                'requested_at' => $now,
                'first_verdict' => $decision->verdict,
                'last_verdict' => $decision->verdict,
                'first_reasons' => $decision->reasonCodes(),
                'last_reasons' => $decision->reasonCodes(),
                'last_checked_at' => $now,
                'check_count' => 1,
                'meta' => ['guard' => $this->guardMeta($decision)] + (array) ($links['meta'] ?? []),
            ]);

            activity()
                ->causedBy($actor)
                ->performedOn($locked)
                ->event(self::EVENT_DEFERRED)
                ->withProperties([
                    'source' => $source,
                    'pending_termination_id' => $row->getKey(),
                    'guard' => $this->guardMeta($decision),
                ])
                ->log('Terminate ditunda (pagar): '.$locked->code);
        }

        $out['result'] = self::RESULT_DEFERRED;
        $out['pending_termination_id'] = (int) $row->getKey();

        return $out;
    }

    /**
     * Sweeper path: the guard now allows a pending termination. Locks row and
     * customer itself (opens its own transaction). Returns false when the row
     * is no longer pending or the customer can no longer be terminated.
     */
    public function finalize(CustomerPendingTermination $row, GuardDecision $decision, ?User $actor = null): bool
    {
        if (! $decision->allowed()) {
            return false;
        }

        return DB::transaction(function () use ($row, $decision, $actor): bool {
            // Lock order customer -> row (same as takeOver / receipts) so the
            // sweeper cannot deadlock against them.
            $customer = Customer::query()->whereKey($row->customer_id)->lockForUpdate()->first();
            /** @var CustomerPendingTermination|null $locked */
            $locked = CustomerPendingTermination::query()->whereKey($row->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                return false;
            }

            if (! $customer || ! in_array($customer->status, self::allowedFromStatuses($locked), true)) {
                return false;
            }

            // Last safety net: the verdict was computed outside this lock; a PPPoE
            // session that came back since then keeps the customer (row stays pending).
            if ((bool) $customer->is_online) {
                $locked->forceFill([
                    'last_verdict' => GuardDecision::DENY,
                    'last_reasons' => ['pppoe:online_at_finalize'],
                    'last_checked_at' => now(),
                    'check_count' => (int) $locked->check_count + 1,
                ])->save();

                return false;
            }

            $this->markTerminated($customer, $decision, (string) $locked->source, $actor, 'guard_passed', self::EVENT_FINALIZED);

            $locked->forceFill([
                'status' => CustomerPendingTermination::STATUS_TERMINATED,
                'resolution' => 'guard_passed',
                'resolved_at' => now(),
                'resolved_by' => $actor?->getKey(),
                'last_verdict' => $decision->verdict,
                'last_reasons' => $decision->reasonCodes(),
                'last_checked_at' => now(),
                'check_count' => (int) $locked->check_count + 1,
                'meta' => ((array) $locked->meta) + ['finalize_guard' => $this->guardMeta($decision)],
            ])->save();

            return true;
        });
    }

    /**
     * Sweeper, report mode: remember the latest guard verdict of a pending row.
     * Uses save() (never a query update) so the open_key invariant of the
     * model's saving hook runs (review L6).
     *
     * @param  array<string, mixed>  $metaMerge  merged into meta (ids/flags only)
     */
    public function recordCheck(CustomerPendingTermination $row, GuardDecision $decision, array $metaMerge = []): void
    {
        DB::transaction(function () use ($row, $decision, $metaMerge): void {
            $locked = CustomerPendingTermination::query()->whereKey($row->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                return;
            }
            $locked->forceFill([
                'last_verdict' => $decision->verdict,
                'last_reasons' => $decision->reasonCodes(),
                'last_checked_at' => now(),
                'check_count' => (int) $locked->check_count + 1,
                'meta' => $metaMerge === [] ? $locked->meta : ((array) $locked->meta) + $metaMerge,
            ])->save();
        });
    }

    /** Sweeper: a row whose customer is not in a terminable status was looked at (rotation only). */
    public function touchChecked(CustomerPendingTermination $row): void
    {
        DB::transaction(function () use ($row): void {
            $locked = CustomerPendingTermination::query()->whereKey($row->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                return;
            }
            $locked->forceFill([
                'last_checked_at' => now(),
                'check_count' => (int) $locked->check_count + 1,
            ])->save();
        });
    }

    /**
     * Sweeper housekeeping: the customer is gone (archived) or already
     * terminated elsewhere, so the pending row is closed. save() keeps open_key honest.
     */
    public function closeRow(CustomerPendingTermination $row, string $resolution): bool
    {
        return DB::transaction(function () use ($row, $resolution): bool {
            $locked = CustomerPendingTermination::query()->whereKey($row->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                return false;
            }
            $locked->forceFill([
                'status' => CustomerPendingTermination::STATUS_CLOSED,
                'resolution' => $resolution,
                'resolved_at' => now(),
                'last_checked_at' => now(),
            ])->save();

            return true;
        });
    }

    /**
     * Admin/superadmin cancels the deferral: the customer stays subscribed,
     * the billing hold is lifted, the current-period invoice is created now
     * when billable. Missed months are listed, never generated (owner Q2).
     *
     * invoice is invoice_created | invoice_exists | invoice_skipped:{reason}.
     *
     * @return array{invoice: string, period: string, missed_periods: list<string>}
     */
    public function cancel(CustomerPendingTermination $row, User $actor, string $reason): array
    {
        if (! ($actor->isAdmin() || $actor->isSuperAdmin())) {
            throw new AuthorizationException('Hanya admin/superadmin yang bisa membatalkan.');
        }

        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Alasan pembatalan minimal 10 karakter.']);
        }

        $customerId = DB::transaction(function () use ($row, $actor, $reason): int {
            /** @var CustomerPendingTermination|null $locked */
            $locked = CustomerPendingTermination::query()->whereKey($row->getKey())->lockForUpdate()->first();
            if (! $locked || ! $locked->isPending()) {
                throw ValidationException::withMessages(['reason' => 'Terminate tertunda sudah tidak aktif.']);
            }

            $locked->forceFill([
                'status' => CustomerPendingTermination::STATUS_CANCELLED,
                'resolution' => 'admin_cancelled',
                'resolved_at' => now(),
                'resolved_by' => $actor->getKey(),
                'resolution_note' => SensitiveDataRedactor::redact($reason),
            ])->save();

            $customer = Customer::withTrashed()->find($locked->customer_id);
            if ($customer) {
                activity()
                    ->causedBy($actor)
                    ->performedOn($customer)
                    ->event(self::EVENT_CANCELLED)
                    ->withProperties([
                        'pending_termination_id' => $locked->getKey(),
                        'source' => $locked->source,
                        'reason' => SensitiveDataRedactor::redact($reason),
                    ])
                    ->log('Terminate tertunda dibatalkan: '.$customer->code);
            }

            return (int) $locked->customer_id;
        });

        // After commit: the hold is lifted, so ensureForPeriod no longer returns null.
        $customer = Customer::query()->with('package')->find($customerId);
        $now = OpsTime::now();
        $period = $now->startOfMonth();
        $result = ['invoice' => 'invoice_skipped:customer_missing', 'period' => $period->format('Y-m'), 'missed_periods' => []];
        if (! $customer) {
            return $result;
        }

        // The cancel is already committed: an invoice failure must not turn it into a 500.
        try {
            $skip = $this->invoices->currentPeriodSkipReason($customer, $now);
            if ($skip === null) {
                $existed = $customer->invoices()->where('period', $period->toDateString())->exists();
                $invoice = $this->invoices->ensureForPeriod($customer, $period);
                $result['invoice'] = $invoice === null
                    ? 'invoice_skipped:not_created'
                    : ($existed ? 'invoice_exists' : 'invoice_created');
            } else {
                $result['invoice'] = 'invoice_skipped:'.$skip;
            }
            $result['missed_periods'] = $this->missedPeriods($customer, CarbonImmutable::instance($row->requested_at), $period);
        } catch (Throwable $e) {
            Log::warning('[LifecycleTermination] invoice after cancel failed', [
                'customer_id' => $customerId,
                'exception_class' => $e::class,
            ]);
            $result['invoice'] = 'invoice_skipped:error';
        }

        return $result;
    }

    /** "2026-11" -> "November 2026". */
    public static function periodLabel(string $period): string
    {
        $months = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        [$year, $month] = array_pad(explode('-', $period), 2, '');
        $name = $months[(int) $month] ?? null;

        return $name === null ? $period : $name.' '.$year;
    }

    /** Flash text T7 (owner approved). */
    public static function cancelMessage(array $result): string
    {
        $period = self::periodLabel($result['period']);
        if ($result['invoice'] === 'invoice_created') {
            $text = "Terminate tertunda dibatalkan. Tagihan {$period} dibuat.";
        } elseif ($result['invoice'] === 'invoice_exists') {
            $text = "Terminate tertunda dibatalkan. Tagihan {$period} sudah ada.";
        } else {
            $reason = str_replace('invoice_skipped:', '', $result['invoice']);
            $label = [
                'join_month' => 'bulan bergabung',
                'price_zero' => 'paket Rp0',
                'join_date_null' => 'tanggal gabung kosong',
                'no_package' => 'tidak ada paket',
                'status_not_billable' => 'status tidak ditagih',
                'pending_termination_hold' => 'masih ada terminate tertunda lain',
                'error' => 'gagal membuat, akan dibuat otomatis oleh catch-up harian',
            ][$reason] ?? $reason;
            $text = "Terminate tertunda dibatalkan. Tidak ada tagihan dibuat ({$label}).";
        }

        if ($result['missed_periods'] !== []) {
            $text .= ' Bulan yang terlewat ('.implode(', ', array_map([self::class, 'periodLabel'], $result['missed_periods'])).') tidak ditagih otomatis — buat manual jika perlu.';
        }

        return $text;
    }

    /**
     * Months whose regular invoice run happened while the termination was
     * pending (requested before the 1st), before the current month, still
     * without an invoice and otherwise billable.
     *
     * @return list<string> Y-m
     */
    public function missedPeriods(Customer $customer, CarbonImmutable $requestedAt, CarbonImmutable $currentPeriod): array
    {
        if (! $customer->package || (float) $customer->package->price <= 0 || ! $customer->join_date) {
            return [];
        }

        $missed = [];
        // L2: a row requested on the 1st before the 07:00 WIB monthly run was
        // also held out of THAT month's run, so the requested month counts too.
        $local = OpsTime::local($requestedAt);
        $month = $local->startOfMonth();
        if (! ($local->day === 1 && $local->hour < 7)) {
            $month = $month->addMonth();
        }
        for ($i = 0; $i < 24 && $month->lessThan($currentPeriod); $i++) {
            $billing = $this->invoices->resolveBillingPeriod($customer, $month);
            if ($billing->toDateString() === $month->toDateString()
                && ! $customer->invoices()->where('period', $month->toDateString())->exists()) {
                $missed[] = $month->format('Y-m');
            }
            $month = $month->addMonth();
        }

        return $missed;
    }

    /**
     * Short Indonesian reason text for stored guard reason codes ("pppoe:pppoe_in_use").
     *
     * @param  list<string>|null  $codes
     */
    public static function reasonLabel(?array $codes): string
    {
        $map = [
            'pppoe_in_use' => 'PPPoE masih aktif',
            'onu_in_use' => 'ONU masih online',
            'traffic_in_use' => 'masih ada trafik',
            'pppoe_unknown' => 'status PPPoE tidak dapat dipastikan',
            'onu_unknown' => 'status ONU tidak dapat dipastikan',
            'traffic_unknown' => 'trafik tidak dapat dipastikan',
            'guard_failed' => 'pagar gagal memeriksa',
            'not_evaluated' => 'belum dievaluasi',
            'onu_reused_by_other_customer' => 'ONU dipakai pelanggan lain',
            'twin_not_online' => 'ONU kembar belum terbukti online',
            'not_evaluated_takeover' => 'belum dievaluasi (ambil alih ONU)',
            'online_at_finalize' => 'PPPoE online saat finalisasi',
        ];
        $labels = [];
        foreach ((array) $codes as $code) {
            $reason = (string) (str_contains((string) $code, ':') ? substr((string) $code, strpos((string) $code, ':') + 1) : $code);
            $labels[] = $map[$reason] ?? $reason;
        }

        return $labels === [] ? '-' : implode(', ', array_unique($labels));
    }

    /** Age text in Indonesian ("5 menit lalu"). */
    public static function ageLabel(?CarbonInterface $at): string
    {
        if ($at === null) {
            return 'belum pernah';
        }
        $minutes = max(0, (int) $at->diffInMinutes(now()));
        if ($minutes < 60) {
            return $minutes.' menit lalu';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60).' jam lalu';
        }

        return intdiv($minutes, 1440).' hari lalu';
    }

    public function isBillingHeld(Customer $customer): bool
    {
        return self::billingHeld($customer);
    }

    public static function holdEnabled(): bool
    {
        $value = Setting::get(self::SETTING_HOLD_BILLING, true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** HOLD = open pending row with hold_billing AND the setting on. */
    public static function billingHeld(Customer $customer): bool
    {
        if (! self::holdEnabled()) {
            return false;
        }

        return CustomerPendingTermination::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->where('hold_billing', true)
            ->exists();
    }

    /**
     * Bulk form for commands: the subset of $customerIds that is held.
     *
     * @param  list<int>  $customerIds
     * @return list<int>
     */
    public static function heldCustomerIds(array $customerIds): array
    {
        if ($customerIds === [] || ! self::holdEnabled()) {
            return [];
        }

        return CustomerPendingTermination::query()
            ->whereIn('customer_id', $customerIds)
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->where('hold_billing', true)
            ->pluck('customer_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** @return list<string> */
    private static function allowedFromStatuses(CustomerPendingTermination $row): array
    {
        return $row->allowedFromStatuses();
    }

    /** Close the customer's open pending row (if any) inside the caller's transaction. */
    private function resolveOpenRow(Customer $customer, string $status, string $resolution, GuardDecision $decision, ?User $actor): void
    {
        $row = CustomerPendingTermination::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', CustomerPendingTermination::STATUS_PENDING)
            ->lockForUpdate()
            ->first();
        if (! $row) {
            return;
        }

        // save() (not a query update) so the open_key invariant in the saving hook runs.
        $row->forceFill([
            'status' => $status,
            'resolution' => $resolution,
            'resolved_at' => now(),
            'resolved_by' => $actor?->getKey(),
            'last_verdict' => $decision->verdict,
            'last_reasons' => $decision->reasonCodes(),
            'last_checked_at' => now(),
            'check_count' => (int) $row->check_count + 1,
        ])->save();
    }

    private function markTerminated(Customer $customer, GuardDecision $decision, string $source, ?User $actor, string $resolution, string $event = 'terminated_guard_allowed'): void
    {
        // Eloquent so the Customer activity log records the status change.
        // A10: every caller of markTerminated holds a guard-allowed decision.
        LifecycleBackstop::allow([(int) $customer->getKey()], 'lifecycle_termination:'.$source, function () use ($customer): void {
            $customer->status = 'terminated';
            $customer->save();
        });

        activity()
            ->causedBy($actor)
            ->performedOn($customer)
            ->event($event)
            ->withProperties([
                'source' => $source,
                'resolution' => $resolution,
                'guard' => $this->guardMeta($decision),
            ])
            ->log('Customer terminated (pagar lulus): '.$customer->code);
    }

    /** Ids and reason codes only (no free-form customer data). @return array<string, mixed> */
    private function guardMeta(GuardDecision $decision): array
    {
        return [
            'action' => $decision->action,
            'verdict' => $decision->verdict,
            'reasons' => $decision->reasonCodes(),
        ];
    }
}
