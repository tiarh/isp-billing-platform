<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Http\Requests\PaymentReversalRequest;
use App\Http\Requests\PaymentStoreRequest;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Services\BillingEligibilityService;
use App\Services\BillingWaEventService;
use App\Services\PaymentReversalService;
use App\Support\AnalyticsCache;
use App\Support\AreaScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class PaymentController extends Controller
{
    /**
     * Show the payment form for an invoice
     */
    public function create(Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, request()->user());

        $invoice->load('customer.package');

        return Inertia::render('Payments/Create', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Store a new payment
     */
    public function store(PaymentStoreRequest $request, Invoice $invoice)
    {
        AreaScope::authorizeInvoice($invoice, $request->user());

        $validated = $request->validated();
        $invoice->loadMissing(['customer' => fn ($query) => $query->withTrashed()]);
        $this->validatePaymentIdentity($invoice, $validated);
        $idempotencyKey = trim((string) ($validated['idempotency_key'] ?? $request->header('Idempotency-Key', '')));
        if ($idempotencyKey === '') {
            // Backwards-compatible clients still get a unique transaction;
            // current UI sends a stable key to make double-submit safe.
            $idempotencyKey = 'legacy-'.Str::uuid();
        }

        $existing = Transaction::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ((int) $existing->invoice_id !== (int) $invoice->id) {
                return back()->withErrors([
                    'idempotency_key' => 'Idempotency key sudah digunakan untuk invoice lain.',
                ]);
            }

            return redirect()->route('invoices.show', $invoice)
                ->with('success', 'Payment sudah diproses sebelumnya.');
        }

        // Handle proof upload
        $proofUrl = null;
        if ($request->hasFile('proof')) {
            $proofUrl = $request->file('proof')->store('payment-proofs', 'private');
        }

        [$transaction, $settled] = DB::transaction(function () use ($invoice, $validated, $proofUrl, $idempotencyKey) {
            $lockedInvoice = Invoice::query()->with('customer')->lockForUpdate()->findOrFail($invoice->id);
            $existing = Transaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ((int) $existing->invoice_id !== (int) $lockedInvoice->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Idempotency key sudah digunakan untuk invoice lain.',
                    ]);
                }

                return [$existing, false];
            }

            if ($lockedInvoice->status === 'void') {
                throw ValidationException::withMessages([
                    'amount' => 'Invoice yang sudah void tidak dapat menerima pembayaran.',
                ]);
            }

            if ($lockedInvoice->isSettled()) {
                throw ValidationException::withMessages([
                    'amount' => 'Invoice sudah lunas.',
                ]);
            }

            $paidAmount = $lockedInvoice->totalPaid();
            $remaining = round((float) $lockedInvoice->amount - $paidAmount, 2);
            $amount = round((float) $validated['amount'], 2);
            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal pembayaran melebihi sisa invoice: '.number_format($remaining, 2, ',', '.'),
                ]);
            }

            $transaction = Transaction::create([
                'invoice_id' => $lockedInvoice->id,
                'reference' => 'MANUAL-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6)),
                'idempotency_key' => $idempotencyKey,
                'channel' => 'manual',
                'admin_id' => auth()->id(),
                'amount' => $amount,
                'status' => 'verified',
                'method' => $validated['method'],
                'proof_url' => $proofUrl,
                'paid_at' => $this->clampPaidAt($validated['paid_at'] ?? null),
                'notes' => $validated['notes'] ?? null,
            ]);
            $settled = $lockedInvoice->fresh()->isSettled();
            if ($settled && $lockedInvoice->status !== 'paid') {
                $lockedInvoice->update(['status' => 'paid']);
            }

            return [$transaction, $settled];
        });

        $reconnectNotice = $settled
            ? $this->dispatchSettlementSideEffects($invoice, $transaction)
            : null;

        AnalyticsCache::flushForUser($request->user());

        return redirect()->route('invoices.show', $invoice)
            ->with('success', trim('Payment recorded successfully. '.($reconnectNotice ?? '')));
    }

    public function reverse(
        PaymentReversalRequest $request,
        Invoice $invoice,
        Transaction $transaction,
        PaymentReversalService $reversals,
    ) {
        AreaScope::authorizeInvoice($invoice, $request->user());
        Gate::authorize('reversePayment', $invoice);

        $reversals->reverse($invoice, $transaction, $request->user(), $request->validated());
        AnalyticsCache::flushForUser($request->user());

        return back()->with('success', 'Payment berhasil dibatalkan secara audit dan invoice dihitung ulang.');
    }

    /**
     * Bulk import payments from CSV
     */
    public function bulkImport(Request $request)
    {
        $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
        ]);

        $path = $request->file('csv_file')->getRealPath();
        $fileHash = hash_file('sha256', $path);
        $handle = fopen($path, 'rb');
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            return back()->withErrors(['csv_file' => 'CSV kosong atau tidak memiliki header.']);
        }

        $headers = array_map(function ($header) {
            $header = strtolower(trim((string) $header));
            $header = preg_replace('/^'.chr(239).chr(187).chr(191).'/', '', $header);

            return str_replace([' ', '-'], '_', $header);
        }, $headers);
        $required = ['amount', 'method'];
        if (! in_array('invoice_id', $headers, true) && ! in_array('invoice_code', $headers, true)) {
            $required[] = 'invoice_id atau invoice_code';
        }
        $missing = array_values(array_diff($required, $headers));
        if ($missing !== []) {
            fclose($handle);

            return back()->withErrors(['csv_file' => 'Header wajib: '.implode(', ', $missing).'.']);
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $rows = 0;
        $errors = [];
        while (($values = fgetcsv($handle)) !== false) {
            if (count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows++;
            if ($rows > 1000) {
                $errors[] = 'CSV dibatasi maksimal 1000 baris.';
                break;
            }
            $values = array_slice(array_pad($values, count($headers), null), 0, count($headers));
            $row = array_combine($headers, $values);
            $row['idempotency_key'] = trim((string) ($row['idempotency_key'] ?? ''))
                ?: 'csv:'.$fileHash.':'.$rows;
            try {
                $invoice = isset($row['invoice_id']) && trim((string) $row['invoice_id']) !== ''
                    ? Invoice::query()->findOrFail((int) $row['invoice_id'])
                    : Invoice::query()->where('code', trim((string) $row['invoice_code']))->firstOrFail();
                AreaScope::authorizeInvoice($invoice, $request->user());
                $validated = Validator::make($row, [
                    'amount' => ['required', 'numeric', 'min:0.01'],
                    'method' => ['required', Rule::in(['cash', 'transfer', 'qris', 'other'])],
                    'paid_at' => PaymentStoreRequest::paidAtRules(),
                    'idempotency_key' => ['required', 'string', 'max:191'],
                    'notes' => ['nullable', 'string', 'max:1000'],
                ])->validate();
                $this->recordCsvPayment($invoice, $validated, $request->user());
                $processed++;
            } catch (ValidationException $exception) {
                $failed++;
                $messages = collect($exception->errors())->flatten()->implode(' ');
                $errors[] = 'Baris '.$rows.': '.$messages;
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = 'Baris '.$rows.': '.$exception->getMessage();
            }
        }
        fclose($handle);

        $message = "CSV payment selesai: {$processed} diproses, {$failed} gagal validasi, {$skipped} dilewati.";
        $response = back()->with('success', $message);

        return $errors === []
            ? $response
            : $response->withErrors(['csv_file' => implode(' | ', array_slice($errors, 0, 20))]);
    }

    /**
     * Record a confirmed manual payment for selected invoices.
     * This intentionally skips invoices with existing transactions and
     * reconnects an isolated customer after one selected invoice is paid.
     */
    public function bulkStore(Request $request)
    {
        $validated = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['integer', 'distinct', 'exists:invoices,id'],
            'method' => ['required', 'in:cash,transfer'],
            'paid_at' => PaymentStoreRequest::paidAtRules(),
            'idempotency_key' => ['required', 'string', 'max:100'],
            'invoice_confirmations' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_confirmations.*.id' => ['required', 'integer', 'distinct'],
            'invoice_confirmations.*.code' => ['required', 'string', 'max:191'],
            'invoice_confirmations.*.customer_code' => ['nullable', 'string', 'max:191'],
            'invoice_confirmations.*.customer_name' => ['required', 'string', 'max:191'],
            'invoice_confirmations.*.area_id' => ['nullable', 'integer'],
            'invoice_confirmations.*.period' => ['required', 'date'],
            'invoice_confirmations.*.amount' => ['required', 'numeric', 'min:0'],
            'invoice_confirmations.*.due_date' => ['nullable', 'date'],
            'invoice_confirmations.*.status' => ['required', Rule::in(['unpaid'])],
            'confirmed' => ['accepted'],
        ]);

        $invoices = collect();
        $batchHash = strtoupper(substr(hash('sha256', $validated['idempotency_key']), 0, 16));
        $paid = 0;
        $skipped = 0;
        $alreadyProcessed = 0;
        $total = 0;
        $reconnectCustomerIds = [];
        $notIsolatedCustomerIds = [];

        DB::transaction(function () use (&$invoices, $validated, $batchHash, &$paid, &$skipped, &$alreadyProcessed, &$total, &$reconnectCustomerIds, &$notIsolatedCustomerIds, $request) {
            $invoices = Invoice::query()
                ->with(['customer' => fn ($query) => $query->withTrashed(), 'transactions'])
                ->whereIn('id', $validated['invoice_ids'])
                // Use one lock order for overlapping batches to reduce deadlocks.
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $confirmationById = collect($validated['invoice_confirmations'])
                ->keyBy(fn (array $confirmation): int => (int) $confirmation['id']);
            $requestedIds = collect($validated['invoice_ids'])
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values();
            if ($confirmationById->count() !== $requestedIds->count()
                || $confirmationById->keys()->map(fn ($id): int => (int) $id)->sort()->values()->all() !== $requestedIds->all()
                || $invoices->count() !== $requestedIds->count()) {
                throw ValidationException::withMessages([
                    'invoice_confirmations' => 'Daftar konfirmasi invoice tidak lengkap atau sudah berubah. Muat ulang halaman lalu periksa kembali.',
                ]);
            }

            // Validate every selected identity while all invoice rows are locked,
            // before creating any transaction in this batch.
            foreach ($invoices as $invoice) {
                AreaScope::authorizeInvoice($invoice, $request->user());
                $confirmation = $confirmationById->get((int) $invoice->id);
                if (! is_array($confirmation) || ! $this->bulkConfirmationMatches($invoice, $confirmation)) {
                    throw ValidationException::withMessages([
                        'invoice_confirmations' => "Data invoice {$invoice->code} berubah. Muat ulang halaman dan konfirmasi ulang seluruh daftar.",
                    ]);
                }
            }

            foreach ($invoices as $invoice) {
                $reference = "BULK-{$batchHash}-{$invoice->id}";
                $idempotencyKey = "bulk:{$batchHash}:{$invoice->id}";
                if (Transaction::where('idempotency_key', $idempotencyKey)->orWhere('reference', $reference)->exists()) {
                    $alreadyProcessed++;

                    continue;
                }

                if ($invoice->status !== 'unpaid' || $invoice->transactions->isNotEmpty()) {
                    $skipped++;

                    continue;
                }

                $amount = (float) $invoice->amount;
                Transaction::create([
                    'invoice_id' => $invoice->id,
                    'reference' => $reference,
                    'idempotency_key' => $idempotencyKey,
                    'channel' => 'manual',
                    'admin_id' => $request->user()->id,
                    'amount' => $amount,
                    'method' => $validated['method'],
                    'status' => 'verified',
                    'paid_at' => $this->clampPaidAt($validated['paid_at'] ?? null),
                    'notes' => 'Bulk payment confirmed by operator.',
                ]);
                $invoice->update(['status' => 'paid']);
                $paid++;
                $total += $amount;
                // Bulk payments trigger the fast reconnect path (R1) exactly once per
                // customer: collected here and dispatched after commit below (the job
                // re-evaluates eligibility itself).
                if ($invoice->customer && in_array((string) $invoice->customer->status, CustomerStatus::isolatedValues(), true)) {
                    $reconnectCustomerIds[(int) $invoice->customer->id] = (int) $invoice->id;
                } elseif ($invoice->customer) {
                    $notIsolatedCustomerIds[(int) $invoice->customer->id] = (string) $invoice->customer->status;
                }
            }
        });

        DB::afterCommit(function () use ($reconnectCustomerIds): void {
            foreach ($reconnectCustomerIds as $customerId => $invoiceId) {
                $customer = Customer::find($customerId);
                if ($customer) {
                    ReconnectCustomerJob::dispatch($customer, $invoiceId);
                }
            }
        });

        foreach ($notIsolatedCustomerIds as $customerId => $status) {
            Log::info('[billing] payment reconnect not dispatched', [
                'customer_id' => $customerId,
                'reason' => 'customer_not_isolated',
                'customer_status' => $status,
                'source' => 'bulk',
            ]);
        }

        AnalyticsCache::flushForUser($request->user());

        return back()->with('success', sprintf(
            '%d invoice dicatat lunas (%s). %d dilewati%s. Reconnect dijadwalkan: %d pelanggan%s.',
            $paid,
            number_format($total, 0, ',', '.'),
            $skipped,
            $alreadyProcessed > 0 ? "; {$alreadyProcessed} sudah diproses sebelumnya" : '',
            count($reconnectCustomerIds),
            $notIsolatedCustomerIds !== []
                ? '; '.count($notIsolatedCustomerIds).' pelanggan tidak berstatus isolir di eBilling (jika profil MikroTik masih isolir, gunakan tombol Reconnect)'
                : ''
        ));
    }

    /**
     * paid_at is validated to not be after today (WIB). It is parsed by
     * PaymentStoreRequest::parsePaidAt() (tz-less strings are WIB) and stored as
     * the exact UTC instant. A time later than now (same WIB day) is clamped to now.
     */
    private function clampPaidAt(mixed $value): Carbon
    {
        $parsed = PaymentStoreRequest::parsePaidAt($value);
        if ($parsed === null || $parsed->greaterThan(now())) {
            return now();
        }

        return $parsed;
    }

    private function recordCsvPayment(Invoice $invoice, array $validated, $admin): array
    {
        [$transaction, $settled] = DB::transaction(function () use ($invoice, $validated, $admin) {
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $existing = Transaction::query()->where('idempotency_key', $validated['idempotency_key'])->first();
            if ($existing) {
                if ((int) $existing->invoice_id !== (int) $lockedInvoice->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Idempotency key sudah dipakai invoice lain.',
                    ]);
                }

                return [$existing, false];
            }
            // Same rule as the single payment form: anything not void and not settled.
            if ($lockedInvoice->status === 'void') {
                throw ValidationException::withMessages([
                    'amount' => 'Invoice yang sudah void tidak dapat menerima pembayaran.',
                ]);
            }
            if ($lockedInvoice->isSettled()) {
                throw ValidationException::withMessages([
                    'amount' => 'Invoice sudah lunas.',
                ]);
            }

            $paid = $lockedInvoice->totalPaid();
            $remaining = round((float) $lockedInvoice->amount - $paid, 2);
            $amount = round((float) $validated['amount'], 2);
            if ($amount > $remaining) {
                throw ValidationException::withMessages([
                    'amount' => 'Nominal melebihi sisa invoice.',
                ]);
            }

            $transaction = Transaction::create([
                'invoice_id' => $lockedInvoice->id,
                'reference' => 'CSV-'.strtoupper(Str::random(16)),
                'idempotency_key' => $validated['idempotency_key'],
                'channel' => 'manual',
                'admin_id' => $admin->id,
                'amount' => $amount,
                'status' => 'verified',
                'method' => $validated['method'],
                'paid_at' => $this->clampPaidAt($validated['paid_at'] ?? null),
                'notes' => $validated['notes'] ?? null,
            ]);
            $settled = $lockedInvoice->fresh()->isSettled();
            if ($settled) {
                $lockedInvoice->update(['status' => 'paid']);
            }

            return [$transaction, $settled];
        });

        if ($settled) {
            $this->dispatchSettlementSideEffects($invoice, $transaction);
        }

        return [$transaction, $settled];
    }

    /**
     * @return string Admin-facing note on whether a reconnect was scheduled.
     */
    private function dispatchSettlementSideEffects(Invoice $invoice, Transaction $transaction): string
    {
        $customer = $invoice->fresh('customer')->customer;
        $currentInvoice = $invoice->fresh();
        $decision = $customer
            ? app(BillingEligibilityService::class)->evaluate($customer)
            : null;
        if ($customer && ($decision['reconnect_eligible'] ?? false)) {
            DB::afterCommit(fn () => ReconnectCustomerJob::dispatch($customer, (int) $decision['current_invoice_id']));
            $notice = 'Reconnect dijadwalkan.';
        } else {
            [$reason, $notice] = $this->reconnectNotDispatchedReason($customer, $decision);
            Log::info('[billing] payment reconnect not dispatched', [
                'customer_id' => $customer?->id,
                'invoice_id' => $invoice->id,
                'transaction_id' => $transaction->id,
                'reason' => $reason,
                'customer_status' => $customer?->status,
                'eligibility_reason' => $decision['reason'] ?? null,
                'paid_this_month' => $decision['paid_this_month'] ?? null,
            ]);
        }
        DB::afterCommit(function () use ($currentInvoice, $transaction): void {
            try {
                app(BillingWaEventService::class)->notifyPayment($currentInvoice, $transaction);
            } catch (\Throwable $e) {
                Log::warning('[BillingWa] notifyPayment failed: '.$e->getMessage());
            }
        });

        return $notice;
    }

    /**
     * @param  array<string, mixed>|null  $decision
     * @return array{0: string, 1: string}
     */
    private function reconnectNotDispatchedReason(?Customer $customer, ?array $decision): array
    {
        if (! $customer) {
            return ['customer_missing', 'Reconnect tidak dijadwalkan: pelanggan tidak ditemukan.'];
        }
        if (! in_array((string) $customer->status, CustomerStatus::isolatedValues(), true)) {
            return ['customer_not_isolated', "Reconnect tidak dijadwalkan: status pelanggan di eBilling '{$customer->status}' (bukan isolir). Jika profil MikroTik masih isolir, gunakan tombol Reconnect."];
        }
        if ($decision['current_is_future'] ?? false) {
            return ['current_invoice_future', 'Reconnect tidak dijadwalkan: invoice berjalan untuk periode mendatang.'];
        }
        $reason = (string) ($decision['reason'] ?? 'not_eligible');

        return ['not_eligible:'.$reason, "Reconnect tidak dijadwalkan: belum memenuhi syarat ({$reason})."];
    }

    private function validatePaymentIdentity(Invoice $invoice, array $validated): void
    {
        if (! hash_equals((string) $invoice->code, trim((string) $validated['invoice_confirmation']))) {
            throw ValidationException::withMessages([
                'invoice_confirmation' => 'Kode invoice tidak cocok.',
            ]);
        }

        $expectedCustomer = (string) ($invoice->customer?->code ?: $invoice->customer?->name);
        if ($expectedCustomer === ''
            || ! hash_equals($expectedCustomer, trim((string) $validated['customer_confirmation']))) {
            throw ValidationException::withMessages([
                'customer_confirmation' => 'Konfirmasi pelanggan tidak cocok.',
            ]);
        }
    }

    private function bulkConfirmationMatches(Invoice $invoice, array $confirmation): bool
    {
        $customer = $invoice->customer;
        $expectedCustomerCode = (string) ($customer?->code ?? '');
        $expectedCustomerName = (string) ($customer?->name ?? '');
        $expectedPeriod = $invoice->period?->toDateString();
        $expectedDueDate = $invoice->due_date?->toDateString();

        return hash_equals((string) $invoice->code, (string) ($confirmation['code'] ?? ''))
            && hash_equals($expectedCustomerCode, (string) ($confirmation['customer_code'] ?? ''))
            && hash_equals($expectedCustomerName, (string) ($confirmation['customer_name'] ?? ''))
            && (int) ($confirmation['area_id'] ?? 0) === (int) ($customer?->area_id ?? 0)
            && $this->dateOnly($confirmation['period'] ?? null) === $expectedPeriod
            && $this->money($confirmation['amount'] ?? null) === $this->money($invoice->amount)
            && $this->dateOnly($confirmation['due_date'] ?? null) === $expectedDueDate
            && (string) ($confirmation['status'] ?? '') === (string) $invoice->status;
    }

    private function dateOnly(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(mixed $value): int
    {
        return (int) round((float) $value * 100);
    }
}
