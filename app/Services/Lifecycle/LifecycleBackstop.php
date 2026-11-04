<?php

namespace App\Services\Lifecycle;

use App\Exceptions\LifecycleBackstopException;
use App\Jobs\SendLifecycleBackstopAlertJob;
use App\Models\Customer;
use App\Models\OltOnu;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Roadmap 1.3 A10: model-level backstop for terminate/archive writes that did
 * NOT go through the lifecycle guard.
 *
 * Guarded writers wrap their write in LifecycleBackstop::allow(). Any other
 * Customer update to `terminated` (from a non-terminal status) or delete of a
 * non-terminated customer is checked against LOCAL data only (no HTTP, no
 * Grafana): customers.is_online with a fresh pppoe_online_checked_at, or a
 * fresh `online` olt_onus row for the serial. Provably in use means:
 *  - log mode (default): Log::warning + activity lifecycle_backstop_violation
 *    (ids/source only, no PII); the write proceeds unchanged,
 *  - enforce mode (LIFECYCLE_GUARD_BACKSTOP_MODE=enforce): LifecycleBackstopException.
 * Creating terminated stub rows is never checked (only updating/deleting hooks).
 */
final class LifecycleBackstop
{
    /** @var list<array{ids: list<int>|null, source: string}> */
    private static array $allowStack = [];

    /**
     * Run $callback with the given customers (null = any customer) allowed to
     * be terminated/archived. The caller is responsible for having applied the
     * lifecycle guard.
     *
     * @template T
     *
     * @param  list<int>|null  $customerIds
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function allow(?array $customerIds, string $source, Closure $callback): mixed
    {
        self::$allowStack[] = [
            'ids' => $customerIds === null ? null : array_map('intval', $customerIds),
            'source' => $source,
        ];

        try {
            return $callback();
        } finally {
            array_pop(self::$allowStack);
        }
    }

    public static function isAllowed(int $customerId): bool
    {
        foreach (self::$allowStack as $entry) {
            if ($entry['ids'] === null || in_array($customerId, $entry['ids'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function mode(): string
    {
        return config('lifecycle_guard.backstop_mode') === 'enforce' ? 'enforce' : 'log';
    }

    /** Model hook: Customer::updating. */
    public static function checkUpdating(Customer $customer): void
    {
        if (! $customer->isDirty('status') || (string) $customer->status !== 'terminated') {
            return;
        }
        if ((string) $customer->getOriginal('status') === 'terminated') {
            return;
        }

        self::check($customer, 'terminate');
    }

    /** Model hook: Customer::deleting. */
    public static function checkDeleting(Customer $customer): void
    {
        if ((string) $customer->status === 'terminated') {
            return;
        }

        self::check($customer, 'archive');
    }

    private static function check(Customer $customer, string $event): void
    {
        $id = (int) $customer->getKey();
        if ($id <= 0 || self::isAllowed($id)) {
            return;
        }

        try {
            $reasons = self::inUseReasons($customer);
        } catch (Throwable $e) {
            // Never break a save because the backstop itself failed.
            Log::warning('[LifecycleBackstop] check failed', ['customer_id' => $id, 'exception_class' => $e::class]);

            return;
        }
        if ($reasons === []) {
            return;
        }

        $source = self::entryPoint();

        if (self::mode() === 'enforce') {
            Log::warning('[LifecycleBackstop] enforced', ['customer_id' => $id, 'event' => $event, 'source' => $source, 'reasons' => $reasons]);

            self::alertRefusal($id, $event, $source, $reasons);

            throw new LifecycleBackstopException($id, (string) $customer->code, $reasons);
        }

        // Log mode never throws: the whole tail is fail-open.
        try {
            Log::warning('[LifecycleBackstop] violation (log mode, write proceeds)', [
                'customer_id' => $id, 'event' => $event, 'source' => $source, 'reasons' => $reasons,
            ]);

            activity()
                ->performedOn($customer)
                ->event('lifecycle_backstop_violation')
                ->withProperties(['customer_id' => $id, 'attempt' => $event, 'source' => $source, 'reasons' => $reasons, 'mode' => 'log'])
                ->log('lifecycle_backstop_violation');
        } catch (Throwable) {
            // nothing: never break the save because logging failed
        }
    }

    /**
     * N8: enforce-refusal ops alert. Never sent inline (the save may hold DB
     * locks): a queued job on the wa queue, pushed immediately (NOT afterCommit,
     * because the refusal throws and rolls the transaction back, which would
     * discard an afterCommit job). Bounded: once per customer per day, at most
     * backstop_alert_per_source_hour per source per hour and
     * backstop_alert_per_hour overall; the rest only go to the log. Any failure
     * here is swallowed: the refusal is never affected.
     *
     * @param  list<string>  $reasons
     */
    private static function alertRefusal(int $id, string $event, string $source, array $reasons): void
    {
        try {
            $hour = date('YmdH');
            $perSource = max(1, (int) config('lifecycle_guard.backstop_alert_per_source_hour', 3));
            $overall = max(1, (int) config('lifecycle_guard.backstop_alert_per_hour', 10));

            if (! Cache::add('lifecycle-backstop-alert:cust:'.$id.':'.date('Ymd'), 1, 86400)) {
                return;
            }
            $sourceKey = 'lifecycle-backstop-alert:src:'.md5($source).':'.$hour;
            $globalKey = 'lifecycle-backstop-alert:all:'.$hour;
            Cache::add($sourceKey, 0, 3600);
            Cache::add($globalKey, 0, 3600);
            if ((int) Cache::increment($sourceKey) > $perSource || (int) Cache::increment($globalKey) > $overall) {
                Log::warning('[LifecycleBackstop] enforce alert suppressed (rate cap)', ['customer_id' => $id, 'source' => $source]);

                return;
            }

            SendLifecycleBackstopAlertJob::dispatch(
                'Pagar terminate (enforce) menolak '.$event.' pelanggan #'.$id.' ('.implode(', ', $reasons).'); sumber: '.$source.'. Writer ini belum dibungkus pagar. (Batas notifikasi per jam berlaku; sisanya ada di log.)',
                'lifecycle-backstop-enforce:'.$id.':'.date('Ymd'),
            );
        } catch (Throwable) {
            // alert path must never change the refusal
        }
    }

    /**
     * Public read of the same local evidence (customers.is_online with a fresh check,
     * fresh online olt_onus row) for callers that need the backstop's freshness notion.
     *
     * @return list<string> reason codes (pppoe_in_use, onu_in_use)
     */
    public static function localUseReasons(Customer $customer, bool $strict = false): array
    {
        return self::inUseReasons($customer, $strict);
    }

    /**
     * LOCAL evidence only. @return list<string> reason codes (pppoe_in_use, onu_in_use)
     */
    private static function inUseReasons(Customer $customer, bool $strict = false): array
    {
        $fresh = max(1, (int) config('lifecycle_guard.backstop_fresh_minutes', 15));
        $cutoff = CarbonImmutable::now()->subMinutes($fresh);
        $reasons = [];

        $checkedAt = $customer->pppoe_online_checked_at;
        if ((bool) $customer->is_online && $checkedAt !== null && $checkedAt->greaterThanOrEqualTo($cutoff)) {
            $reasons[] = 'pppoe_in_use';
        }

        // N7: only the olt_onus read is guarded here, so a failure of that read
        // never discards the PPPoE evidence computed above (it needs no query).
        try {
            if (self::freshOnuOnline($customer->onu_serial, $cutoff)) {
                $reasons[] = 'onu_in_use';
            }
        } catch (Throwable $e) {
            if ($strict) {
                // Fail closed for callers that use this as proof of non-use (override).
                throw $e;
            }
            Log::warning('[LifecycleBackstop] olt_onus evidence read failed', ['customer_id' => (int) $customer->getKey(), 'exception_class' => $e::class]);
        }

        return $reasons;
    }

    /**
     * Fresh `online` olt_onus row for the serial. Forms include the GPON ASCII/hex
     * twin. Pass 1 matches the indexed serial_number column exactly (common case,
     * no scan). Only when that finds no online row does pass 2 run the
     * separator-tolerant compare (OltCollectorService style: UPPER + strip
     * '-', ' ', ':', '.', '_', '/') restricted to rows collected inside the fresh
     * window. Pass 2 cannot use the serial_number index, but it only runs on
     * unwrapped terminate/archive transitions that have no other proof of use.
     */
    private static function freshOnuOnline(mixed $serial, CarbonImmutable $cutoff): bool
    {
        $forms = CustomerLifecycleGuard::serialForms($serial);
        if ($forms === []) {
            return false;
        }

        $exact = DB::table('olt_onus')
            ->whereIn('serial_number', $forms)
            ->where('state_collected_at', '>=', $cutoff)
            ->get(['state', 'phase_state']);
        if (self::anyOnline($exact)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($forms), '?'));
        $tolerant = DB::table('olt_onus')
            ->where('state_collected_at', '>=', $cutoff)
            ->whereRaw(CustomerLifecycleGuard::serialSql('serial_number').' IN ('.$placeholders.')', $forms)
            ->get(['state', 'phase_state']);

        return self::anyOnline($tolerant);
    }

    /** @param  iterable<object>  $rows */
    private static function anyOnline(iterable $rows): bool
    {
        foreach ($rows as $row) {
            if (OltOnu::normalizeOperationalState($row->state, $row->phase_state) === 'online') {
                return true;
            }
        }

        return false;
    }

    private static function entryPoint(): string
    {
        $entry = 'unknown';
        try {
            $entry = app()->runningInConsole()
                ? 'console:'.(string) ($_SERVER['argv'][1] ?? 'unknown')
                : 'http:'.(string) (request()->route()?->getName() ?? 'unnamed');
        } catch (Throwable) {
            // keep 'unknown'
        }

        $caller = self::callerFrame();

        return $caller === null ? $entry : $entry.' '.$caller;
    }

    /** First non-vendor, non-backstop app frame as Class::method (no args). */
    private static function callerFrame(): ?string
    {
        try {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
                $class = (string) ($frame['class'] ?? '');
                if ($class === '' || $class === self::class || $class === Customer::class || ! str_starts_with($class, 'App\\')) {
                    continue;
                }

                return $class.'::'.(string) ($frame['function'] ?? '');
            }
        } catch (Throwable) {
            // fall through
        }

        return null;
    }
}
