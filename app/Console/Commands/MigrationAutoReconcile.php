<?php

namespace App\Console\Commands;

use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\CustomerMigrationService;
use App\Services\Migration\MigrationAutoReconcileService;
use App\Services\Migration\MigrationReconcileVerdict;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Owner 2026-10-15: a migration that is still open although the new ONU is online on the target OLT AND the PPPoE
 * session is online on the target router is marked synced ("otomatis: ONU + PPPoE online"). Report-only by default;
 * writes only with setting migration_auto_reconcile_apply=1 or --apply. DB-only: never MikroTik / OLT / GenieACS /
 * customers / old ONU / WhatsApp. Output never prints phone, NIK, address, coordinates, password or raw_text.
 */
class MigrationAutoReconcile extends Command
{
    protected $signature = 'migration:auto-reconcile
                            {--dry-run : Report only; change nothing (wins over every apply switch)}
                            {--apply : Apply this run even if the apply setting is off}
                            {--limit= : Max rows marked this run (default setting max_per_run)}
                            {--id=* : Evaluate only these migration ids and print every rule}
                            {--json : Print the full result as JSON}
                            {--revert=* : Undo an earlier auto-reconcile of these migration ids}';

    protected $description = 'Tandai migrasi selesai otomatis bila ONU + PPPoE online (laporan dulu, tulis hanya jika diizinkan)';

    public function handle(MigrationAutoReconcileService $evaluator, CustomerMigrationService $migrations): int
    {
        $started = microtime(true);

        $revertIds = array_filter(array_map('intval', (array) $this->option('revert')));
        if ($revertIds !== []) {
            foreach ($revertIds as $rid) {
                $res = $migrations->revertAutoReconcile($rid);
                $this->line(sprintf('#%d %s (%s)', $rid, $res['reverted'] ? 'DIKEMBALIKAN' : 'TIDAK dikembalikan', $res['reason']));
                if ($res['reverted']) {
                    $this->line('  catatan: dry-run lama tetap dibatalkan; jalankan dry-run ulang sebelum #migrasi eksekusi.');
                }
            }

            return self::SUCCESS;
        }

        $settings = $evaluator->settings();
        $dryRun = (bool) $this->option('dry-run');
        if (! $settings['enabled'] && ! $dryRun) {
            $this->warn('Migrasi otomatis dimatikan (migration_auto_reconcile_enabled=0). Tidak ada yang dikerjakan.');

            return self::SUCCESS;
        }

        $wantApply = (bool) $this->option('apply') || $settings['apply'];
        $apply = $wantApply && ! $dryRun && ! $settings['maintenance'] && $settings['column_ok'];
        $modeLabel = $apply ? 'APPLY' : 'DRY-RUN';
        if (! $apply) {
            $why = $dryRun ? '--dry-run' : ($settings['maintenance'] ? 'mode maintenance migrasi' : (! $settings['column_ok'] ? 'kolom pppoe_online_checked_at belum ada' : 'apply setting OFF'));
            $modeLabel .= " ($why)";
        }
        $runId = now()->format('Ymd-His').'-'.Str::lower(Str::random(4));
        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(1, min(500, (int) $this->option('limit')))
            : $settings['max_per_run'];
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('id'))));

        $result = $evaluator->evaluate($ids === [] ? null : $ids, $settings);
        /** @var list<MigrationReconcileVerdict> $verdicts */
        $verdicts = $result['verdicts'];

        $eligible = array_values(array_filter($verdicts, fn ($v) => $v->kind === MigrationReconcileVerdict::ELIGIBLE));
        $admin = array_values(array_filter($verdicts, fn ($v) => $v->kind === MigrationReconcileVerdict::PERLU_DICEK_ADMIN));
        $snMissing = $evaluator->snNotFound(null, $verdicts);
        $skipped = [];
        foreach ($verdicts as $v) {
            if ($v->kind === MigrationReconcileVerdict::SKIP) {
                $skipped[$v->reason] = ($skipped[$v->reason] ?? 0) + 1;
            }
        }

        // ---- apply
        $applied = 0;
        $appliedIds = [];
        if ($apply) {
            foreach ($eligible as $v) {
                if ($applied >= $limit) {
                    $skipped['batas_per_putaran'] = ($skipped['batas_per_putaran'] ?? 0) + 1;

                    continue;
                }
                $reason = $this->applyOne($migrations, $v, $runId);
                if ($reason === null) {
                    $applied++;
                    $appliedIds[] = $v->migrationId;
                } else {
                    $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
                }
            }
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'run_id' => $runId,
            'eligible' => count($eligible),
            'applied' => $applied,
            'perlu_dicek_admin' => count($admin),
            'sn_tidak_ditemukan' => count($snMissing),
            'skipped' => array_sum($skipped),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'summary' => $summary,
                'histogram' => $result['histogram'],
                'skipped_by_reason' => $skipped,
                'applied_ids' => $appliedIds,
                'verdicts' => array_map(fn (MigrationReconcileVerdict $v) => [
                    'id' => $v->migrationId, 'kind' => $v->kind, 'reason' => $v->reason, 'status' => $v->status,
                    'area_id' => $v->areaId, 'created_at' => $v->createdAt, 'link_proof' => $v->linkProof,
                    'evidence' => $v->evidence, 'trace' => $v->trace,
                ], $verdicts),
            ], JSON_UNESCAPED_UNICODE));
        } else {
            $this->render($settings, $modeLabel, $runId, $result['histogram'], $eligible, $admin, $snMissing, $skipped, $verdicts, $ids !== [], $apply, $appliedIds);
            $this->line('Summary: '.json_encode($summary, JSON_UNESCAPED_UNICODE));
        }

        if (! $apply) {
            // The only write of a report run: one summary Setting (no migration row is touched).
            Setting::set('migration_auto_reconcile_last_run', $summary + ['at' => now()->toIso8601String()], 'json', 'migration');
        }

        return self::SUCCESS;
    }

    /** @return string|null skip reason, or null when applied */
    private function applyOne(CustomerMigrationService $migrations, MigrationReconcileVerdict $v, string $runId): ?string
    {
        $id = $v->migrationId;
        // Non-blocking gets on OUR keys only. customer-migration-wa-live-active is NEVER probed.
        $names = ["customer-migration-device-{$id}", "customer-migration-{$id}", "customer-migration-review-{$id}"];
        $held = [];
        try {
            foreach ($names as $name) {
                $lock = Cache::lock($name, 120);
                if (! $lock->get()) {
                    return 'kunci_sibuk';
                }
                $held[] = $lock;
            }

            $row = CustomerMigration::query()->find($id, ['id', 'new_olt_id', 'new_pon_port', 'new_onu_ref']);
            $oltId = (int) ($row?->new_olt_id ?? 0);
            $port = trim((string) ($row?->new_pon_port ?? ''));
            if ($port === '' && preg_match('/^(\d+\/\d+\/\d+):\d+$/', (string) ($v->evidence['onu_ref'] ?? ''), $m) === 1) {
                $port = $m[1];
            }
            if ($oltId > 0 && $port !== '') {
                // Informational probe only: get + release immediately. It tells us a PON operation is running right
                // now (skip), it does not protect the DB write below (that is guarded by the row lock + re-checks).
                $pon = Cache::lock("device-olt-pon-{$oltId}-{$port}", 240);
                if (! $pon->get()) {
                    return 'kunci_pon_sibuk';
                }
                $pon->release();
            }

            $res = $migrations->markSyncedByAutoReconcile($id, $v->evidence, $runId, [
                'status' => $v->status,
                'updated_at' => $v->updatedAt ?? '',
            ]);

            return $res['applied'] ? null : $res['reason'];
        } catch (\Throwable $e) {
            report($e);

            return 'error';
        } finally {
            foreach ($held as $lock) {
                try {
                    $lock->release();
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $histogram
     * @param  list<MigrationReconcileVerdict>  $eligible
     * @param  list<MigrationReconcileVerdict>  $admin
     * @param  list<array<string, mixed>>  $snMissing
     * @param  array<string, int>  $skipped
     * @param  list<MigrationReconcileVerdict>  $verdicts
     * @param  list<int>  $appliedIds
     */
    private function render(array $s, string $mode, string $runId, array $histogram, array $eligible, array $admin, array $snMissing, array $skipped, array $verdicts, bool $traceAll, bool $apply, array $appliedIds): void
    {
        $this->line("migration:auto-reconcile  mode={$mode}  run={$runId}");
        $this->line(sprintf('Ambang: ONU <= %d mnt | PPPoE <= %d mnt | jeda ubah >= %d mnt | umur >= %d mnt',
            $s['onu_max_age'], $s['pppoe_max_age'], $s['quiet'], MigrationAutoReconcileService::CREATED_MIN_AGE_MINUTES));
        $hist = [];
        foreach ($histogram as $status => $n) {
            $hist[] = "{$status} {$n}";
        }
        $this->line('Status (baris terbaru per pelanggan, belum selesai): '.($hist === [] ? '-' : implode(' | ', $hist)));
        $this->newLine();

        $this->line(($apply ? 'DITANDAI SELESAI / BISA DITANDAI' : 'BISA DITANDAI SELESAI (akan otomatis jika diizinkan)').': '.count($eligible));
        foreach ($eligible as $v) {
            $e = $v->evidence;
            $tag = ($e['sn_source'] ?? 'new_sn') === 'customer_profile' ? ' [SN dari profil pelanggan]' : '';
            $this->line(sprintf(' #%d %s %s pppoe=%s | OLT %s %s online %s | PPPoE online %s %s | bukti: %s | ledger %s%s%s',
                $v->migrationId, $v->status, $v->label ?? '-', $e['pppoe_user'] ?? '-', $e['olt_name'] ?? '-', $e['onu_ref'] ?? '-',
                isset($e['state_collected_at']) ? substr((string) $e['state_collected_at'], 11, 5) : '-',
                $e['router_name'] ?? '-', isset($e['pppoe_online_checked_at']) ? substr((string) $e['pppoe_online_checked_at'], 11, 5) : '-',
                $v->linkProof ?? '-', ($e['ledger_id'] ?? null) ? 'dry-run #'.$e['ledger_id'].' -> dibatalkan' : 'tidak ada',
                in_array($v->migrationId, $appliedIds, true) ? ' | SUDAH DITANDAI' : '', $tag));
        }

        $this->line('PERLU DICEK ADMIN (status gagal/setengah jalan, pelanggan sudah online): '.count($admin));
        foreach ($admin as $v) {
            $this->line(sprintf(' #%d %s %s', $v->migrationId, $v->status, $v->label ?? '-'));
        }

        $this->line('SN TIDAK DITEMUKAN DI OLT (cek SN di laporan migrasi): '.count($snMissing));
        foreach ($snMissing as $r) {
            $this->line(sprintf(' #%d %s area=%s dibuat=%s', $r['id'], $r['status'], $r['area'] ?? '-', $r['created_at'] ?? '-'));
        }

        $this->line('DILEWATI: '.array_sum($skipped));
        arsort($skipped);
        $this->line('  '.($skipped === [] ? '-' : implode(' | ', array_map(fn ($k, $n) => "{$k} {$n}", array_keys($skipped), $skipped))));

        if ($traceAll) {
            foreach ($verdicts as $v) {
                $this->newLine();
                $this->line("Migrasi #{$v->migrationId}: {$v->kind} ({$v->reason})");
                foreach ($v->trace as $t) {
                    $this->line(sprintf('  %s %s%s', $t['rule'], $t['ok'] ? 'lulus' : 'GAGAL', $t['reason'] ? ' - '.$t['reason'] : ''));
                }
            }
        }
    }
}
