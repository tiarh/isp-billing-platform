<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\LifecycleBackstop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off operator cleanup: status 'inactive' -> 'terminated' for customers the
 * lifecycle guard proves unused. DRY RUN unless --execute (which needs --actor).
 * --actor must be a superadmin or admin. Only changes customers.status and
 * writes TWO activity_log rows per terminated customer (the model's own
 * `updated` status diff + `terminated_inactive_cleanup`): no soft delete, no
 * invoice / router / OLT change, nothing queued. The guard cannot be overridden here.
 * Output holds ids/codes only (no names, phones).
 */
class CustomersTerminateInactive extends Command
{
    public const SOURCE = 'cli_terminate_inactive';

    protected $signature = 'customers:terminate-inactive
        {--execute : Really terminate the allowed customers (default: dry run)}
        {--actor= : User id or email recorded in the activity log (required with --execute)}
        {--ids= : Comma separated customer ids to consider}
        {--max-id= : Only customers with id <= this}';

    protected $description = 'Terminate status=inactive customers that the lifecycle guard allows (dry run by default)';

    public function handle(CustomerLifecycleGuard $guard): int
    {
        $execute = (bool) $this->option('execute');
        $actor = null;
        if ($execute) {
            $raw = trim((string) $this->option('actor'));
            if ($raw === '') {
                $this->error('--execute requires --actor=<user id or email>.');

                return self::INVALID;
            }
            $actor = ctype_digit($raw) ? User::find((int) $raw) : User::where('email', $raw)->first();
            if (! $actor || ! ($actor->isSuperAdmin() || $actor->isAdmin())) {
                $this->error('Actor not found or not an admin/superadmin.');

                return self::INVALID;
            }
        }

        $query = Customer::query()->where('status', 'inactive')->whereNull('deleted_at')->with(['router', 'olt'])->orderBy('id');
        if ($this->option('ids') !== null) {
            $tokens = array_map('trim', explode(',', (string) $this->option('ids')));
            foreach ($tokens as $token) {
                if (! ctype_digit($token) || (int) $token < 1) {
                    $this->error('--ids must be a comma separated list of positive integers.');

                    return self::INVALID;
                }
            }
            $query->whereIn('id', array_map('intval', $tokens));
        }
        if ($this->option('max-id') !== null) {
            $maxId = trim((string) $this->option('max-id'));
            if (! ctype_digit($maxId) || (int) $maxId < 1) {
                $this->error('--max-id must be a positive integer.');

                return self::INVALID;
            }
            $query->where('id', '<=', (int) $maxId);
        }

        $context = GuardContext::automated(self::SOURCE);
        $verdicts = [GuardDecision::ALLOW => 0, GuardDecision::DENY => 0, GuardDecision::UNKNOWN => 0];
        $reasons = [];
        $noEvidence = 0;
        $terminated = 0;
        $skipped = 0;

        foreach ($query->get() as $customer) {
            $decision = $guard->canTerminate($customer, $context);
            $verdicts[$decision->verdict]++;
            $noEvidence += $decision->allowedWithNoEvidence() ? 1 : 0;
            foreach ($decision->reasonCodes() as $code) {
                $key = $decision->verdict.' '.$code;
                $reasons[$key] = ($reasons[$key] ?? 0) + 1;
            }

            $outcome = $execute ? 'skipped' : 'dry-run';
            if ($execute && $decision->allowed()) {
                $outcome = $this->terminate($guard, $customer, $context, $actor) ? 'terminated' : 'skipped';
            }
            if ($outcome === 'terminated') {
                $terminated++;
            } elseif ($execute) {
                $skipped++;
            }

            $this->line(sprintf(
                'customer=%d code=%s decision=%s reasons=%s result=%s',
                $customer->id,
                $customer->code,
                $decision->verdict,
                implode(',', $decision->reasonCodes()),
                $outcome,
            ));
        }

        $this->info(sprintf(
            'terminate-inactive (%s): allow=%d deny=%d unknown=%d',
            $execute ? 'EXECUTE' : 'dry run, nothing written',
            $verdicts[GuardDecision::ALLOW],
            $verdicts[GuardDecision::DENY],
            $verdicts[GuardDecision::UNKNOWN],
        ));
        $this->line(sprintf('allowed with no evidence (a signal was not applicable): %d', $noEvidence));
        ksort($reasons);
        foreach ($reasons as $key => $count) {
            $this->line(sprintf('  %-48s %d', $key, $count));
        }
        if ($execute) {
            $this->info(sprintf('terminated=%d skipped=%d', $terminated, $skipped));
        }

        return self::SUCCESS;
    }

    private function terminate(CustomerLifecycleGuard $guard, Customer $candidate, GuardContext $context, User $actor): bool
    {
        return (bool) DB::transaction(function () use ($guard, $candidate, $context, $actor): bool {
            $customer = Customer::query()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $customer || $customer->status !== 'inactive') {
                return false;
            }
            $decision = $guard->canTerminate($customer, $context);
            if (! $decision->allowed()) {
                return false;
            }

            // A10: guard allowed above.
            LifecycleBackstop::allow([(int) $customer->getKey()], 'customers_terminate_inactive', function () use ($customer): void {
                $customer->status = 'terminated';
                $customer->save();
            });

            activity()
                ->causedBy($actor)
                ->performedOn($customer)
                ->event('terminated_inactive_cleanup')
                ->withProperties([
                    'previous_status' => 'inactive',
                    'guard' => $decision->toArray(),
                    'actor' => ['id' => $actor->id],
                ])
                ->log('terminated_inactive_cleanup');

            return true;
        });
    }
}
