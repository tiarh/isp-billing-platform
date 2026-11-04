<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Lifecycle\GuardSignal;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\OpsAlertService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-4b (A9): the deferred-termination sweeper. Part 1 re-checks
 * pending rows (report-only by default), part 2 re-dispatches held ONU deletes
 * (off by default). The guard is never overridden and a row is never cancelled.
 */
#[Group('blocking-core')]
class CustomersFinalizeDeferredTerminationsTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 03:00:00', 'UTC')); // 10:00 WIB
        Bus::fake();
        foreach ([9, 10] as $id) {
            DB::table('olts')->insert(['id' => $id, 'name' => 'OLT '.$id, 'code' => 'OLT-'.$id, 'vendor' => 'zte_c300', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- part 1: modes ---------------------------------------------------

    public function test_default_is_report_only_it_records_the_verdict_but_terminates_nothing(): void
    {
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer();
        $row = $this->pending($customer, lastChecked: null, checks: 0);

        $this->assertSame(0, Artisan::call('customers:finalize-deferred-terminations'));
        $out = Artisan::output();

        $this->assertStringContainsString('would finalize (report-only', $out);
        $this->assertStringContainsString('mode=report', $out);
        $this->assertSame('active', $customer->fresh()->status);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame('allow', $row->last_verdict);
        $this->assertSame(1, $row->check_count);
        $this->assertNotNull($row->last_checked_at);
        $this->assertSame($customer->id, (int) $row->open_key, 'sweeper must go through save() so open_key stays set');
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_dry_run_writes_nothing_even_with_apply(): void
    {
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer();
        $row = $this->pending($customer, lastChecked: null, checks: 0);

        Artisan::call('customers:finalize-deferred-terminations', ['--dry-run' => true, '--apply' => true]);

        $out = Artisan::output();
        $this->assertStringContainsString('mode=dry-run', $out);
        $this->assertStringContainsString('dry-run: nothing written', $out);
        $this->assertSame('active', $customer->fresh()->status);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, $row->check_count);
        $this->assertNull($row->last_checked_at);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'terminated_deferred')->count());
    }

    public function test_apply_setting_finalizes_when_the_guard_allows(): void
    {
        Setting::set('lifecycle_deferred_sweeper_apply', '1');
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer();
        $row = $this->pending($customer, source: 'recap_receive');

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertStringContainsString('mode=apply', Artisan::output());
        $this->assertSame('terminated', $customer->fresh()->status);
        $row->refresh();
        $this->assertSame('terminated', $row->status);
        $this->assertSame('guard_passed', $row->resolution);
        $this->assertNull($row->open_key);
        $this->assertDatabaseHas('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_deferred']);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class); // recap rows never dispatch an OLT delete
    }

    public function test_apply_flag_forces_apply_without_the_setting(): void
    {
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer();
        $this->pending($customer, source: 'recap_receive');

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);

        $this->assertSame('terminated', $customer->fresh()->status);
    }

    public function test_apply_dispatches_the_onu_delete_for_a_warehouse_row_once_the_guard_passes(): void
    {
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer(['olt_id' => 9, 'onu_serial' => 'FHTTSWP00001']);
        $case = $this->receivedCase($customer);
        $this->pending($customer, source: 'warehouse_receive', extra: ['recovery_case_id' => $case]);

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);

        $this->assertSame('terminated', $customer->fresh()->status);
        Bus::assertDispatched(DeleteDismantledOnuJob::class, fn (DeleteDismantledOnuJob $job): bool => (int) $job->case->id === $case);
    }

    public function test_no_onu_job_when_cleanup_is_disabled_or_the_delete_already_succeeded(): void
    {
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer(['olt_id' => 9]);
        $case = $this->receivedCase($customer);
        $this->pending($customer, extra: ['recovery_case_id' => $case]);

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);
        $this->assertSame('terminated', $customer->fresh()->status);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);

        $this->enableCleanup();
        $done = $this->customer(['olt_id' => 9]);
        $doneCase = $this->receivedCase($done, ['olt_onu_delete' => ['ok' => true, 'reason' => 'deleted']]);
        $this->pending($done, extra: ['recovery_case_id' => $doneCase]);
        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);
        $this->assertSame('terminated', $done->fresh()->status);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_at_most_one_onu_delete_per_olt_per_run(): void
    {
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $first = $this->customer(['olt_id' => 9]);
        $second = $this->customer(['olt_id' => 9]);
        $other = $this->customer(['olt_id' => 10]);
        foreach ([$first, $second, $other] as $customer) {
            $this->pending($customer, lastChecked: null, checks: 0, extra: ['recovery_case_id' => $this->receivedCase($customer)]);
        }

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);

        $this->assertStringContainsString('waiting_olt_slot', Artisan::output());
        $this->assertSame(2, collect([$first, $second, $other])->filter(fn (Customer $c): bool => $c->fresh()->status === 'terminated')->count());
        $this->assertSame(1, CustomerPendingTermination::query()->pending()->count());
        Bus::assertDispatchedTimes(DeleteDismantledOnuJob::class, 2);

        // Next run (a new slot) takes the waiting one.
        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);
        $this->assertSame(0, CustomerPendingTermination::query()->pending()->count());
        Bus::assertDispatchedTimes(DeleteDismantledOnuJob::class, 3);
    }

    public function test_deny_and_unknown_stay_pending_and_are_never_cancelled_or_overridden(): void
    {
        Setting::set('lifecycle_deferred_sweeper_apply', '1');
        $this->denyCustomerLifecycleGuard();
        $denied = $this->customer();
        $deniedRow = $this->pending($denied);

        Artisan::call('customers:finalize-deferred-terminations');
        $this->assertSame('active', $denied->fresh()->status);
        $this->assertSame('pending', $deniedRow->fresh()->status);
        $this->assertSame('deny', $deniedRow->fresh()->last_verdict);

        $this->stubCustomerLifecycleGuard(GuardDecision::fromSignals('terminate', [
            GuardSignal::unknown(GuardSignal::TRAFFIC, 'traffic_unknown', 'Trafik tidak dapat dipastikan.'),
        ]));
        Artisan::call('customers:finalize-deferred-terminations');
        $this->assertSame('active', $denied->fresh()->status);
        $this->assertSame('unknown', $deniedRow->fresh()->last_verdict);
        $this->assertSame(3, $deniedRow->fresh()->check_count); // seeded 1 + two sweeps
        $this->assertSame(0, CustomerPendingTermination::query()->where('status', 'cancelled')->count());
    }

    public function test_guard_that_throws_keeps_the_row_pending(): void
    {
        Setting::set('lifecycle_deferred_sweeper_apply', '1');
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $customer = $this->customer();
        $row = $this->pending($customer);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame('unknown', $row->fresh()->last_verdict);
    }

    public function test_summary_and_log_carry_error_counts_and_exception_classes(): void
    {
        $service = Mockery::mock(LifecycleTerminationService::class)->makePartial();
        $service->shouldReceive('preVerdict')->andThrow(new \LogicException('boom secret'));
        $this->app->instance(LifecycleTerminationService::class, $service);
        $customer = $this->customer();
        $row = $this->pending($customer);
        Log::spy();

        $exit = Artisan::call('customers:finalize-deferred-terminations');
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('errors=1 error_reasons=LogicException:1', $out);
        $this->assertStringNotContainsString('boom secret', $out, 'only the exception class is reported, never the message');
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === '[lifecycle] deferred terminations sweep'
            && ($context['errors'] ?? null) === 1
            && ($context['error_reasons'] ?? null) === [\LogicException::class => 1]
            && ($context['error_row_ids'] ?? null) === [(int) $row->id])->once();
        $this->assertSame('active', $customer->fresh()->status, 'report-only: nothing terminated');
    }

    public function test_summary_shows_no_error_reasons_on_a_clean_run(): void
    {
        $this->pending($this->customer());

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertStringContainsString('errors=0 error_reasons=-', Artisan::output());
    }

    public function test_rows_of_archived_or_already_terminated_customers_are_closed(): void
    {
        $this->allowCustomerLifecycleGuard();
        $archived = $this->customer();
        $archivedRow = $this->pending($archived);
        $archived->delete();
        $done = $this->customer(['status' => 'terminated']);
        $doneRow = $this->pending($done);

        Artisan::call('customers:finalize-deferred-terminations');

        $archivedRow->refresh();
        $doneRow->refresh();
        $this->assertSame('closed', $archivedRow->status);
        $this->assertSame('customer_archived', $archivedRow->resolution);
        $this->assertNull($archivedRow->open_key);
        $this->assertSame('closed', $doneRow->status);
        $this->assertSame('already_terminated', $doneRow->resolution);
    }

    public function test_dry_run_does_not_close_rows(): void
    {
        $done = $this->customer(['status' => 'terminated']);
        $row = $this->pending($done);

        Artisan::call('customers:finalize-deferred-terminations', ['--dry-run' => true]);

        $this->assertStringContainsString('would_close already_terminated', Artisan::output());
        $this->assertSame('pending', $row->fresh()->status);
    }

    public function test_kill_switch_stops_everything_but_the_digest(): void
    {
        Setting::set('lifecycle_deferred_sweeper_enabled', '0');
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->never();
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $customer = $this->customer();
        $row = $this->pending($customer);

        $this->artisan('customers:finalize-deferred-terminations', ['--apply' => true])
            ->expectsOutputToContain('sweeper disabled')
            ->assertExitCode(0);

        $this->assertSame(1, $row->fresh()->check_count);
        $this->assertSame('active', $customer->fresh()->status);
    }

    // ---- part 1: takeover / reused context -------------------------------

    public function test_takeover_row_with_a_valid_new_account_waives_the_onu_signal_only_then(): void
    {
        $seen = [];
        $this->captureContexts($seen);
        $old = $this->customer(['onu_serial' => 'FHTTTKO00001', 'status' => 'isolated']);
        $new = $this->customer(['onu_serial' => 'FHTTTKO00001', 'status' => 'active']);
        $this->pending($old, source: 'takeover', extra: ['takeover_customer_id' => $new->id]);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame([[$old->id, $new->id]], $seen);
        $this->assertStringNotContainsString('takeover_link_broken', Artisan::output());
    }

    public function test_takeover_link_broken_uses_the_full_context_and_is_flagged(): void
    {
        $seen = [];
        $this->captureContexts($seen);
        $old = $this->customer(['onu_serial' => 'FHTTTKO00002', 'status' => 'isolated']);
        $new = $this->customer(['onu_serial' => 'FHTTOTHER0002', 'status' => 'active']); // serial moved away
        $row = $this->pending($old, source: 'takeover', extra: ['takeover_customer_id' => $new->id]);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame([[$old->id, null]], $seen);
        $this->assertStringContainsString('takeover_link_broken', Artisan::output());
        $this->assertTrue($row->fresh()->meta['takeover_link_broken']);
    }

    public function test_recap_reused_row_uses_the_owner_account_like_a_takeover(): void
    {
        $seen = [];
        $this->captureContexts($seen);
        $old = $this->customer(['onu_serial' => 'FHTTRCP00001']);
        $owner = $this->customer(['onu_serial' => 'FHTTRCP00001']);
        $this->pending($old, source: 'recap_reused', extra: ['takeover_customer_id' => $owner->id]);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame([[$old->id, $owner->id]], $seen);
    }

    // ---- part 2: held ONU delete re-dispatch -----------------------------

    public function test_redispatch_off_skips_the_whole_part_two_scan_with_no_guard_calls(): void
    {
        $this->enableCleanup();
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->never();
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $case = $this->heldCase();

        Artisan::call('customers:finalize-deferred-terminations');
        Artisan::call('customers:finalize-deferred-terminations', ['--dry-run' => true]);

        $this->assertStringNotContainsString("held-onu #{$case}", Artisan::output());
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_apply_does_not_terminate_a_customer_that_is_online_under_the_lock(): void
    {
        Setting::set('lifecycle_deferred_sweeper_apply', '1');
        $this->allowCustomerLifecycleGuard(); // verdict computed "outside": allow
        $customer = $this->customer();
        $customer->forceFill(['is_online' => true])->save(); // session came back before the lock
        $row = $this->pending($customer, source: 'recap_receive');

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame('active', $customer->fresh()->status);
        $row->refresh();
        $this->assertSame('pending', $row->status);
        $this->assertSame(['pppoe:online_at_finalize'], $row->last_reasons);
        $this->assertSame($customer->id, (int) $row->open_key);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $customer->id, 'event' => 'terminated_deferred']);
    }

    public function test_customers_without_olt_id_share_one_slot_per_run(): void
    {
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $a = $this->customer(['olt_id' => null]);
        $b = $this->customer(['olt_id' => null]);
        foreach ([$a, $b] as $customer) {
            $this->pending($customer, lastChecked: null, checks: 0, extra: ['recovery_case_id' => $this->receivedCase($customer)]);
        }

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);

        Bus::assertDispatchedTimes(DeleteDismantledOnuJob::class, 1);
        $this->assertSame(1, CustomerPendingTermination::query()->pending()->count());
    }

    public function test_stagger_counts_only_onu_jobs_and_non_terminable_rows_rotate(): void
    {
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $recap = $this->customer();
        $this->pending($recap, source: 'recap_receive', lastChecked: null, checks: 0); // finalized, no ONU job
        $wh = $this->customer(['olt_id' => 9]);
        $this->pending($wh, lastChecked: null, checks: 0, extra: ['recovery_case_id' => $this->receivedCase($wh)]);
        $stuck = $this->customer(['status' => 'pending_installation']);
        $stuckRow = $this->pending($stuck, lastChecked: null, checks: 0);

        Artisan::call('customers:finalize-deferred-terminations', ['--apply' => true]);

        $delay = null;
        Bus::assertDispatched(DeleteDismantledOnuJob::class, function (DeleteDismantledOnuJob $job) use (&$delay): bool {
            $delay = $job->delay instanceof \DateTimeInterface ? $job->delay->getTimestamp() : (int) $job->delay;

            return true;
        });
        $this->assertSame(now()->getTimestamp(), $delay, 'first ONU job has no extra delay');
        $this->assertNotNull($stuckRow->fresh()->last_checked_at, 'not-terminable rows are touched so they rotate');
        $this->assertSame(1, $stuckRow->fresh()->check_count);
    }

    public function test_recap_reused_link_compares_the_case_serial_not_only_the_stored_one(): void
    {
        $seen = [];
        $this->captureContexts($seen);
        $old = $this->customer(['onu_serial' => 'FHTTSTORED001']);
        $owner = $this->customer(['onu_serial' => 'FHTTRECAP0001']);
        $caseId = $this->receivedCase($old);
        DB::table('device_recovery_items')->insert([
            'recovery_case_id' => $caseId, 'device_type' => 'onu', 'expected_serial_number' => 'FHTTRECAP0001',
            'captured_serial_number' => 'FHTTRECAP0001', 'status' => 'received', 'condition' => 'good',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->pending($old, source: 'recap_reused', extra: ['takeover_customer_id' => $owner->id, 'recovery_case_id' => $caseId]);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertSame([[$old->id, $owner->id]], $seen);
    }

    public function test_redispatch_dispatches_staggered_one_per_olt_when_enabled(): void
    {
        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $a = $this->heldCase(9);
        $b = $this->heldCase(9);
        $c = $this->heldCase(10);

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertStringContainsString('waiting_olt_slot', Artisan::output());
        Bus::assertDispatchedTimes(DeleteDismantledOnuJob::class, 2);
        $delays = [];
        Bus::assertDispatched(DeleteDismantledOnuJob::class, function (DeleteDismantledOnuJob $job) use (&$delays): bool {
            $delays[(int) $job->case->id] = $job->delay instanceof \DateTimeInterface ? $job->delay->getTimestamp() : (int) $job->delay;

            return true;
        });
        $this->assertArrayHasKey($a, $delays);
        $this->assertArrayHasKey($c, $delays);
        $this->assertArrayNotHasKey($b, $delays, 'second case of the same OLT waits for the next run');
        $this->assertSame(120, $delays[$c] - $delays[$a], 'dispatches are staggered by 120 s');
    }

    public function test_redispatch_never_runs_in_dry_run_or_when_cleanup_is_disabled(): void
    {
        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        $this->allowCustomerLifecycleGuard();
        $this->heldCase();

        Artisan::call('customers:finalize-deferred-terminations'); // cleanup disabled
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);

        $this->enableCleanup();
        Artisan::call('customers:finalize-deferred-terminations', ['--dry-run' => true]);
        $this->assertStringContainsString('would_redispatch (dry-run)', Artisan::output());
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_redispatch_skips_recent_in_use_pending_and_excluded_reasons(): void
    {
        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();

        $recent = $this->heldCase(9, at: now()->subHours(2));
        $online = $this->heldCase(9, reason: 'onu_online');
        $mismatch = $this->heldCase(9, reason: 'live_serial_mismatch');
        $withPending = $this->heldCase(9);
        $this->pending(Customer::query()->find(DB::table('device_recovery_cases')->where('id', $withPending)->value('customer_id')));
        $liveOwner = $this->heldCase(9);
        $liveCustomerId = (int) DB::table('device_recovery_cases')->where('id', $liveOwner)->value('customer_id');
        $this->customer(['onu_serial' => (string) Customer::query()->find($liveCustomerId)->onu_serial, 'status' => 'active']);

        // dry-run: part 1 must not close the pending row of the (terminated) test customer.
        Artisan::call('customers:finalize-deferred-terminations', ['--dry-run' => true]);
        $out = Artisan::output();

        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
        $this->assertStringNotContainsString("held-onu #{$recent}", $out);
        $this->assertStringNotContainsString("held-onu #{$online}", $out);
        $this->assertStringNotContainsString("held-onu #{$mismatch}", $out);
        $this->assertStringContainsString("held-onu #{$withPending} -> skip customer_pending_termination", $out);
        $this->assertStringContainsString("held-onu #{$liveOwner} -> skip serial_in_use", $out);
    }

    public function test_redispatch_skips_while_pppoe_or_traffic_is_still_in_use(): void
    {
        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        $this->enableCleanup();
        $this->denyCustomerLifecycleGuard();
        $case = $this->heldCase();

        Artisan::call('customers:finalize-deferred-terminations');

        $this->assertStringContainsString("held-onu #{$case} -> skip in_use_pppoe", Artisan::output());
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_redispatch_is_at_most_once_per_case_per_window(): void
    {
        Setting::set('lifecycle_deferred_onu_redispatch_apply', '1');
        $this->enableCleanup();
        $this->allowCustomerLifecycleGuard();
        $this->heldCase();

        Artisan::call('customers:finalize-deferred-terminations');
        Artisan::call('customers:finalize-deferred-terminations');

        Bus::assertDispatchedTimes(DeleteDismantledOnuJob::class, 1);
    }

    // ---- digest fixes (L4, N5) -------------------------------------------

    public function test_digest_stays_within_3500_chars_and_keeps_the_footer(): void
    {
        $long = str_repeat('Nama Pelanggan Sangat Panjang ', 4);
        for ($i = 0; $i < 40; $i++) {
            $this->pending($this->customer(['name' => $long.$i]), reasons: ['pppoe:pppoe_in_use', 'onu:onu_in_use', 'traffic:traffic_in_use']);
        }

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);
        $out = Artisan::output();
        $message = trim(explode('digest: dry-run, not sent.', $out)[0]);

        $this->assertLessThanOrEqual(3500, mb_strlen($message));
        $this->assertStringContainsString('Selama tertunda: tagihan baru TIDAK dibuat dan pelanggan TIDAK diisolir. Jika pelanggan masih berlangganan, batalkan di Pelanggan › Detail.', $message);
        $this->assertMatchesRegularExpression('/dan \d+ lainnya/', $message);
        $this->assertStringContainsString('Total 40 pelanggan', $message);
    }

    public function test_digest_line_has_no_double_space_when_the_name_is_empty(): void
    {
        $customer = $this->customer(['name' => '']);
        $this->pending($customer);

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true, '--dry-run' => true]);

        $out = Artisan::output();
        $this->assertStringContainsString('1. '.($customer->code ?: '#'.$customer->id).' — sejak', $out);
        $this->assertStringNotContainsString('  —', $out);
    }

    // ---- L2: missed periods ----------------------------------------------

    public function test_missed_periods_include_the_requested_month_when_requested_before_the_monthly_run(): void
    {
        $customer = $this->customer(['join_date' => '2026-01-05']);
        $service = app(LifecycleTerminationService::class);
        $current = CarbonImmutable::parse('2026-11-01', 'Asia/Jakarta');

        // 1 Oct 06:30 WIB: before the 07:00 monthly run, so October was held too.
        $early = CarbonImmutable::parse('2026-10-01 06:30:00', 'Asia/Jakarta')->utc();
        $this->assertSame(['2026-10'], $service->missedPeriods($customer, $early, $current));

        // 1 Oct 08:00 WIB: the run already happened before the request.
        $late = CarbonImmutable::parse('2026-10-01 08:00:00', 'Asia/Jakarta')->utc();
        $this->assertSame([], $service->missedPeriods($customer, $late, $current));

        // Mid-month request: next month's run is the first missed one.
        $mid = CarbonImmutable::parse('2026-09-15 10:00:00', 'Asia/Jakarta')->utc();
        $this->assertSame(['2026-10'], $service->missedPeriods($customer, $mid, $current));
    }

    // ---- L6 / schedule ---------------------------------------------------

    public function test_pending_row_can_be_reopened_after_the_sweeper_resolved_it(): void
    {
        Setting::set('lifecycle_deferred_sweeper_apply', '1');
        $this->allowCustomerLifecycleGuard();
        $customer = $this->customer();
        $this->pending($customer, source: 'recap_receive');
        Artisan::call('customers:finalize-deferred-terminations');
        $this->assertSame(0, CustomerPendingTermination::query()->pending()->count());

        $second = $this->pending($customer);
        $this->assertSame($customer->id, (int) $second->open_key);
    }

    public function test_schedule_entries_exist_in_monitoring_mode_with_the_agreed_shape(): void
    {
        config(['services.ebilling_scheduler.mode' => 'monitoring']);
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        ScheduleFacade::clearResolvedInstance(Schedule::class);
        require base_path('routes/console.php');

        $events = array_values(array_filter(
            $schedule->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'customers:finalize-deferred-terminations'),
        ));
        $this->assertCount(2, $events);

        $sweeper = collect($events)->first(fn (Event $e): bool => ! str_contains((string) $e->command, '--digest'));
        $digest = collect($events)->first(fn (Event $e): bool => str_contains((string) $e->command, '--digest'));
        $this->assertSame('41 * * * *', $sweeper->expression);
        $this->assertSame('22 8 * * *', $digest->expression);
        foreach ([$sweeper, $digest] as $event) {
            $this->assertSame('Asia/Jakarta', (string) $event->timezone);
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
        }
    }

    public function test_digest_still_sends_through_the_ops_group_with_a_daily_dedupe_key(): void
    {
        $this->pending($this->customer());
        $captured = [];
        $ops = Mockery::mock(OpsAlertService::class);
        $ops->shouldReceive('send')->once()->andReturnUsing(function (string $message, string $key, int $ttl) use (&$captured): bool {
            $captured = [$key, $ttl];

            return true;
        });
        $this->app->instance(OpsAlertService::class, $ops);

        Artisan::call('customers:finalize-deferred-terminations', ['--digest' => true]);

        $this->assertSame(['deferred-termination-digest:20261110', 86400], $captured);
    }

    // ---- helpers ---------------------------------------------------------

    /** @param list<array{0: int, 1: int|null}> $seen */
    private function captureContexts(array &$seen): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->andReturnUsing(function (Customer $customer, $context) use (&$seen): GuardDecision {
            $seen[] = [(int) $customer->id, $context->onuReusedByCustomerId];

            return GuardDecision::fromSignals('terminate', [GuardSignal::inUse(GuardSignal::PPPOE, 'pppoe_in_use', 'Sesi PPPoE masih aktif.')]);
        });
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
    }

    private function enableCleanup(): void
    {
        config(['device-recovery.mode' => 'write']);
        Setting::set('device_recovery_olt_delete_enabled', '1', 'boolean', 'ops');
    }

    private function customer(array $attributes = []): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'SWP-100'], ['name' => 'Paket 100', 'price' => 100000]);
        $router = Router::firstOrCreate(['name' => 'Sweeper router'], ['ip_address' => '192.0.2.78', 'username' => 't', 'password' => 't', 'is_active' => true]);

        return Customer::create(array_merge([
            'name' => 'Sweeper Test '.$this->seq,
            'phone' => '0813333'.$this->seq,
            'address' => 'RT 1 / RW 1',
            'package_id' => $package->id,
            'status' => 'active',
            'router_id' => $router->id,
            'pppoe_user' => 'sweeper-test-'.$this->seq,
            'onu_serial' => 'FHTTSWX'.str_pad((string) $this->seq, 5, '0', STR_PAD_LEFT),
            'join_date' => '2026-01-05',
            'due_day' => 5,
        ], $attributes));
    }

    /** @param list<string> $reasons */
    private function pending(Customer $customer, string $source = 'warehouse_receive', ?Carbon $lastChecked = null, int $checks = 1, array $reasons = ['pppoe:pppoe_in_use'], array $extra = []): CustomerPendingTermination
    {
        return CustomerPendingTermination::create(array_merge([
            'customer_id' => $customer->id,
            'source' => $source,
            'status' => 'pending',
            'hold_billing' => true,
            'requested_at' => now()->subDay(),
            'first_verdict' => 'deny',
            'last_verdict' => 'deny',
            'first_reasons' => $reasons,
            'last_reasons' => $reasons,
            'last_checked_at' => $checks === 0 ? null : ($lastChecked ?? now()->subHours(2)),
            'check_count' => $checks,
        ], $extra));
    }

    /** @param array<string, mixed> $meta @return int case id */
    private function receivedCase(Customer $customer, array $meta = []): int
    {
        $actor = User::query()->first() ?? User::factory()->create();
        $ticketId = DB::table('support_tickets')->insertGetId([
            'code' => 'TCK-SWP-'.$customer->id, 'customer_id' => $customer->id, 'status' => 'closed',
            'type' => 'dismantle', 'priority' => 'normal', 'subject' => 'Dismantle',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('device_recovery_cases')->insertGetId([
            'ticket_id' => $ticketId, 'customer_id' => $customer->id, 'status' => 'received',
            'created_by' => $actor->id, 'received_at' => now()->subDay(), 'version' => 1,
            'meta' => $meta === [] ? null : json_encode($meta),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A received case whose ONU delete was held; customer terminated (nobody live owns the serial). */
    private function heldCase(int $oltId = 9, string $reason = 'guard_hold', ?Carbon $at = null): int
    {
        $customer = $this->customer(['status' => 'terminated', 'olt_id' => $oltId]);

        return $this->receivedCase($customer, ['olt_onu_delete' => [
            'ok' => false,
            'reason' => $reason,
            'serial' => (string) $customer->onu_serial,
            'at' => ($at ?? now()->subHours(30))->toIso8601String(),
        ]]);
    }
}
