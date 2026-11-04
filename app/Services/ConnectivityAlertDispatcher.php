<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\MonitoringAlertEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ConnectivityAlertDispatcher
{
    public function __construct(
        protected ConnectivityAlertSettings $settings,
        protected PonPppoeMismatchDetector $detector,
    ) {}

    /**
     * @return array{
     *   customer_sent: int,
     *   odp_sent: int,
     *   customer_skipped: int,
     *   odp_skipped: int,
     *   failed: int,
     *   dry_run: bool
     * }
     */
    public function dispatch(Collection $events, EvolutionApiService $evolution, bool $dryRun = false): array
    {
        $groupId = $this->settings->groupId();
        $stats = [
            'customer_sent' => 0,
            'odp_sent' => 0,
            'customer_skipped' => 0,
            'odp_skipped' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
        ];

        if ($events->isEmpty()) {
            return $stats;
        }

        $events = $this->filterDispatchableEvents($events);
        if ($events->isEmpty()) {
            return $stats;
        }

        $customerLimit = (int) config('services.connectivity_alerts.customer_limit_per_run', 5);
        $odpLimit = (int) config('services.connectivity_alerts.odp_limit_per_run', 5);
        $now = now();

        if ($this->settings->sendsCustomerAlerts()) {
            foreach ($events as $event) {
                if ($this->customerAlreadyNotified($event)) {
                    continue;
                }

                if ($stats['customer_sent'] >= $customerLimit) {
                    $stats['customer_skipped']++;

                    continue;
                }

                $message = $this->buildCustomerMessage($event);

                if ($dryRun) {
                    Log::info('Connectivity customer alert dry-run', ['event_id' => $event->id, 'message' => $message]);
                    $stats['customer_sent']++;

                    continue;
                }

                $opsEvolution = $evolution->forChannel('ops');
                if ($groupId === '' || ! $opsEvolution->isConfigured()) {
                    $stats['failed']++;

                    continue;
                }

                $result = $opsEvolution->sendToGroup($groupId, $message);
                if (! ($result['success'] ?? false)) {
                    Log::warning('Connectivity customer alert failed', [
                        'event_id' => $event->id,
                        'error' => $result['error'] ?? 'unknown',
                    ]);
                    $stats['failed']++;

                    continue;
                }

                $this->markCustomerNotified($event, $now);
                $stats['customer_sent']++;
            }
        }

        if (! $this->settings->sendsOdpAlerts()) {
            return $stats;
        }

        $odpGroups = $events->groupBy(fn (MonitoringAlertEvent $event) => $this->odpGroupKey($event));

        foreach ($odpGroups as $groupKey => $groupEvents) {
            if ($stats['odp_sent'] >= $odpLimit) {
                $stats['odp_skipped'] += $groupEvents->count();

                continue;
            }

            if ($this->odpAlreadyNotified($groupEvents)) {
                continue;
            }

            $message = $this->buildOdpMessage($groupKey, $groupEvents);

            if ($dryRun) {
                Log::info('Connectivity ODP alert dry-run', [
                    'group' => $groupKey,
                    'count' => $groupEvents->count(),
                    'message' => $message,
                ]);
                $stats['odp_sent']++;

                continue;
            }

            $opsEvolution = $evolution->forChannel('ops');
            if ($groupId === '' || ! $opsEvolution->isConfigured()) {
                $stats['failed'] += $groupEvents->count();

                continue;
            }

            $result = $opsEvolution->sendToGroup($groupId, $message);
            if (! ($result['success'] ?? false)) {
                Log::warning('Connectivity ODP alert failed', [
                    'group' => $groupKey,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                $stats['failed'] += $groupEvents->count();

                continue;
            }

            $this->markOdpNotified($groupEvents, $now);
            $stats['odp_sent']++;
        }

        return $stats;
    }

    /**
     * Dispatch recovery notifications to NOC group for pon_pppoe_mismatch events
     * that recovered within the last 30 minutes and had previously received a WA alert.
     *
     * @return array{recovery_sent: int, recovery_skipped: int, failed: int, dry_run: bool}
     */
    public function dispatchRecovery(EvolutionApiService $evolution, bool $dryRun = false): array
    {
        $groupId = $this->settings->groupId();
        $stats = [
            'recovery_sent' => 0,
            'recovery_skipped' => 0,
            'failed' => 0,
            'dry_run' => $dryRun,
        ];

        $recoveryLimit = (int) config('services.connectivity_alerts.recovery_limit_per_run', 10);
        $now = now();

        // Only events that: recovered in last 30min + alert was previously sent (last_sent_at column,
        // NOT wa_odp_sent_at payload — payload gets overwritten by scanner every 5min) + recovery not yet notified
        $events = MonitoringAlertEvent::query()
            ->where('source', 'connectivity_scan')
            ->where('status', 'recovered')
            ->where('issue', 'pon_pppoe_mismatch')
            ->where('recovered_at', '>=', $now->copy()->subMinutes(30))
            ->whereNotNull('last_sent_at')      // alert was actually sent (DB column, persists through scan cycles)
            ->orderBy('recovered_at')
            ->get()
            ->filter(fn (MonitoringAlertEvent $e) => empty($e->payload['wa_recovery_sent_at'])  // idempotent — not yet notified this recovery cycle
            );

        if ($events->isEmpty()) {
            return $stats;
        }

        $odpGroups = $events->groupBy(fn (MonitoringAlertEvent $e) => $this->odpGroupKey($e));

        foreach ($odpGroups as $groupKey => $groupEvents) {
            if ($stats['recovery_sent'] >= $recoveryLimit) {
                $stats['recovery_skipped'] += $groupEvents->count();

                continue;
            }

            $message = $this->buildRecoveryMessage($groupKey, $groupEvents);

            if ($dryRun) {
                Log::info('Connectivity recovery dry-run', [
                    'group' => $groupKey,
                    'count' => $groupEvents->count(),
                    'message' => $message,
                ]);
                $stats['recovery_sent']++;

                continue;
            }

            $opsEvolution = $evolution->forChannel('ops');
            if ($groupId === '' || ! $opsEvolution->isConfigured()) {
                $stats['failed'] += $groupEvents->count();

                continue;
            }

            $result = $opsEvolution->sendToGroup($groupId, $message);
            if (! ($result['success'] ?? false)) {
                Log::warning('Connectivity recovery alert failed', [
                    'group' => $groupKey,
                    'error' => $result['error'] ?? 'unknown',
                ]);
                $stats['failed'] += $groupEvents->count();

                continue;
            }

            $this->markRecoverySent($groupEvents, $now);
            $stats['recovery_sent']++;
        }

        return $stats;
    }

    public function buildRecoveryMessage(string $groupKey, Collection $events): string
    {
        [$odp, $pon] = array_pad(explode('|', $groupKey, 3), 3, '-');

        /** @var MonitoringAlertEvent|null $latest */
        $latest = $events->sortByDesc('recovered_at')->first();

        $lines = [
            '✅ [Recovery] PON offline · PPPoE aktif',
            'ODP: '.$odp.' | PON: '.$pon,
            'Total pulih: '.$events->count().' pelanggan',
            '',
        ];

        foreach ($events->sortBy(fn (MonitoringAlertEvent $e) => $e->payload['customer_code'] ?? $e->entity_name) as $event) {
            $payload = $event->payload ?? [];
            $line = '• '.($payload['customer_name'] ?? $event->entity_name ?? '-')
                .(isset($payload['customer_code']) ? ' ('.$payload['customer_code'].')' : '');

            if (! empty($payload['olt_port_label'])) {
                $line .= ' · '.$payload['olt_port_label'];
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Pulih: '.optional($latest?->recovered_at)->timezone('Asia/Jakarta')->format('d/m H:i').' WIB';
        $lines[] = 'Skynet eBilling · '.now()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';

        return implode("\n", $lines);
    }

    protected function markRecoverySent(Collection $events, $now): void
    {
        foreach ($events as $event) {
            $payload = $event->payload ?? [];
            $payload['wa_recovery_sent_at'] = $now->toISOString();
            $event->forceFill(['payload' => $payload])->save();
        }
    }

    public function buildCustomerMessage(MonitoringAlertEvent $event): string
    {
        $payload = $event->payload ?? [];
        $issueLabel = $this->issueLabel((string) $event->issue);
        $lines = [
            '[Connectivity ALERT] '.$issueLabel,
            'Pelanggan: '.($payload['customer_name'] ?? $event->entity_name ?? '-')
                .(isset($payload['customer_code']) ? ' ('.$payload['customer_code'].')' : ''),
            'ODP: '.($payload['odp'] ?? 'Tanpa ODP'),
            'PON: '.($payload['pon_port'] ?? '-'),
        ];

        if (! empty($payload['olt_port_label'])) {
            $lines[] = 'Port: '.$payload['olt_port_label'];
        }
        if (! empty($payload['onu_serial'])) {
            $lines[] = 'SN: '.$payload['onu_serial'];
        }
        if (isset($payload['onu_rx_power_dbm']) && $payload['onu_rx_power_dbm'] !== null) {
            $lines[] = 'RX: '.$payload['onu_rx_power_dbm'].' dBm';
        }
        if (! empty($payload['area'])) {
            $lines[] = 'Area: '.$payload['area'];
        }
        if (! empty($payload['olt_name'])) {
            $lines[] = 'OLT: '.$payload['olt_name'];
        }

        $lines[] = 'PPPoE: '.(($payload['pppoe_online'] ?? false) ? 'online' : 'offline');
        $lines[] = 'PON: '.($payload['olt_status'] ?? '-');
        $lines[] = 'Terdeteksi: '.optional($event->first_seen_at)->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
        $lines[] = 'Detail: Monitoring → tab NOC';
        $lines[] = 'Skynet eBilling · '.now()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';

        return implode("\n", $lines);
    }

    public function buildOdpMessage(string $groupKey, Collection $events): string
    {
        /** @var MonitoringAlertEvent $first */
        $first = $events->first();
        $issueLabel = $this->issueLabel((string) $first->issue);
        [$odp, $pon, $issue] = array_pad(explode('|', $groupKey, 3), 3, '-');

        $lines = [
            '[Connectivity ODP] '.$issueLabel,
            'ODP: '.$odp,
            'PON: '.$pon,
            'Total: '.$events->count().' pelanggan',
            '',
        ];

        foreach ($events->sortBy(fn (MonitoringAlertEvent $e) => $e->payload['customer_code'] ?? $e->entity_name) as $event) {
            $payload = $event->payload ?? [];
            $line = '• '.($payload['customer_name'] ?? $event->entity_name ?? '-')
                .(isset($payload['customer_code']) ? ' ('.$payload['customer_code'].')' : '');

            $details = array_filter([
                $payload['olt_port_label'] ?? null,
                $payload['onu_serial'] ?? null,
                isset($payload['onu_rx_power_dbm']) ? $payload['onu_rx_power_dbm'].' dBm' : null,
            ]);
            if ($details !== []) {
                $line .= ' · '.implode(' · ', $details);
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Detail: Monitoring → tab NOC';
        $lines[] = 'Skynet eBilling · '.now()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';

        return implode("\n", $lines);
    }

    protected function issueLabel(string $issue): string
    {
        return match ($issue) {
            'pon_pppoe_mismatch' => 'PON offline · PPPoE aktif',
            'bad_signal_online' => 'Redaman buruk · PON+PPPoE online',
            default => $issue,
        };
    }

    protected function filterDispatchableEvents(Collection $events): Collection
    {
        return $events->filter(function (MonitoringAlertEvent $event): bool {
            $customer = Customer::query()->find($event->entity_id);
            if (! $customer) {
                $this->detector->recoverCustomerConnectivityEvents((int) $event->entity_id, (string) $event->issue);

                return false;
            }

            if ($this->detector->isFalsePositiveAlert($customer, (string) $event->issue)) {
                Log::info('Skipped connectivity alert for migrated/stale customer', [
                    'event_id' => $event->id,
                    'customer_id' => $customer->id,
                    'customer_code' => $customer->code,
                    'issue' => $event->issue,
                    'onu_serial' => $customer->onu_serial,
                ]);

                return false;
            }

            $this->refreshEventPayload($event, $customer);

            return true;
        })->values();
    }

    protected function refreshEventPayload(MonitoringAlertEvent $event, Customer $customer): void
    {
        $existing = $event->payload ?? [];
        $fresh = $this->detector->buildPayload($customer->fresh(['area', 'olt']));

        foreach (['wa_customer_sent_at', 'wa_odp_sent_at', 'wa_recovery_sent_at'] as $key) {
            if (! empty($existing[$key])) {
                $fresh[$key] = $existing[$key];
            }
        }

        $event->forceFill(['payload' => $fresh])->save();
    }

    protected function odpGroupKey(MonitoringAlertEvent $event): string
    {
        $payload = $event->payload ?? [];
        $odp = $payload['odp'] ?? 'Tanpa ODP';
        $pon = $payload['pon_port'] ?? ($payload['olt_port_label'] ?? '-');

        return $odp.'|'.$pon.'|'.$event->issue;
    }

    protected function customerAlreadyNotified(MonitoringAlertEvent $event): bool
    {
        $sentAt = $event->payload['wa_customer_sent_at'] ?? null;
        if ($sentAt === null || $sentAt === '') {
            return false;
        }

        return Carbon::parse($sentAt)->gte(now()->subMinutes($this->settings->repeatMinutes()));
    }

    protected function odpAlreadyNotified(Collection $events): bool
    {
        $cutoff = now()->subMinutes($this->settings->repeatMinutes());

        return $events->every(function (MonitoringAlertEvent $event) use ($cutoff) {
            $sentAt = $event->payload['wa_odp_sent_at'] ?? null;
            if ($sentAt === null || $sentAt === '') {
                return false;
            }

            return Carbon::parse($sentAt)->gte($cutoff);
        });
    }

    protected function markCustomerNotified(MonitoringAlertEvent $event, $now): void
    {
        $payload = $event->payload ?? [];
        $payload['wa_customer_sent_at'] = $now->toISOString();
        $event->forceFill([
            'payload' => $payload,
            'last_sent_at' => $event->last_sent_at ?? $now,
        ])->save();
    }

    protected function markOdpNotified(Collection $events, $now): void
    {
        foreach ($events as $event) {
            $payload = $event->payload ?? [];
            $payload['wa_odp_sent_at'] = $now->toISOString();
            $event->forceFill([
                'payload' => $payload,
                'last_sent_at' => $now,
            ])->save();
        }
    }
}
