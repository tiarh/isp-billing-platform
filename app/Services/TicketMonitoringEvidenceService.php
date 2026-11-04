<?php

namespace App\Services;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AreaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TicketMonitoringEvidenceService
{
    public const DEFAULT_ISSUE = 'pon_pppoe_mismatch';

    public const ISSUES = ['pon_pppoe_mismatch', 'bad_signal_online'];

    public const CLOSED_TICKET_STATUSES = ['resolved', 'closed', 'cancelled'];

    public const SUMMARY_CACHE_SECONDS = 60;

    /**
     * Events whose last_seen_at is older than this many minutes are ignored (scan late/stopped).
     * Config: services.monitoring.candidate_stale_minutes (default 45, the scan may run every 30 minutes).
     */
    public function staleMinutes(): int
    {
        return max(1, (int) config('services.monitoring.candidate_stale_minutes', 45));
    }

    /**
     * Cheap payload for the toolbar chip: counts only, computed in SQL and cached for a minute per area scope.
     *
     * @return array{total: int, by_issue: array<string, int>, massal: int, ticketed: int, stale: bool}
     */
    public function summary(?User $user): array
    {
        return Cache::remember(
            'ticket_monitoring_summary.'.$this->scopeKey($user).'.'.$this->staleMinutes(),
            now()->addSeconds(self::SUMMARY_CACHE_SECONDS),
            fn () => $this->computeSummary($user, $this->openTicketLookup()),
        );
    }

    /**
     * Drawer payload for one issue tab (candidates limited to $limit rows) plus massal groups and fresh counts.
     *
     * @param  array<string, mixed>  $filters
     * @return array{candidates: array<int, array<string, mixed>>, mass_outage_drafts: array<int, array<string, mixed>>, summary: array<string, mixed>, stale: bool, stale_minutes: int, filters: array<string, mixed>}
     */
    public function indexPayload(?User $user, int $limit = 50, array $filters = []): array
    {
        $status = (string) ($filters['monitoring_status'] ?? 'active');
        $issue = (string) ($filters['monitoring_issue'] ?? self::DEFAULT_ISSUE);
        if (! in_array($issue, self::ISSUES, true)) {
            $issue = self::DEFAULT_ISSUE;
        }
        $showTicketed = filter_var($filters['monitoring_show_ticketed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $severity = (string) ($filters['monitoring_severity'] ?? 'all');
        $source = (string) ($filters['monitoring_source'] ?? 'all');
        $area = $filters['monitoring_area'] ?? null;

        $lookup = $this->openTicketLookup();
        $summary = $this->computeSummary($user, $lookup);

        $base = $this->candidateQuery($user, $status, $lookup, $showTicketed)
            ->when($severity !== 'all', fn (Builder $q) => $q->where('severity', $severity))
            ->when($source !== 'all', fn (Builder $q) => $q->where('source', $source))
            ->when(filled($area), fn (Builder $q) => $q->where('payload->area', $area));

        $candidates = (clone $base)
            ->where('issue', $issue)
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info') ASC")
            ->orderByDesc('last_seen_at')
            ->limit($limit)
            ->get();

        return [
            'candidates' => $candidates->map(fn (MonitoringAlertEvent $event) => $this->eventPayload($event, $lookup))->values()->all(),
            'mass_outage_drafts' => $this->massOutageDrafts($base),
            'summary' => $summary,
            'stale' => $summary['stale'],
            'stale_minutes' => $this->staleMinutes(),
            'filters' => [
                'monitoring_status' => $status,
                'monitoring_issue' => $issue,
                'monitoring_severity' => $severity,
                'monitoring_source' => $source,
                'monitoring_area' => (string) ($area ?? ''),
                'monitoring_show_ticketed' => $showTicketed,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function ticketEvidence(SupportTicket $ticket, ?User $user): array
    {
        $keys = array_values(array_filter([
            $ticket->meta['monitoring_alert_dedupe_key'] ?? null,
        ]));

        $query = $this->visibleEvents($user)
            ->where(function (Builder $q) use ($ticket, $keys) {
                if ($keys !== []) {
                    $q->whereIn('dedupe_key', $keys);
                }
                if ($ticket->customer_id) {
                    $q->orWhere(function (Builder $customer) use ($ticket) {
                        $customer->where('entity_type', 'customer')
                            ->where('entity_id', (string) $ticket->customer_id);
                    });
                }
            })
            ->orderByRaw("FIELD(status, 'active', 'recovered') ASC")
            ->orderByDesc('last_seen_at')
            ->limit(8);

        $events = $query->get();
        $lookup = ['keys' => $this->existingTicketMap($events->pluck('dedupe_key')->filter()->values()->all()), 'customers' => []];

        return $events->map(fn (MonitoringAlertEvent $event) => $this->eventPayload($event, $lookup))->values()->all();
    }

    public function createDraftTicket(MonitoringAlertEvent $event, User $user): SupportTicket
    {
        $customer = $this->customerForEvent($event, $user);
        $dedupeKey = (string) $event->dedupe_key;

        $existing = SupportTicket::query()
            ->where('meta->monitoring_alert_dedupe_key', $dedupeKey)
            ->whereNotIn('status', self::CLOSED_TICKET_STATUSES)
            ->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($event, $customer, $user, $dedupeKey) {
            $payload = $event->payload ?? [];
            $ticket = SupportTicket::create([
                'customer_id' => $customer?->id,
                'customer_name' => $payload['customer_name'] ?? $event->entity_name,
                'customer_phone' => $customer?->phone,
                'customer_coords' => $customer && $customer->geo_lat && $customer->geo_long
                    ? $customer->geo_lat.','.$customer->geo_long
                    : null,
                'type' => 'incident',
                'priority' => $event->severity === 'critical' ? 'urgent' : 'high',
                'status' => 'pending',
                'gangguan' => $this->issueLabel((string) $event->issue).' - '.($payload['customer_name'] ?? $event->entity_name ?? 'Pelanggan'),
                'keterangan' => $this->evidenceSummary($event),
                'created_by' => $user->id,
                'meta' => [
                    'approval_state' => 'monitoring_draft',
                    'monitoring_alert_event_id' => $event->id,
                    'monitoring_alert_dedupe_key' => $dedupeKey,
                    'monitoring_alert_source' => $event->source,
                    'monitoring_payload_snapshot' => $payload,
                ],
            ]);

            $ticket->activities()->create([
                'user_id' => $user->id,
                'action' => 'created',
                'description' => 'Draft tiket dibuat dari kandidat monitoring setelah approval operator.',
                'new_value' => ['monitoring_alert_dedupe_key' => $dedupeKey],
            ]);

            return $ticket;
        });
    }

    protected function visibleEvents(?User $user): Builder
    {
        $query = MonitoringAlertEvent::query()
            ->where('source', 'connectivity_scan')
            ->where('entity_type', 'customer')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('customers')
                    ->whereColumn('customers.id', 'monitoring_alert_events.entity_id')
                    ->whereIn('customers.status', CustomerStatus::isolatedOrClosed());
            });

        if ($user && $user->hasAreaScope()) {
            $customers = Customer::query()->select('id');
            AreaScope::applyToCustomers($customers, $user);
            $query->whereIn('entity_id', $customers);
        }

        return $query;
    }

    protected function customerForEvent(MonitoringAlertEvent $event, User $user): ?Customer
    {
        $customer = Customer::query()->find((int) $event->entity_id);
        if ($customer) {
            AreaScope::authorizeCustomer($customer, $user);
        }

        return $customer;
    }

    protected function scopeKey(?User $user): string
    {
        if (! $user) {
            return 'guest';
        }
        if ($user->hasAreaScope()) {
            return 'areas.'.$user->accessibleAreaIds()->sort()->values()->implode('-');
        }

        return 'global';
    }

    /**
     * Open (not resolved/closed/cancelled) tickets: dedupe key -> ticket id for monitoring drafts,
     * customer id -> ticket id for any open ticket of that customer. Latest ticket wins.
     *
     * @return array{keys: array<string, int>, customers: array<string, int>}
     */
    protected function openTicketLookup(): array
    {
        $rows = SupportTicket::query()
            ->whereNotIn('status', self::CLOSED_TICKET_STATUSES)
            ->selectRaw("id, customer_id, JSON_UNQUOTE(JSON_EXTRACT(meta, '$.monitoring_alert_dedupe_key')) as dkey")
            ->orderBy('id')
            ->get();

        $keys = [];
        $customers = [];
        foreach ($rows as $row) {
            if (filled($row->dkey)) {
                $keys[(string) $row->dkey] = (int) $row->id;
            }
            if ($row->customer_id) {
                $customers[(string) $row->customer_id] = (int) $row->id;
            }
        }

        return ['keys' => $keys, 'customers' => $customers];
    }

    /**
     * Fresh (not stale) events of the known issues, optionally without those that already have an open ticket.
     *
     * @param  array{keys: array<string, int>, customers: array<string, int>}  $lookup
     */
    protected function candidateQuery(?User $user, string $status, array $lookup, bool $includeTicketed): Builder
    {
        $query = $this->visibleEvents($user)
            ->whereIn('issue', self::ISSUES)
            ->when($status !== 'all', fn (Builder $q) => $q->where('status', $status))
            ->where('last_seen_at', '>=', now()->subMinutes($this->staleMinutes()));

        if (! $includeTicketed) {
            if ($lookup['keys'] !== []) {
                $query->where(fn (Builder $q) => $q->whereNull('dedupe_key')->orWhereNotIn('dedupe_key', array_keys($lookup['keys'])));
            }
            if ($lookup['customers'] !== []) {
                $query->whereNotIn('entity_id', array_map('strval', array_keys($lookup['customers'])));
            }
        }

        return $query;
    }

    /**
     * @param  array{keys: array<string, int>, customers: array<string, int>}  $lookup
     * @return array{total: int, by_issue: array<string, int>, massal: int, ticketed: int, stale: bool}
     */
    protected function computeSummary(?User $user, array $lookup): array
    {
        $fresh = $this->countByIssue($this->candidateQuery($user, 'active', $lookup, true));
        $openQuery = $this->candidateQuery($user, 'active', $lookup, false);
        $open = $this->countByIssue(clone $openQuery);

        $byIssue = [];
        foreach (self::ISSUES as $issue) {
            $byIssue[$issue] = $open[$issue] ?? 0;
        }
        $total = array_sum($byIssue);

        // Late/stopped scan: active events exist but none was refreshed inside the cutoff.
        $hasActive = $this->visibleEvents($user)->where('status', 'active')->exists();
        $hasFresh = $this->visibleEvents($user)->where('status', 'active')
            ->where('last_seen_at', '>=', now()->subMinutes($this->staleMinutes()))->exists();

        return [
            'total' => $total,
            'by_issue' => $byIssue,
            'massal' => $this->massGroupQuery($openQuery)->get()->count(),
            'ticketed' => max(0, array_sum($fresh) - $total),
            'stale' => $hasActive && ! $hasFresh,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function countByIssue(Builder $query): array
    {
        return $query->selectRaw('issue, COUNT(*) as c')
            ->groupBy('issue')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->issue => (int) $row->c])
            ->all();
    }

    protected function odpExpression(): string
    {
        return "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.odp')), 'null'), 'Tanpa ODP')";
    }

    protected function ponExpression(): string
    {
        return "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.pon_port')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.olt_port_label')), 'null'), '-')";
    }

    /**
     * ODP|PON|issue groups with at least 3 events, biggest first.
     */
    protected function massGroupQuery(Builder $query): Builder
    {
        return (clone $query)
            ->selectRaw('issue, '.$this->odpExpression().' as g_odp, '.$this->ponExpression().' as g_pon, COUNT(*) as g_count, '
                ."MAX(last_seen_at) as last_seen_at, MIN(FIELD(severity, 'critical', 'warning', 'info')) as g_sev")
            ->groupByRaw('issue, g_odp, g_pon')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('g_count');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function massOutageDrafts(Builder $base): array
    {
        $severities = [1 => 'critical', 2 => 'warning', 3 => 'info'];

        return $this->massGroupQuery($base)
            ->limit(5)
            ->get()
            ->map(function ($group) use ($base, $severities) {
                $samples = (clone $base)
                    ->where('issue', $group->issue)
                    ->whereRaw($this->odpExpression().' = ?', [$group->g_odp])
                    ->whereRaw($this->ponExpression().' = ?', [$group->g_pon])
                    ->orderByDesc('last_seen_at')
                    ->limit(5)
                    ->get()
                    ->map(fn (MonitoringAlertEvent $event) => $event->payload['customer_name'] ?? $event->entity_name)
                    ->values()
                    ->all();

                return [
                    'group_key' => implode('|', [$group->g_odp, $group->g_pon, $group->issue]),
                    'issue' => $group->issue,
                    'issue_label' => $this->issueLabel((string) $group->issue),
                    'severity' => $severities[(int) $group->g_sev] ?? 'warning',
                    'count' => (int) $group->g_count,
                    'odp' => $group->g_odp,
                    'pon' => $group->g_pon,
                    'last_seen_at' => optional($group->last_seen_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
                    'sample_customers' => $samples,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{keys: array<string, int>, customers: array<string, int>}  $lookup
     * @return array<string, mixed>
     */
    protected function eventPayload(MonitoringAlertEvent $event, array $lookup = ['keys' => [], 'customers' => []]): array
    {
        $payload = $event->payload ?? [];

        return [
            'id' => $event->id,
            'dedupe_key' => $event->dedupe_key,
            'source' => $event->source,
            'source_label' => $payload['source_label'] ?? 'Connectivity snapshot',
            'issue' => $event->issue,
            'issue_label' => $this->issueLabel((string) $event->issue),
            'severity' => $event->severity,
            'status' => $event->status,
            'entity_name' => $event->entity_name,
            'customer_id' => $event->entity_type === 'customer' ? (int) $event->entity_id : null,
            'customer_name' => $payload['customer_name'] ?? $event->entity_name,
            'customer_code' => $payload['customer_code'] ?? null,
            'area' => $payload['area'] ?? null,
            'odp' => $payload['odp'] ?? null,
            'pon_port' => $payload['pon_port'] ?? ($payload['olt_port_label'] ?? null),
            'olt_name' => $payload['olt_name'] ?? null,
            'onu_serial' => $payload['onu_serial'] ?? null,
            'onu_rx_power_dbm' => $payload['onu_rx_power_dbm'] ?? null,
            'pppoe_online' => $payload['pppoe_online'] ?? null,
            'olt_status' => $payload['olt_status'] ?? null,
            'first_seen_at' => optional($event->first_seen_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            'last_seen_at' => optional($event->last_seen_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
            'existing_ticket_id' => $lookup['keys'][(string) $event->dedupe_key] ?? $lookup['customers'][(string) $event->entity_id] ?? null,
            'occurrence_count' => (int) ($payload['occurrence_count'] ?? 1),
        ];
    }

    /**
     * @param  array<int, string>  $dedupeKeys
     * @return array<string, int>
     */
    protected function existingTicketMap(array $dedupeKeys): array
    {
        $dedupeKeys = array_values(array_filter(array_unique(array_map('strval', $dedupeKeys))));
        if ($dedupeKeys === []) {
            return [];
        }

        return SupportTicket::query()
            ->whereIn('meta->monitoring_alert_dedupe_key', $dedupeKeys)
            ->whereNotIn('status', self::CLOSED_TICKET_STATUSES)
            ->get(['id', 'meta'])
            ->mapWithKeys(fn (SupportTicket $ticket) => [
                (string) ($ticket->meta['monitoring_alert_dedupe_key'] ?? '') => (int) $ticket->id,
            ])
            ->filter(fn ($id, $key) => $key !== '')
            ->all();
    }

    protected function evidenceSummary(MonitoringAlertEvent $event): string
    {
        $payload = $event->payload ?? [];
        $parts = array_filter([
            'Sumber monitoring: '.$this->issueLabel((string) $event->issue),
            isset($payload['odp']) ? 'ODP '.$payload['odp'] : null,
            isset($payload['pon_port']) ? 'PON '.$payload['pon_port'] : null,
            isset($payload['olt_status']) ? 'OLT '.$payload['olt_status'] : null,
            isset($payload['pppoe_online']) ? 'PPPoE '.($payload['pppoe_online'] ? 'online' : 'offline') : null,
            isset($payload['onu_rx_power_dbm']) ? 'RX '.$payload['onu_rx_power_dbm'].' dBm' : null,
            'Last seen '.optional($event->last_seen_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB',
        ]);

        return Str::limit(implode(' | ', $parts), 1800);
    }

    protected function issueLabel(string $issue): string
    {
        return match ($issue) {
            'pon_pppoe_mismatch' => 'PON offline - PPPoE aktif',
            'bad_signal_online' => 'Redaman buruk - pelanggan online',
            default => Str::headline(str_replace('_', ' ', $issue)),
        };
    }
}
