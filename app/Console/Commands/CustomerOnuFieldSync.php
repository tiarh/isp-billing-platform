<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\Setting;
use App\Services\CustomerOnu\CustomerOnuFieldSyncService;
use App\Services\CustomerOnu\CustomerOnuSyncVerdict;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Customer ONU field sync (owner 2026-10-15). Dry-run by default; writes only customers.olt_id, olt_port_label and
 * (scope A) onu_serial, one customer per transaction. Never touches an OLT, MikroTik, GenieACS, WhatsApp or any
 * migration/PSB table. Output never prints phone, NIK, address, coordinates or password.
 */
class CustomerOnuFieldSync extends Command
{
    protected $signature = 'customers:sync-onu-fields
                            {--scope=all : all|migration|serial}
                            {--olt_id=* : Only rows touching these OLT ids}
                            {--id=* : Only these customer ids}
                            {--limit= : Max customers changed this run}
                            {--json : Print the result as JSON}
                            {--apply : Apply an approved dry-run plan (needs --plan)}
                            {--plan= : Run id of the approved dry-run}
                            {--confirm= : Production only: SYNC-ONU-<run_id>}
                            {--scheduled : Daily scheduled run (evaluate + apply; gated by settings)}
                            {--revert=* : Revert the latest sync of these customer ids}
                            {--revert-run= : Revert every sync of this run id}';

    protected $description = 'Sinkron olt_id / port / SN pelanggan dari ONU di OLT (dry-run dulu, tulis hanya dengan --apply --plan atau jadwal harian)';

    private const LOCK = 'customers:sync-onu-fields';

    private const PLAN_MAX_AGE_HOURS = 24;

    public function handle(CustomerOnuFieldSyncService $service): int
    {
        $scheduled = (bool) $this->option('scheduled');
        $apply = (bool) $this->option('apply');
        $revertIds = array_values(array_filter(array_map('intval', (array) $this->option('revert'))));
        $revertRun = trim((string) $this->option('revert-run'));
        $plan = trim((string) $this->option('plan'));

        if ($scheduled && ($apply || $plan !== '' || $revertIds !== [] || $revertRun !== '')) {
            $this->error('--scheduled tidak boleh digabung dengan --apply / --plan / --revert.');

            return self::FAILURE;
        }
        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['all', 'migration', 'serial'], true)) {
            $this->error('--scope harus all|migration|serial.');

            return self::FAILURE;
        }

        $settings = $service->settings();

        if ($scheduled && (! $settings['enabled'] || ! $settings['schedule_enabled'])) {
            $this->line('Sinkron ONU pelanggan: jadwal harian nonaktif (customer_onu_sync_enabled / customer_onu_sync_schedule_enabled). Tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        if ($revertIds !== [] || $revertRun !== '') {
            return $this->handleRevert($service, $revertIds, $revertRun);
        }

        if ($apply) {
            return $this->handleManualApply($service, $settings, $plan, $scope);
        }

        $mode = $scheduled ? 'scheduled' : 'dry-run';
        $lock = null;
        if ($scheduled) {
            $lock = Cache::lock(self::LOCK, 900);
            if (! $lock->get()) {
                $this->line('Sinkron ONU pelanggan sedang berjalan. Tidak ada yang dikerjakan.');

                return self::SUCCESS;
            }
        }

        try {
            $runId = $this->newRunId();
            $result = $service->evaluate($this->filters($scope), $settings);
            $planPath = $service->writePlan($runId, $mode, $result['verdicts'], $settings, $result['sudah_sesuai']);

            $applied = ['applied' => 0, 'skipped' => [], 'applied_ids' => []];
            $brake = null;
            if ($scheduled) {
                $syncs = $this->syncRows($result['verdicts']);
                if (count($syncs) > $settings['brake']) {
                    $brake = 'terlalu_banyak';
                } else {
                    $applied = $service->applyMany($syncs, $runId, 'scheduled', $this->limit($settings));
                }
                Setting::set('customer_onu_sync_last_run', $this->lastRun($runId, 'scheduled', $result, $applied['applied'], $brake), 'json', 'customer_onu_sync');
            }

            $this->report($settings, strtoupper($mode), $runId, $result, $planPath, $scheduled ? $applied : null, $brake);
        } finally {
            $lock?->release();
        }

        return self::SUCCESS;
    }

    private function handleManualApply(CustomerOnuFieldSyncService $service, array $settings, string $planRunId, string $scope): int
    {
        if (! $settings['enabled']) {
            $this->warn('Sinkron ONU pelanggan dimatikan (customer_onu_sync_enabled=0). Tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }
        if ($planRunId === '' || ! CustomerOnuFieldSyncService::validRunId($planRunId)) {
            $this->error('--apply membutuhkan --plan=<run_id> dari dry-run yang disetujui.');

            return self::FAILURE;
        }
        $plan = $service->readPlan($planRunId);
        if ($plan === null) {
            $this->error("Rencana {$planRunId} tidak ditemukan.");

            return self::FAILURE;
        }
        $generated = Carbon::parse((string) ($plan['generated_at'] ?? '2000-01-01'));
        if ($generated->lt(Carbon::now()->subHours(self::PLAN_MAX_AGE_HOURS))) {
            $this->error('Rencana lebih dari '.self::PLAN_MAX_AGE_HOURS.' jam; jalankan dry-run baru.');

            return self::FAILURE;
        }
        if (app()->environment('production') && $this->option('confirm') !== 'SYNC-ONU-'.$planRunId) {
            $this->error('Menolak menulis di production tanpa --confirm=SYNC-ONU-'.$planRunId);

            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            $this->error('Sinkron ONU pelanggan sedang berjalan.');

            return self::FAILURE;
        }

        try {
            $filters = $this->filters($scope);
            $result = $service->evaluate($filters, $settings);

            $planned = [];
            foreach ((array) ($plan['rows'] ?? []) as $row) {
                if (($row['kind'] ?? null) !== CustomerOnuSyncVerdict::SYNC || ! $this->planRowMatchesFilters($row, $filters)) {
                    continue;
                }
                $planned[(int) $row['customer_id']] = $row;
            }

            $skipped = [];
            $toApply = [];
            foreach ($result['verdicts'] as $v) {
                $row = $planned[$v->customerId] ?? null;
                if (! $v->isSync() || $row === null) {
                    continue;
                }
                if (($row['before'] ?? null) === $v->before && ($row['after'] ?? null) === $v->after) {
                    $toApply[] = $v;
                    unset($planned[$v->customerId]); // a SYNC row whose before/after changed stays counted below
                }
            }
            foreach ($planned as $ignored) {
                $skipped['berubah_sejak_rencana'] = ($skipped['berubah_sejak_rencana'] ?? 0) + 1;
            }
            // a current SYNC that is not in the plan (or differs) is simply not applied
            $outcome = $service->applyMany($toApply, $planRunId, 'manual', $this->limit($settings));
            $outcome['skipped'] = array_merge_recursive($skipped, $outcome['skipped']);
            Setting::set('customer_onu_sync_last_run', $this->lastRun($planRunId, 'manual', $result, $outcome['applied'], null), 'json', 'customer_onu_sync');

            $this->line("customers:sync-onu-fields  mode=APPLY  plan={$planRunId}");
            $this->line('DITERAPKAN: '.$outcome['applied'].(($outcome['applied_ids'] ?? []) !== [] ? ' (id '.implode(',', $outcome['applied_ids']).')' : ''));
            foreach ($outcome['skipped'] as $reason => $n) {
                $this->line("  dilewati {$reason}: ".(is_array($n) ? array_sum($n) : $n));
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /** @param list<int> $ids */
    private function handleRevert(CustomerOnuFieldSyncService $service, array $ids, string $runId): int
    {
        $lock = Cache::lock(self::LOCK, 900);
        if (! $lock->get()) {
            $this->error('Sinkron ONU pelanggan sedang berjalan.');

            return self::FAILURE;
        }

        try {
            foreach ($ids as $id) {
                $res = $service->revertCustomer($id);
                $this->line(sprintf('#%d %s (%s)', $id, $res['reverted'] ? 'DIKEMBALIKAN' : 'TIDAK dikembalikan', $res['reason']));
            }
            if ($runId !== '') {
                $res = $service->revertRun($runId);
                $this->line("run {$runId}: dikembalikan {$res['reverted']}");
                foreach ($res['results'] as $cid => $reason) {
                    $this->line("  #{$cid} {$reason}");
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }

    /** @return array{scope: string, olt_ids: list<int>, ids: list<int>} */
    private function filters(string $scope): array
    {
        return [
            'scope' => $scope,
            'olt_ids' => array_values(array_filter(array_map('intval', (array) $this->option('olt_id')))),
            'ids' => array_values(array_filter(array_map('intval', (array) $this->option('id')))),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{scope: string, olt_ids: list<int>, ids: list<int>}  $filters
     */
    private function planRowMatchesFilters(array $row, array $filters): bool
    {
        if ($filters['ids'] !== [] && ! in_array((int) $row['customer_id'], $filters['ids'], true)) {
            return false;
        }
        if ($filters['scope'] !== 'all' && ($row['scope'] ?? null) !== $filters['scope']) {
            return false;
        }
        if ($filters['olt_ids'] !== []) {
            $mine = array_filter([$row['before']['olt_id'] ?? null, $row['after']['olt_id'] ?? null, $row['evidence']['olt_id'] ?? null], static fn ($x) => $x !== null);
            if (array_intersect(array_map('intval', $mine), $filters['olt_ids']) === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<CustomerOnuSyncVerdict>  $verdicts
     * @return list<CustomerOnuSyncVerdict>
     */
    private function syncRows(array $verdicts): array
    {
        return array_values(array_filter($verdicts, static fn (CustomerOnuSyncVerdict $v) => $v->isSync()));
    }

    /** @param array<string, mixed> $settings */
    private function limit(array $settings): int
    {
        $opt = $this->option('limit');

        return $opt !== null && $opt !== '' ? max(1, min(500, (int) $opt)) : (int) $settings['max_per_run'];
    }

    private function newRunId(): string
    {
        return now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function lastRun(string $runId, string $mode, array $result, int $applied, ?string $brake): array
    {
        return array_filter([
            'run_id' => $runId,
            'mode' => $mode,
            'at' => now()->toIso8601String(),
            'counts' => $result['counts'] + ['sudah_sesuai' => $result['sudah_sesuai']],
            'applied' => $applied,
            'brake' => $brake,
        ], static fn ($x) => $x !== null);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $result
     * @param  array{applied: int, skipped: array<string, int>, applied_ids: list<int>}|null  $applied
     */
    private function report(array $settings, string $mode, string $runId, array $result, string $planPath, ?array $applied, ?string $brake): void
    {
        /** @var list<CustomerOnuSyncVerdict> $verdicts */
        $verdicts = $result['verdicts'];
        $sync = array_values(array_filter($verdicts, fn ($v) => $v->kind === CustomerOnuSyncVerdict::SYNC));
        $admin = array_values(array_filter($verdicts, fn ($v) => $v->kind === CustomerOnuSyncVerdict::PERLU_DICEK_ADMIN));
        $skip = array_values(array_filter($verdicts, fn ($v) => $v->kind === CustomerOnuSyncVerdict::SKIP));
        $skipHist = [];
        foreach ($skip as $v) {
            $skipHist[$v->reason] = ($skipHist[$v->reason] ?? 0) + 1;
        }
        arsort($skipHist);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'mode' => $mode,
                'run_id' => $runId,
                'plan_file' => $planPath,
                'counts' => $result['counts'] + ['sudah_sesuai' => $result['sudah_sesuai']],
                'histogram' => $result['histogram'],
                'applied' => $applied['applied'] ?? 0,
                'brake' => $brake,
                'verdicts' => array_map(fn (CustomerOnuSyncVerdict $v) => $v->toArray(), $verdicts),
            ], JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->line("customers:sync-onu-fields  mode={$mode}  run={$runId}");
        $this->line(sprintf('Ambang: ONU <= %d mnt | PPPoE <= %d mnt (wajib utk pindah OLT) | migrasi otomatis >= %d jam', $settings['onu_max_age'], $settings['pppoe_max_age'], $settings['settle_hours']));
        $this->line('Jadwal harian 09:23: '.($settings['schedule_enabled'] ? 'AKTIF' : 'NONAKTIF (customer_onu_sync_schedule_enabled=0)'));

        $sub = ['A' => 0, 'B1' => 0, 'B2' => 0, 'B3' => 0];
        foreach ($sync as $v) {
            $sub[(string) $v->subScope] = ($sub[(string) $v->subScope] ?? 0) + 1;
        }
        $this->line(sprintf('Kandidat: migrasi otomatis %d | olt kosong %d | olt lama/basi %d | label port saja %d', $sub['A'], $sub['B1'], $sub['B2'], $sub['B3']));
        $oltNames = Olt::query()->pluck('name', 'id');
        $this->line('AKAN DISINKRON'.($applied === null ? ' (perlu --apply --plan='.$runId.')' : '').': '.count($sync));
        foreach ($sync as $v) {
            $this->line($this->syncLine($v, $oltNames));
        }
        $this->line('PERLU DICEK ADMIN: '.count($admin));
        foreach ($admin as $v) {
            $this->line(sprintf(' #%d %s [%s] %s', $v->customerId, $v->code, $v->subScope, $v->reason));
        }
        $this->line('DILEWATI: '.count($skip).($skipHist !== [] ? ' ('.implode(' | ', array_map(fn ($r, $n) => "{$r} {$n}", array_keys($skipHist), $skipHist)).')' : ''));
        $this->line('SUDAH SESUAI: '.$result['sudah_sesuai']);
        if ($applied !== null) {
            $this->line($brake !== null
                ? sprintf('REM: terlalu banyak (%d > %d), tidak ada yang diterapkan', count($sync), $settings['brake'])
                : 'DITERAPKAN: '.$applied['applied']);
        }
        $this->line('Rencana disimpan: '.$planPath);
    }

    /** @param Collection<int|string, mixed> $oltNames */
    private function syncLine(CustomerOnuSyncVerdict $v, Collection $oltNames): string
    {
        $b = (array) $v->before;
        $a = (array) $v->after;
        $olt = static fn ($id) => $id === null ? 'NULL' : (($oltNames[$id] ?? '?').'('.$id.')');
        $parts = [sprintf(' #%d %s [%s]', $v->customerId, $v->code, $v->subScope)];
        $parts[] = 'OLT: '.$olt($b['olt_id'] ?? null).' -> '.$olt($a['olt_id'] ?? null);
        $parts[] = 'port: "'.($b['olt_port_label'] ?? '').'" -> "'.($a['olt_port_label'] ?? '').'"';
        $parts[] = ($b['onu_serial'] ?? null) === ($a['onu_serial'] ?? null) ? 'SN tetap' : 'SN: '.($b['onu_serial'] ?? 'NULL').' -> '.($a['onu_serial'] ?? 'NULL');
        if ($v->migrationId !== null) {
            $parts[] = 'migrasi #'.$v->migrationId;
        }

        return implode(' | ', $parts);
    }
}
