<?php

namespace App\Http\Controllers;

use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Services\PaymentGateway\GatewayStatusChecker;
use App\Services\PaymentGatewayService;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reconciliation of online payments (admin + superadmin only, NOT NOC; admin is area scoped).
 */
class PaymentGatewayTransactionController extends Controller
{
    private const STATUSES = ['created', 'pending', 'paid', 'credited_balance', 'held', 'rejected', 'failed', 'expired'];

    public function index(Request $request): Response
    {
        $this->ensureAdminOrSuperadmin($request);

        // Invalid dates (e.g. 2026-13-45) bounce back with a validation error (Inertia shows it
        // via shared props) instead of being silently ignored.
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = [
            'status' => in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : '',
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 60),
            'date_from' => $this->date($request->query('date_from')),
            'date_to' => $this->date($request->query('date_to')),
        ];

        $query = PaymentGatewayTransaction::query()
            ->with(['invoice:id,code,uuid,status,amount,customer_id', 'invoice.customer:id,name,code', 'transaction:id,reference,paid_at']);
        AreaScope::applyToGatewayTransactions($query, $request->user());

        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('merchant_order_id', 'like', "%{$q}%")
                    ->orWhere('provider_reference', 'like', "%{$q}%")
                    ->orWhere('va_number', 'like', "%{$q}%")
                    ->orWhereHas('invoice', fn ($i) => $i->where('code', 'like', "%{$q}%")
                        ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")));
            });
        }
        // created_at is stored in UTC; the date filters are WIB calendar days.
        if ($filters['date_from']) {
            $query->where('created_at', '>=', OpsTime::rangeBounds($filters['date_from'], $filters['date_from'])[0]);
        }
        if ($filters['date_to']) {
            $query->where('created_at', '<=', OpsTime::rangeBounds($filters['date_to'], $filters['date_to'])[1]);
        }

        $summaryQuery = PaymentGatewayTransaction::query();
        AreaScope::applyToGatewayTransactions($summaryQuery, $request->user());
        $summary = $summaryQuery
            ->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount_invoice), 0) as amount')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => ['count' => (int) $row->total, 'amount' => (int) $row->amount]]);

        $eventsQuery = PaymentGatewayEvent::query()->whereIn('status', [
            PaymentGatewayService::STATUS_HELD,
            PaymentGatewayService::STATUS_UNMATCHED,
            PaymentGatewayService::STATUS_REJECTED_AMOUNT,
            PaymentGatewayService::STATUS_REJECTED_VOID,
            'rejected_amount_mismatch',
        ]);
        AreaScope::applyToPaymentGatewayEvents($eventsQuery, $request->user());
        $attention = $eventsQuery->with('invoice:id,code,uuid')
            ->latest('id')
            ->limit(20)
            ->get()
            ->map(fn (PaymentGatewayEvent $e): array => [
                'id' => $e->id,
                'provider' => $e->provider,
                'reference' => $e->reference,
                'status' => $e->status,
                'amount' => $e->amount !== null ? (int) round((float) $e->amount) : null,
                'invoice_code' => $e->invoice?->code,
                'created_at' => $e->created_at?->toISOString(),
            ]);

        $page = $query->latest('id')->paginate(25)->withQueryString();
        $page->getCollection()->transform(fn (PaymentGatewayTransaction $t): array => [
            'id' => $t->id,
            'provider' => $t->provider,
            'environment' => $t->environment,
            'order_id' => $t->merchant_order_id,
            'provider_reference' => $t->provider_reference,
            'method' => $t->payment_method,
            'method_name' => $t->method_name,
            'method_group' => $t->method_group,
            'amount_invoice' => $t->amount_invoice,
            'fee' => $t->fee,
            'fee_bearer' => $t->fee_bearer,
            'amount_charged' => $t->amount_charged,
            'status' => $t->status,
            'source' => $t->source,
            'expires_at' => $t->expires_at?->toISOString(),
            'paid_at' => $t->paid_at?->toISOString(),
            'created_at' => $t->created_at?->toISOString(),
            'status_check_count' => $t->status_check_count,
            'last_status_check_at' => $t->last_status_check_at?->toISOString(),
            'last_error' => $t->last_error,
            'can_check' => $t->isOpen() && $t->provider !== 'midtrans',
            'invoice' => $t->invoice ? [
                'id' => $t->invoice->id,
                'code' => $t->invoice->code,
                'status' => $t->invoice->status,
                'customer' => $t->invoice->customer?->name,
                'customer_code' => $t->invoice->customer?->code,
            ] : null,
        ]);

        return Inertia::render('PaymentGateway/Transactions', [
            'transactions' => $page,
            'filters' => $filters,
            'summary' => $summary,
            'attention' => $attention,
            'statuses' => self::STATUSES,
        ]);
    }

    /**
     * Manual "Cek status" for one open attempt (rate limited per user).
     */
    public function check(Request $request, PaymentGatewayTransaction $transaction, GatewayStatusChecker $checker): RedirectResponse
    {
        $this->ensureAdminOrSuperadmin($request);

        $scoped = PaymentGatewayTransaction::query()->whereKey($transaction->getKey());
        AreaScope::applyToGatewayTransactions($scoped, $request->user());
        abort_unless($scoped->exists(), 403);

        $outcome = $checker->check($transaction);

        activity()
            ->causedBy($request->user())
            ->performedOn($transaction)
            ->withProperties(['order_id' => $transaction->merchant_order_id, 'outcome' => $outcome])
            ->log('payment_gateway_status_checked');

        $messages = [
            'paid' => 'Provider: sudah dibayar. Invoice diproses.',
            'pending' => 'Provider: belum dibayar.',
            'failed' => 'Provider: gagal/dibatalkan.',
            'expired' => 'Provider: kedaluwarsa.',
            'unknown' => 'Provider tidak memberi status yang jelas. Coba lagi nanti.',
            'skipped' => 'Transaksi ini tidak bisa dicek (sudah final atau provider tidak mendukung).',
        ];

        return back()->with($outcome === 'unknown' || $outcome === 'skipped' ? 'warning' : 'success', $messages[$outcome] ?? $outcome);
    }

    /**
     * Owner decision: online transactions are for admin and superadmin only.
     * NOC passes the `admin` middleware (isAdminLike) but must not see this.
     */
    private function ensureAdminOrSuperadmin(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isAdmin() || $user->isSuperAdmin()), 403);
    }

    private function date(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
