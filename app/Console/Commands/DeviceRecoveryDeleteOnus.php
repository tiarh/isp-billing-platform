<?php

namespace App\Console\Commands;

use App\Models\DeviceRecoveryCase;
use App\Models\User;
use App\Services\DeviceRecoveryOltCleanupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator-run ONU delete for specific dismantled (received) recovery cases,
 * WITHOUT enabling the global kill switch device_recovery_olt_delete_enabled.
 *
 * Default is a read-only dry run (resolveTarget + live OLT read-back).
 * --execute deletes through DeviceRecoveryOltCleanupService::deleteForCase()
 * with explicitOperatorRun=true: every gate stays (received, exact serial,
 * serial_in_use, live read-back, config mode=write) except the kill switch.
 */
class DeviceRecoveryDeleteOnus extends Command
{
    protected $signature = 'device-recovery:delete-onus
        {--case=* : Recovery case ids (required)}
        {--execute : Actually delete (default: dry run)}
        {--actor= : User id for the audit trail (required with --execute)}
        {--sleep=2 : Seconds to wait between OLT writes with --execute}
        {--retry-unverified : For cases recorded as deleted, live-read the stored ref; if the serial is still present, delete again}';

    protected $description = 'Dry-run (default) or execute OLT ONU deletion for specific received device-recovery cases';

    public function handle(DeviceRecoveryOltCleanupService $service): int
    {
        $ids = collect((array) $this->option('case'))
            ->flatMap(fn ($v) => preg_split('/[\s,]+/', (string) $v, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn ($v) => (int) $v)
            ->filter(fn (int $v) => $v > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            $this->error('At least one --case=<id> is required.');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $actor = null;
        if ($execute) {
            $actorId = (int) $this->option('actor');
            $actor = $actorId > 0 ? User::query()->find($actorId) : null;
            if (! $actor) {
                $this->error('--actor=<user id> (existing user) is required with --execute.');

                return self::FAILURE;
            }
            if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
                $this->error('device-recovery.mode is not "write"; refusing to execute.');

                return self::FAILURE;
            }
        }

        return $execute
            ? $this->runExecute($service, $ids->all(), $actor)
            : $this->runDryRun($service, $ids->all());
    }

    /**
     * @param  list<int>  $ids
     */
    private function runDryRun(DeviceRecoveryOltCleanupService $service, array $ids): int
    {
        $rows = [];
        foreach ($ids as $id) {
            $case = DeviceRecoveryCase::query()->with('customer')->find($id);
            if (! $case) {
                $rows[] = $this->dryRow($id, null, ['would_delete' => false, 'reason' => 'case_not_found']);

                continue;
            }
            $retry = (bool) $this->option('retry-unverified') && $service->isRetryable($case);
            $rows[] = $retry
                ? $this->dryRow($id, $case, $service->planRetryUnverified($case), true)
                : $this->dryRow($id, $case, $service->planForCase($case));
        }

        $this->table(
            ['case', 'customer', 'customer status', 'olt', 'serial', 'onu_ref', 'live_serial', 'live_state', 'would_delete', 'reason'],
            $rows
        );
        $would = count(array_filter($rows, fn (array $r): bool => str_starts_with((string) $r['would_delete'], 'yes')));
        $this->info(sprintf('DRY RUN (nothing written): cases=%d would_delete=%d', count($rows), $would));
        if ((string) config('device-recovery.mode', 'read_only') !== 'write') {
            $this->warn('device-recovery.mode is not "write": --execute would refuse.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return array<string, string|int>
     */
    private function dryRow(int $id, ?DeviceRecoveryCase $case, array $plan, bool $retry = false): array
    {
        $customer = $case?->customer;

        return [
            'case' => $id,
            'customer' => $customer ? trim(($customer->code ?? '').' '.($customer->name ?? '')) : '-',
            'customer status' => (string) ($customer->status ?? '-'),
            'olt' => (string) ($plan['olt_name'] ?? $plan['olt_id'] ?? '-'),
            'serial' => (string) ($plan['serial'] ?? '-'),
            'onu_ref' => (string) ($plan['onu_ref'] ?? '-'),
            'live_serial' => (string) ($plan['live_serial'] ?? '-'),
            'live_state' => (string) ($plan['live_state'] ?? '-'),
            'would_delete' => ($plan['would_delete'] ?? false)
                ? ($retry ? 'yes (retry: still present)' : 'yes')
                : $this->noLabel((string) ($plan['reason'] ?? '')),
            'reason' => (string) ($plan['reason'] ?? '-'),
        ];
    }

    private function noLabel(string $reason): string
    {
        return match (true) {
            $reason === 'absent_verified' => 'no (absent: verify+purge only, no device write)',
            in_array($reason, ['onu_online', 'live_state_unknown'], true) => 'no ('.$reason.')',
            default => 'no',
        };
    }

    /**
     * @param  list<int>  $ids
     */
    private function runExecute(DeviceRecoveryOltCleanupService $service, array $ids, User $actor): int
    {
        $sleep = max(0, (int) $this->option('sleep'));
        $rows = [];
        $deleted = 0;
        $failed = 0;
        $wrote = false;

        foreach ($ids as $id) {
            $case = DeviceRecoveryCase::query()->find($id);
            if (! $case) {
                $rows[] = ['case' => $id, 'result' => 'skipped_case_not_found', 'verified_absent' => '-', 'inventory_purged' => '-'];
                Log::warning('[device-recovery] delete-onus case not found', ['case_id' => $id, 'actor_id' => $actor->id]);

                continue;
            }

            if ($wrote && $sleep > 0) {
                sleep($sleep);
            }

            $wasOk = (bool) $this->option('retry-unverified') && $service->isRetryable($case);
            $result = $wasOk
                ? $service->retryUnverified($case, $actor)
                : $service->deleteForCase($case, $actor, explicitOperatorRun: true);
            $status = (string) ($result['status'] ?? 'unknown');
            $purged = $result['inventory_purged'] ?? null;
            $rows[] = [
                'case' => $id,
                'result' => $status,
                'verified_absent' => array_key_exists('verified_absent', $result) ? ($result['verified_absent'] ? 'yes' : 'no') : '-',
                'inventory_purged' => is_array($purged) ? sprintf('db=%d snapshot=%d', $purged['db'] ?? 0, $purged['snapshot'] ?? 0) : '-',
            ];
            if (in_array($status, ['deleted', 'delete_failed'], true)) {
                $wrote = true;
            }
            if (in_array($status, ['deleted', 'deleted_verified_on_retry'], true)) {
                $deleted++;
            } elseif ($status === 'delete_failed') {
                $failed++;
            } elseif ($status === 'olt_busy') {
                // Another OLT operation holds the per-OLT lock: stop, write nothing more.
                $this->error('OLT is busy (another OLT operation holds the lock); stopping. Re-run later.');
                $failed++;
                break;
            }

            Log::info('[device-recovery] delete-onus operator run', [
                'case_id' => $id,
                'actor_id' => $actor->id,
                'status' => $status,
                'serial' => $result['serial'] ?? null,
                'onu_ref' => $result['onu_ref'] ?? null,
            ]);
        }

        $this->table(['case', 'result', 'verified_absent', 'inventory_purged'], $rows);
        $this->info(sprintf(
            'EXECUTE actor=%d cases=%d deleted=%d delete_failed=%d other=%d',
            $actor->id,
            count($rows),
            $deleted,
            $failed,
            count($rows) - $deleted - $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
