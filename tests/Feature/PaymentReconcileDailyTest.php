<?php

namespace Tests\Feature;

use App\Models\CustomerCredit;
use App\Models\PaymentGatewayEvent;
use App\Models\PaymentGatewayTransaction;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\WaReportDelivery;
use App\Services\PaymentGateway\PaymentReconciliation;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\GatewayFixtures;
use Tests\TestCase;

/**
 * payments:reconcile-daily: read-only totals digest of yesterday's online
 * payments to the Skynet Report group (ledger report_key pay_recon, flag
 * payments_recon_daily_enabled). Fixtures only, no real data.
 */
#[Group('blocking-billing')]
class PaymentReconcileDailyTest extends TestCase
{
    use GatewayFixtures;
    use RefreshDatabase;

    private const GROUP = '120363-skynetreport@g.us';

    /** Reconciled WIB day (the run happens on 2026-10-22 08:13 WIB). */
    private const DAY = '2026-10-21';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:13', 'Asia/Jakarta'));
        Setting::set('report_wa_group_ids', [self::GROUP]);
        Setting::set('payments_recon_daily_enabled', '1');
        config(['services.evolution_api.api_key' => 'canary-pw', 'services.evolution_api.base_url' => 'http://evo.test']);
        Http::fake([
            'evo.test/instance/connectionState/*' => Http::response(['instance' => ['state' => 'open']]),
            'evo.test/message/sendText/*' => Http::response(['key' => ['id' => 'WAMID-RECON']]),
        ]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_dry_run_prints_totals_only_and_writes_nothing(): void
    {
        $this->seedDay();
        $before = $this->snapshot();

        $this->assertSame(0, Artisan::call('payments:reconcile-daily', ['--dry-run' => true, '--date' => self::DAY]));
        $out = Artisan::output();
        foreach ([
            'DRY RUN',
            'Dibayar via gateway: 3 transaksi, Rp300.000',
            'Tercatat di Transaksi: 2 transaksi, Rp200.000',
            'Selisih: 1 transaksi, Rp100.000',
            '- Nominal tidak cocok: 1',
            '- Ditolak / tidak dikenali: 1',
            '- Sudah bayar tapi masih isolir (>30 menit): 1',
            '- Tertahan (saklar bayar mati): 1',
            'Saat ini:',
            '- VA kedaluwarsa >60 menit masih terbuka: 1',
            '- Kelebihan bayar menunggu keputusan: 1',
            'Status: ADA YANG PERLU DICEK',
        ] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }

        $this->assertSame($before, $this->snapshot(), 'dry-run must not write anything');
        $this->assertSame(0, WaReportDelivery::query()->count());
        Http::assertNothingSent();
    }

    public function test_message_has_totals_only_no_names_or_phones(): void
    {
        $this->seedDay();
        $this->artisan('payments:reconcile-daily')->assertExitCode(0);
        $sent = Http::recorded(fn ($request) => str_contains($request->url(), 'sendText'))->values();
        $this->assertCount(1, $sent);
        $text = (string) $sent[0][0]['text'];
        $this->assertStringContainsString('*REKON BAYAR ONLINE*', $text);
        $this->assertStringContainsString('Data: Rabu, 21/10/2026', $text);
        $this->assertStringNotContainsString('Gateway Customer', $text);
        $this->assertStringNotContainsString('081200000000', $text);
        $this->assertStringNotContainsString('INV', $text);
    }

    public function test_live_run_sends_once_per_day_through_the_ledger(): void
    {
        $this->seedDay();

        $this->artisan('payments:reconcile-daily')->assertExitCode(0);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:13', 'Asia/Jakarta'));
        $this->artisan('payments:reconcile-daily')->assertExitCode(0);

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'sendText')));
        $row = WaReportDelivery::query()->where('report_key', 'pay_recon')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame(self::DAY, $row->period_date->toDateString());
        $this->assertSame(self::GROUP, $row->group_jid);
        $this->assertSame('WAMID-RECON', $row->provider_message_id);
        $this->assertSame(0, WaReportDelivery::query()->where('report_key', 'daily_recap')->count());
    }

    public function test_zero_day_is_not_sent(): void
    {
        $this->artisan('payments:reconcile-daily')->expectsOutputToContain('Zero day')->assertExitCode(0);

        Http::assertNothingSent();
        $row = WaReportDelivery::query()->where('report_key', 'pay_recon')->sole();
        $this->assertSame('skipped', $row->status);
        $this->assertSame('zero_day', $row->error);
    }

    public function test_flag_off_window_and_missing_group_send_nothing(): void
    {
        $this->seedDay();

        Setting::set('payments_recon_daily_enabled', '0');
        $this->artisan('payments:reconcile-daily')->expectsOutputToContain('is off')->assertExitCode(0);

        Setting::set('payments_recon_daily_enabled', '1');
        $this->travelTo(CarbonImmutable::parse('2026-10-22 12:13', 'Asia/Jakarta'));
        $this->artisan('payments:reconcile-daily')->expectsOutputToContain('Outside the send window')->assertExitCode(0);

        $this->travelTo(CarbonImmutable::parse('2026-10-22 08:13', 'Asia/Jakarta'));
        Setting::set('report_wa_group_ids', []);
        $this->artisan('payments:reconcile-daily')->expectsOutputToContain('No Report group')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, WaReportDelivery::query()->count());
    }

    public function test_flag_defaults_to_off(): void
    {
        Setting::query()->where('key', 'payments_recon_daily_enabled')->delete();
        Setting::flushRequestMemo();
        $this->seedDay();

        $this->artisan('payments:reconcile-daily')->expectsOutputToContain('is off')->assertExitCode(0);
        Http::assertNothingSent();
    }

    public function test_date_option_is_refused_on_a_live_run(): void
    {
        $this->artisan('payments:reconcile-daily', ['--date' => self::DAY])->assertExitCode(1);
        $this->artisan('payments:reconcile-daily', ['--dry-run' => true, '--date' => '21-10-2026'])->assertExitCode(1);
    }

    public function test_clean_day_with_payments_says_all_match(): void
    {
        $customer = $this->gatewayCustomer();
        $this->paidPair($this->gatewayInvoice($customer), 150000);

        $this->assertSame(0, Artisan::call('payments:reconcile-daily', ['--dry-run' => true, '--date' => self::DAY]));
        $out = Artisan::output();
        $this->assertStringContainsString('Selisih: tidak ada', $out);
        $this->assertStringContainsString('Status: SEMUA COCOK', $out);
    }

    public function test_recently_expired_open_va_is_not_reported_yet(): void
    {
        $invoice = $this->gatewayInvoice($this->gatewayCustomer());
        $this->attempt($invoice, 100000, PaymentGatewayTransaction::STATUS_PENDING, '2026-10-21 10:00', now()->subMinutes(30));
        $this->assertSame(0, app(PaymentReconciliation::class)->run(CarbonImmutable::parse(self::DAY, 'Asia/Jakarta'))['open_past_expiry']);

        $this->attempt($this->gatewayInvoice($this->gatewayCustomer()), 100000, PaymentGatewayTransaction::STATUS_PENDING, '2026-10-21 10:00', now()->subMinutes(90));
        $this->assertSame(1, app(PaymentReconciliation::class)->run(CarbonImmutable::parse(self::DAY, 'Asia/Jakarta'))['open_past_expiry']);
    }

    public function test_it_is_scheduled_in_a_free_minute_with_one_catch_up_and_never_over_the_stagger_cap(): void
    {
        $events = $this->monitoringEvents();
        $event = collect($events)->first(fn ($e) => str_contains((string) $e->command, 'payments:reconcile-daily'));

        $this->assertNotNull($event);
        $this->assertSame('17 8,9 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);

        // The stagger contract only samples hour 10: check hours 8 and 9 here (cap 5 per minute).
        foreach ([8, 9] as $hour) {
            $start = CarbonImmutable::create(2026, 10, 22, $hour, 0, 0, 'Asia/Jakarta');
            for ($m = 0; $m < 60; $m++) {
                $due = array_filter($events, fn ($e) => $e->expression !== '* * * * *'
                    && (new CronExpression($e->expression))->isDue($start->addMinutes($m), 'Asia/Jakarta'));
                $this->assertLessThanOrEqual(5, count($due), sprintf('%02d:%02d fires %d entries', $hour, $m, count($due)));
            }
        }
    }

    /** @return list<Event> */
    private function monitoringEvents(): array
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        return $schedule->events();
    }

    private function seedDay(): void
    {
        // Two paid online payments, both recorded as PG-% transactions.
        $this->paidPair($this->gatewayInvoice($this->gatewayCustomer()), 100000);
        $isolated = $this->gatewayCustomer(['status' => 'isolated']);
        $this->paidPair($this->gatewayInvoice($isolated, ['status' => 'paid']), 100000);

        // Paid at the provider, no transaction row: shows as difference.
        $this->attempt($this->gatewayInvoice($this->gatewayCustomer()), 100000, PaymentGatewayTransaction::STATUS_PAID, '2026-10-21 14:00');

        // Open VA past expiry.
        $this->attempt($this->gatewayInvoice($this->gatewayCustomer()), 100000, PaymentGatewayTransaction::STATUS_PENDING, '2026-10-20 10:00', now()->subHours(3));

        $customer = $this->gatewayCustomer();
        $invoice = $this->gatewayInvoice($customer);
        foreach ([
            ['rejected_amount_mismatch', 'a'],
            ['unmatched_invoice', 'b'],
            ['held_kill_switch', 'c'],
        ] as [$status, $key]) {
            $event = PaymentGatewayEvent::create([
                'provider' => 'duitku',
                'external_id' => 'recon-'.$key,
                'reference' => 'RECON-'.$key,
                'invoice_id' => $invoice->id,
                'amount' => 100000,
                'status' => $status,
                'payload' => [],
            ]);
            $event->forceFill(['created_at' => CarbonImmutable::parse('2026-10-21 11:00', 'Asia/Jakarta')->utc()])->saveQuietly();
        }

        CustomerCredit::create([
            'customer_id' => $customer->id,
            'source_invoice_id' => $invoice->id,
            'provider' => 'duitku',
            'amount' => 50000,
            'reason_code' => CustomerCredit::REASON_OVERPAYMENT,
            'status' => CustomerCredit::STATUS_NEEDS_REVIEW,
        ])->forceFill(['created_at' => CarbonImmutable::parse('2026-10-21 12:00', 'Asia/Jakarta')->utc()])->saveQuietly();
    }

    private function paidPair($invoice, int $amount): void
    {
        $txn = Transaction::create([
            'reference' => 'PG-DUITKU-'.$invoice->id,
            'invoice_id' => $invoice->id,
            'idempotency_key' => 'gateway:duitku:'.$invoice->id,
            'amount' => $amount,
            'channel' => 'gateway',
            'method' => 'va',
            'status' => 'paid',
            'paid_at' => CarbonImmutable::parse('2026-10-21 10:00', 'Asia/Jakarta')->utc(),
        ]);
        $pgt = $this->attempt($invoice, $amount, PaymentGatewayTransaction::STATUS_PAID, '2026-10-21 10:00');
        $pgt->forceFill(['transaction_id' => $txn->id])->saveQuietly();
    }

    private function attempt($invoice, int $amount, string $status, string $at, $expires = null): PaymentGatewayTransaction
    {
        $time = CarbonImmutable::parse($at, 'Asia/Jakarta')->utc();

        return PaymentGatewayTransaction::create([
            'provider' => 'duitku',
            'environment' => 'sandbox',
            'invoice_id' => $invoice->id,
            'merchant_order_id' => 'INV'.$invoice->id.'-'.strtoupper(substr(uniqid(), -8)),
            'payment_method' => 'BC',
            'method_group' => 'va',
            'method_name' => 'BCA Virtual Account',
            'kind' => 'va',
            'amount_invoice' => $amount,
            'fee' => 0,
            'fee_bearer' => 'customer',
            'amount_charged' => $amount,
            'status' => $status,
            'expires_at' => $expires ?? $time->addDay(),
            'paid_at' => $status === PaymentGatewayTransaction::STATUS_PAID ? $time : null,
        ]);
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        $out = [];
        foreach (['payment_gateway_transactions', 'payment_gateway_events', 'transactions', 'invoices', 'customers', 'customer_credits', 'wa_report_deliveries', 'settings'] as $table) {
            $out[$table] = DB::table($table)->count();
        }
        $out['hash'] = crc32(json_encode(DB::table('payment_gateway_transactions')->orderBy('id')->get()).json_encode(DB::table('customers')->orderBy('id')->get()));

        return $out;
    }
}
