<?php

namespace App\Console\Commands;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Services\CustomerConnectivityService;
use App\Services\UnifiedConnectivityReadModel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MonitoringShadowCompare extends Command
{
    protected $signature = 'monitoring:shadow-compare
        {--limit=100 : Maximum customers; use 0 for a chunked all-customer audit}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Compare legacy connectivity status with the MON-004 read model without device I/O';

    public function handle(
        UnifiedConnectivityReadModel $readModel,
        CustomerConnectivityService $legacy,
    ): int {
        $requestedLimit = (int) $this->option('limit');
        $limit = max(0, min(20000, $requestedLimit));

        $result = [
            'sample_size' => 0,
            'limit' => $limit,
            'legacy' => ['online' => 0, 'offline_or_pending' => 0, 'isolated' => 0],
            'unified' => [],
            'status_diffs' => 0,
            'transitions' => [],
            'transition_breakdown' => [],
            'examples' => [],
            'review_candidates' => [],
            'read_only' => true,
            'device_io' => false,
            'writes' => false,
            'generated_at' => now()->timezone('Asia/Jakarta')->toISOString(),
        ];

        $compare = function (Collection $customers) use (&$result, $legacy, $readModel): void {
            foreach ($customers as $customer) {
                $result['sample_size']++;
                $old = $legacy->evaluate($customer, $customer->genieAcsDevice);
                $new = $readModel->compose($customer, $customer->genieAcsDevice);
                $isolated = in_array((string) $customer->status, CustomerStatus::isolatedOrClosed(), true);

                if ($isolated) {
                    $result['legacy']['isolated']++;
                } elseif ($old['service_online']) {
                    $result['legacy']['online']++;
                } else {
                    $result['legacy']['offline_or_pending']++;
                }

                $result['unified'][$new['status']] = ($result['unified'][$new['status']] ?? 0) + 1;
                $oldStatus = $isolated ? 'isolated' : ($old['service_online'] ? 'online' : 'not_online');
                $newStatus = (string) $new['status'];
                $transition = $oldStatus.'->'.$newStatus;
                $result['transitions'][$transition] = ($result['transitions'][$transition] ?? 0) + 1;
                $breakdownKey = implode('|', [
                    $transition,
                    (string) ($new['reason_code'] ?? 'unknown'),
                    (string) ($new['strategy'] ?? 'unknown'),
                    (string) ($new['confidence'] ?? 'unknown'),
                    ($new['stale'] ?? false) ? 'stale' : 'fresh',
                ]);
                if (! isset($result['transition_breakdown'][$breakdownKey])) {
                    $result['transition_breakdown'][$breakdownKey] = [
                        'transition' => $transition,
                        'reason_code' => $new['reason_code'] ?? 'unknown',
                        'strategy' => $new['strategy'] ?? 'unknown',
                        'confidence' => $new['confidence'] ?? 'unknown',
                        'stale' => (bool) ($new['stale'] ?? false),
                        'source' => $new['source'] ?? 'unknown',
                        'count' => 0,
                    ];
                }
                $result['transition_breakdown'][$breakdownKey]['count']++;
                $compatible = $isolated
                    ? $newStatus === 'isolated'
                    : ($old['service_online'] ? $newStatus === 'online' : $newStatus !== 'online');

                if (! $compatible) {
                    $result['status_diffs']++;
                    if (count($result['examples']) < 10) {
                        $result['examples'][] = [
                            'customer_id' => (int) $customer->id,
                            'old' => $oldStatus,
                            'new' => $newStatus,
                            'new_reason' => $new['reason_code'] ?? null,
                        ];
                    }
                    if ($oldStatus === 'not_online' && $newStatus === 'online' && count($result['review_candidates']) < 25) {
                        $result['review_candidates'][] = [
                            'customer_id' => (int) $customer->id,
                            'transition' => $transition,
                            'reason_code' => $new['reason_code'] ?? null,
                            'strategy' => $new['strategy'] ?? null,
                            'confidence' => $new['confidence'] ?? null,
                            'source' => $new['source'] ?? null,
                            'stale' => (bool) ($new['stale'] ?? false),
                        ];
                    }
                }
            }
        };

        $baseQuery = fn (string $status): Builder => Customer::query()
            ->where('status', $status)
            ->with([
                'area:id,name',
                'router:id,name',
                'olt:id,name,vendor',
                'genieAcsDevice:id,customer_id,device_id,manufacturer,last_inform_at,last_ping_at,last_ping_success,last_ping_rtt_ms',
            ])
            ->orderBy('id');

        if ($limit === 0) {
            foreach (CustomerStatus::monitored() as $status) {
                $baseQuery($status)->chunkById(100, $compare);
            }
        } else {
            $customers = Customer::query()
                ->whereIn('status', CustomerStatus::monitored())
                ->with([
                    'area:id,name',
                    'router:id,name',
                    'olt:id,name,vendor',
                    'genieAcsDevice:id,customer_id,device_id,manufacturer,last_inform_at,last_ping_at,last_ping_success,last_ping_rtt_ms',
                ])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->limit($limit)
                ->get();
            $compare($customers);
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'MON-004 shadow sample=%d diffs=%d read_only=yes device_io=no writes=no',
                $result['sample_size'],
                $result['status_diffs'],
            ));
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }
}
