<?php

namespace App\Console\Commands;

use App\Models\GenieAcsDevice;
use App\Services\CustomerConnectivityService;
use App\Services\GenieAcsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProbeCustomerInternet extends Command
{
    protected $signature = 'customers:probe-internet
                            {--limit=12 : Max devices to ping this run}
                            {--host= : Override ping host}
                            {--customer= : Probe a single linked customer id}';

    protected $description = 'Run GenieACS IP ping on FiberHome CPE and refresh service_online KPI';

    public function handle(GenieAcsService $genieAcs, CustomerConnectivityService $connectivity): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $host = $this->option('host') ?: null;
        $customerId = $this->option('customer');

        $query = GenieAcsDevice::query()
            ->with('customer:id,code,name,pppoe_user,status,is_online,olt_status')
            ->whereNotNull('customer_id')
            ->whereHas('customer', fn ($builder) => $builder->where('status', 'active'));

        if ($customerId !== null && $customerId !== '') {
            $query->where('customer_id', $customerId);
        } else {
            $freshMinutes = $connectivity->pingFreshMinutes();
            $query->where(function ($builder) use ($freshMinutes) {
                $builder->whereNull('last_ping_at')
                    ->orWhere('last_ping_at', '<', now()->subMinutes($freshMinutes));
            })->orderByRaw('last_ping_at IS NULL DESC')->orderBy('last_ping_at');
        }

        $devices = $query->limit($limit)->get();
        if ($devices->isEmpty()) {
            $this->info('No GenieACS devices due for internet probe.');

            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;

        foreach ($devices as $device) {
            $label = $device->customer?->pppoe_user ?: $device->customer?->code ?: $device->device_id;
            $this->line("Probing {$label} ({$device->device_id})...");

            try {
                $result = $genieAcs->runPingDiagnostic($device, $host);
                $connectivity->syncCustomerServiceState($device->customer, $device->fresh());

                if ($result['success']) {
                    $ok++;
                    $this->info("  OK ping {$result['host']} rtt=".($result['average_rtt_ms'] ?? '-').'ms');
                } else {
                    $failed++;
                    $this->warn('  FAIL: '.($result['error'] ?? 'ping failed'));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  ERROR: '.$e->getMessage());
                Log::warning('customers:probe-internet failed', [
                    'device_id' => $device->device_id,
                    'customer_id' => $device->customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Internet probe complete: ok={$ok} failed={$failed}");

        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
