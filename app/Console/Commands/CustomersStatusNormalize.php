<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceVoidService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\LifecycleBackstop;
use App\Support\AnalyticsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Roadmap 2.2 D1 / D1b one-off: archived (soft-deleted) customers whose status
 * is not 'terminated' -> 'terminated' (deleted_at kept). DRY RUN unless
 * --execute (which needs --actor, an admin/superadmin). Status write only:
 * no router / OLT / RADIUS call, no invoice change, nothing queued, no WA.
 *
 * --void-dup-invoices (separate mode, D1b): voids unpaid, payment-less invoices
 * of archived "DUP-" customers (2026-07-28 duplicate merge) with the same logic
 * as InvoiceController::void. In this mode the status step is NOT run.
 *
 * Output holds ids/codes/amounts only (no names, phones).
 */
class CustomersStatusNormalize extends Command
{
    public const SOURCE = 'status_normalize_2_2';

    public const VOID_REASON = 'akun duplikat DUP (2.2)';

    protected $signature = 'customers:status-normalize
        {--execute : Really write (default: dry run)}
        {--actor= : User id or email recorded in the activity log (required with --execute)}
        {--limit=25 : Max rows handled per run}
        {--ids= : Comma separated customer ids to consider}
        {--void-dup-invoices : Void unpaid invoices of archived DUP- customers instead of normalizing status}';

    protected $description = 'Set archived non-terminated customers to terminated (2.2 D1); optional DUP invoice void (D1b). Dry run by default';

    public function handle(CustomerLifecycleGuard $guard, InvoiceVoidService $voider): int
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

        $limitRaw = trim((string) $this->option('limit'));
        if (! ctype_digit($limitRaw) || (int) $limitRaw < 1) {
            $this->error('--limit must be a positive integer.');

            return self::INVALID;
        }
        $limit = (int) $limitRaw;

        $ids = null;
        if ($this->option('ids') !== null) {
            $ids = [];
            foreach (explode(',', (string) $this->option('ids')) as $token) {
                $token = trim($token);
                if (! ctype_digit($token) || (int) $token < 1) {
                    $this->error('--ids must be a comma separated list of positive integers.');

                    return self::INVALID;
                }
                $ids[] = (int) $token;
            }
        }

        if ($this->option('void-dup-invoices')) {
            return $this->voidDupInvoices($voider, $execute, $actor, $limit, $ids);
        }

        $query = Customer::withTrashed()
            ->whereNotNull('deleted_at')
            ->where('status', '!=', 'terminated')
            ->with(['router', 'olt'])
            ->orderBy('id');
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        $context = GuardContext::automated(self::SOURCE);
        $rows = $query->limit($limit)->get();
        $changed = 0;
        $skipped = 0;
        $unknown = 0;

        foreach ($rows as $customer) {
            $decision = $guard->canTerminate($customer, $context);
            $unknown += $decision->verdict === GuardDecision::UNKNOWN ? 1 : 0;
            $outcome = 'dry-run';
            if ($execute) {
                $ok = $decision->allowed() && $this->normalize($guard, $customer, $context, $actor);
                $outcome = $ok ? 'terminated' : 'skipped';
                $ok ? $changed++ : $skipped++;
            } elseif (! $decision->allowed()) {
                $outcome = 'dry-run (would skip)';
            }

            $this->line(sprintf(
                'customer=%d code=%s status=%s deleted_at=%s decision=%s reasons=%s result=%s',
                $customer->id,
                $customer->code ?? '-',
                $customer->status,
                $customer->deleted_at?->toDateTimeString() ?? '-',
                $decision->verdict,
                implode(',', $decision->reasonCodes()),
                $outcome,
            ));
        }

        $this->info(sprintf(
            'status-normalize (%s): rows=%d%s',
            $execute ? 'EXECUTE' : 'dry run, nothing written',
            $rows->count(),
            $execute ? sprintf(' terminated=%d skipped=%d', $changed, $skipped) : '',
        ));
        if ($unknown > 0) {
            $this->warn('Dilewati: data trafik/ONU tidak ada, belum bisa dipastikan pelanggan sudah tidak memakai.');
        }

        return self::SUCCESS;
    }

    private function normalize(CustomerLifecycleGuard $guard, Customer $candidate, GuardContext $context, User $actor): bool
    {
        return (bool) DB::transaction(function () use ($guard, $candidate, $context, $actor): bool {
            $customer = Customer::withTrashed()->whereKey($candidate->id)->lockForUpdate()->first();
            if (! $customer || $customer->deleted_at === null || $customer->status === 'terminated') {
                return false;
            }
            if (! $guard->canTerminate($customer, $context)->allowed()) {
                return false;
            }

            $from = (string) $customer->status;
            // A10: guard allowed above.
            LifecycleBackstop::allow([(int) $customer->getKey()], self::SOURCE, function () use ($customer): void {
                $customer->status = 'terminated';
                $customer->save();
            });

            activity()
                ->causedBy($actor)
                ->performedOn($customer)
                ->withProperties([
                    'from' => $from,
                    'to' => 'terminated',
                    'reason' => 'archived row with non-terminated status (2.2 D1)',
                ])
                ->log(self::SOURCE);

            return true;
        });
    }

    /** @param  list<int>|null  $ids */
    private function voidDupInvoices(InvoiceVoidService $voider, bool $execute, ?User $actor, int $limit, ?array $ids): int
    {
        if ($execute && $ids === null) {
            $this->error('--void-dup-invoices --execute requires --ids=<customer ids> (take them from the dry run).');

            return self::INVALID;
        }

        $query = Invoice::query()
            ->with(['customer' => fn ($q) => $q->withTrashed()])
            ->where('status', 'unpaid')
            ->whereDoesntHave('transactions')
            ->whereIn('customer_id', Customer::withTrashed()
                ->select('id')
                ->whereNotNull('deleted_at')
                ->where('code', 'like', 'DUP-%'))
            ->orderBy('id');
        if ($ids !== null) {
            $query->whereIn('customer_id', $ids);
        }

        $invoices = $query->limit($limit)->get();
        $voided = 0;
        $skipped = 0;
        $sum = 0.0;

        foreach ($invoices as $invoice) {
            $outcome = 'dry-run';
            if ($execute) {
                $error = $voider->void((int) $invoice->id);
                if ($error === null) {
                    activity()
                        ->causedBy($actor)
                        ->performedOn($invoice)
                        ->withProperties(['reason' => self::VOID_REASON])
                        ->log('Invoice voided');
                    $voided++;
                    $sum += (float) $invoice->amount;
                    $outcome = 'voided';
                } else {
                    $skipped++;
                    $outcome = 'skipped';
                }
            }

            $this->line(sprintf(
                'invoice=%d customer=%d code=%s period=%s amount=%s result=%s',
                $invoice->id,
                $invoice->customer_id,
                $invoice->customer?->code ?? '-',
                $invoice->period?->toDateString() ?? '-',
                number_format((float) $invoice->amount, 0, '.', ''),
                $outcome,
            ));
        }

        if ($voided > 0) {
            AnalyticsCache::flushForUser($actor);
        }

        $this->info(sprintf(
            'void-dup-invoices (%s): invoices=%d total=%s%s',
            $execute ? 'EXECUTE' : 'dry run, nothing written',
            $invoices->count(),
            number_format((float) $invoices->sum('amount'), 0, '.', ''),
            $execute ? sprintf(' voided=%d skipped=%d', $voided, $skipped) : '',
        ));

        return self::SUCCESS;
    }
}
