<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\OltCollectRun;
use App\Models\OltOnu;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Support\OpsTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only NOC inbox. It consumes snapshots/events and never opens a device
 * session or mutates customer, ticket, or device state.
 */
class NocMonitoringReadModel
{
    public function payload(?Request $request = null): array
    {
        if (! filter_var(Setting::get('monitoring_noc_cockpit_enabled', false), FILTER_VALIDATE_BOOLEAN)) {
            return [
                'enabled' => false,
                'summary' => ['active_incidents' => 0, 'affected_customers' => 0, 'p1' => 0, 'p2' => 0, 'source_alerts' => 0],
                'sources' => [],
                'incidents' => [],
                'queue_status' => $this->queueStatus(),
                'coverage' => ['total' => 0, 'mapped' => 0, 'needs_mapping' => 0, 'isolated' => 0, 'strategies' => []],
                'ticket_triage_enabled' => false,
                'read_only' => true,
                'generated_at' => now()->timezone('Asia/Jakarta')->toISOString(),
                'message' => 'NOC cockpit feature flag masih OFF; data lama tetap menjadi source of truth.',
            ];
        }

        $cacheKey = 'monitoring.noc-inbox.v1.'.sha1(json_encode([
            'area' => $request?->query('area'),
            'severity' => $request?->query('severity'),
            'status' => $request?->query('status', 'active'),
            'noc_view' => $request?->query('noc_view', 'action'),
            'noc_page' => max(1, min(100, (int) ($request?->query('noc_page', 1) ?? 1))),
            'noc_per_page' => max(10, min(50, (int) ($request?->query('noc_per_page', 25) ?? 25))),
            'events_version' => Schema::hasTable('monitoring_alert_events') ? MonitoringAlertEvent::query()->where('status', 'active')->max('updated_at') : null,
            'events_count' => Schema::hasTable('monitoring_alert_events') ? MonitoringAlertEvent::query()->where('status', 'active')->count() : 0,
            'events_max_id' => Schema::hasTable('monitoring_alert_events') ? MonitoringAlertEvent::query()->where('status', 'active')->max('id') : null,
            'tickets_version' => Schema::hasTable('support_tickets') ? SupportTicket::query()->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->max('updated_at') : null,
            'tickets_count' => Schema::hasTable('support_tickets') ? SupportTicket::query()->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->count() : 0,
            'tickets_max_id' => Schema::hasTable('support_tickets') ? SupportTicket::query()->whereNotIn('status', ['resolved', 'closed', 'cancelled'])->max('id') : null,
        ]));

        $correlationEnabled = filter_var(Setting::get('monitoring_incident_correlation_enabled', false), FILTER_VALIDATE_BOOLEAN);

        return Cache::remember($cacheKey.'.'.($correlationEnabled ? 'corr-on' : 'corr-off'), now()->addSeconds(20), function () use ($request, $correlationEnabled) {
            $incidentPage = $correlationEnabled ? $this->incidents($request) : [
                'items' => [],
                'pagination' => ['page' => 1, 'per_page' => 25, 'last_page' => 1, 'total' => 0],
            ];

            return [
                'summary' => $correlationEnabled ? $this->summary() : ['active_incidents' => 0, 'affected_customers' => 0, 'p1' => 0, 'p2' => 0, 'source_alerts' => 0],
                'sources' => $this->sources(),
                'queue_status' => $this->queueStatus(),
                'incidents' => $incidentPage['items'],
                'incident_pagination' => $incidentPage['pagination'],
                'coverage' => $this->coverage(),
                'correlation_enabled' => $correlationEnabled,
                'ticket_triage_enabled' => filter_var(Setting::get('monitoring_ticket_triage_enabled', false), FILTER_VALIDATE_BOOLEAN),
                'read_only' => true,
                'generated_at' => now()->timezone('Asia/Jakarta')->toISOString(),
            ];
        });
    }

    private function summary(): array
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return [
                'active_incidents' => 0,
                'affected_customers' => 0,
                'p1' => 0,
                'p2' => 0,
                'source_alerts' => 0,
            ];
        }

        $rows = $this->activeEvents()
            ->orderByDesc('last_seen_at')
            ->limit(2000)
            ->get(['id', 'source', 'entity_type', 'entity_id', 'issue', 'severity', 'payload']);
        $incidents = $this->groupEvents($rows);
        $affectedCustomers = $rows
            ->filter(fn (MonitoringAlertEvent $event) => $event->entity_type === 'customer')
            ->pluck('entity_id')
            ->filter()
            ->unique()
            ->count();

        return [
            'active_incidents' => count($incidents),
            'affected_customers' => (int) $affectedCustomers,
            'p1' => collect($incidents)->where('priority', 'P1')->count(),
            'p2' => collect($incidents)->where('priority', 'P2')->count(),
            'source_alerts' => collect($incidents)->filter(fn (array $row) => ($row['source'] ?? '') !== 'connectivity_scan')->count(),
            'capped' => $rows->count() >= 2000,
        ];
    }

    private function sources(): array
    {
        $now = now();
        $items = [];

        $routerSourceMeta = $this->routerSourceMeta();
        $items[] = $this->source(
            'mikrotik_pppoe',
            'MikroTik PPPoE',
            Customer::query()->whereNotNull('pppoe_user')->where('pppoe_user', '<>', ''),
            'mikrotik_sync_checked_at',
            $now->copy()->subMinutes(15),
            $routerSourceMeta,
        );
        $oltSourceMeta = $this->oltSourceMeta();
        $items[] = $this->oltSource($oltSourceMeta, $now->copy()->subMinutes(30));

        if (Schema::hasTable('genieacs_devices')) {
            $total = (int) DB::table('genieacs_devices')->whereNotNull('customer_id')->count();
            $freshAt = $now->copy()->subMinutes(30);
            $fresh = (int) DB::table('genieacs_devices')->whereNotNull('customer_id')->where('last_inform_at', '>=', $freshAt)->count();
            $last = DB::table('genieacs_devices')->max('last_inform_at');
            $items[] = $this->sourcePayload('genieacs', 'GenieACS', $total, $fresh, $last, $this->sourceStatus($total, $fresh));
        } else {
            $items[] = $this->sourcePayload('genieacs', 'GenieACS', 0, 0, null, 'unknown');
        }

        return $items;
    }

    private function source(string $key, string $label, $query, string $timestampColumn, $freshAt, array $meta = []): array
    {
        if (! Schema::hasColumn('customers', $timestampColumn)) {
            return $this->sourcePayload($key, $label, 0, 0, null, 'unknown', $meta);
        }

        $total = (clone $query)->count();
        $fresh = (clone $query)->where($timestampColumn, '>=', $freshAt)->count();
        $last = (clone $query)->max($timestampColumn);

        return $this->sourcePayload($key, $label, $total, $fresh, $last, $this->sourceStatus($total, $fresh), $meta);
    }

    private function oltSource(array $meta, Carbon $freshAt): array
    {
        $customers = Customer::query()
            ->where(function ($query) {
                $query->whereNotNull('onu_serial')->orWhereNotNull('olt_id');
            })
            ->get(['olt_id', 'onu_serial']);
        $total = $customers->count();

        if ($total === 0 || ! Schema::hasTable('olt_onus')) {
            return $this->sourcePayload('olt_onu', 'OLT/ONU snapshot', $total, 0, null, $this->sourceStatus($total, 0), $meta);
        }

        $oltIds = $customers->pluck('olt_id')->filter()->unique()->values();
        $serials = $customers->pluck('onu_serial')->filter()->map(fn ($serial) => strtoupper(trim((string) $serial)))->unique()->values();
        $freshRows = OltOnu::query()
            ->where('state_collected_at', '>=', $freshAt)
            ->when($oltIds->isNotEmpty(), fn ($query) => $query->whereIn('olt_id', $oltIds))
            ->when($serials->isNotEmpty(), fn ($query) => $query->whereIn('serial_number', $serials))
            ->get(['olt_id', 'serial_number', 'state_collected_at']);
        $evidence = $freshRows->mapWithKeys(fn (OltOnu $onu) => [
            ((int) $onu->olt_id).'|'.strtoupper(trim((string) $onu->serial_number)) => true,
        ]);
        $fresh = $customers->filter(function ($customer) use ($evidence) {
            $serial = strtoupper(trim((string) $customer->onu_serial));

            return $customer->olt_id && $serial !== '' && $evidence->has(((int) $customer->olt_id).'|'.$serial);
        })->count();
        $last = $freshRows->max('state_collected_at');

        return $this->sourcePayload('olt_onu', 'OLT/ONU snapshot', $total, $fresh, $last, $this->sourceStatus($total, $fresh), $meta);
    }

    private function sourcePayload(string $key, string $label, int $total, int $fresh, mixed $last, string $status, array $meta = []): array
    {
        $collectorLastSuccess = $meta['last_success_at'] ?? null;
        $collectorFresh = false;
        if ($collectorLastSuccess) {
            try {
                $collectorFresh = Carbon::parse((string) $collectorLastSuccess)
                    ->gte(now()->subMinutes((int) ($meta['fresh_window_minutes'] ?? 30)));
            } catch (\Throwable) {
                $collectorLastSuccess = null;
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'status' => $status,
            'mapped' => $total,
            'fresh' => $fresh,
            'stale' => max(0, $total - $fresh),
            'freshness_ratio' => $total > 0 ? round($fresh / $total, 4) : null,
            'last_attempt_at' => array_key_exists('last_attempt_at', $meta) ? $meta['last_attempt_at'] : ($last ? OpsTime::iso((string) $last) : null),
            'last_success_at' => array_key_exists('last_success_at', $meta) ? $meta['last_success_at'] : ($last ? OpsTime::iso((string) $last) : null),
            'collector_status' => $collectorLastSuccess ? ($collectorFresh ? 'healthy' : 'stale') : 'unknown',
            'collector_fresh' => $collectorFresh,
            'projection_status' => $status,
            'freshness_note' => $collectorFresh && $status !== 'healthy'
                ? 'Collector sehat; timestamp projection pelanggan masih stale.'
                : null,
            'duration_ms' => array_key_exists('duration_ms', $meta) ? $meta['duration_ms'] : null,
            'error' => array_key_exists('error', $meta) ? $meta['error'] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function routerSourceMeta(): array
    {
        if (! Schema::hasTable('routers')) {
            return [];
        }

        $lastHealth = Schema::hasColumn('routers', 'last_health_check_at') ? Router::query()->max('last_health_check_at') : null;
        $lastScanned = Schema::hasColumn('routers', 'last_scanned_at') ? Router::query()->max('last_scanned_at') : null;
        $lastAttempt = $lastHealth ?: $lastScanned;
        $latest = Schema::hasColumn('routers', 'last_sync_stats')
            ? Router::query()
                ->whereNotNull('last_sync_stats')
                ->when(Schema::hasColumn('routers', 'last_scanned_at'), fn ($query) => $query->orderByDesc('last_scanned_at'))
                ->first(['last_sync_stats', 'sync_message'])
            : null;
        $stats = is_array($latest?->last_sync_stats) ? $latest->last_sync_stats : [];
        $errors = array_values(array_filter((array) ($stats['errors'] ?? [])));
        $healthError = $stats['health_error'] ?? null;
        $hasError = ($stats['health_ok'] ?? true) === false || $healthError !== null || $errors !== [];
        $error = $hasError
            ? (string) ($healthError ?: ($errors[0] ?? $latest?->sync_message ?? 'Router sync gagal.'))
            : null;

        return [
            'last_attempt_at' => $lastAttempt,
            'last_success_at' => ($lastAttempt && $error === null && (($stats['success'] ?? true) !== false)) ? $lastAttempt : null,
            'fresh_window_minutes' => 15,
            'duration_ms' => isset($stats['duration_ms']) ? (int) $stats['duration_ms'] : null,
            'error' => $error,
        ];
    }

    /** @return array<string, mixed> */
    private function oltSourceMeta(): array
    {
        if (! Schema::hasTable('olt_collect_runs')) {
            return [];
        }

        $columns = ['status', 'duration_ms', 'error', 'started_at', 'finished_at'];
        $latestAttempt = OltCollectRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first($columns);
        $latestSuccess = OltCollectRun::query()
            ->whereIn('status', ['success', 'completed', 'ok', 'finished'])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first($columns);
        if (! $latestAttempt && ! $latestSuccess) {
            return [];
        }

        $latestFailure = OltCollectRun::query()
            ->whereNotIn('status', ['success', 'completed', 'ok', 'finished'])
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first($columns);
        $successAt = $latestSuccess?->finished_at;
        $failureIsNewer = $latestFailure?->finished_at && (! $successAt || $latestFailure->finished_at->gt($successAt));

        return [
            'last_attempt_at' => $latestAttempt?->started_at?->toISOString(),
            'last_success_at' => $successAt?->toISOString(),
            'fresh_window_minutes' => 30,
            'duration_ms' => $latestAttempt?->duration_ms !== null ? (int) $latestAttempt->duration_ms : null,
            'error' => $failureIsNewer ? $latestFailure?->error : null,
        ];
    }

    private function sourceStatus(int $total, int $fresh): string
    {
        if ($total === 0) {
            return 'unknown';
        }

        $ratio = $fresh / $total;

        return $ratio >= 0.95 ? 'healthy' : ($fresh > 0 ? 'degraded' : 'stale');
    }

    /**
     * Queue depth is observability only. Reading a missing target lane must
     * never dispatch a job or create a Redis key with application data.
     *
     * @return array{queues: array<string, array{pending: int, consumer_active: bool}>, max_pending: int}
     */
    private function queueStatus(): array
    {
        $names = [
            'psb',
            'migration-live',
            'migration-lookup',
            'monitoring',
            'monitoring-router',
            'monitoring-olt',
            'monitoring-acs',
            'monitoring-ping',
            'monitoring-evaluate',
        ];
        $activeConsumers = array_flip((array) config('services.monitoring.queue_consumers', []));
        $queues = [];

        foreach ($names as $name) {
            try {
                $pending = max(0, (int) Queue::size($name));
            } catch (\Throwable) {
                $pending = 0;
            }

            $queues[$name] = [
                'pending' => $pending,
                'consumer_active' => isset($activeConsumers[$name]),
            ];
        }

        return [
            'queues' => $queues,
            'max_pending' => collect($queues)->max('pending') ?? 0,
            'lane_contract' => app(MonitoringQueueContract::class)->status(),
        ];
    }

    /**
     * Return bounded coverage counts without loading customer rows into PHP.
     * The strategy labels are evidence-based; unknown is intentional when the
     * current schema cannot prove a stronger mapping.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        $cacheKey = 'monitoring.noc-coverage.v1';

        return Cache::remember($cacheKey, now()->addSeconds(30), function (): array {
            $active = Customer::query()->whereIn('status', CustomerStatus::monitored());
            $total = (int) (clone $active)->count();
            $isolated = (int) (clone $active)->whereIn('status', CustomerStatus::isolatedOrClosed())->count();
            $mapped = (int) (clone $active)
                ->whereNotNull('router_id')
                ->where(function ($query) {
                    $query->whereNotNull('olt_id')->orWhereNotNull('onu_serial');
                })
                ->count();

            $fiberhome = Schema::hasTable('genieacs_devices')
                ? (int) DB::table('genieacs_devices')->whereNotNull('customer_id')->distinct()->count('customer_id')
                : 0;
            $hiosoHtb = Schema::hasTable('olts')
                ? (int) DB::table('customers')
                    ->join('olts', 'olts.id', '=', 'customers.olt_id')
                    ->whereIn('customers.status', CustomerStatus::monitored())
                    ->where(function ($query) {
                        $query->where('olts.vendor', 'like', '%hioso%')
                            ->orWhere('olts.vendor', 'like', '%htb%');
                    })
                    ->distinct()
                    ->count('customers.id')
                : 0;
            $pppoe = (int) (clone $active)->whereNotNull('pppoe_user')->where('pppoe_user', '<>', '')->count();

            $known = min($total, max($fiberhome, 0) + max($hiosoHtb, 0) + max($pppoe - $fiberhome - $hiosoHtb, 0));

            return [
                'total' => $total,
                'mapped' => $mapped,
                'needs_mapping' => max(0, $total - $mapped),
                'isolated' => $isolated,
                'strategies' => [
                    'fiberhome_tr069' => $fiberhome,
                    'pppoe_only' => max(0, $pppoe - $fiberhome - $hiosoHtb),
                    'hioso_htb' => $hiosoHtb,
                    'manual' => max(0, $mapped - $known),
                    'unknown' => max(0, $total - $known),
                ],
                'as_of' => now()->timezone('Asia/Jakarta')->toISOString(),
            ];
        });
    }

    private function incidents(?Request $request): array
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return [];
        }

        $query = $this->activeEvents()
            ->when($request?->filled('severity'), fn ($builder) => $builder->where('severity', $request->query('severity')))
            ->orderByDesc('last_seen_at')
            ->limit(2000);

        $events = $query->get(['id', 'source', 'entity_type', 'entity_id', 'entity_name', 'issue', 'severity', 'status', 'dedupe_key', 'payload', 'first_seen_at', 'last_seen_at']);
        $ticketMap = $this->ticketMap($events->pluck('dedupe_key')->filter()->map(fn ($key) => (string) $key)->unique()->values()->all());
        $grouped = collect($this->groupEvents($events, $ticketMap));
        $page = max(1, min(100, (int) ($request?->query('noc_page', 1) ?? 1)));
        $perPage = max(10, min(50, (int) ($request?->query('noc_per_page', 25) ?? 25)));
        $total = $grouped->count();

        return [
            'items' => $grouped->forPage($page, $perPage)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'total' => $total,
            ],
        ];
    }

    private function activeEvents()
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return MonitoringAlertEvent::query()->whereRaw('1 = 0');
        }

        return MonitoringAlertEvent::query()
            ->where('status', 'active')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('customers')
                    ->whereColumn('customers.id', 'monitoring_alert_events.entity_id')
                    ->whereIn('customers.status', CustomerStatus::isolatedOrClosed());
            });
    }

    private function groupEvents($events, array $ticketMap = []): array
    {
        return $events->groupBy(fn (MonitoringAlertEvent $event) => $this->incidentKey($event))
            ->map(function ($group, string $key) use ($ticketMap) {
                $first = $group->sortBy('first_seen_at')->first();
                $last = $group->sortByDesc('last_seen_at')->first();
                $payload = $last->payload ?? [];
                $affectedCount = $group
                    ->filter(fn (MonitoringAlertEvent $event) => $event->entity_type === 'customer')
                    ->pluck('entity_id')
                    ->filter()
                    ->unique()
                    ->count();
                $affectedCount = max(1, $affectedCount ?: $group->count());
                $platformSource = $last->source !== 'connectivity_scan';
                $dataQuality = in_array((string) $last->issue, ['stale_snapshot', 'mapping_missing', 'mapping_conflict'], true);
                $priority = $dataQuality ? 'P4' : ($platformSource || $affectedCount >= 20 ? 'P1' : ($affectedCount >= 5 ? 'P2' : 'P3'));
                $severity = match ($priority) {
                    'P1' => 'critical',
                    'P2' => 'warning',
                    default => 'info',
                };
                $ticket = $group
                    ->pluck('dedupe_key')
                    ->map(fn ($dedupeKey) => $ticketMap[(string) $dedupeKey] ?? null)
                    ->filter()
                    ->first();

                return [
                    'incident_key' => $key,
                    'priority' => $priority,
                    'severity' => $severity,
                    'source' => $last->source,
                    'issue' => $last->issue,
                    'issue_label' => $this->issueLabel((string) $last->issue),
                    'affected_count' => $affectedCount,
                    'entity_names' => $group->pluck('entity_name')->filter()->unique()->take(5)->values()->all(),
                    'area' => $payload['area'] ?? null,
                    'router' => $payload['router'] ?? ($payload['router_name'] ?? null),
                    'olt' => $payload['olt_name'] ?? null,
                    'pon' => $payload['pon_port'] ?? ($payload['olt_port_label'] ?? null),
                    'odp' => $payload['odp'] ?? null,
                    'first_seen_at' => $first->first_seen_at?->timezone('Asia/Jakarta')?->toISOString(),
                    'last_seen_at' => $last->last_seen_at?->timezone('Asia/Jakarta')?->toISOString(),
                    'evidence' => $payload,
                    'ticket_id' => $ticket['id'] ?? null,
                    'ticket_status' => $ticket['status'] ?? null,
                    'ticket_owner' => $ticket['owner'] ?? null,
                    'ticket_due_at' => $ticket['due_at'] ?? null,
                    'status' => 'active',
                ];
            })
            ->sortByDesc(fn (array $row) => [$row['severity'] === 'critical' ? 2 : 1, $row['affected_count']])
            ->values()
            ->all();
    }

    /** @param array<int, string> $dedupeKeys */
    private function ticketMap(array $dedupeKeys): array
    {
        if ($dedupeKeys === [] || ! Schema::hasTable('support_tickets')) {
            return [];
        }

        $wanted = array_fill_keys($dedupeKeys, true);

        return SupportTicket::query()
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
            ->orderByDesc('updated_at')
            ->limit(500)
            ->with('assignee:id,name')
            ->get(['id', 'status', 'assigned_to', 'due_at', 'meta'])
            ->mapWithKeys(function (SupportTicket $ticket) use ($wanted) {
                $key = (string) ($ticket->meta['monitoring_alert_dedupe_key'] ?? '');
                if ($key === '' || ! isset($wanted[$key])) {
                    return [];
                }

                return [$key => [
                    'id' => (int) $ticket->id,
                    'status' => $ticket->status,
                    'owner' => $ticket->assignee?->name,
                    'due_at' => $ticket->due_at?->timezone('Asia/Jakarta')?->toISOString(),
                ]];
            })
            ->all();
    }

    private function incidentKey(MonitoringAlertEvent $event): string
    {
        $payload = $event->payload ?? [];

        return (string) ($payload['incident_key'] ?? implode('|', [
            $event->source,
            $payload['router_id'] ?? ($payload['router'] ?? '-'),
            $payload['olt_id'] ?? ($payload['olt_name'] ?? '-'),
            $payload['pon_port'] ?? ($payload['olt_port_label'] ?? '-'),
            $payload['odp'] ?? '-',
            $event->issue,
        ]));
    }

    private function issueLabel(string $issue): string
    {
        return match ($issue) {
            'pon_pppoe_mismatch' => 'PON offline - PPPoE aktif',
            'bad_signal_online' => 'Redaman buruk - pelanggan online',
            default => ucwords(str_replace('_', ' ', $issue)),
        };
    }
}
