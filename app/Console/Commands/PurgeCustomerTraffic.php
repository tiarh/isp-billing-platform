<?php

namespace App\Console\Commands;

use App\Services\MonitoringAdmissionGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurgeCustomerTraffic extends Command
{
    protected $signature = 'monitoring:traffic-purge {--limit=5000 : Maximum rows per table per run}';

    protected $description = 'Chunked retention purge for dedicated customer traffic tables';

    public function handle(MonitoringAdmissionGate $gate): int
    {
        if (! (bool) config('services.monitoring.traffic.enabled', false)) {
            $this->line('Monitoring traffic OFF; purge skipped.');

            return self::SUCCESS;
        }
        if (! $gate->allows('traffic_purge')) {
            $this->warn('Admission gate merah; purge ditunda.');

            return self::SUCCESS;
        }
        if (! Schema::hasTable('customer_traffic_daily') || ! Schema::hasTable('customer_traffic_ingest_batches')) {
            $this->line('Traffic tables belum tersedia; purge skipped.');

            return self::SUCCESS;
        }

        $limit = min(5000, max(100, (int) $this->option('limit')));
        $dailyBefore = now('Asia/Jakarta')->subMonths((int) config('services.monitoring.traffic.retention_months', 36))->toDateString();
        $batchBefore = now()->subDays((int) config('services.monitoring.traffic.batch_ledger_days', 14));
        $dailyIds = DB::table('customer_traffic_daily')->where('traffic_date_wib', '<', $dailyBefore)->orderBy('id')->limit($limit)->pluck('id');
        $batchIds = DB::table('customer_traffic_ingest_batches')->where('created_at', '<', $batchBefore)->orderBy('id')->limit($limit)->pluck('id');
        $dailyDeleted = $dailyIds->isEmpty() ? 0 : DB::table('customer_traffic_daily')->whereIn('id', $dailyIds)->delete();
        $batchDeleted = $batchIds->isEmpty() ? 0 : DB::table('customer_traffic_ingest_batches')->whereIn('id', $batchIds)->delete();
        $this->info("traffic purge daily={$dailyDeleted} batches={$batchDeleted}");

        return self::SUCCESS;
    }
}
