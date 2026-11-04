<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-09: an OPEN pickup case whose device (serial) is now the
 * onu_serial of ANOTHER live customer (PSB finalize, customer edit, reused-ONU
 * takeover, migration) has nothing left to collect: it is closed as `reused`
 * ("Dipakai ulang") with an audited event. Never deletes the ONU and never
 * changes either customer. Idempotent.
 *
 * Settings:
 *  - device_recovery_close_reused_enabled (default ON): kill switch for the
 *    customer hook and the daily sweep.
 *  - device_recovery_close_reused_apply (default OFF): the daily sweep only
 *    reports (dry-run) until the owner turns this on. The hook is live.
 */
class DeviceRecoveryReuseCloser
{
    public const OPEN_STATUSES = [
        DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT,
        DeviceRecoveryService::STATUS_ASSIGNED,
        DeviceRecoveryService::STATUS_SCHEDULED,
        DeviceRecoveryService::STATUS_PICKUP_STARTED,
        DeviceRecoveryService::STATUS_CARRIED,
        DeviceRecoveryService::STATUS_EXCEPTION,
    ];

    /** States where the technician may already have touched the device. */
    private const AFTER_PICKUP_STATUSES = [
        DeviceRecoveryService::STATUS_PICKUP_STARTED,
        DeviceRecoveryService::STATUS_CARRIED,
        DeviceRecoveryService::STATUS_EXCEPTION,
    ];

    public const EVENT_TYPE = 'reused_closed';

    private const SERIAL_SQL = "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(onu_serial, ''), '-', ''), ' ', ''), ':', ''), '.', ''))";

    public function __construct(private DeviceRecoveryService $recovery) {}

    public function isEnabled(): bool
    {
        if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
            return false;
        }
        if (! Schema::hasTable('device_recovery_cases')) {
            return false;
        }

        return (bool) filter_var(
            Setting::get('device_recovery_close_reused_enabled', true),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    public function sweepApplyEnabled(): bool
    {
        return (bool) filter_var(
            Setting::get('device_recovery_close_reused_apply', false),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * Hook entry: $owner just got (or kept, on reactivation) an onu_serial.
     *
     * @return list<int> closed case ids
     */
    public function closeForCustomer(Customer $owner, string $source = 'customer_saved'): array
    {
        if (in_array((string) $owner->status, DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES, true)
            || $owner->trashed()) {
            return [];
        }
        $serial = $this->recovery->normalizeSerial($owner->onu_serial);
        if ($serial === null || ! $this->isEnabled()) {
            return [];
        }
        if (! DeviceRecoveryCase::query()->whereIn('status', self::OPEN_STATUSES)->exists()) {
            return [];
        }

        $closed = [];
        foreach ($this->openCasesForSerial($serial, (int) $owner->id) as $case) {
            if ($this->close($case, $owner, $serial, $source)) {
                $closed[] = (int) $case->id;
            }
        }

        return $closed;
    }

    /**
     * Daily safety net (writers that bypass Eloquent events). Report rows; when
     * $apply, closes the matched cases.
     *
     * @return list<array<string, mixed>>
     */
    public function sweep(bool $apply, ?User $actor = null): array
    {
        $rows = [];
        DeviceRecoveryCase::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->with(['items', 'ticket:id,code', 'customer' => fn ($q) => $q->withTrashed()])
            ->chunkById(100, function (Collection $cases) use (&$rows, $apply, $actor): void {
                foreach ($cases as $case) {
                    foreach ($this->caseSerials($case) as $serial) {
                        $owners = Customer::query()
                            ->whereKeyNot((int) $case->customer_id)
                            ->whereNotIn('status', DeviceRecoveryService::RELEASED_CUSTOMER_STATUSES)
                            ->whereRaw(self::SERIAL_SQL.' = ?', [$serial])
                            ->orderBy('id')
                            ->get(['id', 'code', 'status', 'onu_serial']);
                        if ($owners->isEmpty()) {
                            continue;
                        }

                        $owner = $owners->first();
                        $result = $apply
                            ? ($this->close($case, $owner, $serial, 'daily_sweep', $actor, $owners->pluck('id')->all()) ? 'closed' : 'skipped')
                            : 'would_close';
                        $rows[] = [
                            'case_id' => (int) $case->id,
                            'ticket' => $case->ticket?->code,
                            'case_status' => $case->status,
                            'case_customer_id' => (int) $case->customer_id,
                            'serial' => $serial,
                            'live_customer_ids' => $owners->pluck('id')->implode('|'),
                            'live_customer_code' => $owner->code,
                            'result' => $result,
                        ];
                        break;
                    }
                }
            });

        return $rows;
    }

    /**
     * Prefilter (cheap, indexed/small tables) then confirm in PHP that the
     * case's effective serial matches.
     *
     * @return Collection<int, DeviceRecoveryCase>
     */
    public function openCasesForSerial(string $serial, int $excludeCustomerId): Collection
    {
        return DeviceRecoveryCase::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('customer_id', '!=', $excludeCustomerId)
            ->where(function ($query) use ($serial): void {
                $query->whereHas('items', fn ($items) => $items->where(fn ($match) => $match
                    ->where('captured_serial_number', $serial)
                    ->orWhere('expected_serial_number', $serial)))
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->withTrashed()
                        ->whereRaw(self::SERIAL_SQL.' = ?', [$serial]));
            })
            ->with(['items', 'customer' => fn ($q) => $q->withTrashed()])
            ->orderBy('id')
            ->get()
            ->filter(fn (DeviceRecoveryCase $case): bool => in_array($serial, $this->caseSerials($case), true))
            ->values();
    }

    /**
     * Effective serials of a case: per item captured ?: expected; when no item
     * has a serial, the case customer's onu_serial.
     *
     * @return list<string>
     */
    public function caseSerials(DeviceRecoveryCase $case): array
    {
        $serials = $case->items
            ->map(fn ($item): ?string => $this->recovery->normalizeSerial($item->captured_serial_number ?: $item->expected_serial_number))
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($serials !== []) {
            return $serials;
        }

        $customer = $case->customer ?: Customer::withTrashed()->find($case->customer_id);
        $fallback = $this->recovery->normalizeSerial($customer?->onu_serial);

        return $fallback !== null ? [$fallback] : [];
    }

    /**
     * @param  list<int>  $candidateIds
     */
    private function close(
        DeviceRecoveryCase $case,
        Customer $owner,
        string $serial,
        string $source,
        ?User $actor = null,
        array $candidateIds = [],
    ): bool {
        $key = 'reused:case:'.$case->id.':customer:'.$owner->id;

        return DB::transaction(function () use ($case, $owner, $serial, $source, $actor, $candidateIds, $key): bool {
            $locked = DeviceRecoveryCase::query()->lockForUpdate()->find($case->id);
            if (! $locked || ! in_array($locked->status, self::OPEN_STATUSES, true)) {
                return false;
            }
            if (DeviceRecoveryEvent::query()->where('idempotency_key', $key)->exists()) {
                return false;
            }

            $from = (string) $locked->status;
            $reason = 'ONU dipakai ulang oleh pelanggan '.($owner->code ?: '-').' (#'.$owner->id.')';
            $reuse = [
                'customer_id' => (int) $owner->id,
                'customer_code' => $owner->code,
                'serial' => $serial,
                'source' => $source,
                'from_status' => $from,
                'reuse_after_pickup' => in_array($from, self::AFTER_PICKUP_STATUSES, true),
                'candidate_customer_ids' => $candidateIds !== [] ? array_values($candidateIds) : [(int) $owner->id],
                'at' => now()->toIso8601String(),
            ];
            $meta = is_array($locked->meta) ? $locked->meta : [];
            $meta['reused'] = $reuse;

            $locked->forceFill([
                'status' => DeviceRecoveryService::STATUS_REUSED,
                'exception_reason' => $reason,
                'updated_by' => $actor?->id,
                'version' => (int) $locked->version + 1,
                'meta' => $meta,
            ])->save();

            DeviceRecoveryEvent::create([
                'recovery_case_id' => $locked->id,
                'item_id' => null,
                'actor_id' => $actor?->id,
                'event_type' => self::EVENT_TYPE,
                'from_status' => $from,
                'to_status' => DeviceRecoveryService::STATUS_REUSED,
                'reason' => $reason,
                'source' => 'system',
                'idempotency_key' => $key,
                'metadata' => $reuse,
            ]);

            return true;
        });
    }
}
