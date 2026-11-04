<?php

namespace App\Http\Controllers;

use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Services\CustomerCreditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Kelebihan bayar": customer credits from double / excess online payments.
 * Money screen: superadmin and admin WITHOUT area scope only (NOC and
 * area-scoped admins get 403, same boundary as the sidebar `global_admin_only`).
 */
class CustomerCreditController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeCredits($request);

        $filters = [
            'status' => in_array($request->query('status'), CustomerCredit::STATUSES, true) ? (string) $request->query('status') : '',
            'q' => mb_substr(trim((string) $request->query('q', '')), 0, 60),
        ];

        $query = CustomerCredit::query()
            ->with(['customer' => fn ($q) => $q->withTrashed()->select('id', 'name', 'code', 'deleted_at'), 'sourceInvoice:id,code,period', 'appliedInvoice:id,code', 'actor:id,name']);
        if ($filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }
        if ($filters['q'] !== '') {
            $q = $filters['q'];
            $query->where(function ($w) use ($q) {
                $w->where('gateway_reference', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn ($c) => $c->withTrashed()->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%"))
                    ->orWhereHas('sourceInvoice', fn ($i) => $i->where('code', 'like', "%{$q}%"));
            });
        }

        $summary = CustomerCredit::query()
            ->selectRaw('status, COUNT(*) as total, COALESCE(SUM(amount), 0) as amount')
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->status => ['count' => (int) $row->total, 'amount' => (float) $row->amount]]);

        $page = $query->latest('id')->paginate(25)->withQueryString();

        // One query for every unpaid invoice of the customers on this page (apply targets).
        $customerIds = $page->getCollection()->pluck('customer_id')->unique()->values();
        $openInvoices = Invoice::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', ['unpaid', 'overdue'])
            ->with('transactions', 'reversals')
            ->orderBy('period')
            ->get()
            ->filter(fn (Invoice $i) => ! $i->isSettled())
            ->groupBy('customer_id');

        $page->getCollection()->transform(function (CustomerCredit $c) use ($openInvoices): array {
            $targets = $c->status === CustomerCredit::STATUS_AVAILABLE
                ? ($openInvoices[$c->customer_id] ?? collect())->map(fn (Invoice $i): array => [
                    'id' => $i->id,
                    'code' => $i->code,
                    'period' => $i->period?->format('Y-m'),
                    'remaining' => round((float) $i->amount - $i->totalPaid(), 2),
                ])->values()
                : collect();

            return [
                'id' => $c->id,
                'status' => $c->status,
                'reason_code' => $c->reason_code,
                'amount' => (float) $c->amount,
                'provider' => $c->provider,
                'gateway_reference' => $c->gateway_reference,
                'reason' => $c->reason,
                'created_at' => $c->created_at?->toISOString(),
                'resolved_at' => $c->resolved_at?->toISOString(),
                'applied_at' => $c->applied_at?->toISOString(),
                'customer' => $c->customer ? ['id' => $c->customer->id, 'name' => $c->customer->name, 'code' => $c->customer->code, 'archived' => $c->customer->trashed()] : null,
                'source_invoice' => $c->sourceInvoice ? ['id' => $c->sourceInvoice->id, 'code' => $c->sourceInvoice->code] : null,
                'applied_invoice' => $c->appliedInvoice ? ['id' => $c->appliedInvoice->id, 'code' => $c->appliedInvoice->code] : null,
                'actor' => $c->actor?->name,
                'open_invoices' => $targets,
            ];
        });

        return Inertia::render('CustomerCredits/Index', [
            'credits' => $page,
            'filters' => $filters,
            'summary' => $summary,
            'statuses' => CustomerCredit::STATUSES,
        ]);
    }

    public function apply(Request $request, CustomerCredit $credit, CustomerCreditService $service): RedirectResponse
    {
        $this->authorizeCredits($request);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'expected_amount' => ['required', 'numeric'],
        ]);

        $result = $service->apply(
            $credit->id,
            (int) $data['invoice_id'],
            isset($data['amount']) ? (float) $data['amount'] : null,
            (float) $data['expected_amount'],
            $request->user(),
        );

        return back()->with('success', $result['settled']
            ? 'Saldo diterapkan dan tagihan lunas.'
            : 'Saldo diterapkan sebagian ke tagihan.');
    }

    public function resolve(Request $request, CustomerCredit $credit, CustomerCreditService $service): RedirectResponse
    {
        $this->authorizeCredits($request);

        $data = $request->validate([
            'action' => ['required', Rule::in(['make_balance', 'refunded_manual', 'dismissed'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $service->resolve($credit->id, $data['action'], $data['reason'] ?? null, $request->user());

        return back()->with('success', match ($data['action']) {
            'make_balance' => 'Dijadikan saldo pelanggan.',
            'refunded_manual' => 'Ditandai sudah direfund manual.',
            default => 'Diabaikan.',
        });
    }

    private function authorizeCredits(Request $request): void
    {
        $user = $request->user();
        abort_unless($user && ($user->isSuperAdmin() || ($user->isAdmin() && ! $user->hasAreaScope())), 403);
    }
}
