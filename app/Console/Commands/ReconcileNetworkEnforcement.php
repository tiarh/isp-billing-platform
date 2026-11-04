<?php

namespace App\Console\Commands;

use App\Services\NetworkEnforcementReconciler;
use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use Illuminate\Console\Command;

/**
 * F0 PR-D: READ-ONLY router <-> DB isolation reconciliation.
 *
 * Reads /ppp/secret, /ppp/active, /ppp/profile (and /ppp/aaa) per router and
 * compares the isolation profile with eBilling customer status. Never writes
 * to a router. --store persists findings to `enforcement_drift`; --notify
 * sends a count-only summary to the ops/NOC WhatsApp group.
 */
class ReconcileNetworkEnforcement extends Command
{
    protected $signature = 'billing:reconcile-network
                            {--router= : Only this router id (scanned even when inactive)}
                            {--json : Print the full report as JSON}
                            {--store : Persist findings to enforcement_drift + last-run summary}
                            {--notify : Send a count-only summary to the ops WA group}
                            {--limit=30 : Max finding lines in the console table}';

    protected $description = 'Read-only reconciliation of router isolation profile vs eBilling customer status';

    public function handle(NetworkEnforcementReconciler $reconciler, OpsAlertService $ops): int
    {
        $routerOption = $this->option('router');
        if ($routerOption !== null && $routerOption !== '' && ! ctype_digit((string) $routerOption)) {
            $this->error('--router must be a numeric router id');

            return self::INVALID;
        }

        $report = $reconciler->run($routerOption !== null && $routerOption !== '' ? (int) $routerOption : null);

        if ($this->option('store')) {
            $stored = $reconciler->store($report);
            $report['stored_rows'] = $stored;
        }

        if ($this->option('notify')) {
            $report['notified'] = $ops->send($reconciler->summaryMessage($report), 'network-reconcile:'.now()->format('Y-m-d'), 6 * 3600, '', OpsAlertSource::NETWORK_RECONCILE);
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Router <-> DB isolation reconciliation (read-only) run {$report['run_id']}");
        $this->table(
            ['Router', 'Status', 'Customers', 'Checked', 'Secrets', 'Online', 'Findings'],
            array_map(static fn (array $r): array => [
                "{$r['name']} (#{$r['router_id']})",
                $r['status'].($r['radius'] ? ' [radius]' : ''),
                $r['customers'],
                $r['customers_checked'],
                $r['secrets'],
                $r['active_sessions'],
                $r['counts'] === [] ? '-' : implode(', ', array_map(
                    static fn (string $k, int $v): string => "{$k}={$v}",
                    array_keys($r['counts']),
                    $r['counts'],
                )),
            ], $report['routers']),
        );

        $limit = max(1, (int) $this->option('limit'));
        $shown = array_slice(array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => $f['severity'] !== NetworkEnforcementReconciler::SEVERITY_INFO,
        )), 0, $limit);
        if ($shown !== []) {
            $this->table(
                ['Router', 'Kind', 'Customer', 'PPPoE', 'DB status', 'Router profile', 'Disabled', 'Online'],
                array_map(static fn (array $f): array => [
                    $f['router_id'],
                    $f['kind'],
                    $f['customer_code'] ?? '-',
                    $f['pppoe_user'] ?? '-',
                    $f['db_status'] ?? '-',
                    $f['router_profile'] ?? '-',
                    $f['router_disabled'] === null ? '-' : ($f['router_disabled'] ? 'yes' : 'no'),
                    $f['online'] === null ? '-' : ($f['online'] ? 'yes' : 'no'),
                ], $shown),
            );
        }

        $t = $report['totals'];
        $this->line(sprintf('drift=%d info=%d error=%d duration_ms=%d', $t['drift'], $t['info'], $t['error'], $report['duration_ms']));
        if (isset($report['stored_rows'])) {
            $this->line("stored_rows={$report['stored_rows']}");
        }

        return self::SUCCESS;
    }
}
