<?php

namespace App\Jobs;

use App\Models\Olt;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Services\HuaweiOltSnmpOpticalService;
use App\Services\OltSnapshotBuilder;
use App\Services\Outage\OnuStateEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Huawei optical read lane. It never calls the Huawei SSH writer.
 */
class SyncOltOpticalJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // A worker SIGKILL/OOM leaves the reservation to expire and the job is redelivered
    // as attempt 2. The sync is idempotent, so re-run it instead of failing it.
    public int $tries = 2;

    public int $timeout = 120;

    // A real SNMP hang must still fail loudly on the first attempt, not be retried.
    public bool $failOnTimeout = true;

    public int $uniqueFor = 300;

    public function __construct(public int $oltId)
    {
        $this->onQueue('olt-sync');
    }

    public function uniqueId(): string
    {
        return 'huawei-olt-optical:'.$this->oltId;
    }

    public function middleware(): array
    {
        return [
            // Share the Huawei inventory lock so SNMP cannot race the existing
            // read-only SSH inventory collector. SSH write locks remain separate.
            (new WithoutOverlapping('olt-collect:'.$this->oltId.':huawei'))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(
        HuaweiOltSnmpOpticalService $collector,
        OltSnapshotBuilder $snapshotBuilder,
    ): void {
        $olt = Olt::query()->find($this->oltId);
        if (! $olt || ! $this->isHuawei($olt)) {
            return;
        }

        $run = OltCollectRun::query()->create([
            'olt_id' => $olt->id,
            'tier' => OltCollectRun::TIER_HUAWEI_OPTICAL,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->reapAbandonedRuns($olt, $run);

        try {
            $started = microtime(true);
            $payload = $collector->collect($olt);
            $onus = $payload['onus'] ?? [];
            if ($onus === []) {
                throw new \RuntimeException('Huawei SNMP returned no mapped optical ONU rows.');
            }

            $persisted = $this->persistRows($olt, $onus);
            $snapshotBuilder->materialize($olt->fresh(), syncCustomers: false);

            $run->update([
                'status' => 'success',
                'onus_total' => count($onus),
                'onus_updated' => $persisted['updated'],
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'meta' => array_merge($payload['meta'] ?? [], [
                    'created' => $persisted['created'],
                    'updated' => $persisted['updated'],
                    'state_written' => $persisted['state_written'],
                    'rx_cleared' => $persisted['rx_cleared'],
                ]),
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            $run->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
            ]);
            Log::warning('Huawei OLT optical SNMP sync failed; last snapshot retained.', [
                'olt_id' => $olt->id,
                'exception' => get_class($e),
                'message' => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Close `running` rows left behind by a worker that was killed mid-run.
     * Mirrors OltCollectorService::reapAbandonedRuns.
     */
    private function reapAbandonedRuns(Olt $olt, OltCollectRun $current): int
    {
        try {
            return OltCollectRun::query()
                ->where('olt_id', $olt->id)
                ->where('tier', OltCollectRun::TIER_HUAWEI_OPTICAL)
                ->where('status', 'running')
                ->where('id', '<', $current->id)
                ->where('started_at', '<=', now()->subSeconds($this->timeout + 60))
                ->update([
                    'status' => 'failed',
                    'error' => 'Abandoned: worker stopped before result',
                    'finished_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::debug("OLT optical abandoned-run reap skipped for #{$olt->id}: {$e->getMessage()}");

            return 0;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $onus
     * @return array{created: int, updated: int, state_written: int, rx_cleared: int}
     */
    private function persistRows(Olt $olt, array $onus): array
    {
        if (! Schema::hasTable('olt_onus')) {
            throw new \RuntimeException('olt_onus table is not available.');
        }

        foreach (['pon_port', 'snmp_index', 'serial_number', 'rx_power_dbm', 'distance_m'] as $column) {
            if (! Schema::hasColumn('olt_onus', $column)) {
                throw new \RuntimeException('olt_onus optical schema is incomplete.');
            }
        }

        return DB::transaction(function () use ($olt, $onus): array {
            $now = now();
            $existingByRef = OltOnu::query()
                ->where('olt_id', $olt->id)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (OltOnu $row): string => strtolower((string) $row->onu_ref));
            $created = 0;
            $updated = 0;
            $stateWritten = 0;
            $rxSeenRefs = [];
            $stateIncoming = [];

            foreach ($onus as $onu) {
                $ref = strtolower(trim((string) ($onu['onu_ref'] ?? '')));
                if (! preg_match('/^\d+\/\d+\/\d+:\d+$/', $ref)) {
                    continue;
                }

                $payload = [
                    'pon_port' => $onu['pon_port'] ?? null,
                    'snmp_index' => $onu['snmp_index'] ?? null,
                ];
                $serial = strtoupper(trim((string) ($onu['serial_number'] ?? '')));
                if ($serial !== '') {
                    $payload['serial_number'] = $serial;
                    $payload['serial_source'] = 'huawei_snmp';
                }
                if (($onu['rx_power_dbm'] ?? null) !== null) {
                    $payload['rx_power_dbm'] = (float) $onu['rx_power_dbm'];
                    $payload['rx_source'] = 'huawei_snmp';
                    $payload['rx_collected_at'] = $now;
                }
                if (($onu['distance_m'] ?? null) !== null) {
                    $payload['distance_m'] = (int) $onu['distance_m'];
                }
                if (isset($payload['rx_power_dbm'])) {
                    $rxSeenRefs[$ref] = true;
                }

                // ONT run state (only when HUAWEI_OLT_SNMP_STATE_ENABLED and the
                // walk returned a mapped value). Offline clears RX: a dead ONU
                // must not keep its last optical reading (audit 2026-10-08).
                $state = $onu['state'] ?? null;
                if ($state === 'online' || $state === 'offline') {
                    $payload['state'] = $state;
                    $payload['phase_state'] = $state === 'online' ? 'normal' : 'offline';
                    $payload['state_collected_at'] = $now;
                    if ($state === 'offline') {
                        $payload['rx_power_dbm'] = null;
                        $payload['rx_source'] = 'huawei_snmp';
                        $payload['rx_collected_at'] = $now;
                        unset($rxSeenRefs[$ref]);
                    }
                    $stateWritten++;
                }

                $existing = $existingByRef->get($ref);
                if ($existing && isset($payload['state'])) {
                    // RCA-2: history only (transitions), compared with the row before it is overwritten.
                    $from = OnuStateEvents::phaseKey($existing->state, $existing->phase_state);
                    $to = OnuStateEvents::phaseKey($payload['state'], $payload['phase_state'] ?? null);
                    if ($from !== null && $to !== null && $from !== $to) {
                        $stateIncoming[$ref] = ['from' => $from, 'to' => $to];
                    }
                }
                if ($existing) {
                    $existing->forceFill($payload);
                    if ($existing->isDirty()) {
                        $existing->save();
                        $updated++;
                    }

                    continue;
                }

                $createdRow = OltOnu::query()->create(array_merge([
                    'olt_id' => $olt->id,
                    'onu_ref' => $ref,
                ], $payload));
                $existingByRef->put($ref, $createdRow);
                $created++;
            }

            $rxCleared = $this->clearMissingRx($olt, $existingByRef, $rxSeenRefs, $now);
            OnuStateEvents::recordChanges($olt, $stateIncoming, 'olt_poll', $now);

            return [
                'created' => $created,
                'updated' => $updated,
                'state_written' => $stateWritten,
                'rx_cleared' => $rxCleared,
            ];
        });
    }

    /**
     * The Huawei RX table only returns rows for ONTs that currently have
     * light, so an ONT missing from a successful walk is dark: clear its old
     * RX instead of keeping it "sticky" (it was read as online). Guarded so a
     * short/partial walk cannot wipe the whole OLT.
     *
     * @param  Collection<string, OltOnu>  $existingByRef
     * @param  array<string, true>  $rxSeenRefs
     */
    private function clearMissingRx(Olt $olt, Collection $existingByRef, array $rxSeenRefs, mixed $now): int
    {
        $previousWithRx = $existingByRef->filter(fn (OltOnu $row): bool => $row->rx_power_dbm !== null);
        $seen = count($rxSeenRefs);
        $ratio = (float) config('services.huawei_olt_snmp.rx_clear_min_ratio', 0.5);
        if ($seen === 0 || $seen < (int) ceil($previousWithRx->count() * $ratio)) {
            return 0;
        }

        $cleared = 0;
        foreach ($previousWithRx as $ref => $row) {
            if (isset($rxSeenRefs[$ref]) || ! $row->exists) {
                continue;
            }
            $row->forceFill([
                'rx_power_dbm' => null,
                'rx_source' => 'huawei_snmp',
                'rx_collected_at' => $now,
            ]);
            if ($row->isDirty('rx_power_dbm')) {
                $row->save();
                $cleared++;
            }
        }

        return $cleared;
    }

    private function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower(trim((string) $olt->vendor));

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }
}
