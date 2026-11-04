<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\PppoeOnlineFlagWriter;
use App\Services\UnifiedConnectivityReadModel;
use App\Support\OpsTime;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner decision 2026-10-08: "menunggu instalasi" (pending_installation)
 * becomes active automatically once the customer is really online:
 *   - ONU online on the OLT (fresh state) with a valid, fresh RX, AND
 *   - PPPoE session active on MikroTik (fresh pppoe_online_checked_at).
 * Freshness comes from OnlineStatusEvaluator (services.online_freshness),
 * through UnifiedConnectivityReadModel::composeMany, so this command uses the
 * exact same "online" truth as the NOC table. The strict three-signal rule is
 * applied for every OLT vendor. Stale data never activates anyone.
 *
 * Activation goes through Eloquent ($customer->update) so the Customer model
 * hook creates the current-period invoice (join month / Rp0 / NULL join_date
 * are skipped by the invoice job). No device is contacted.
 *
 * Owner rule 2026-10-09 (reused ONU): when the online pending customer's
 * onu_serial belongs ONLY to other accounts that are isolated / inactive /
 * suspended, the ONU was physically reused: the new account becomes active
 * and, since PR-4c (option C), the old accounts keep their status with a
 * pending termination (billing hold) that the sweeper finalizes once the
 * lifecycle guard passes, in one transaction. Old unpaid invoices are
 * kept (finance still collects arrears). Another account that is active or
 * pending on the same serial, or that shares the PPPoE user, stays
 * `suspected_duplicate`. Switch: billing.auto_activate.takeover_reused_onu.
 */
class AutoActivateOnlineCustomers extends Command
{
    public const REASON = 'auto: online OLT+PPPoE';

    public const TAKEOVER_REASON = 'auto: reused ONU taken over by new account';

    /** Old-account statuses whose ONU may be taken over by a new online account. */
    public const TAKEOVER_FROM_STATUSES = ['isolated', 'inactive', 'suspended'];

    protected $signature = 'customers:auto-activate-online
                            {--dry-run : List who would be activated; change nothing}
                            {--limit= : Max activations this run (default billing.auto_activate.max_per_run)}';

    protected $description = 'Activate pending_installation customers that are online on OLT (ONU+RX) and PPPoE, both fresh';

    public function handle(UnifiedConnectivityReadModel $readModel): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $enabled = (bool) config('billing.auto_activate.enabled', true);
        $takeoverEnabled = (bool) config('billing.auto_activate.takeover_reused_onu', true);
        $limit = max(1, (int) ($this->option('limit') ?: config('billing.auto_activate.max_per_run', 200)));

        if (! $enabled && ! $dryRun) {
            $this->warn('Auto-activation disabled (BILLING_AUTO_ACTIVATE_ONLINE=false). Nothing done.');

            return self::SUCCESS;
        }

        if (! PppoeOnlineFlagWriter::hasCheckedAtColumn()) {
            $this->warn('customers.pppoe_online_checked_at missing: PPPoE freshness unknown, nobody can be activated.');

            return self::SUCCESS;
        }

        $stats = [
            'reviewed' => 0,
            'activated' => 0,
            'would_activate' => 0,
            'cap_reached' => 0,
            'took_over_reused_onu' => 0,
            'old_deferred' => 0,
            'would_take_over' => 0,
        ];
        $skipped = [];
        $activatedIds = [];
        $priceZeroIds = [];
        $takeovers = [];

        Customer::ebilling()
            ->where('status', 'pending_installation')
            ->with(['olt:id,vendor', 'package:id,price'])
            ->chunkById(200, function (Collection $chunk) use ($readModel, $dryRun, $limit, $takeoverEnabled, &$stats, &$skipped, &$activatedIds, &$priceZeroIds, &$takeovers) {
                $rows = $readModel->composeMany($chunk);
                $conflicts = $this->conflictMap($chunk, $takeoverEnabled);

                foreach ($chunk as $customer) {
                    $stats['reviewed']++;
                    $id = (int) $customer->id;
                    $conflict = $conflicts[$id] ?? null;
                    $reason = $this->skipReason($customer, $rows[$id] ?? null, $conflict);
                    if ($reason !== null) {
                        $skipped[$reason][] = $id;

                        continue;
                    }

                    if ($limit <= $stats['activated'] + $stats['would_activate']) {
                        $stats['cap_reached']++;

                        continue;
                    }

                    $evidence = $rows[$id]['evidence'] ?? [];
                    $oldIds = $conflict['takeover'] ?? [];
                    $isPriceZero = (float) $customer->package->price <= 0;
                    $this->line(sprintf(
                        '%s #%d %s | join %s | RX %s dBm | %s%s',
                        $dryRun ? 'WOULD ACTIVATE' : 'ACTIVATE',
                        $id,
                        $customer->name,
                        $customer->join_date?->toDateString() ?? '-',
                        $evidence['rx']['dbm'] ?? '-',
                        $isPriceZero ? 'Rp0 (no invoice)' : 'Rp '.number_format((float) $customer->package->price),
                        $oldIds !== [] ? ' | reused ONU from #'.implode(', #', $oldIds).' (old -> termination deferred)' : '',
                    ));

                    if ($dryRun) {
                        $stats['would_activate']++;
                        if ($oldIds !== []) {
                            $stats['would_take_over']++;
                            $takeovers[] = ['new' => $id, 'old' => $oldIds];
                        }
                        if ($isPriceZero) {
                            $priceZeroIds[] = $id;
                        }

                        continue;
                    }

                    try {
                        $done = $oldIds === []
                            ? $this->activate($id, $evidence)
                            : $this->takeOver($id, $oldIds, $evidence);
                    } catch (\Throwable $e) {
                        // A deadlock / duplicate row must not stop the run (retried next run).
                        // A plain activation failure is not a takeover failure: keep the two reasons apart.
                        $skipped[$oldIds === [] ? 'activation_error' : 'takeover_error'][] = $id;
                        Log::warning('[customers:auto-activate-online] activation failed', ['customer_id' => $id, 'exception_class' => $e::class]);

                        continue;
                    }
                    if ($done) {
                        $stats['activated']++;
                        $activatedIds[] = $id;
                        if ($oldIds !== []) {
                            $stats['took_over_reused_onu']++;
                            $stats['old_deferred'] += count($oldIds);
                            $takeovers[] = ['new' => $id, 'old' => $oldIds];
                        }
                        if ($isPriceZero) {
                            $priceZeroIds[] = $id;
                        }
                    } else {
                        $skipped['status_changed'][] = $id;
                    }
                }
            });

        $summary = $stats + ['skipped' => array_map('count', $skipped)];
        $this->info('Summary: '.json_encode($summary));
        foreach ($skipped as $reason => $ids) {
            $this->line("skip {$reason}: ".implode(',', array_slice($ids, 0, 200)));
        }
        if ($priceZeroIds !== []) {
            $this->line('price_zero (activated, no invoice, never isolated): '.implode(',', $priceZeroIds));
        }
        foreach ($takeovers as $pair) {
            $this->line(sprintf(
                '%s: new #%d <- old #%s (old deferred)',
                $dryRun ? 'would_take_over' : 'took_over_reused_onu',
                $pair['new'],
                implode(', #', $pair['old']),
            ));
        }

        Log::info('[customers:auto-activate-online] run complete', [
            'dry_run' => $dryRun,
            'stats' => $summary,
            'activated_ids' => $activatedIds,
            'price_zero_ids' => $priceZeroIds,
            'takeovers' => $takeovers,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $row  UnifiedConnectivityReadModel row
     * @param  array{duplicate_of?: int, takeover?: list<int>}|null  $conflict
     */
    private function skipReason(Customer $customer, ?array $row, ?array $conflict): ?string
    {
        if (! $customer->package_id || ! $customer->package) {
            return 'no_package';
        }
        if (isset($conflict['duplicate_of'])) {
            return 'suspected_duplicate';
        }
        if (! $customer->router_id || ! $customer->olt_id || ! filled($customer->onu_serial) || ! filled($customer->pppoe_user)) {
            return 'mapping_incomplete';
        }
        if ($row === null) {
            return 'no_connectivity_row';
        }

        $ppp = $row['evidence']['pppoe'] ?? [];
        $olt = $row['evidence']['olt'] ?? [];
        $rx = $row['evidence']['rx'] ?? [];

        // Strict for every vendor: the ONU state itself must be "online"
        // (the lenient RX-only override of compose() does not count).
        if (($olt['state'] ?? null) !== 'online') {
            return ($olt['state'] ?? null) === null ? 'onu_state_unknown' : 'onu_not_online';
        }
        if (! ($olt['fresh'] ?? false)) {
            return 'onu_state_stale';
        }
        if (! ($rx['valid'] ?? false)) {
            return 'rx_invalid';
        }
        if (! ($rx['fresh'] ?? false)) {
            return 'rx_stale';
        }
        if (! ($ppp['fresh'] ?? false)) {
            return 'pppoe_stale';
        }
        if (! ($ppp['online'] ?? false)) {
            return 'pppoe_down';
        }

        return null;
    }

    /**
     * Other non-terminated customers sharing the PPPoE user or ONU serial.
     *  - same PPPoE user (any status), or same serial on an account that is
     *    not isolated / inactive / suspended (e.g. active: one person with two
     *    installs) => duplicate_of, never auto-activated;
     *  - same serial only on isolated / inactive / suspended accounts and
     *    takeover enabled => takeover (ONU physically reused).
     * Terminated accounts never block (their ONU is already released).
     *
     * @param  Collection<int, Customer>  $chunk
     * @return array<int, array{duplicate_of?: int, takeover?: list<int>}>
     */
    private function conflictMap(Collection $chunk, bool $takeoverEnabled): array
    {
        $norm = static fn ($v): string => strtolower(trim((string) $v));
        $users = $chunk->pluck('pppoe_user')->map($norm)->filter()->unique()->values()->all();
        $serials = $chunk->pluck('onu_serial')->map($norm)->filter()->unique()->values()->all();
        if ($users === [] && $serials === []) {
            return [];
        }

        $others = Customer::query()
            ->where('status', '!=', 'terminated')
            ->where(function ($q) use ($users, $serials) {
                if ($users !== []) {
                    $q->whereIn('pppoe_user', $users);
                }
                if ($serials !== []) {
                    $q->orWhereIn('onu_serial', $serials);
                }
            })
            ->orderBy('id')
            ->get(['id', 'pppoe_user', 'onu_serial', 'status']);

        $map = [];
        foreach ($chunk as $customer) {
            $id = (int) $customer->id;
            $user = $norm($customer->pppoe_user);
            $serial = $norm($customer->onu_serial);
            $notSelf = $others->filter(fn (Customer $o) => (int) $o->id !== $id);

            $sameUser = $user === '' ? null : $notSelf->first(fn (Customer $o) => $norm($o->pppoe_user) === $user);
            if ($sameUser) {
                $map[$id] = ['duplicate_of' => (int) $sameUser->id];

                continue;
            }

            $sameSerial = $serial === ''
                ? collect()
                : $notSelf->filter(fn (Customer $o) => $norm($o->onu_serial) === $serial)->values();
            if ($sameSerial->isEmpty()) {
                continue;
            }

            $blocking = $sameSerial->first(fn (Customer $o) => ! in_array((string) $o->status, self::TAKEOVER_FROM_STATUSES, true));
            if ($blocking || ! $takeoverEnabled) {
                $map[$id] = ['duplicate_of' => (int) ($blocking ?? $sameSerial->first())->id];

                continue;
            }

            $map[$id] = ['takeover' => $sameSerial->pluck('id')->map(fn ($v) => (int) $v)->values()->all()];
        }

        return $map;
    }

    /** @param array<string, mixed> $evidence */
    private function activate(int $customerId, array $evidence): bool
    {
        return DB::transaction(function () use ($customerId, $evidence): bool {
            /** @var Customer|null $customer */
            $customer = Customer::query()->whereKey($customerId)->lockForUpdate()->first();
            if (! $customer || $customer->status !== 'pending_installation') {
                return false;
            }

            // Eloquent update => Customer::updated hook => current-period invoice.
            $customer->update(['status' => 'active']);

            activity()
                ->performedOn($customer)
                ->event('auto_activated')
                ->withProperties([
                    'reason' => self::REASON,
                    'from' => 'pending_installation',
                    'to' => 'active',
                    'at_wib' => OpsTime::now()->toDateTimeString(),
                    'evidence' => $evidence,
                ])
                ->log(self::REASON);

            return true;
        });
    }

    /**
     * Reused ONU: new pending account -> active, old account(s) keep their
     * status and get a pending termination (deferred, billing hold), atomically. All rows are locked and re-checked, so a
     * concurrent change (old account reactivated or re-mapped, new account
     * already handled) aborts the whole takeover. Old unpaid invoices are NOT
     * touched (arrears are still collected).
     *
     * @param  list<int>  $oldIds
     * @param  array<string, mixed>  $evidence
     */
    private function takeOver(int $newId, array $oldIds, array $evidence): bool
    {
        return DB::transaction(function () use ($newId, $oldIds, $evidence): bool {
            $locked = Customer::query()
                ->whereKey(array_merge([$newId], $oldIds))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Customer|null $new */
            $new = $locked->get($newId);
            if (! $new || $new->status !== 'pending_installation') {
                return false;
            }
            $serial = strtolower(trim((string) $new->onu_serial));
            foreach ($oldIds as $oldId) {
                $old = $locked->get($oldId);
                if (! $old
                    || ! in_array((string) $old->status, self::TAKEOVER_FROM_STATUSES, true)
                    || strtolower(trim((string) $old->onu_serial)) !== $serial) {
                    return false;
                }
            }

            // Roadmap 1.3 PR-4c (option C): old accounts are NOT terminated here.
            // They keep their status and get a pending termination (billing
            // hold); the sweeper terminates them only after the full guard
            // passes (traffic/PPPoE of the old account, takeover-aware ONU
            // signal). No guard call in this command.
            $at = OpsTime::now()->toDateTimeString();
            $oldStatuses = [];
            $deferral = GuardDecision::fromSignals('terminate', [
                GuardSignal::unknown(GuardSignal::PPPOE, 'not_evaluated_takeover', 'Pagar belum dievaluasi (ambil alih ONU); sweeper memeriksa.'),
            ]);
            foreach ($oldIds as $oldId) {
                /** @var Customer $old */
                $old = $locked->get($oldId);
                $oldStatuses[$oldId] = (string) $old->status;

                // A10: deferral only (unknown verdict never terminates); wrapped for safety.
                LifecycleBackstop::allow([(int) $oldId], 'auto_activate_takeover', fn () => app(LifecycleTerminationService::class)->terminateOrDefer(
                    $old,
                    $deferral,
                    'takeover',
                    ['takeover_customer_id' => $newId],
                ));

                activity()
                    ->performedOn($old)
                    ->event('termination_deferred_reused_onu')
                    ->withProperties([
                        'reason' => self::TAKEOVER_REASON.' (old termination deferred)',
                        'status' => $oldStatuses[$oldId],
                        'new_customer_id' => $newId,
                        'onu_serial' => $new->onu_serial,
                        'at_wib' => $at,
                    ])
                    ->log(self::TAKEOVER_REASON.' (old termination deferred)');
            }

            // Eloquent update => Customer::updated hook => current-period invoice.
            $new->update(['status' => 'active']);

            activity()
                ->performedOn($new)
                ->event('auto_activated')
                ->withProperties([
                    'reason' => self::REASON,
                    'from' => 'pending_installation',
                    'to' => 'active',
                    'at_wib' => $at,
                    'took_over_from' => $oldIds,
                    'evidence' => $evidence,
                ])
                ->log(self::REASON);

            Log::info('[customers:auto-activate-online] took over reused ONU', [
                'new_customer_id' => $newId,
                'old_customer_ids' => $oldIds,
                'old_statuses' => $oldStatuses,
                'onu_serial' => $new->onu_serial,
                'evidence' => $evidence,
            ]);

            return true;
        });
    }
}
