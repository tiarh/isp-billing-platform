<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use Illuminate\Console\Command;

/**
 * READ-ONLY report of what CustomerLifecycleGuard would decide per customer.
 * Never writes anything (no model save, no activity log, no cache write beyond
 * the Grafana read cache the guard already uses). Output holds ids/codes only:
 * no names, phones, addresses or PPPoE usernames.
 */
class CustomersLifecycleGuardReport extends Command
{
    protected $signature = 'customers:lifecycle-guard-report
        {--status=isolated,inactive,terminated : Comma separated customer statuses}
        {--archived : Report soft-deleted (archived) customers instead (status filter only when --status is given explicitly)}
        {--limit=0 : Max customers to check (0 = all)}
        {--lines : Also print one line per customer (id, code, verdict, reason codes)}';

    protected $description = 'Read-only: how many customers the lifecycle guard would allow / deny / treat as unknown, by reason';

    public function handle(CustomerLifecycleGuard $guard): int
    {
        $archived = (bool) $this->option('archived');
        $limit = max(0, (int) $this->option('limit'));
        $statuses = array_values(array_filter(array_map(
            static fn (string $s): string => strtolower(trim($s)),
            explode(',', (string) $this->option('status')),
        )));

        $query = $archived ? Customer::onlyTrashed() : Customer::query();
        if (! $archived || $this->input->hasParameterOption('--status')) {
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }
        $query->with(['router', 'olt'])->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $context = GuardContext::automated('lifecycle-guard-report');
        $verdicts = [GuardDecision::ALLOW => 0, GuardDecision::DENY => 0, GuardDecision::UNKNOWN => 0];
        $reasons = [];
        $total = 0;
        $noEvidence = 0;

        foreach ($query->get() as $customer) {
            $decision = $archived ? $guard->canArchive($customer, $context) : $guard->canTerminate($customer, $context);
            $total++;
            $verdicts[$decision->verdict]++;
            $noEvidence += $decision->allowedWithNoEvidence() ? 1 : 0;
            foreach ($decision->reasonCodes() as $code) {
                $reasons[$decision->verdict.' '.$code] = ($reasons[$decision->verdict.' '.$code] ?? 0) + 1;
            }
            if ($this->option('lines')) {
                $this->line(sprintf('customer=%d code=%s verdict=%s reasons=%s', $customer->id, $customer->code, $decision->verdict, implode(',', $decision->reasonCodes())));
            }
        }

        $this->info(sprintf(
            'Lifecycle guard report (%s, read-only): total=%d allow=%d deny=%d unknown=%d',
            $archived ? 'archived' : 'status='.implode(',', $statuses),
            $total,
            $verdicts[GuardDecision::ALLOW],
            $verdicts[GuardDecision::DENY],
            $verdicts[GuardDecision::UNKNOWN],
        ));
        $this->line(sprintf('allowed with no evidence (a signal was not applicable): %d', $noEvidence));
        if ($total > 0) {
            $this->line(sprintf('unknown rate: %.1f%%', $verdicts[GuardDecision::UNKNOWN] / $total * 100));
        }
        ksort($reasons);
        foreach ($reasons as $key => $count) {
            $this->line(sprintf('  %-48s %d', $key, $count));
        }

        return self::SUCCESS;
    }
}
