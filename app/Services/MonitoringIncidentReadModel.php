<?php

namespace App\Services;

use App\Models\MonitoringAlertEvent;
use App\Models\Setting;
use App\Models\SupportTicket;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Groups active monitoring alerts into actionable incidents scoped to a PON/ODC
 * or a single ODP, so NOC can see "this is one ODC / one ODP", not 135 loose rows.
 *
 * The alert `payload['odp']` is unreliable: it is often the ODC name ("ODC",
 * "ODC 1") or even the area name, not an ODP. So the ODC/PON scope is keyed on
 * olt + pon_port (always present), and a real ODP scope is only used when the
 * value actually looks like an ODP name.
 */
class MonitoringIncidentReadModel
{
    private const MAX_EVENTS = 3000;

    /** @return array<string, mixed> */
    public function payload(?array $filters = null): array
    {
        $filters = $filters ?? [];
        $cacheKey = 'monitoring.incidents.v1.'.sha1(json_encode($filters)).'.'.MonitoringAlertEvent::query()->where('status', 'active')->max('updated_at');

        return Cache::remember($cacheKey, now()->addSeconds(15), fn (): array => $this->build($filters));
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function build(array $filters): array
    {
        if (! Schema::hasTable('monitoring_alert_events')) {
            return $this->empty('table_missing');
        }

        $rows = MonitoringAlertEvent::query()
            ->where('status', 'active')
            ->orderByDesc('last_seen_at')
            ->limit(self::MAX_EVENTS)
            ->get();

        $ticketMap = $this->ticketMap();

        // group events by PON/ODC scope, and track real-ODP subgroups inside it.
        $pons = [];
        foreach ($rows as $event) {
            $p = is_array($event->payload) ? $event->payload : [];
            $olt = trim((string) ($p['olt_name'] ?? '')) ?: 'OLT ?';
            $pon = trim((string) ($p['pon_port'] ?? '')) ?: '-';
            $area = trim((string) ($p['area'] ?? ''));
            $odp = $this->validOdpName($p['odp'] ?? null);

            $ponKey = strtolower($olt).'|'.$pon;
            $pons[$ponKey] ??= [
                'olt' => $olt, 'pon' => $pon, 'area' => $area,
                'customers' => [], 'odps' => [], 'issues' => [], 'dedupe' => [], 'since' => null, 'last' => null,
            ];

            $customerId = $event->entity_type === 'customer' ? (int) $event->entity_id : null;
            $customerName = (string) ($p['customer_name'] ?? $event->entity_name ?? '');
            if ($customerId) {
                $pons[$ponKey]['customers'][$customerId] = $customerName ?: ('#'.$customerId);
            }
            $pons[$ponKey]['issues'][(string) $event->issue] = true;
            if ((string) $event->dedupe_key !== '') {
                $pons[$ponKey]['dedupe'][(string) $event->dedupe_key] = true;
            }

            if ($odp !== null && $customerId) {
                $odpKey = strtolower($odp);
                $pons[$ponKey]['odps'][$odpKey] ??= ['odp' => $odp, 'customers' => [], 'dedupe' => []];
                $pons[$ponKey]['odps'][$odpKey]['customers'][$customerId] = $customerName ?: ('#'.$customerId);
                if ((string) $event->dedupe_key !== '') {
                    $pons[$ponKey]['odps'][$odpKey]['dedupe'][(string) $event->dedupe_key] = true;
                }
            }

            $seen = $event->last_seen_at ? Carbon::parse($event->last_seen_at) : null;
            if ($seen) {
                if ($pons[$ponKey]['since'] === null || $seen->lt($pons[$ponKey]['since'])) {
                    $pons[$ponKey]['since'] = $seen;
                }
                if ($pons[$ponKey]['last'] === null || $seen->gt($pons[$ponKey]['last'])) {
                    $pons[$ponKey]['last'] = $seen;
                }
            }
        }

        $ponThreshold = max(2, (int) Setting::get('monitoring_incident_pon_min_customers', 3));
        $odpThreshold = max(2, (int) Setting::get('monitoring_incident_odp_min_customers', 2));

        $incidents = [];
        $areaFilter = trim((string) ($filters['area'] ?? ''));
        $allowedAreas = array_values(array_filter(array_map(
            fn ($a) => strtolower(trim((string) $a)),
            (array) ($filters['areas'] ?? []),
        ), fn ($a) => $a !== ''));
        $allowedLookup = $allowedAreas === [] ? null : array_fill_keys($allowedAreas, true);

        foreach ($pons as $pon) {
            $ponCount = count($pon['customers']);
            if ($areaFilter !== '' && strcasecmp($areaFilter, $pon['area']) !== 0) {
                continue;
            }
            // Area-scoped users only see their areas.
            if ($allowedLookup !== null && ! isset($allowedLookup[strtolower($pon['area'])])) {
                continue;
            }

            // ODP-level incidents (a real ODP name with >= threshold customers).
            foreach ($pon['odps'] as $odp) {
                if (count($odp['customers']) >= $odpThreshold) {
                    $incidents[] = $this->incident([
                        'scope' => 'odp',
                        'scope_label' => $odp['odp'],
                        'olt' => $pon['olt'],
                        'pon' => $pon['pon'],
                        'area' => $pon['area'],
                        'customers' => $odp['customers'],
                        'issues' => $pon['issues'],
                        'dedupe' => $odp['dedupe'],
                        'since' => $pon['since'],
                        'last' => $pon['last'],
                        'ticketMap' => $ticketMap,
                    ]);
                }
            }

            // PON/ODC-level incident (whole PON affected).
            if ($ponCount >= $ponThreshold) {
                $incidents[] = $this->incident([
                    'scope' => 'pon',
                    'scope_label' => $pon['olt'].' · PON '.$pon['pon'],
                    'olt' => $pon['olt'],
                    'pon' => $pon['pon'],
                    'area' => $pon['area'],
                    'customers' => $pon['customers'],
                    'issues' => $pon['issues'],
                    'dedupe' => $pon['dedupe'],
                    'since' => $pon['since'],
                    'last' => $pon['last'],
                    'ticketMap' => $ticketMap,
                ]);
            }
        }

        // Priority: ODC(PON) with many customers = P1; ODP = P2; else P3.
        usort($incidents, fn (array $a, array $b) => [$b['rank'], $b['affected_count']] <=> [$a['rank'], $a['affected_count']]);

        return [
            'enabled' => true,
            'summary' => [
                'incidents' => count($incidents),
                'pon_incidents' => count(array_filter($incidents, fn ($i) => $i['scope'] === 'pon')),
                'odp_incidents' => count(array_filter($incidents, fn ($i) => $i['scope'] === 'odp')),
                'affected_customers' => collect($incidents)->sum('affected_count'),
                'p1' => count(array_filter($incidents, fn ($i) => $i['priority'] === 'P1')),
                'p2' => count(array_filter($incidents, fn ($i) => $i['priority'] === 'P2')),
            ],
            'incidents' => $incidents,
            'thresholds' => ['pon_min_customers' => $ponThreshold, 'odp_min_customers' => $odpThreshold],
            'read_only' => true,
            'generated_at' => now('Asia/Jakarta')->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function incident(array $data): array
    {
        $affected = count($data['customers']);
        $isPon = $data['scope'] === 'pon';
        $priority = $isPon ? ($affected >= 20 ? 'P1' : 'P2') : 'P2';
        if (! $isPon && $affected < 2) {
            $priority = 'P3';
        }

        $issueLabels = array_map(fn ($issue) => $this->issueLabel((string) $issue), array_keys($data['issues']));
        $ticket = null;
        foreach (array_keys($data['dedupe'] ?? []) as $dedupeKey) {
            if (isset($data['ticketMap'][$dedupeKey])) {
                $ticket = $data['ticketMap'][$dedupeKey];
                break;
            }
        }

        return [
            'incident_key' => $data['scope'].'|'.strtolower($data['olt']).'|'.$data['pon'].'|'.strtolower($data['scope_label']),
            'scope' => $data['scope'],
            'scope_label' => $data['scope_label'],
            'priority' => $priority,
            'severity' => match ($priority) {
                'P1' => 'critical',
                'P2' => 'warning',
                default => 'info',
            },
            'rank' => match ($priority) {
                'P1' => 3,
                'P2' => 2,
                default => 1,
            },
            'affected_count' => $affected,
            'area' => $data['area'] ?: null,
            'olt' => $data['olt'],
            'pon' => $data['pon'],
            'issues' => array_values($issueLabels),
            'customers' => collect($data['customers'])
                ->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])
                ->values()
                ->all(),
            'since' => $data['since']?->timezone('Asia/Jakarta')->toISOString(),
            'last_seen_at' => $data['last']?->timezone('Asia/Jakarta')->toISOString(),
            'ticket_id' => $ticket['id'] ?? null,
            'ticket_status' => $ticket['status'] ?? null,
        ];
    }

    /** @return array<string, array{id:int,status:string}> */
    private function ticketMap(): array
    {
        if (! Schema::hasTable('support_tickets')) {
            return [];
        }

        return SupportTicket::query()
            ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get(['id', 'status', 'meta'])
            ->mapWithKeys(function (SupportTicket $ticket): array {
                $key = (string) ($ticket->meta['monitoring_alert_dedupe_key'] ?? '');

                return $key === '' ? [] : [$key => ['id' => (int) $ticket->id, 'status' => (string) $ticket->status]];
            })
            ->all();
    }

    /** An ODP name must look like an ODP; "ODC"/"ODC 1"/area names are not ODPs. */
    private function validOdpName(mixed $value): ?string
    {
        $name = trim((string) $value);
        if ($name === '' || strlen($name) < 3) {
            return null;
        }
        // Reject ODC-style and area-like labels.
        if (preg_match('/^ODC\b/i', $name)) {
            return null;
        }
        if (preg_match('/^SKYNET-/i', $name)) {
            return null;
        }
        // Accept explicit ODP labels and the W2_LW_*.SKY_* pattern.
        if (preg_match('/ODP/i', $name) || preg_match('/^W2_/i', $name) || preg_match('/\.SKY[_-]?\d+/i', $name)) {
            return $name;
        }

        return null;
    }

    private function issueLabel(string $issue): string
    {
        return match ($issue) {
            'pon_pppoe_mismatch' => 'PON offline - PPPoE aktif',
            'bad_signal_online' => 'Redaman buruk - pelanggan online',
            'weak_rx' => 'RX lemah (OLT)',
            'collector_failed' => 'Collector OLT gagal',
            default => ucwords(str_replace('_', ' ', $issue)),
        };
    }

    /** @return array<string, mixed> */
    private function empty(string $reason): array
    {
        return [
            'enabled' => false,
            'summary' => ['incidents' => 0, 'pon_incidents' => 0, 'odp_incidents' => 0, 'affected_customers' => 0, 'p1' => 0, 'p2' => 0],
            'incidents' => [],
            'thresholds' => [],
            'read_only' => true,
            'reason' => $reason,
            'generated_at' => now('Asia/Jakarta')->toISOString(),
        ];
    }
}
