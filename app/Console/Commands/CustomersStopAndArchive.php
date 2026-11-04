<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\GuardsCustomerArchive;
use App\Console\Commands\Concerns\ResolvesCustomerIdentifiers;
use App\Models\Customer;
use App\Models\User;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Services\Lifecycle\ManualLifecycleGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-off operator list action: status -> 'terminated' (Berhenti) AND soft
 * delete (archive) for an explicit list of customers. DRY RUN unless --execute
 * (which needs --actor = admin/superadmin and --reason).
 *
 * Same lifecycle guard as the admin UI archive (canArchive, automated context):
 * deny/unknown is SKIPPED. The only exception is the explicit opt-in
 * --allow-no-router-unknown (owner 2026-10-22): verdict unknown (never deny), no
 * router, no local sign of use; logged with lifecycle_guard_override. Writes only customers.status,
 * customers.deleted_at and the activity log. Invoices, payments, router secrets,
 * OLT, RADIUS, WA and queues are never touched and no job is dispatched.
 * Output holds ids/codes only (no names, phones, addresses).
 */
class CustomersStopAndArchive extends Command
{
    use GuardsCustomerArchive;
    use ResolvesCustomerIdentifiers;

    public const SOURCE = 'cli_stop_and_archive';

    protected $signature = 'customers:stop-and-archive
        {--codes= : Comma separated customer codes}
        {--pppoe= : Comma separated PPPoE usernames (customers without a code)}
        {--execute : Really stop and archive the allowed customers (default: dry run)}
        {--actor= : User id or email recorded in the activity log (required with --execute)}
        {--reason= : Short reason stored in the activity log (required with --execute)}
        {--allow-no-router-unknown : Opt-in override: also archive customers with guard verdict unknown that have NO router and no local sign of use (logged like the UI override)}';

    protected $description = 'Set listed customers to Berhenti (terminated) and archive them when the lifecycle guard allows (dry run by default)';

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $actor = null;
        $reason = trim((string) $this->option('reason'));
        if ($execute) {
            $actor = $this->resolveActor((string) $this->option('actor'));
            if (! $actor) {
                $this->error('--execute requires --actor=<user id or email> of an admin/superadmin.');

                return self::INVALID;
            }
            if ($reason === '') {
                $this->error('--execute requires --reason=<short text>.');

                return self::INVALID;
            }
        }

        $allowOverride = (bool) $this->option('allow-no-router-unknown');
        $codes = $this->parseList($this->option('codes'));
        $pppoe = $this->parseList($this->option('pppoe'));
        if ($codes === [] && $pppoe === []) {
            $this->error('Give --codes= and/or --pppoe=.');

            return self::INVALID;
        }

        $counts = ['allow' => 0, 'deny' => 0, 'unknown' => 0, 'not_found' => 0, 'ambiguous' => 0, 'already_archived' => 0, 'duplicate' => 0, 'override' => 0];
        $archived = 0;
        $skipped = 0;
        $failed = 0;
        $receivable = 0.0;

        foreach ($this->resolveIdentifiers($codes, $pppoe) as $row) {
            if ($row['customer'] === null) {
                $counts[$row['state']]++;
                $skipped++;
                $this->line(sprintf('%s decision=%s result=skipped', $row['label'], $row['state']));

                continue;
            }

            $customer = $row['customer'];
            $oldStatus = (string) $customer->status;
            try {
                $decision = $this->archiveDecision($customer, self::SOURCE);
                $counts[$decision->verdict]++;

                $outcome = 'dry-run';
                $overrideNote = '';
                $canOverride = false;
                if (! $decision->allowed() && $allowOverride) {
                    $why = $this->overrideVerdict($customer, $decision);
                    $canOverride = $why === null;
                    $overrideNote = ' override='.($canOverride ? 'would_apply' : 'not_applicable('.$why.')');
                    $counts['override'] += $canOverride ? 1 : 0;
                }
                if ($decision->allowed() || $canOverride) {
                    $receivable += $this->unpaidAmount($customer);
                    if ($execute) {
                        $outcome = $this->stopAndArchive($customer, $actor, $reason, $allowOverride) ? 'archived' : 'skipped';
                    }
                } elseif ($execute) {
                    $outcome = 'skipped';
                }
                if ($outcome === 'archived') {
                    $archived++;
                } elseif ($outcome === 'skipped') {
                    $skipped++;
                }

                $this->line(sprintf(
                    'customer=%d code=%s status=%s decision=%s reasons=%s%s result=%s',
                    $customer->id,
                    $customer->code,
                    $oldStatus,
                    $decision->verdict,
                    implode(',', $decision->reasonCodes()),
                    $overrideNote,
                    $outcome,
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->line(sprintf('customer=%d code=%s status=%s result=failed error=%s', $customer->id, $customer->code, $oldStatus, $e::class));
            }
        }

        $this->info(sprintf(
            'stop-and-archive (%s): allow=%d deny=%d unknown=%d not_found=%d ambiguous=%d already_archived=%d duplicate=%d override_applicable=%d',
            $execute ? 'EXECUTE' : 'dry run, nothing written',
            $counts[GuardDecision::ALLOW],
            $counts[GuardDecision::DENY],
            $counts[GuardDecision::UNKNOWN],
            $counts['not_found'],
            $counts['ambiguous'],
            $counts['already_archived'],
            $counts['duplicate'],
            $counts['override'],
        ));
        $this->line(sprintf('unpaid invoices of allowed customers stay as receivable (informational): Rp %s', number_format($receivable, 0, ',', '.')));
        if ($execute) {
            $this->info(sprintf('archived=%d skipped=%d failed=%d', $archived, $skipped, $failed));
        }

        return self::SUCCESS;
    }

    private function unpaidAmount(Customer $customer): float
    {
        return (float) $customer->invoices()->whereIn('status', ['unpaid', 'overdue'])->sum('amount');
    }

    /**
     * Opt-in override limits (owner 2026-10-22): verdict must be `unknown` (never deny),
     * the customer has NO router, and local data shows it is not in use
     * (is_online with a fresh check, fresh online olt_onus row: same notion as LifecycleBackstop).
     *
     * @return string|null null = override applies, else why not
     */
    private function overrideVerdict(Customer $customer, GuardDecision $decision): ?string
    {
        if ($decision->verdict !== GuardDecision::UNKNOWN) {
            return 'verdict_'.$decision->verdict;
        }
        if ($customer->router_id) {
            return 'router_present';
        }
        // An evaluation error is not "no evidence": never override on top of it.
        foreach ($decision->signals as $signal) {
            if ($signal->reason === 'guard_failed' || ($signal->evidence['why'] ?? null) === 'exception' || isset($signal->evidence['exception'])) {
                return 'guard_failed';
            }
        }
        try {
            // Strict: any error while reading the evidence means no override (fail closed).
            $inUse = LifecycleBackstop::localUseReasons($customer, true);
        } catch (Throwable) {
            return 'check_failed';
        }
        if ($inUse !== []) {
            return implode(',', $inUse);
        }

        return null;
    }

    private function stopAndArchive(Customer $candidate, User $actor, string $reason, bool $allowOverride): bool
    {
        return (bool) DB::transaction(function () use ($candidate, $actor, $reason, $allowOverride): bool {
            $customer = Customer::query()->whereKey($candidate->id)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $customer) {
                return false;
            }
            // Re-check under lock: the verdict above may be stale.
            $decision = $this->archiveDecision($customer, self::SOURCE);
            $overridden = false;
            if (! $decision->allowed()) {
                if (! $allowOverride || $this->overrideVerdict($customer, $decision) !== null) {
                    return false;
                }
                // Owner decision 2026-10-22: logged like the UI superadmin override, same transaction.
                app(ManualLifecycleGate::class)->logOverride(ManualLifecycleGate::ACTION_ARCHIVE, $customer, $actor, self::SOURCE, $decision, $reason);
                $overridden = true;
            }
            $previous = (string) $customer->status;

            // A10: guard allowed above (or the logged override).
            LifecycleBackstop::allow([(int) $customer->getKey()], self::SOURCE, function () use ($customer, $actor, $reason, $previous, $overridden): void {
                if ($previous !== 'terminated') {
                    $customer->status = 'terminated';
                    $customer->save();
                }

                activity()
                    ->causedBy($actor)
                    ->performedOn($customer)
                    ->event('customer_stopped_and_archived')
                    ->withProperties([
                        'source' => self::SOURCE,
                        'reason' => $reason,
                        'previous_status' => $previous,
                        'pppoe_user' => $customer->pppoe_user,
                        'guard_override' => $overridden,
                    ])
                    ->log('customer_stopped_and_archived');

                $customer->delete(); // SoftDeletes
            });

            return true;
        });
    }
}
