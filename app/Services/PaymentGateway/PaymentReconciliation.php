<?php

namespace App\Services\PaymentGateway;

use App\Enums\CustomerStatus;
use App\Models\CustomerCredit;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Transaction;
use App\Services\PaymentGatewayService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Daily reconciliation of online payments for one WIB day. STRICTLY read-only:
 * only SELECTs, no writes, no provider calls. Totals only (counts and sums):
 * no customer names, phones, VA numbers or order ids leave this class.
 */
class PaymentReconciliation
{
    /** A customer who paid should be un-isolated within this many minutes. */
    public const ISOLATED_GRACE_MINUTES = 30;

    /** An expired open VA gets this long to be closed by the status check before it is reported. */
    public const OPEN_EXPIRY_GRACE_MINUTES = 60;

    /** Event statuses that mean "paid at the provider but not credited". */
    private const REJECTED_EVENT_STATUSES = [
        PaymentGatewayService::STATUS_REJECTED_AMOUNT,
        PaymentGatewayService::STATUS_REJECTED_VOID,
        PaymentGatewayService::STATUS_REJECTED_UNSIGNED,
        PaymentGatewayService::STATUS_UNMATCHED,
    ];

    /**
     * @return array{
     *     date: string,
     *     gateway_paid: array{count: int, sum: int},
     *     recorded: array{count: int, sum: int},
     *     diff: array{count: int, sum: int},
     *     amount_mismatch: int,
     *     rejected: int,
     *     paid_still_isolated: int,
     *     held: int,
     *     open_past_expiry: int,
     *     credits_new: array{count: int, sum: int},
     *     credits_review_backlog: int,
     * }
     */
    public function run(CarbonImmutable $day): array
    {
        [$from, $to] = OpsTime::dayBounds($day);

        $gateway = PaymentGatewayTransaction::query()
            ->where('status', PaymentGatewayTransaction::STATUS_PAID)
            ->whereBetween('paid_at', [$from, $to]);
        $gatewayPaid = ['count' => (int) (clone $gateway)->count(), 'sum' => (int) (clone $gateway)->sum('amount_invoice')];

        $recordedQuery = Transaction::query()
            ->where('reference', 'like', 'PG-%')
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$from, $to]);
        $recorded = ['count' => (int) (clone $recordedQuery)->count(), 'sum' => (int) round((float) (clone $recordedQuery)->sum('amount'))];

        $credits = CustomerCredit::query()->whereBetween('created_at', [$from, $to]);

        return [
            'date' => $day->toDateString(),
            'gateway_paid' => $gatewayPaid,
            'recorded' => $recorded,
            'diff' => ['count' => $gatewayPaid['count'] - $recorded['count'], 'sum' => $gatewayPaid['sum'] - $recorded['sum']],
            'amount_mismatch' => PaymentGatewayEvent::query()
                ->where('status', GatewayPaymentRecorder::STATUS_AMOUNT_MISMATCH)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'rejected' => PaymentGatewayEvent::query()
                ->whereIn('status', self::REJECTED_EVENT_STATUSES)
                ->whereBetween('created_at', [$from, $to])
                ->count(),
            'paid_still_isolated' => $this->paidStillIsolated($from, $to),
            'held' => PaymentGatewayEvent::query()->where('status', PaymentGatewayService::STATUS_HELD)->count(),
            'open_past_expiry' => PaymentGatewayTransaction::query()
                ->open()
                ->whereNotNull('expires_at')
                ->where('expires_at', '<', now()->subMinutes(self::OPEN_EXPIRY_GRACE_MINUTES))
                ->count(),
            'credits_new' => ['count' => (int) (clone $credits)->count(), 'sum' => (int) round((float) (clone $credits)->sum('amount'))],
            'credits_review_backlog' => CustomerCredit::query()->where('status', CustomerCredit::STATUS_NEEDS_REVIEW)->count(),
        ];
    }

    /**
     * Nothing happened and nothing needs a look: the daily WA is not sent.
     *
     * @param  array<string, mixed>  $r
     */
    public function isZeroDay(array $r): bool
    {
        return $r['gateway_paid']['count'] === 0
            && $r['recorded']['count'] === 0
            && $r['credits_new']['count'] === 0
            && ! $this->needsAttention($r);
    }

    /**
     * @param  array<string, mixed>  $r
     */
    public function needsAttention(array $r): bool
    {
        return $r['diff']['count'] !== 0
            || $r['diff']['sum'] !== 0
            || $r['amount_mismatch'] > 0
            || $r['rejected'] > 0
            || $r['paid_still_isolated'] > 0
            || $r['held'] > 0
            || $r['open_past_expiry'] > 0
            || $r['credits_review_backlog'] > 0;
    }

    /**
     * @param  array<string, mixed>  $r
     */
    public function message(array $r, CarbonImmutable $day, CarbonImmutable $sentAt): string
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $day = $day->timezone(OpsTime::TZ);
        $rp = fn (int $n): string => 'Rp'.number_format($n, 0, ',', '.');

        $lines = [
            '*REKON BAYAR ONLINE*',
            'Data: '.$days[(int) $day->format('w')].', '.$day->format('d/m/Y').' (00:00-23:59 WIB)',
            'Dikirim: '.$sentAt->timezone(OpsTime::TZ)->format('d/m/Y H:i').' WIB',
            '',
            'Dibayar via gateway: '.$r['gateway_paid']['count'].' transaksi, '.$rp($r['gateway_paid']['sum']),
            'Tercatat di Transaksi: '.$r['recorded']['count'].' transaksi, '.$rp($r['recorded']['sum']),
            ($r['diff']['count'] === 0 && $r['diff']['sum'] === 0)
                ? 'Selisih: tidak ada'
                : 'Selisih: '.$r['diff']['count'].' transaksi, '.$rp($r['diff']['sum']),
            '',
            'Perlu dicek (hari itu):',
            '- Nominal tidak cocok: '.$r['amount_mismatch'],
            '- Ditolak / tidak dikenali: '.$r['rejected'],
            '- Sudah bayar tapi masih isolir (>'.self::ISOLATED_GRACE_MINUTES.' menit): '.$r['paid_still_isolated'],
            'Saldo baru hari itu: '.$r['credits_new']['count'].' ('.$rp($r['credits_new']['sum']).')',
            '',
            'Saat ini:',
            '- Tertahan (saklar bayar mati): '.$r['held'],
            '- VA kedaluwarsa >'.self::OPEN_EXPIRY_GRACE_MINUTES.' menit masih terbuka: '.$r['open_past_expiry'],
            '- Kelebihan bayar menunggu keputusan: '.$r['credits_review_backlog'],
            '',
            $this->needsAttention($r) ? 'Status: ADA YANG PERLU DICEK' : 'Status: SEMUA COCOK',
        ];

        return implode("\n", $lines);
    }

    /**
     * Paid online during the day, invoice paid, customer still isolated more
     * than the grace period later and no other unpaid invoice explains it.
     */
    private function paidStillIsolated(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('payment_gateway_transactions as pgt')
            ->join('invoices as i', 'i.id', '=', 'pgt.invoice_id')
            ->join('customers as c', 'c.id', '=', 'i.customer_id')
            ->where('pgt.status', PaymentGatewayTransaction::STATUS_PAID)
            ->whereBetween('pgt.paid_at', [$from, $to])
            ->where('pgt.paid_at', '<', now()->subMinutes(self::ISOLATED_GRACE_MINUTES))
            ->where('i.status', 'paid')
            ->whereIn('c.status', CustomerStatus::isolatedValues())
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('invoices as other')
                    ->whereColumn('other.customer_id', 'c.id')
                    ->whereIn('other.status', ['unpaid', 'overdue']);
            })
            ->distinct()
            ->count('c.id');
    }
}
