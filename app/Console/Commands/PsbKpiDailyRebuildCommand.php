<?php

namespace App\Console\Commands;

use App\Services\PsbKpiService;
use App\Services\PsbStarliteReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3: rebuild psb_kpi_daily from unified rows (read-only source → cache table).
 */
class PsbKpiDailyRebuildCommand extends Command
{
    protected $signature = 'psb:kpi-daily-rebuild {--days=45}';

    protected $description = 'Rebuild psb_kpi_daily materialization from unified PSB rows';

    public function handle(PsbStarliteReportService $starlite, PsbKpiService $kpi): int
    {
        if (! Schema::hasTable('psb_kpi_daily')) {
            $this->error('Table psb_kpi_daily missing — run migrate');

            return self::FAILURE;
        }

        $days = max(7, (int) $this->option('days'));
        $tz = 'Asia/Jakarta';
        $from = now($tz)->subDays($days - 1)->toDateString();

        // Use KPI enrich via dashboard filtered full range
        $data = $kpi->dashboard(['from' => $from, 'to' => now($tz)->toDateString()]);
        // Rebuild from starlite unified + enrich by re-calling internal is heavy;
        // Use recent_rows is capped — better re-pull unifiedRows.
        $rows = $starlite->unifiedRows();
        $agg = [];
        foreach ($rows as $row) {
            $d = $row['work_date'] ?? $row['created_date'] ?? null;
            if (! $d || $d < $from) {
                continue;
            }
            // light enrich site
            $sk = (string) ($row['site_profile_key'] ?? $row['site_key'] ?? 'unknown');
            if ($sk === 'krian_full_olt') {
                $sk = 'krian';
            }
            if ($sk === 'ra_full_olt' || $sk === 'default_full_olt') {
                $sk = 'ra';
            }
            if (str_starts_with($sk, 'htb_')) {
                $sk = substr($sk, 4);
            }
            $aid = $row['area_id'] ?? null;
            $key = $d.'|'.$sk.'|'.($aid ?? 'null');
            if (! isset($agg[$key])) {
                $agg[$key] = [
                    'work_date' => $d,
                    'site_key' => $sk ?: 'unknown',
                    'area_id' => $aid,
                    'input_count' => 0,
                    'success_count' => 0,
                    'pending_count' => 0,
                    'failed_count' => 0,
                ];
            }
            $agg[$key]['input_count']++;
            $sheet = (string) ($row['sheet_status'] ?? '');
            if ($sheet === 'SUCCESS') {
                $agg[$key]['success_count']++;
            } elseif (in_array($sheet, ['PENDING', 'RECEIVED', 'WAITING_SN', 'PENDING_VLAN', 'SECRET_OK'], true)) {
                $agg[$key]['pending_count']++;
            } elseif (in_array($sheet, ['FAILED', 'TIMEOUT', 'CANCELLED'], true)) {
                $agg[$key]['failed_count']++;
            }
        }

        DB::table('psb_kpi_daily')->where('work_date', '>=', $from)->delete();
        foreach (array_chunk(array_values($agg), 100) as $chunk) {
            $now = now();
            $insert = array_map(function ($r) use ($now) {
                return [
                    'work_date' => $r['work_date'],
                    'site_key' => $r['site_key'],
                    'area_id' => $r['area_id'],
                    'input_count' => $r['input_count'],
                    'success_count' => $r['success_count'],
                    'pending_count' => $r['pending_count'],
                    'failed_count' => $r['failed_count'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);
            DB::table('psb_kpi_daily')->insert($insert);
        }

        $this->info('Rebuilt '.count($agg).' kpi daily rows from '.$from);
        // silence unused
        unset($data);

        return self::SUCCESS;
    }
}
