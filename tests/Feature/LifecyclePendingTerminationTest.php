<?php

namespace Tests\Feature;

use App\Jobs\BatchIsolateCustomersJob;
use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Jobs\IsolateCustomerJob;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\BillingEligibilityService;
use App\Services\BillingEnforcementLedger;
use App\Services\InvoiceGenerationService;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\MikrotikService;
use App\Services\RadiusUserService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4a: pending terminations + "tahan tagihan" billing hold.
 * Nothing in production creates pending rows yet; these tests create them.
 */
#[Group('blocking-core')]
#[Group('blocking-billing')]
class LifecyclePendingTerminationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-10 10:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-11-10 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- service ---------------------------------------------------------

    public function test_allow_terminates_with_guard_activity_and_no_pending_row(): void
    {
        $customer = $this->customer();

        $out = app(LifecycleTerminationService::class)->terminateOrDefer($customer, $this->allow(), 'warehouse_receive');

        $this->assertSame('customer_terminated', $out['result']);
        $this->assertNull($out['pending_termination_id']);
        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_guard_allowed']);
        $this->assertDatabaseCount('customer_pending_terminations', 0);
    }

    public function test_deny_and_unknown_defer_keep_status_and_are_idempotent(): void
    {
        foreach (['deny' => $this->deny(), 'unknown' => $this->unknown()] as $verdict => $decision) {
            $customer = $this->customer();
            $service = app(LifecycleTerminationService::class);

            $first = $service->terminateOrDefer($customer, $decision, 'warehouse_receive', ['recovery_case_id' => 77]);
            $second = $service->terminateOrDefer($customer, $decision, 'warehouse_receive', ['recovery_case_id' => 77]);

            $this->assertSame('termination_deferred', $first['result'], $verdict);
            $this->assertSame($first['pending_termination_id'], $second['pending_termination_id'], $verdict);
            $this->assertSame('active', $customer->fresh()->status, $verdict);
            $rows = CustomerPendingTermination::query()->where('customer_id', $customer->id)->get();
            $this->assertCount(1, $rows, $verdict);
            $row = $rows->first();
            $this->assertSame($customer->id, $row->open_key);
            $this->assertSame($verdict, $row->first_verdict);
            $this->assertSame(2, $row->check_count);
            $this->assertSame(77, $row->recovery_case_id);
            $this->assertSame(1, Activity::query()
                ->where('subject_id', $customer->id)->where('event', 'termination_deferred')->count(), $verdict);
        }
    }

    public function test_pre_verdict_is_unknown_when_guard_throws(): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);

        $decision = app(LifecycleTerminationService::class)->preVerdict($this->customer(), GuardContext::automated('test'));

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertFalse($decision->allowed());
    }

    public function test_finalize_terminates_only_on_allow_and_closes_row(): void
    {
        $customer = $this->customer();
        $service = app(LifecycleTerminationService::class);
        $out = $service->terminateOrDefer($customer, $this->deny(), 'warehouse_receive');
        $row = CustomerPendingTermination::query()->findOrFail($out['pending_termination_id']);

        $this->assertFalse($service->finalize($row, $this->deny()));
        $this->assertSame('active', $customer->fresh()->status);

        $this->assertTrue($service->finalize($row, $this->allow()));
        $this->assertSame('terminated', $customer->fresh()->status);
        $row->refresh();
        $this->assertSame('terminated', $row->status);
        $this->assertSame('guard_passed', $row->resolution);
        $this->assertNull($row->open_key);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_deferred']);
        $this->assertFalse($service->finalize($row, $this->allow()));
    }

    public function test_a_resolved_row_frees_the_open_key_so_the_customer_can_be_deferred_again(): void
    {
        $customer = $this->customer();
        $first = $this->pendingRow($customer);
        $first->forceFill(['status' => 'cancelled', 'resolution' => 'admin_cancelled'])->save();

        $second = $this->pendingRow($customer);
        $this->assertSame($customer->id, $second->open_key);
        $this->assertNull($first->fresh()->open_key);
    }

    public function test_open_key_unique_one_open_row_per_customer(): void
    {
        $customer = $this->customer();
        $this->pendingRow($customer);

        $this->expectException(QueryException::class);
        CustomerPendingTermination::create([
            'customer_id' => $customer->id, 'source' => 'takeover', 'status' => 'pending', 'requested_at' => now(),
        ]);
    }

    public function test_cancel_requires_admin_and_reason_then_lifts_hold_and_creates_current_invoice(): void
    {
        $customer = $this->customer();
        $row = $this->pendingRow($customer, now()->subMonths(2));
        $service = app(LifecycleTerminationService::class);

        try {
            $service->cancel($row, User::factory()->create(['role' => 'teknisi']), 'alasan cukup panjang sekali');
            $this->fail('teknisi must not cancel');
        } catch (AuthorizationException) {
        }
        try {
            $service->cancel($row, User::factory()->create(['role' => 'admin']), 'pendek');
            $this->fail('short reason must fail');
        } catch (ValidationException) {
        }
        $this->assertTrue($row->fresh()->isPending());

        $admin = User::factory()->create(['role' => 'admin']);
        $result = $service->cancel($row, $admin, 'pelanggan masih berlangganan, salah SN');

        $this->assertSame('invoice_created', $result['invoice']);
        $this->assertSame('2026-11', $result['period']);
        // Pending since 2026-09-10: 2026-10 passed during the hold -> listed, NOT generated.
        $this->assertSame(['2026-10'], $result['missed_periods']);
        $this->assertSame(0, Invoice::query()->where('customer_id', $customer->id)->whereDate('period', '2026-10-01')->count());
        $this->assertSame(1, Invoice::query()->where('customer_id', $customer->id)->whereDate('period', '2026-11-01')->count());
        $row->refresh();
        $this->assertSame('cancelled', $row->status);
        $this->assertSame('admin_cancelled', $row->resolution);
        $this->assertSame($admin->id, $row->resolved_by);
        $this->assertNull($row->open_key);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'termination_deferral_cancelled', 'causer_id' => $admin->id]);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(
            'Terminate tertunda dibatalkan. Tagihan November 2026 dibuat. Bulan yang terlewat (Oktober 2026) tidak ditagih otomatis — buat manual jika perlu.',
            LifecycleTerminationService::cancelMessage($result),
        );
    }

    public function test_cancel_message_variants(): void
    {
        $this->assertSame('Terminate tertunda dibatalkan. Tagihan November 2026 sudah ada.', LifecycleTerminationService::cancelMessage(['invoice' => 'invoice_exists', 'period' => '2026-11', 'missed_periods' => []]));
        $this->assertSame('Terminate tertunda dibatalkan. Tidak ada tagihan dibuat (bulan bergabung).', LifecycleTerminationService::cancelMessage(['invoice' => 'invoice_skipped:join_month', 'period' => '2026-11', 'missed_periods' => []]));
        $this->assertSame('Terminate tertunda dibatalkan. Tidak ada tagihan dibuat (paket Rp0).', LifecycleTerminationService::cancelMessage(['invoice' => 'invoice_skipped:price_zero', 'period' => '2026-11', 'missed_periods' => []]));
        $this->assertSame('Terminate tertunda dibatalkan. Tidak ada tagihan dibuat (tanggal gabung kosong).', LifecycleTerminationService::cancelMessage(['invoice' => 'invoice_skipped:join_date_null', 'period' => '2026-11', 'missed_periods' => []]));
    }

    public function test_billing_held_follows_open_row_and_setting(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $service = app(LifecycleTerminationService::class);
        $this->assertFalse($service->isBillingHeld($customer));

        $row = $this->pendingRow($customer);
        $this->assertTrue($service->isBillingHeld($customer));
        $this->assertFalse($service->isBillingHeld($other));
        $this->assertSame([$customer->id], LifecycleTerminationService::heldCustomerIds([$customer->id, $other->id]));

        Setting::set(LifecycleTerminationService::SETTING_HOLD_BILLING, '0');
        Cache::flush();
        $this->assertFalse($service->isBillingHeld($customer));
        $this->assertSame([], LifecycleTerminationService::heldCustomerIds([$customer->id]));

        Setting::set(LifecycleTerminationService::SETTING_HOLD_BILLING, '1');
        Cache::flush();
        $row->forceFill(['hold_billing' => false])->save();
        $this->assertFalse($service->isBillingHeld($customer));
    }

    // ---- H1 / H2 invoices ------------------------------------------------

    public function test_ensure_for_period_returns_null_for_held_and_creates_after_release(): void
    {
        $customer = $this->customer();
        $row = $this->pendingRow($customer);
        $generator = app(InvoiceGenerationService::class);
        $period = Carbon::parse('2026-11-01', 'Asia/Jakarta');

        $this->assertNull($generator->ensureForPeriod($customer, $period));
        $this->assertSame(0, Invoice::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('pending_termination_hold', $generator->currentPeriodSkipReason($customer));

        $row->forceFill(['status' => 'closed', 'resolution' => 'customer_archived'])->save();
        $this->assertNotNull($generator->ensureForPeriod($customer, $period));
    }

    public function test_generate_command_reports_held_and_does_not_bill_them_in_dry_run_and_live(): void
    {
        $held = $this->customer();
        $normal = $this->customer();
        $this->pendingRow($held);

        $this->artisan('billing:generate', ['--month' => '2026-11', '--dry-run' => true])
            ->expectsOutputToContain('held_pending_termination=1 (tahan tagihan, not billed): '.$held->id)
            ->assertExitCode(0);
        $this->assertSame(0, Invoice::query()->count());

        $this->artisan('billing:generate', ['--month' => '2026-11'])->assertExitCode(0);
        $this->assertSame(0, Invoice::query()->where('customer_id', $held->id)->count());
        $this->assertSame(1, Invoice::query()->where('customer_id', $normal->id)->count());
    }

    public function test_generate_output_is_unchanged_when_no_pending_rows_exist(): void
    {
        $normal = $this->customer();

        Artisan::call('billing:generate', ['--month' => '2026-11', '--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringNotContainsString('held_pending_termination', $output);
        $this->assertStringContainsString('Generating invoice for: '.$normal->name, $output);

        Artisan::call('billing:generate', ['--catch-up' => true, '--dry-run' => true]);
        $catchUp = Artisan::output();
        $this->assertStringNotContainsString('pending_termination_hold', $catchUp);
        $this->assertStringContainsString('Summary: {"created":0,"would_create":1,"skipped_exists":0,"join_month":0,"join_date_null":0,"no_package":0,"price_zero":0}', $catchUp);

        // A pending row on ANOTHER customer changes nothing for this one.
        $this->pendingRow($this->customer());
        Artisan::call('billing:generate', ['--month' => '2026-11', '--dry-run' => true]);
        $this->assertStringContainsString('Generating invoice for: '.$normal->name, Artisan::output());
    }

    public function test_hold_created_after_prefetch_still_blocks_invoice_in_monthly_and_catch_up(): void
    {
        foreach (['monthly' => ['--month' => '2026-11'], 'catch-up' => ['--catch-up' => true]] as $label => $options) {
            $customer = $this->customer();
            $real = new InvoiceGenerationService;
            $racing = Mockery::mock(InvoiceGenerationService::class)->makePartial();
            $racing->shouldReceive('ensureForPeriod')->andReturnUsing(function (...$args) use ($customer, $real) {
                // The customer was not held when the chunk was prefetched; a deferral lands now.
                if (! $customer->pendingTermination()->exists()) {
                    $this->pendingRow($customer);
                }

                return $real->ensureForPeriod(...$args);
            });
            $this->app->instance(InvoiceGenerationService::class, $racing);

            $this->artisan('billing:generate', $options)->assertExitCode(0);

            $this->assertSame(0, Invoice::query()->where('customer_id', $customer->id)->count(), $label);
            $customer->pendingTermination()->update(['status' => 'closed']);
        }
    }

    public function test_catch_up_reports_held_with_reason(): void
    {
        $held = $this->customer();
        $this->pendingRow($held);

        $this->artisan('billing:generate', ['--catch-up' => true])
            ->expectsOutputToContain('pending_termination_hold (tahan tagihan, not billed): '.$held->id)
            ->assertExitCode(0);
        $this->assertSame(0, Invoice::query()->where('customer_id', $held->id)->count());
    }

    public function test_manual_invoice_for_held_customer_is_still_possible(): void
    {
        $held = $this->customer();
        $this->pendingRow($held);

        // H4: the hold only gates the automatic generators; a direct Invoice insert (manual path) works.
        $invoice = Invoice::create([
            'customer_id' => $held->id, 'period' => '2026-11-01', 'amount' => 100000,
            'due_date' => '2026-11-20', 'status' => 'unpaid', 'generated_at' => now(),
        ]);
        $this->assertNotNull($invoice->id);
    }

    // ---- H3 isolation ----------------------------------------------------

    public function test_held_customer_is_not_isolation_eligible_but_reconnect_unchanged(): void
    {
        $overdue = $this->customer();
        $this->invoice($overdue, '2026-10-01', '2026-10-05', 'unpaid');
        $service = app(BillingEligibilityService::class);

        $before = $service->evaluate($overdue);
        $this->assertTrue($before['isolation_eligible']);
        $this->assertFalse($before['billing_hold']);
        $this->assertNull($before['hold_reason']);

        $this->pendingRow($overdue);
        $after = $service->evaluate($overdue->fresh());
        $this->assertFalse($after['isolation_eligible']);
        $this->assertTrue($after['billing_hold']);
        $this->assertSame('pending_termination', $after['hold_reason']);

        // Isolated + paid current invoice: reconnect stays eligible while held.
        $isolated = $this->customer(['status' => 'isolated']);
        $this->invoice($isolated, '2026-11-01', '2026-11-20', 'paid');
        $this->assertTrue($service->evaluate($isolated)['reconnect_eligible']);
        $this->pendingRow($isolated);
        $held = $service->evaluate($isolated->fresh());
        $this->assertTrue($held['reconnect_eligible']);
        $this->assertTrue($held['billing_hold']);
    }

    public function test_check_overdue_counts_held_and_does_not_dispatch_isolation(): void
    {
        Queue::fake();
        Setting::set('billing_enforcement_dry_run', '0');
        Cache::flush();
        $held = $this->customer();
        $this->invoice($held, '2026-10-01', '2026-10-05', 'unpaid');
        $this->pendingRow($held);
        $plain = $this->customer();
        $this->invoice($plain, '2026-10-01', '2026-10-05', 'unpaid');

        $this->artisan('billing:check-overdue')->assertExitCode(0);

        $stats = Setting::get('billing_enforcement_last_run')['stats'] ?? [];
        $this->assertSame(1, $stats['billing_hold']);
        $this->assertSame(1, $stats['isolir_candidates']);
        Queue::assertPushed(BatchIsolateCustomersJob::class, function (BatchIsolateCustomersJob $job) use ($plain, $held): bool {
            $ids = array_column($job->items, 'customer_id');

            return $ids === [$plain->id] && ! in_array($held->id, $ids, true);
        });
    }

    public function test_queued_isolate_jobs_skip_a_held_customer(): void
    {
        $held = $this->customer();
        $router = Router::query()->firstOrFail();
        $invoice = $this->invoice($held, '2026-10-01', '2026-10-05', 'unpaid');
        $this->pendingRow($held);

        // Batch job: no MikroTik isolate, no ledger row, status unchanged.
        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->andReturnSelf();
        $mikrotik->shouldReceive('disconnect');
        $mikrotik->shouldNotReceive('isolateUser');
        $radius = Mockery::mock(RadiusUserService::class);
        $ledger = Mockery::mock(BillingEnforcementLedger::class);
        $ledger->shouldNotReceive('start');
        (new BatchIsolateCustomersJob($router->id, [['customer_id' => $held->id, 'invoice_id' => $invoice->id]]))
            ->handle($mikrotik, $radius, app(BillingEligibilityService::class), $ledger);
        $this->assertSame('active', $held->fresh()->status);

        // Single job: same, and the skip log names the hold.
        Log::spy();
        (new IsolateCustomerJob($held, $invoice->id))
            ->handle($mikrotik, $radius, app(BillingEligibilityService::class), $ledger);
        $this->assertSame('active', $held->fresh()->status);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === '[billing] isolate skipped by policy'
            && ($context['hold_reason'] ?? null) === 'pending_termination')->once();
    }

    public function test_ensure_current_invoice_job_skips_a_held_customer(): void
    {
        $held = $this->customer();
        $this->pendingRow($held);
        Log::spy();

        (new EnsureCustomerCurrentInvoiceJob($held->id, '2026-11-01'))->handle(app(InvoiceGenerationService::class));

        $this->assertSame(0, Invoice::query()->where('customer_id', $held->id)->count());
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === '[billing] activation invoice skipped'
            && ($context['reason'] ?? null) === 'pending_termination_hold')->once();
    }

    public function test_allow_resolves_an_existing_open_row_and_closes_it_for_already_terminated(): void
    {
        $service = app(LifecycleTerminationService::class);

        $customer = $this->customer();
        $row = $this->pendingRow($customer);
        $service->terminateOrDefer($customer, $this->allow(), 'warehouse_receive');
        $row->refresh();
        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertSame('terminated', $row->status);
        $this->assertSame('guard_passed', $row->resolution);
        $this->assertNull($row->open_key);

        $already = $this->customer(['status' => 'terminated']);
        $row2 = $this->pendingRow($already);
        $service->terminateOrDefer($already, $this->deny(), 'warehouse_receive');
        $row2->refresh();
        $this->assertSame('closed', $row2->status);
        $this->assertSame('already_terminated', $row2->resolution);
        $this->assertFalse($service->isBillingHeld($already));
    }

    public function test_cancel_survives_an_invoice_failure(): void
    {
        $customer = $this->customer();
        $row = $this->pendingRow($customer);
        $failing = Mockery::mock(InvoiceGenerationService::class)->makePartial();
        $failing->shouldReceive('ensureForPeriod')->andThrow(new \RuntimeException('deadlock'));
        $this->app->instance(InvoiceGenerationService::class, $failing);

        $result = app(LifecycleTerminationService::class)->cancel($row, User::factory()->create(['role' => 'admin']), 'pelanggan masih berlangganan');

        $this->assertSame('invoice_skipped:error', $result['invoice']);
        $this->assertSame('cancelled', $row->fresh()->status);
        $this->assertStringContainsString('Tidak ada tagihan dibuat', LifecycleTerminationService::cancelMessage($result));
    }

    public function test_reason_labels_cover_twin_and_takeover_codes(): void
    {
        $this->assertSame('ONU kembar belum terbukti online', LifecycleTerminationService::reasonLabel(['onu_twin:twin_not_online']));
        $this->assertSame('belum dievaluasi (ambil alih ONU)', LifecycleTerminationService::reasonLabel(['guard:not_evaluated_takeover']));
    }

    public function test_digest_held_onu_line_depends_on_redispatch_setting(): void
    {
        $customer = $this->customer();
        $this->pendingRow($customer);
        $actor = User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-'.$customer->id, 'customer_id' => $customer->id, 'status' => 'closed',
            'type' => 'other', 'priority' => 'normal', 'subject' => 'Dismantle',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('device_recovery_cases')->insert([
            'ticket_id' => $ticketId, 'customer_id' => $customer->id, 'status' => 'received',
            'created_by' => $actor->id, 'received_at' => now(), 'version' => 1,
            'meta' => json_encode(['olt_onu_delete' => ['ok' => false, 'reason' => 'guard_hold']]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);
        $this->assertStringContainsString('Hapus ONU ditahan: 1 case (perlu dicek manual).', Artisan::output());

        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        Cache::flush();
        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);
        $this->assertStringContainsString('Hapus ONU ditahan: 1 case (dicek ulang otomatis harian).', Artisan::output());
    }

    public function test_check_overdue_stats_have_no_hold_key_with_zero_rows(): void
    {
        $plain = $this->customer();
        $this->invoice($plain, '2026-10-01', '2026-10-05', 'unpaid');

        Artisan::call('billing:check-overdue', ['--dry-run' => true]);

        $this->assertStringNotContainsString('billing_hold', Artisan::output());
    }

    // ---- UI / cancel route ----------------------------------------------

    public function test_show_exposes_pending_termination_card_only_when_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $customer = $this->customer();

        $this->actingAs($admin)->get(route('customers.show', $customer))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Customers/Show')->where('pendingTermination', null));

        $this->pendingRow($customer, null, ['pppoe:pppoe_in_use']);
        $this->actingAs($admin)->get(route('customers.show', $customer))
            ->assertInertia(fn (Assert $page) => $page
                ->where('pendingTermination.source', 'warehouse_receive')
                ->where('pendingTermination.can_cancel', true)
                ->where('pendingTermination.last_reason_label', 'PPPoE masih aktif')
                ->where('pendingTermination.hold_billing', true));
    }

    public function test_index_flags_only_customers_with_a_pending_termination_without_n_plus_one(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $held = $this->customer(['name' => 'Held Customer']);
        $plain = $this->customer(['name' => 'Plain Customer']);
        $this->pendingRow($held);
        $resolved = $this->customer(['name' => 'Resolved Customer']);
        $this->pendingRow($resolved)->forceFill(['status' => 'cancelled'])->save();

        $this->actingAs($admin)->get(route('customers.index', ['limit' => 50]))->assertOk(); // warm-up (caches)
        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get(route('customers.index', ['limit' => 50]));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk()->assertInertia(function (Assert $page) use ($held, $plain, $resolved) {
            $rows = collect($page->toArray()['props']['customers']['data'])->keyBy('id');
            $this->assertTrue((bool) $rows[$held->id]['has_pending_termination']);
            $this->assertFalse((bool) $rows[$plain->id]['has_pending_termination']);
            $this->assertFalse((bool) $rows[$resolved->id]['has_pending_termination']);
        });

        // The flag is one subquery column: adding more customers must not add queries.
        foreach (range(1, 5) as $i) {
            $this->pendingRow($this->customer());
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get(route('customers.index', ['limit' => 50]))->assertOk();
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_cancel_route_permissions_validation_and_flash(): void
    {
        $customer = $this->customer();
        $row = $this->pendingRow($customer);
        $pid = $row->id;
        $url = route('customers.pending-termination.cancel', $customer);

        foreach (['teknisi', 'noc'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post($url, ['reason' => 'alasan yang cukup panjang', 'pending_id' => $pid])
                ->assertForbidden();
        }
        $this->assertTrue($customer->pendingTermination()->exists());

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post($url, ['reason' => 'pendek', 'pending_id' => $pid])->assertSessionHasErrors('reason');
        $this->assertTrue($customer->pendingTermination()->exists());

        // A row id other than the one the page showed is refused (nothing cancelled).
        $this->actingAs($admin)->post($url, ['reason' => 'pelanggan masih berlangganan', 'pending_id' => $pid + 999])
            ->assertSessionHas('error', 'Terminate tertunda sudah berubah. Muat ulang halaman lalu periksa lagi.');
        $this->assertTrue($customer->pendingTermination()->exists());

        $this->actingAs($admin)->post($url, ['reason' => 'pelanggan masih berlangganan', 'pending_id' => $pid])
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Terminate tertunda dibatalkan. Tagihan November 2026 dibuat.'));
        $this->assertFalse($customer->pendingTermination()->exists());

        // Superadmin may too; second cancel reports it is no longer active.
        $super = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($super)->post($url, ['reason' => 'sekali lagi untuk uji', 'pending_id' => $pid])
            ->assertSessionHas('error', 'Terminate tertunda sudah tidak aktif.');
    }

    // ---- digest command shell -------------------------------------------

    public function test_digest_dry_run_prints_t5_text(): void
    {
        $customer = $this->customer(['code' => 'CUST-PEND-1']);
        $this->pendingRow($customer, now()->subDays(20), ['pppoe:pppoe_in_use'], ['recovery_case_id' => 5]);

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('Terminate tertunda (tahan tagihan) — 10/11/2026 WIB', $out);
        $this->assertStringContainsString('Total 1 pelanggan · kemarin: +0 baru, 0 selesai diterminate, 0 dibatalkan.', $out);
        $this->assertStringContainsString('1. '.$customer->code.' '.$customer->name.' — sejak 21/10 (20 hari) · Gudang #5 · PPPoE masih aktif', $out);
        $this->assertStringContainsString('Lebih dari 14 hari: 1 pelanggan — perlu dicek manual (perangkat sudah di gudang tapi masih terdeteksi dipakai).', $out);
        $this->assertStringContainsString('Selama tertunda: tagihan baru TIDAK dibuat dan pelanggan TIDAK diisolir. Jika pelanggan masih berlangganan, batalkan di Pelanggan › Detail.', $out);
        $this->assertStringContainsString('digest: dry-run, not sent.', $out);
    }

    public function test_digest_is_silent_when_nothing_pending_or_changed(): void
    {
        $this->artisan('customers:finalize-deferred-terminations', ['--digest' => true])
            ->expectsOutputToContain('nothing pending and nothing changed')
            ->assertExitCode(0);
    }

    public function test_digest_caps_lines_at_thirty(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->pendingRow($this->customer());
        }

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('30. ', $out);
        $this->assertStringNotContainsString('31. ', $out);
        $this->assertStringContainsString('dan 2 lainnya', $out);
    }

    public function test_command_is_scheduled_since_4b(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('finalize-deferred-terminations', Artisan::output());
    }

    // ---- helpers ---------------------------------------------------------

    private function customer(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'PEND-100'], ['name' => 'Paket 100', 'price' => 100000]);
        $router = Router::firstOrCreate(['name' => 'Pending router'], ['ip_address' => '192.0.2.77', 'username' => 't', 'password' => 't', 'is_active' => true]);

        return Customer::create(array_merge([
            'name' => 'Pending Test '.$this->seq,
            'phone' => '0813222'.$this->seq,
            'address' => 'RT 1 / RW 1',
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'pppoe_user' => 'pending-test-'.$this->seq,
            'join_date' => '2026-01-05',
            'due_day' => 5,
        ], $attributes));
    }

    /** @param list<string> $reasons */
    private function pendingRow(Customer $customer, ?Carbon $requestedAt = null, array $reasons = ['pppoe:pppoe_in_use'], array $extra = []): CustomerPendingTermination
    {
        return CustomerPendingTermination::create(array_merge([
            'customer_id' => $customer->id,
            'source' => 'warehouse_receive',
            'status' => 'pending',
            'hold_billing' => true,
            'requested_at' => $requestedAt ?? now(),
            'first_verdict' => 'deny',
            'last_verdict' => 'deny',
            'first_reasons' => $reasons,
            'last_reasons' => $reasons,
            'last_checked_at' => now()->subMinutes(5),
            'check_count' => 1,
        ], $extra));
    }

    private function invoice(Customer $customer, string $period, string $due, string $status): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id, 'period' => $period, 'amount' => 100000,
            'due_date' => $due, 'status' => $status, 'generated_at' => now()->subMonth(),
        ]);
    }

    private function allow(): GuardDecision
    {
        return GuardDecision::fromSignals('terminate', [GuardSignal::clear(GuardSignal::PPPOE, 'pppoe_offline_fresh', '')]);
    }

    private function deny(): GuardDecision
    {
        return GuardDecision::fromSignals('terminate', [GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', 'Sesi PPPoE masih aktif.')]);
    }

    private function unknown(): GuardDecision
    {
        return GuardDecision::fromSignals('terminate', [GuardSignal::unknown(GuardSignal::TRAFFIC, 'traffic_unknown', 'Trafik tidak dapat dipastikan.')]);
    }
}
