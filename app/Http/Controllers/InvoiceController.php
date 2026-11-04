<?php

namespace App\Http\Controllers;

use App\Http\Requests\InvoiceStoreRequest;
use App\Http\Requests\InvoiceUpdateRequest;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentReversal;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\BillingReceiptService;
use App\Services\InvoiceVoidService;
use App\Support\AnalyticsCache;
use App\Support\AreaScope;
use App\Support\CompanyContact;
use App\Support\OpsTime;
use App\Support\SimpleXlsxWriter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;

class InvoiceController extends Controller
{
    /**
     * Display a listing of all invoices
     */
    public function index(Request $request)
    {
        $query = $this->invoiceIndexQuery($request, [
            'customer' => function ($q) {
                $q->select('id', 'name', 'code', 'area_id', 'package_id')->withTrashed()
                    ->with(['package:id,name,price,rate_limit', 'area:id,name']);
            },
        ]);

        $limit = min(max((int) $request->input('limit', 20), 1), 100);
        $invoices = $query
            ->withSum([
                'transactions as paid_amount' => fn ($transactions) => $transactions
                    ->whereIn('status', ['verified', 'paid']),
            ], 'amount')
            ->withSum('reversals as reversed_amount', 'amount')
            ->withCount('transactions')
            ->paginate($limit)
            ->withQueryString();

        $invoices->getCollection()->transform(
            fn (Invoice $invoice) => $this->enrichInvoiceForView($invoice)
        );

        $areasQuery = Area::query()->select('id', 'name', 'code')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, $request->user());

        $periodsQuery = Invoice::query()->select('period')->distinct()->orderByDesc('period');
        AreaScope::applyToInvoices($periodsQuery, $request->user());

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'search' => $request->search,
                'status' => $request->status ?? 'all',
                'limit' => $limit,
                'area_id' => $request->area_id,
                'period' => $request->period,
                'customer_id' => $request->filled('customer_id') ? (int) $request->input('customer_id') : null,
            ],
            'areas' => $areasQuery->get(),
            'periods' => $periodsQuery
                ->pluck('period')
                ->map(fn ($period) => Carbon::parse($period)->startOfMonth()->toDateString())
                ->unique()
                ->values(),
        ]);
    }

    public function export(Request $request)
    {
        $query = $this->invoiceIndexQuery($request, [
            'customer' => function ($q) {
                $q->withTrashed()->with(['package:id,name,rate_limit']);
            },
            'transactions.admin',
            'transactions.reversals',
            'reversals',
        ]);

        $rowNumber = 1;
        $rows = $query->lazy(1000)->map(function (Invoice $invoice) use (&$rowNumber) {
            $paidTransactions = $invoice->transactions
                ->whereIn('status', ['verified', 'paid'])
                ->filter(fn (Transaction $transaction): bool => $transaction->reversals->isEmpty());
            $latestPayment = $paidTransactions->sortByDesc('paid_at')->first();
            $paidAmount = max(0, $paidTransactions->sum('amount'));
            if ((float) $paidAmount <= 0 && $invoice->status === 'paid' && $invoice->transactions->isEmpty()) {
                $paidAmount = $invoice->amount;
            }

            return [
                $rowNumber++,
                $invoice->customer?->code ?? '',
                $invoice->customer?->nik ?? '',
                $invoice->customer?->name ?? '',
                $invoice->customer?->pppoe_user ?? '',
                $invoice->customer?->address ?? '',
                $invoice->customer?->phone ?? '',
                $invoice->customer?->package?->name ?? '',
                $invoice->customer?->package?->rate_limit ?? '',
                $invoice->amount,
                $paidAmount,
                $invoice->period?->format('F Y') ?? '',
                $this->paymentStatusLabel($invoice->status),
                $this->paymentMethodLabel($latestPayment),
                $latestPayment?->paid_at ? OpsTime::local($latestPayment->paid_at)->format('Y-m-d H:i:s') : '',
                $latestPayment?->admin ? 'Diinput oleh '.$latestPayment->admin->name : '',
            ];
        });

        $path = SimpleXlsxWriter::create('invoices-export.xlsx', [
            'No',
            'ID Pelanggan',
            'No KTP',
            'Nama Pelanggan',
            'PPPoE',
            'Alamat',
            'Tlp',
            'Nama Langganan',
            'Keterangan Langganan',
            'Nominal Harus Dibayar',
            'Nominal Pembayaran',
            'Bulan',
            'Status Pembayaran',
            'Metode',
            'Waktu Entry',
            'Keterangan',
        ], $rows);

        return response()->download($path, 'invoices-'.now()->format('Ymd-His').'.xlsx')->deleteFileAfterSend();
    }

    /**
     * Display a specific invoice
     */
    public function show(Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, request()->user());

        $invoice->load([
            'customer' => function ($q) {
                $q->withTrashed()->with('package');
            },
            'transactions.admin',
            'transactions.reversals',
            'reversals',
        ]);

        $invoice = $this->enrichInvoiceForView($invoice);
        $invoice->setAttribute(
            'public_invoice_url',
            $invoice->status === 'void'
                ? null
                : app(BillingReceiptService::class)->url($invoice),
        );

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Display all invoices for a specific customer
     */
    public function customerInvoices(Customer $customer)
    {
        AreaScope::authorizeCustomer($customer, request()->user());

        // The dedicated Customers/Invoices page never existed; the area-scoped
        // Invoices index already lists invoices, so send the customer there
        // filtered by that customer (exact id + code as the visible search).
        return redirect()->route('invoices.index', array_filter([
            'customer_id' => $customer->id,
            'search' => $customer->code,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * Show the form for creating a new invoice
     */
    public function create()
    {
        // I2 (PR-UX4): the page no longer receives every billable customer;
        // the combobox searches `invoices.customer-options` (limit 50).
        $areasQuery = Area::select('id', 'name', 'code')->orderBy('name');
        AreaScope::applyToAreas($areasQuery, request()->user());

        return Inertia::render('Invoices/Create', [
            'customers' => [],
            'areas' => $areasQuery->get(),
        ]);
    }

    /**
     * Billable customers for the Invoice/Create combobox (same filters the
     * create page used to preload: eBilling, active/isolated, has a package,
     * area-scoped), searched by name/code/PPPoE, at most 50 rows.
     */
    public function customerOptions(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'area_id' => ['nullable', 'integer'],
        ]);

        $query = Customer::ebilling()
            ->whereIn('status', ['active', 'isolated'])
            ->whereHas('package')
            ->with('package:id,name,price')
            ->select('id', 'name', 'code', 'package_id', 'due_day', 'area_id');
        AreaScope::applyToCustomers($query, $request->user());

        if (! empty($data['area_id'])) {
            $query->where('area_id', (int) $data['area_id']);
        }

        $terms = array_values(array_filter(preg_split('/\s+/', trim((string) ($data['q'] ?? ''))) ?: []));
        foreach ($terms as $term) {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(function ($sub) use ($like) {
                $sub->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('pppoe_user', 'like', $like);
            });
        }

        return response()->json(
            $query->orderBy('name')->limit(50)->get(),
            200,
            ['Cache-Control' => 'no-store'],
        );
    }

    /**
     * Store a newly created invoice
     */
    public function store(InvoiceStoreRequest $request)
    {
        $validated = $request->validated();
        $customer = Customer::findOrFail($validated['customer_id']);
        AreaScope::authorizeCustomer($customer, $request->user());
        $customer->load('package');

        if ($customer->package?->price === null) {
            return back()->withErrors([
                'customer_id' => 'Pelanggan belum memiliki subscription package aktif.',
            ])->withInput();
        }

        if ($this->money($validated['amount']) !== $this->money($customer->package->price)) {
            return back()->withErrors([
                'amount' => 'Nominal invoice harus sama dengan harga subscription package aktif: '
                    .number_format((float) $customer->package->price, 0, ',', '.'),
            ])->withInput();
        }

        // Convert period to 1st of month for consistency
        $period = Carbon::parse($validated['period'])->startOfMonth();

        // Check idempotency: prevent duplicate invoice for same customer + period
        $exists = Invoice::where('customer_id', $validated['customer_id'])
            ->where('period', $period->format('Y-m-d'))
            ->exists();

        if ($exists) {
            return back()->withErrors([
                'period' => 'An invoice already exists for this customer in this billing period.',
            ])->withInput();
        }

        Invoice::create([
            'customer_id' => $validated['customer_id'],
            'period' => $period->format('Y-m-d'),
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'],
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        AnalyticsCache::flushForUser($request->user());

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice created successfully.');
    }

    public function edit(Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, request()->user());
        $invoice->load(['customer' => fn ($query) => $query->withTrashed()->with('package')]);

        return Inertia::render('Invoices/Edit', [
            'invoice' => $this->enrichInvoiceForView($invoice),
        ]);
    }

    public function update(InvoiceUpdateRequest $request, Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, $request->user());

        if ($invoice->status !== 'unpaid' || $invoice->transactions()->exists()) {
            return back()->with('error', 'Invoice dengan riwayat transaksi tidak dapat diedit.');
        }

        $validated = $request->validated();
        $period = Carbon::createFromFormat('!Y-m', $validated['period'])->startOfMonth();
        $duplicate = Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->whereDate('period', $period)
            ->where('id', '!=', $invoice->id)
            ->exists();
        if ($duplicate) {
            return back()->withErrors(['period' => 'Invoice untuk periode tersebut sudah ada.'])->withInput();
        }

        $invoice->load('customer.package');
        if ($invoice->customer?->package?->price === null
            || $this->money($validated['amount']) !== $this->money($invoice->customer->package->price)) {
            return back()->withErrors([
                'amount' => 'Nominal invoice harus sama dengan harga subscription package aktif.',
            ])->withInput();
        }

        $invoice->update([
            'period' => $period->toDateString(),
            'amount' => $validated['amount'],
            'due_date' => $validated['due_date'],
        ]);
        AnalyticsCache::flushForUser($request->user());

        return redirect()->route('invoices.show', $invoice)
            ->with('success', 'Invoice berhasil diperbarui.');
    }

    /**
     * Void an unpaid invoice
     */
    public function void(Request $request, Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, $request->user());

        $error = app(InvoiceVoidService::class)->void((int) $invoice->id);

        if ($error !== null) {
            return back()->with('error', $error);
        }

        $invoice->refresh();

        AnalyticsCache::flushForUser($request->user());

        // Log the reason via activity log (Spatie)
        activity()
            ->performedOn($invoice)
            ->withProperties(['reason' => $request->input('reason', 'No reason provided')])
            ->log('Invoice voided');

        return back()->with('success', 'Invoice has been voided.');
    }

    /**
     * Delete an invoice (only if no payments recorded)
     */
    public function destroy(Request $request, Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, $request->user());

        if ($invoice->transactions()->count() > 0) {
            return back()->with('error', 'Cannot delete an invoice with recorded payments. Void it instead.');
        }

        $invoice->delete();

        AnalyticsCache::flushForUser($request->user());

        return redirect()->route('invoices.index')
            ->with('success', 'Invoice deleted successfully.');
    }

    /**
     * Download invoice as PDF
     */
    public function download(Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, request()->user());

        return $this->invoicePdfResponse($invoice);
    }

    /**
     * Public customer invoice/receipt. The signed middleware is the
     * authorization boundary; this route never changes invoice/payment/
     * customer state. It is intentionally available before payment so the
     * customer can open the invoice sent for collection.
     */
    public function publicReceipt(Invoice $invoice)
    {
        if (! $invoice->customer()->withTrashed()->exists() || $invoice->status === 'void') {
            abort(404);
        }

        return $this->invoicePdfResponse($invoice, true);
    }

    private function invoicePdfResponse(Invoice $invoice, bool $public = false)
    {

        $invoice->load([
            'customer' => function ($q) {
                $q->withTrashed()->with('package');
            },
            'transactions.reversals',
            'reversals',
        ]);

        $company = [
            'name' => Setting::get('company_name', 'PT. SKYNET LINTAS NUSANTARA'),
            'address' => Setting::get('company_address', 'Randuagung Gg VIII RT3, RW7, No.01 Singosari - Malang 65153'),
            'email' => CompanyContact::email(),
            'phone' => CompanyContact::phone(),
        ];

        $manual_accounts = Setting::get('payment_channels', []);
        if (! is_array($manual_accounts)) {
            $manual_accounts = [];
        }

        // Public pay page (stable): Midtrans QRIS/VA/e-wallet + transfer info
        // Always use /pay/{uuid} (Snap redirect can expire; page creates fresh Snap)
        $payment_url = null;
        if (($invoice->status ?? '') !== 'paid' && ! empty($invoice->uuid)) {
            $payment_url = url('/pay/'.$invoice->uuid);
        }

        $pdf = Pdf::loadView('invoices.pdf', compact('invoice', 'company', 'manual_accounts', 'payment_url'));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $invoice->code) ?: 'invoice';
        $response = $pdf->stream("Invoice-{$filename}.pdf");

        if ($public) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('X-Content-Type-Options', 'nosniff');
        }

        return $response;
    }

    private function paymentStatusLabel(string $status): string
    {
        return match ($status) {
            'paid' => 'Lunas',
            'unpaid' => 'Belum Lunas',
            'void' => 'Batal',
            default => ucfirst($status),
        };
    }

    private function paymentMethodLabel(?Transaction $transaction): string
    {
        if (! $transaction) {
            return '';
        }

        // Applied customer credit (saldo kelebihan bayar) is stored as manual/other; label it by reference.
        if (str_starts_with((string) $transaction->reference, 'CREDIT-')) {
            return 'saldo';
        }

        return $transaction->method ?: $transaction->channel;
    }

    private function invoiceIndexQuery(Request $request, array $with)
    {
        $query = Invoice::query()
            ->with($with)
            ->when($request->search, function ($q, $search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('code', 'like', "%{$search}%")
                        ->orWhereHas('customer', function ($c) use ($search) {
                            $c->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        });
                });
            })
            ->when($request->filled('customer_id'), function ($q) use ($request) {
                $q->where('customer_id', (int) $request->input('customer_id'));
            })
            ->when($request->status, function ($q, $status) {
                if ($status !== 'all') {
                    $q->where('status', $status);
                }
            })
            ->when($request->filled('area_id'), function ($q) use ($request) {
                $q->whereHas('customer', function ($customer) use ($request) {
                    $customer->withTrashed()->where('area_id', (int) $request->input('area_id'));
                });
            })
            ->when($request->filled('period'), function ($q) use ($request) {
                try {
                    $period = Carbon::createFromFormat('!Y-m-d', (string) $request->input('period'))->startOfMonth();
                    $q->whereDate('period', $period->toDateString());
                } catch (\Throwable) {
                    $q->whereRaw('1 = 0');
                }
            })
            // Default sort: Unpaid first, then newest
            ->orderByRaw("FIELD(status, 'unpaid', 'paid', 'void')")
            ->latest('period');
        AreaScope::applyToInvoices($query, $request->user());

        return $query;
    }

    private function enrichInvoiceForView(Invoice $invoice): Invoice
    {
        $packagePrice = $invoice->customer?->package?->price;
        $paidAmount = $invoice->relationLoaded('transactions')
            ? $invoice->transactions
                ->whereIn('status', ['verified', 'paid'])
                ->sum(fn (Transaction $transaction) => (float) $transaction->amount)
            : (float) ($invoice->getAttribute('paid_amount') ?? 0);
        $reversedAmount = $invoice->relationLoaded('reversals')
            ? $invoice->reversals->sum(fn (PaymentReversal $reversal): float => (float) $reversal->amount)
            : (float) ($invoice->getAttribute('reversed_amount') ?? 0);
        $paidAmount = round(max(0, $paidAmount - $reversedAmount), 2);

        $hasTransactions = $invoice->relationLoaded('transactions')
            ? $invoice->transactions->isNotEmpty()
            : (int) ($invoice->getAttribute('transactions_count') ?? 0) > 0;
        if ($invoice->status === 'paid' && $paidAmount <= 0 && ! $hasTransactions) {
            $paidAmount = (float) $invoice->amount;
        }

        $invoice->setAttribute('package_price', $packagePrice !== null ? (float) $packagePrice : null);
        $invoice->setAttribute('paid_amount', $paidAmount);
        $invoice->setAttribute(
            'amount_mismatch',
            $invoice->status === 'unpaid'
                && $packagePrice !== null
                && $this->money($invoice->amount) !== $this->money($packagePrice)
        );

        if ($invoice->relationLoaded('transactions')) {
            $invoice->transactions->each(function (Transaction $transaction): void {
                $path = trim((string) $transaction->getRawOriginal('proof_url'));
                if ($path !== '' && ! filter_var($path, FILTER_VALIDATE_URL)) {
                    $transaction->setAttribute('proof_url', URL::temporarySignedRoute(
                        'media.transactions.proof',
                        now()->addMinutes(10),
                        ['transaction' => $transaction->getKey()],
                    ));
                }
            });
        }

        return $invoice;
    }

    private function money(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
