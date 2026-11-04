<?php

namespace App\Console\Commands;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryItem;
use App\Models\Setting;
use App\Services\DeviceRecoveryOltCleanupService;
use App\Services\DeviceRecoveryService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Roadmap 1.3 PR-4b: sweeper for guard-deferred terminations.
 *
 *  - Part 1: pending rows are re-evaluated by the lifecycle guard. Report mode
 *    (default, lifecycle_deferred_sweeper_apply=0) only records the latest
 *    verdict; apply mode (setting or --apply) terminates a customer once the
 *    guard allows. --dry-run writes nothing. The guard is NEVER overridden and
 *    a row is NEVER cancelled here (only an admin cancels).
 *  - Part 2: held ONU deletes are re-dispatched (default OFF).
 *  - --digest: the daily ops WA (text T5).
 *
 * Pending rows are only ever changed through Eloquent save() (the model's
 * saving hook keeps open_key = customer_id while pending, NULL otherwise); a
 * bulk query update would break the one-open-row-per-customer invariant.
 */
class FinalizeDeferredTerminations extends Command
{
    public const SETTING_DIGEST_ENABLED = 'lifecycle_pending_digest_enabled';

    public const SETTING_ONU_REDISPATCH = 'lifecycle_deferred_onu_redispatch_apply';

    public const SETTING_SWEEPER_ENABLED = 'lifecycle_deferred_sweeper_enabled';

    public const SETTING_SWEEPER_APPLY = 'lifecycle_deferred_sweeper_apply';

    private const STAGGER_SECONDS = 120;

    private const REDISPATCH_MIN_AGE_HOURS = 20;

    private const REDISPATCH_MAX_PER_RUN = 10;

    private const REDISPATCH_SCAN_MAX = 50;

    private const DIGEST_MAX_LINES = 30;

    /** OpsAlertService::send cuts the message at 3500 chars; keep headroom for sanitising. */
    private const DIGEST_MAX_CHARS = 3400;

    private const LONG_PENDING_DAYS = 14;

    private const HELD_ONU_REASONS = ['guard_hold', 'serial_in_use', 'live_state_unknown', 'live_verify_failed'];

    protected $signature = 'customers:finalize-deferred-terminations
        {--dry-run : Evaluate and print only, write nothing (with --digest: print the message, do not send)}
        {--apply : Finalize rows whose guard now allows, even when lifecycle_deferred_sweeper_apply is off}
        {--limit=200 : Max pending rows examined per run}
        {--digest : Send the daily ops digest of pending terminations (WA, deduped per day)}';

    protected $description = 'Sweeper for guard-deferred terminations (report-only until enabled), held ONU delete re-dispatch (off by default) and the daily ops digest';

    public function handle(OpsAlertService $ops, LifecycleTerminationService $termination): int
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($this->option('digest')) {
            $rows = CustomerPendingTermination::query()
                ->pending()
                ->with('customer:id,code,name')
                ->orderBy('requested_at')
                ->limit($limit)
                ->get();

            return $this->digest($ops, $rows, (bool) $this->option('dry-run'));
        }

        if (! self::flag(self::SETTING_SWEEPER_ENABLED, true)) {
            $this->info('sweeper disabled (lifecycle_deferred_sweeper_enabled=0). Nothing was changed.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        // --dry-run always wins; --apply forces; the setting default is report-only.
        $apply = ! $dry && ($this->option('apply') || self::flag(self::SETTING_SWEEPER_APPLY, false));
        $mode = $dry ? 'dry-run' : ($apply ? 'apply' : 'report');

        $stats = ['examined' => 0, 'allow' => 0, 'deny' => 0, 'unknown' => 0, 'finalized' => 0, 'closed' => 0, 'onu_dispatched' => 0, 'waiting_olt_slot' => 0, 'not_terminable' => 0, 'errors' => 0];
        $dispatched = [];
        $oltSlots = [];
        /** @var array<string, int> $errorReasons exception class => count (never messages: they may carry data) */
        $errorReasons = [];
        /** @var list<int> $errorRowIds */
        $errorRowIds = [];

        $rows = CustomerPendingTermination::query()
            ->pending()
            // never-checked rows first, then the stalest check
            ->orderByRaw('last_checked_at IS NULL DESC')
            ->orderBy('last_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $stats['examined']++;
            try {
                $this->sweepRow($row, $termination, $mode, $stats, $oltSlots, $dispatched);
            } catch (\Throwable $e) {
                // One deadlock / duplicate must not stop the run; the row is retried next run.
                $stats['errors']++;
                $errorReasons[$e::class] = ($errorReasons[$e::class] ?? 0) + 1;
                $errorRowIds[] = (int) $row->id;
                Log::warning('[lifecycle] deferred termination sweep row failed', ['row_id' => $row->id, 'exception_class' => $e::class]);
                $this->line(sprintf('#%d -> error %s (skipped, retried next run)', $row->id, $e::class));
            }
        }

        $held = $this->redispatchHeldOnuDeletes($dry);

        $total = CustomerPendingTermination::query()->pending()->count();
        $this->info(sprintf(
            'mode=%s pending=%d examined=%d allow=%d deny=%d unknown=%d finalized=%d closed=%d onu_dispatched=%d waiting_olt_slot=%d not_terminable=%d held_onu_candidates=%d held_onu_dispatched=%d errors=%d error_reasons=%s',
            $mode,
            $total,
            $stats['examined'],
            $stats['allow'],
            $stats['deny'],
            $stats['unknown'],
            $stats['finalized'],
            $stats['closed'],
            $stats['onu_dispatched'],
            $stats['waiting_olt_slot'],
            $stats['not_terminable'],
            $held['candidates'],
            $held['dispatched'],
            $stats['errors'],
            $errorReasons === [] ? '-' : implode(',', array_map(static fn (string $class, int $n): string => $class.':'.$n, array_keys($errorReasons), $errorReasons)),
        ));
        Log::info('[lifecycle] deferred terminations sweep', $stats + [
            'error_reasons' => $errorReasons,
            'error_row_ids' => array_slice($errorRowIds, 0, 50),
            'mode' => $mode,
            'pending' => $total,
            'finalized_row_ids' => $dispatched,
            'held_onu_candidates' => $held['candidates'],
            'held_onu_dispatched' => $held['dispatched'],
        ]);

        return self::SUCCESS;
    }

    /**
     * Part 1 for one pending row. Never overrides the guard, never cancels.
     *
     * @param  array<string, int>  $stats
     * @param  array<int|string, bool>  $oltSlots
     * @param  list<int>  $finalizedIds
     */
    private function sweepRow(CustomerPendingTermination $row, LifecycleTerminationService $termination, string $mode, array &$stats, array &$oltSlots, array &$finalizedIds): void
    {
        $dry = $mode === 'dry-run';
        $line = sprintf('#%d customer=%d source=%s', $row->id, $row->customer_id, $row->source);

        $customer = Customer::withTrashed()->find($row->customer_id);
        if (! $customer || $customer->trashed()) {
            if (! $dry) {
                $termination->closeRow($row, 'customer_archived');
            }
            $stats['closed']++;
            $this->line($line.' -> '.($dry ? 'would_close' : 'closed').' customer_archived');

            return;
        }
        if (in_array((string) $customer->status, DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES, true)) {
            if (! $dry) {
                $termination->closeRow($row, 'already_terminated');
            }
            $stats['closed']++;
            $this->line($line.' -> '.($dry ? 'would_close' : 'closed').' already_terminated');

            return;
        }

        $allowedFrom = $row->allowedFromStatuses();
        if (! in_array((string) $customer->status, $allowedFrom, true)) {
            $stats['not_terminable']++;
            if (! $dry) {
                $termination->touchChecked($row); // rotate: must not starve the other rows
            }
            $this->line($line.' status='.$customer->status.' -> not_terminable (stays pending)');

            return;
        }

        [$context, $linkBroken] = $this->contextFor($row, $customer);
        $decision = $termination->preVerdict($customer, $context);
        $stats[$decision->verdict]++;
        $line .= sprintf(' verdict=%s reasons=%s%s', $decision->verdict, implode('|', $decision->reasonCodes()), $linkBroken ? ' takeover_link_broken' : '');

        if ($dry) {
            $this->line($line.' (dry-run: nothing written)');

            return;
        }

        $termination->recordCheck($row, $decision, $linkBroken ? ['takeover_link_broken' => true] : []);

        if (! $decision->allowed()) {
            $this->line($line.' -> stays pending');

            return;
        }
        if ($mode !== 'apply') {
            $this->line($line.' -> would finalize (report-only: lifecycle_deferred_sweeper_apply off)');

            return;
        }

        // Warehouse rows also owe an ONU delete: at most one per OLT per run (one OLT write at a time).
        $case = $this->onuDeleteCase($row);
        $oltKey = self::oltKey($customer->olt_id);
        if ($case !== null && isset($oltSlots[$oltKey])) {
            $stats['waiting_olt_slot']++;
            $this->line($line.' -> waiting_olt_slot (next run)');

            return;
        }

        if (! $termination->finalize($row, $decision)) {
            $this->line($line.' -> finalize_skipped (row/customer changed)');

            return;
        }
        $stats['finalized']++;
        $finalizedIds[] = (int) $row->id;
        $this->line($line.' -> finalized (terminated)');

        if ($case !== null) {
            $this->dispatchOnuDelete($case, $stats['onu_dispatched']); // index = ONU jobs only
            $oltSlots[$oltKey] = true;
            $stats['onu_dispatched']++;
        }
    }

    /**
     * Takeover / recap_reused rows whose new account still holds the ONU are
     * evaluated with the ONU signal waived; otherwise the full context applies.
     *
     * @return array{0: GuardContext, 1: bool} context, link broken
     */
    private function contextFor(CustomerPendingTermination $row, Customer $old): array
    {
        $isLinked = $row->isTakeoverLinked();
        if (! $isLinked || ! $row->takeover_customer_id) {
            return [GuardContext::automated('deferred_sweeper'), $isLinked && $row->source === 'takeover'];
        }

        $new = Customer::query()->find($row->takeover_customer_id); // excludes trashed
        $valid = $new !== null
            && in_array((string) $new->status, ['active', 'isolated'], true)
            && $this->holdsSerialOf($new, $old, $row);

        return $valid
            ? [GuardContext::automated('deferred_sweeper', (int) $new->id), false]
            : [GuardContext::automated('deferred_sweeper'), true];
    }

    /**
     * The new account holds the ONU when its serial equals the old account's
     * stored serial OR a serial of the row's case (a recap SN may differ from
     * the stored one).
     */
    private function holdsSerialOf(Customer $new, Customer $old, CustomerPendingTermination $row): bool
    {
        $recovery = app(DeviceRecoveryService::class);
        $serials = [$old->onu_serial];
        if ($row->recovery_case_id) {
            foreach (DeviceRecoveryItem::query()->where('recovery_case_id', $row->recovery_case_id)->get(['captured_serial_number', 'expected_serial_number']) as $item) {
                $serials[] = $item->captured_serial_number;
                $serials[] = $item->expected_serial_number;
            }
        }
        foreach ($serials as $serial) {
            if ($serial !== null && $recovery->serialsMatch($new->onu_serial, $serial)) {
                return true;
            }
        }

        return false;
    }

    /** The received warehouse case of a row that still needs its ONU deleted, else null. */
    private function onuDeleteCase(CustomerPendingTermination $row): ?DeviceRecoveryCase
    {
        if (! in_array($row->source, ['warehouse_receive', 'warehouse_quick_record'], true) || ! $row->recovery_case_id) {
            return null;
        }
        if ($row->isTakeoverLinked()) {
            return null; // the new account owns the ONU now: never delete it
        }
        if (! app(DeviceRecoveryOltCleanupService::class)->isEnabled()) {
            return null;
        }
        $case = DeviceRecoveryCase::query()->find($row->recovery_case_id);
        if (! $case || $case->status !== DeviceRecoveryService::STATUS_RECEIVED) {
            return null;
        }
        if ((((array) $case->meta)['olt_onu_delete']['ok'] ?? false) === true) {
            return null;
        }

        return $case;
    }

    /** Staggered (index * 120 s) so deletes never pile onto one OLT window. */
    private function dispatchOnuDelete(DeviceRecoveryCase $case, int $index): void
    {
        DeleteDismantledOnuJob::dispatch($case)->delay(now()->addSeconds(max(0, $index) * self::STAGGER_SECONDS));
    }

    /**
     * Part 2: received pickup cases whose ONU delete was held (guard / serial
     * in use / live checks) get another try once nothing blocks any more. The
     * job still does the live read + guard + OltOperationLock itself. Off by
     * default (lifecycle_deferred_onu_redispatch_apply); never in a dry-run.
     *
     * @return array{candidates: int, dispatched: int}
     */
    private function redispatchHeldOnuDeletes(bool $dry): array
    {
        $out = ['candidates' => 0, 'dispatched' => 0];
        // Feature off (setting or cleanup): skip the whole scan, no guard calls at all.
        if (! self::flag(self::SETTING_ONU_REDISPATCH, false)
            || ! app(DeviceRecoveryOltCleanupService::class)->isEnabled()) {
            return $out;
        }
        $apply = ! $dry;
        $threshold = now()->subHours(self::REDISPATCH_MIN_AGE_HOURS);

        $found = [];
        DeviceRecoveryCase::query()
            ->where('status', DeviceRecoveryService::STATUS_RECEIVED)
            ->select(['id', 'customer_id', 'meta'])
            ->chunkById(500, function ($cases) use (&$found, $threshold): void {
                foreach ($cases as $case) {
                    $delete = (array) (((array) $case->meta)['olt_onu_delete'] ?? []);
                    if ($delete === [] || ($delete['ok'] ?? false) === true
                        || ! in_array($delete['reason'] ?? null, self::HELD_ONU_REASONS, true)) {
                        continue;
                    }
                    $at = isset($delete['at']) ? CarbonImmutable::parse((string) $delete['at']) : null;
                    if ($at !== null && $at->greaterThan($threshold)) {
                        continue; // at most one re-check per case per ~day
                    }
                    $found[] = ['case_id' => (int) $case->id, 'customer_id' => $case->customer_id, 'serial' => (string) ($delete['serial'] ?? ''), 'at' => $at?->getTimestamp() ?? 0];
                }
            });
        usort($found, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        $oltSlots = [];
        $guard = app(LifecycleTerminationService::class);
        foreach (array_slice($found, 0, self::REDISPATCH_SCAN_MAX) as $candidate) {
            $label = '#'.$candidate['case_id'];
            if ($out['dispatched'] >= self::REDISPATCH_MAX_PER_RUN) {
                break;
            }
            $skip = $this->redispatchSkipReason($candidate, $guard);
            if ($skip !== null) {
                $this->line("held-onu {$label} -> skip {$skip}");

                continue;
            }
            $out['candidates']++;

            $case = DeviceRecoveryCase::query()->find($candidate['case_id']);
            $oltKey = self::oltKey(Customer::withTrashed()->whereKey($candidate['customer_id'])->value('olt_id'));
            if (! $apply) {
                $this->line("held-onu {$label} -> would_redispatch (dry-run)");

                continue;
            }
            if ($case === null || isset($oltSlots[$oltKey])) {
                $this->line("held-onu {$label} -> waiting_olt_slot (next run)");

                continue;
            }
            if (! Cache::add('deferred-onu-redispatch:'.$case->id, 1, now()->addHours(self::REDISPATCH_MIN_AGE_HOURS))) {
                $this->line("held-onu {$label} -> skip recently_redispatched");

                continue;
            }
            $this->dispatchOnuDelete($case, $out['dispatched']);
            $oltSlots[$oltKey] = true;
            $out['dispatched']++;
            $this->line("held-onu {$label} -> redispatched");
        }

        return $out;
    }

    /**
     * Cheap pre-check, no OLT access: skip while a live customer still owns the
     * serial, a termination is pending for the case customer, or PPPoE/traffic
     * of any owner is still in use.
     *
     * @param  array{case_id: int, customer_id: mixed, serial: string, at: int}  $candidate
     */
    private function redispatchSkipReason(array $candidate, LifecycleTerminationService $termination): ?string
    {
        if ($candidate['serial'] === '') {
            return 'no_serial';
        }
        if ($candidate['customer_id'] && CustomerPendingTermination::query()->pending()->where('customer_id', $candidate['customer_id'])->exists()) {
            return 'customer_pending_termination';
        }

        try {
            $customers = [];
            foreach (CustomerLifecycleGuard::ownersOfSerial($candidate['serial']) as $owner) {
                if (! $owner->trashed() && ! in_array((string) $owner->status, DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES, true)) {
                    return 'serial_in_use';
                }
                $customers[$owner->id] = $owner;
            }
            if ($candidate['customer_id'] && ($caseCustomer = Customer::withTrashed()->find($candidate['customer_id']))) {
                $customers[$caseCustomer->id] = $caseCustomer;
            }
            foreach ($customers as $customer) {
                $decision = $termination->preVerdict($customer, GuardContext::automated('deferred_onu_redispatch'));
                foreach ($decision->signals as $signal) {
                    if ($signal->state === GuardSignal::IN_USE && in_array($signal->name, [GuardSignal::PPPOE, GuardSignal::TRAFFIC], true)) {
                        return 'in_use_'.$signal->name;
                    }
                }
            }
        } catch (\Throwable $e) {
            return 'precheck_failed';
        }

        return null;
    }

    /** Slot key per OLT; customers without olt_id share ONE bucket (max 1 delete per run). */
    private static function oltKey(mixed $oltId): string
    {
        return (int) $oltId > 0 ? (string) (int) $oltId : 'unknown';
    }

    private static function flag(string $setting, bool $default): bool
    {
        return filter_var(Setting::get($setting, $default), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** @param Collection<int, CustomerPendingTermination> $rows */
    private function digest(OpsAlertService $ops, Collection $rows, bool $dryRun): int
    {
        $enabled = filter_var(Setting::get(self::SETTING_DIGEST_ENABLED, true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        if (! $enabled) {
            $this->info('digest disabled (lifecycle_pending_digest_enabled=0).');

            return self::SUCCESS;
        }

        $message = $this->buildDigest($rows);
        if ($message === null) {
            $this->info('digest: nothing pending and nothing changed yesterday; not sent.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line($message);
            $this->info('digest: dry-run, not sent.');

            return self::SUCCESS;
        }

        $sent = $ops->send($message, 'deferred-termination-digest:'.OpsTime::now()->format('Ymd'), 86400, '', OpsAlertSource::DEFERRED_TERMINATIONS);
        $this->info('digest: '.($sent ? 'sent' : 'not sent (dedupe/not configured)'));
        Log::info('[lifecycle] pending terminations digest', ['sent' => $sent, 'pending' => $rows->count()]);

        return self::SUCCESS;
    }

    /** Text T5 (owner approved); null when there is nothing to say. */
    public function buildDigest(Collection $rows): ?string
    {
        $now = OpsTime::now();
        [$yStart, $yEnd] = OpsTime::dayBounds($now->subDay());
        $new = CustomerPendingTermination::query()->whereBetween('requested_at', [$yStart, $yEnd])->count();
        $done = CustomerPendingTermination::query()->where('status', CustomerPendingTermination::STATUS_TERMINATED)
            ->whereBetween('resolved_at', [$yStart, $yEnd])->count();
        $cancelled = CustomerPendingTermination::query()->where('status', CustomerPendingTermination::STATUS_CANCELLED)
            ->whereBetween('resolved_at', [$yStart, $yEnd])->count();
        $total = CustomerPendingTermination::query()->pending()->count();

        if ($total === 0 && $new === 0 && $done === 0 && $cancelled === 0) {
            return null;
        }

        $lines = [
            'Terminate tertunda (tahan tagihan) — '.$now->format('d/m/Y').' WIB',
            "Total {$total} pelanggan · kemarin: +{$new} baru, {$done} selesai diterminate, {$cancelled} dibatalkan.",
        ];

        $takeoverCodes = Customer::withTrashed()
            ->whereIn('id', $rows->pluck('takeover_customer_id')->filter()->unique()->all())
            ->pluck('code', 'id');

        $entries = [];
        foreach ($rows->take(self::DIGEST_MAX_LINES)->values() as $i => $row) {
            $since = OpsTime::local($row->requested_at);
            $days = (int) $since->startOfDay()->diffInDays($now->startOfDay());
            $source = match ($row->source) {
                'warehouse_receive', 'warehouse_quick_record' => 'Gudang'.($row->recovery_case_id ? ' #'.$row->recovery_case_id : ''),
                'takeover' => 'Ambil alih oleh '.($takeoverCodes[$row->takeover_customer_id] ?? '-'),
                default => 'Impor rekap',
            };
            // N5: an empty name must not leave a double space.
            $who = trim(($row->customer?->code ?? '#'.$row->customer_id).' '.trim((string) ($row->customer?->name ?? '')));
            $entries[] = sprintf(
                '%d. %s — sejak %s (%d hari) · %s · %s',
                $i + 1,
                $who,
                $since->format('d/m'),
                $days,
                $source,
                LifecycleTerminationService::reasonLabel($row->last_reasons),
            );
        }

        // Footer lines (never cut).
        $tail = [];
        $cutoff = now()->subDays(self::LONG_PENDING_DAYS);
        $long = CustomerPendingTermination::query()->pending()->where('requested_at', '<', $cutoff)->count();
        if ($long > 0) {
            $tail[] = 'Lebih dari '.self::LONG_PENDING_DAYS." hari: {$long} pelanggan — perlu dicek manual (perangkat sudah di gudang tapi masih terdeteksi dipakai).";
        }

        $heldOnu = $this->heldOnuDeleteCount();
        if ($heldOnu > 0) {
            // The automatic re-check only exists once the owner turns the 4b setting on.
            $auto = filter_var(Setting::get(self::SETTING_ONU_REDISPATCH, false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
            $tail[] = "Hapus ONU ditahan: {$heldOnu} case ".($auto ? '(dicek ulang otomatis harian).' : '(perlu dicek manual).');
        }

        $tail[] = 'Selama tertunda: tagihan baru TIDAK dibuat dan pelanggan TIDAK diisolir. Jika pelanggan masih berlangganan, batalkan di Pelanggan › Detail.';

        // L4: OpsAlertService cuts at 3500 chars. Trim the LIST (not the footer) to fit.
        $assemble = function (int $shown) use ($lines, $entries, $tail, $total): string {
            $out = [...$lines, ...array_slice($entries, 0, $shown)];
            if ($total > $shown) {
                $out[] = 'dan '.($total - $shown).' lainnya';
            }

            return implode("\n", [...$out, ...$tail]);
        };
        $shown = count($entries);
        $message = $assemble($shown);
        while ($shown > 0 && mb_strlen($message) > self::DIGEST_MAX_CHARS) {
            $shown--;
            $message = $assemble($shown);
        }

        return $message;
    }

    /** Received pickup cases whose ONU delete was held by the guard / live checks. */
    private function heldOnuDeleteCount(): int
    {
        $count = 0;
        DeviceRecoveryCase::query()
            ->where('status', 'received')
            ->select(['id', 'meta'])
            ->chunkById(500, function ($cases) use (&$count): void {
                foreach ($cases as $case) {
                    $delete = (array) (((array) $case->meta)['olt_onu_delete'] ?? []);
                    if ($delete === [] || ($delete['ok'] ?? false) === true) {
                        continue;
                    }
                    if (in_array($delete['reason'] ?? null, self::HELD_ONU_REASONS, true)) {
                        $count++;
                    }
                }
            });

        return $count;
    }
}
