<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use Illuminate\Console\Command;

class ReconcileMikrotikMissingStatus extends Command
{
    protected $signature = 'customers:reconcile-mikrotik-missing
                            {--apply : Apply updates; default is dry-run}
                            {--fix-pppoe-typos : Correct high-confidence PPPoE username typos from staged MikroTik secrets}
                            {--max-age-days=45 : Ignore staged secrets older than this many days}
                            {--limit=1000 : Max missing customers to inspect}';

    protected $description = 'Reconcile stale mikrotik_sync_status=missing from latest router staged secrets';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $fixPppoeTypos = (bool) $this->option('fix-pppoe-typos');
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays($maxAgeDays);
        $routers = Router::query()->get()->keyBy('id');
        $stats = ['checked' => 0, 'synced' => 0, 'moved' => 0, 'pppoe_fixed' => 0, 'skipped' => 0];

        Customer::query()
            ->where('mikrotik_sync_status', 'missing')
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '!=', '')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'pppoe_user', 'router_id'])
            ->each(function (Customer $customer) use ($apply, $fixPppoeTypos, $cutoff, $routers, &$stats) {
                $stats['checked']++;

                $candidate = RouterStagedCustomer::query()
                    ->where('pppoe_user', $customer->pppoe_user)
                    ->whereIn('status', ['matched', 'unmatched'])
                    ->where('last_seen_at', '>=', $cutoff)
                    ->get()
                    ->sortByDesc(fn (RouterStagedCustomer $row) => $this->candidateScore($row, $customer, $routers))
                    ->first();
                $pppoeFixed = false;

                if (! $candidate && $fixPppoeTypos) {
                    $candidate = $this->typoCandidate($customer, $cutoff, $routers);
                    $pppoeFixed = $candidate !== null;
                }

                if (! $candidate) {
                    $stats['skipped']++;

                    return;
                }

                $targetRouterId = (int) $candidate->router_id;
                $moved = (int) ($customer->router_id ?? 0) !== $targetRouterId;
                $routerName = $routers->get($targetRouterId)?->name ?? ('#'.$targetRouterId);

                $this->line(sprintf(
                    '%s #%d %s user=%s %s%s',
                    $apply ? 'SYNC' : 'DRY',
                    $customer->id,
                    $customer->name,
                    $pppoeFixed ? $customer->pppoe_user.' => '.$candidate->pppoe_user : $customer->pppoe_user,
                    $moved ? 'move_to='.$routerName.' ' : '',
                    ($pppoeFixed ? 'pppoe_fixed ' : '').'status=synced'
                ));

                if ($apply) {
                    $customer->forceFill([
                        'pppoe_user' => $pppoeFixed ? $candidate->pppoe_user : $customer->pppoe_user,
                        'router_id' => $targetRouterId,
                        'mikrotik_profile' => $candidate->profile,
                        'mikrotik_sync_status' => 'synced',
                        'mikrotik_synced_at' => now(),
                        'mikrotik_sync_checked_at' => now(),
                    ])->save();
                }

                $stats['synced']++;
                if ($moved) {
                    $stats['moved']++;
                }
                if ($pppoeFixed) {
                    $stats['pppoe_fixed']++;
                }
            });

        $this->info(json_encode($stats, JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function candidateScore(RouterStagedCustomer $row, Customer $customer, $routers): int
    {
        $router = $routers->get((int) $row->router_id);
        $score = 0;
        if ((int) ($customer->router_id ?? 0) === (int) $row->router_id) {
            $score += 200;
        }
        if ((int) ($row->matched_customer_id ?? 0) === (int) $customer->id) {
            $score += 80;
        }
        if ($row->status === 'matched') {
            $score += 50;
        }
        if ($router?->is_active) {
            $score += 25;
        }
        if (($router?->sync_status ?? '') === 'success') {
            $score += 25;
        }
        if (($router?->sync_status ?? '') === 'failed') {
            $score -= 50;
        }
        $score += min(50, (int) floor(((int) ($router?->current_online_count ?? 0)) / 20));
        if ($row->last_seen_at) {
            $score += max(0, 30 - min(30, $row->last_seen_at->diffInDays(now())));
        }

        return $score;
    }

    protected function typoCandidate(Customer $customer, $cutoff, $routers): ?RouterStagedCustomer
    {
        $customerUser = trim((string) $customer->pppoe_user);
        if ($customerUser === '') {
            return null;
        }

        $prefix = $this->pppoeBasePrefix($customerUser);
        if ($prefix === '') {
            return null;
        }

        $rows = RouterStagedCustomer::query()
            ->where('pppoe_user', 'like', $prefix.'%')
            ->whereIn('status', ['matched', 'unmatched'])
            ->where('last_seen_at', '>=', $cutoff)
            ->get()
            ->filter(fn (RouterStagedCustomer $row) => $this->isHighConfidenceTypo($customer, $row));

        if ($rows->count() !== 1) {
            return null;
        }

        $candidate = $rows->first();
        if (Customer::query()->where('pppoe_user', $candidate->pppoe_user)->whereKeyNot($customer->id)->exists()) {
            return null;
        }

        return $candidate;
    }

    protected function isHighConfidenceTypo(Customer $customer, RouterStagedCustomer $row): bool
    {
        $old = strtoupper(trim((string) $customer->pppoe_user));
        $new = strtoupper(trim((string) $row->pppoe_user));
        if ($old === '' || $new === '' || $old === $new) {
            return false;
        }
        if ((int) ($customer->router_id ?? 0) !== 0 && (int) $customer->router_id !== (int) $row->router_id) {
            return false;
        }

        $oldParts = $this->rtRwParts($old);
        $newParts = $this->rtRwParts($new);
        if (! $oldParts || ! $newParts) {
            return false;
        }

        [$oldPrefix, $oldRt, $oldRw, $oldSuffix] = $oldParts;
        [$newPrefix, $newRt, $newRw, $newSuffix] = $newParts;

        if ($oldPrefix !== $newPrefix || $oldSuffix !== $newSuffix) {
            return false;
        }

        $diffs = 0;
        if ($oldRt !== $newRt) {
            $diffs++;
        }
        if ($oldRw !== $newRw) {
            $diffs++;
        }

        return $diffs === 1 && levenshtein($old, $new) <= 2;
    }

    protected function pppoeBasePrefix(string $pppoeUser): string
    {
        if (preg_match('/^(.+?)_RT\d+/i', $pppoeUser, $matches)) {
            return strtoupper($matches[1]);
        }

        return strtoupper(strtok($pppoeUser, '_') ?: '');
    }

    /**
     * @return array{string,string,string,string}|null
     */
    protected function rtRwParts(string $pppoeUser): ?array
    {
        if (! preg_match('/^(.+?)_RT0*(\d+)_RW0*(\d+)(.*)$/i', $pppoeUser, $matches)) {
            return null;
        }

        return [
            strtoupper($matches[1]),
            (string) ((int) $matches[2]),
            (string) ((int) $matches[3]),
            strtoupper($matches[4] ?? ''),
        ];
    }
}
