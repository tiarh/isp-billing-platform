<?php

namespace App\Services;

use App\Jobs\SendFinanceBulkWaJob;
use App\Models\BillingWaLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Support\AreaScope;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class FinanceBulkWaService
{
    public const EVENT = 'finance_bulk_reminder';

    public const CONFIRMATION_TEXT = 'KIRIM WA TAGIHAN';

    private const DEFAULT_TEMPLATE = "Halo {name},\n\nTagihan internet Anda untuk invoice {invoice_code} periode {period} sebesar {amount} belum lunas.\nJatuh tempo: {due_date}.\n\nSilakan lakukan pembayaran melalui {payment_link}.\n\n{company_name}";

    public function __construct(
        private FinancePerAreaReportService $report,
        private BillingWaTemplateService $templates,
        private IndonesianPhoneNormalizer $phones,
    ) {}

    /**
     * @return array{enabled: bool, dry_run: bool, max_recipients: int, rate_limit_per_minute: int, allowed_roles: list<string>, confirmation_text: string}
     */
    public function configSnapshot(): array
    {
        return [
            'enabled' => $this->booleanSetting('finance_bulk_wa_enabled', false),
            'dry_run' => $this->booleanSetting('finance_bulk_wa_dry_run', true),
            'max_recipients' => $this->boundedIntSetting('finance_bulk_wa_max_recipients', 100, 1, 1000),
            'rate_limit_per_minute' => $this->boundedIntSetting('finance_bulk_wa_rate_limit_per_minute', 10, 1, 600),
            'allowed_roles' => $this->allowedRoles(),
            'confirmation_text' => self::CONFIRMATION_TEXT,
        ];
    }

    public function canUse(User $user): bool
    {
        return in_array((string) $user->role, $this->allowedRoles(), true);
    }

    public function isStillEligible(BillingWaLog $log): bool
    {
        $invoice = Invoice::query()->with('customer')->find($log->invoice_id);
        if (! $invoice || $invoice->status === 'void' || ! $invoice->customer) {
            return false;
        }
        if ((int) $invoice->customer_id !== (int) $log->customer_id) {
            return false;
        }

        $outstanding = $this->currentOutstanding($invoice);

        return $outstanding !== null && $outstanding > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(User $user, string $period, ?int $areaId, ?string $asOf, array $filters): array
    {
        $snapshot = $this->configSnapshot();
        $targets = $this->targets($user, $period, $areaId, $asOf, $filters, $snapshot['max_recipients']);

        return [
            'enabled' => $snapshot['enabled'],
            'dry_run' => $snapshot['dry_run'],
            'max_recipients' => $snapshot['max_recipients'],
            'rate_limit_per_minute' => $snapshot['rate_limit_per_minute'],
            'can_execute' => $this->canUse($user) && $snapshot['enabled'] && ! $snapshot['dry_run'],
            'confirmation_text' => self::CONFIRMATION_TEXT,
            'period' => $period,
            'area_id' => $areaId,
            'rows' => $targets['rows'],
            'skipped' => $targets['skipped'],
            'eligible_count' => count($targets['rows']),
            'skipped_count' => count($targets['skipped']),
            'scan_limited' => $targets['scan_limited'],
            'source_note' => 'Preview report-only. Tidak memanggil provider, membuat job, mengubah invoice, atau mengubah isolasi pelanggan.',
        ];
    }

    /**
     * @return array{created: int, existing: int, skipped: int, idempotency_key: string, status: string}
     */
    public function execute(User $user, string $period, ?int $areaId, ?string $asOf, array $filters, string $idempotencyKey): array
    {
        $snapshot = $this->configSnapshot();
        if (! $this->canUse($user) || ! $snapshot['enabled'] || $snapshot['dry_run']) {
            return [
                'created' => 0,
                'existing' => 0,
                'skipped' => 0,
                'idempotency_key' => $idempotencyKey,
                'status' => 'blocked_by_setting',
            ];
        }

        $targets = $this->targets($user, $period, $areaId, $asOf, $filters, $snapshot['max_recipients']);
        $created = 0;
        $existing = 0;
        $skipped = count($targets['skipped']);

        DB::transaction(function () use ($user, $targets, $idempotencyKey, &$created, &$existing, &$skipped): void {
            foreach ($targets['candidates'] as $candidate) {
                $invoice = Invoice::query()->with(['customer.area', 'customer.package'])->lockForUpdate()->find($candidate['invoice_id']);
                if (! $invoice || $invoice->status === 'void' || ! $invoice->customer || (int) $invoice->customer_id !== $candidate['customer_id']) {
                    $skipped++;

                    continue;
                }
                AreaScope::authorizeInvoice($invoice, $user);

                $current = $this->currentOutstanding($invoice);
                if ($current === null || $current <= 0) {
                    $skipped++;

                    continue;
                }

                $normalized = $this->phones->normalize((string) $invoice->customer->phone);
                if (! $normalized['is_valid']) {
                    $skipped++;

                    continue;
                }

                $message = $this->message($invoice, $current);
                $dedupeKey = 'finance-bulk:'.hash('sha256', $idempotencyKey.'|'.$invoice->id);
                $log = BillingWaLog::query()->where('dedupe_key', $dedupeKey)->lockForUpdate()->first();
                if ($log) {
                    $existing++;

                    continue;
                }

                try {
                    $log = BillingWaLog::query()->create([
                        'event' => self::EVENT,
                        'customer_id' => $invoice->customer_id,
                        'invoice_id' => $invoice->id,
                        'area_id' => $invoice->customer->area_id,
                        'phone' => $this->maskPhone((string) $normalized['canonical_local']),
                        'message' => $message,
                        'status' => 'pending',
                        'dedupe_key' => $dedupeKey,
                    ]);
                } catch (QueryException $exception) {
                    if (BillingWaLog::query()->where('dedupe_key', $dedupeKey)->exists()) {
                        $existing++;

                        continue;
                    }

                    throw $exception;
                }

                SendFinanceBulkWaJob::dispatch(
                    $log->id,
                    (string) $normalized['canonical_local'],
                    $message,
                )->afterCommit();
                $created++;
            }
        });

        activity('finance-bulk-wa.execute')
            ->withProperties([
                'period' => $period,
                'area_id' => $areaId,
                'idempotency_key' => $idempotencyKey,
                'created' => $created,
                'existing' => $existing,
                'skipped' => $skipped,
                'result' => 'queued',
            ])
            ->log('finance_bulk_wa_queued');

        return [
            'created' => $created,
            'existing' => $existing,
            'skipped' => $skipped,
            'idempotency_key' => $idempotencyKey,
            'status' => 'queued',
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, candidates: list<array<string, mixed>>, skipped: list<array<string, mixed>>, scan_limited: bool}
     */
    private function targets(User $user, string $period, ?int $areaId, ?string $asOf, array $filters, int $limit): array
    {
        $rows = [];
        $candidates = [];
        $skipped = [];
        $scanned = 0;
        $scanLimit = max(1000, $limit * 10);
        $filters['outstanding_only'] = true;

        foreach ($this->report->exportRows($user, $period, $areaId, $asOf, $filters) as $row) {
            if ($scanned++ >= $scanLimit || count($candidates) >= $limit) {
                break;
            }

            $customer = Customer::withTrashed()->with(['area', 'package'])->find((int) $row['customer_id']);
            $invoice = Invoice::query()->find((int) $row['invoice_id']);
            if (! $customer || ! $invoice) {
                $skipped[] = $this->skipRow($row, 'customer_or_invoice_missing');

                continue;
            }
            if ($customer->trashed()) {
                $skipped[] = $this->skipRow($row, 'customer_deleted');

                continue;
            }

            $normalized = $this->phones->normalize((string) $customer->phone);
            if (! $normalized['is_valid']) {
                $skipped[] = $this->skipRow($row, 'invalid_phone');

                continue;
            }

            $message = $this->templates->render(self::DEFAULT_TEMPLATE, $customer, [
                'invoice' => $invoice,
                'amount' => $row['outstanding'],
                'event' => self::EVENT,
            ]);
            $candidates[] = [
                'invoice_id' => (int) $row['invoice_id'],
                'customer_id' => (int) $row['customer_id'],
                'phone' => (string) $normalized['canonical_local'],
                'message' => $message,
            ];
            $rows[] = [
                'invoice_id' => (int) $row['invoice_id'],
                'invoice_code' => $row['invoice_code'],
                'customer_code' => $row['customer_code'],
                'customer_name' => $row['customer_name'],
                'phone' => $this->maskPhone((string) $normalized['canonical_local']),
                'period' => $row['period'],
                'amount' => $row['amount'],
                'outstanding' => $row['outstanding'],
                'due_date' => $row['due_date'],
                'status' => $row['status'],
                'reason' => 'outstanding_invoice',
                'message_preview' => $message,
            ];
        }

        return [
            'rows' => $rows,
            'candidates' => $candidates,
            'skipped' => $skipped,
            'scan_limited' => $scanned >= $scanLimit || count($candidates) >= $limit,
        ];
    }

    private function message(Invoice $invoice, string $outstanding): string
    {
        return $this->templates->render(self::DEFAULT_TEMPLATE, $invoice->customer, [
            'invoice' => $invoice,
            'amount' => $outstanding,
            'event' => self::EVENT,
        ]);
    }

    private function currentOutstanding(Invoice $invoice): ?string
    {
        $paid = (string) DB::table('transactions')
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', ['verified', 'paid'])
            ->sum('amount');
        $reversed = (string) DB::table('payment_reversals')->where('invoice_id', $invoice->id)->sum('amount');
        $transactionCount = DB::table('transactions')->where('invoice_id', $invoice->id)->count();
        if ($invoice->status === 'paid' && $transactionCount === 0) {
            return null;
        }

        $amount = $this->cents((string) $invoice->amount);
        $effective = max(0, $this->cents($paid) - $this->cents($reversed));

        return $effective >= $amount ? '0.00' : $this->amount($amount - $effective);
    }

    /** @return array<string, mixed> */
    private function skipRow(array $row, string $reason): array
    {
        return [
            'invoice_code' => $row['invoice_code'] ?? null,
            'customer_code' => $row['customer_code'] ?? null,
            'reason' => $reason,
        ];
    }

    private function booleanSetting(string $key, bool $default): bool
    {
        return filter_var(Setting::get($key, $default ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    }

    private function boundedIntSetting(string $key, int $default, int $min, int $max): int
    {
        return min($max, max($min, (int) Setting::get($key, $default)));
    }

    /** @return list<string> */
    private function allowedRoles(): array
    {
        $roles = Setting::get('finance_bulk_wa_allowed_roles', ['global_admin', 'superadmin']);
        if (! is_array($roles)) {
            return ['global_admin', 'superadmin'];
        }

        $roles = array_values(array_intersect(array_map('strval', $roles), ['global_admin', 'superadmin']));

        return $roles !== [] ? $roles : ['global_admin', 'superadmin'];
    }

    private function cents(string $value): int
    {
        $value = trim($value);
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
            return 0;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function amount(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }

    private function maskPhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        return strlen($digits) <= 4
            ? str_repeat('*', strlen($digits))
            : substr($digits, 0, 2).str_repeat('*', max(2, strlen($digits) - 4)).substr($digits, -2);
    }
}
