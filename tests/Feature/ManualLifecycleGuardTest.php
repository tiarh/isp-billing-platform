<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Support\SimpleXlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-3: the MANUAL terminate/archive paths (customer archive,
 * status -> terminated, ticket dismantle approval, archive artisan commands)
 * are behind the CustomerLifecycleGuard. Real guard, real signals: only the
 * Grafana read model and the clock are faked.
 */
#[Group('blocking-core')]
class ManualLifecycleGuardTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'Perangkat sudah dicabut teknisi, sesi PPPoE sisa router.';

    private Router $router;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);

        $this->router = Router::create(['name' => 'MG', 'ip_address' => '127.0.33.1', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
        // Rollup heartbeat: the router's pppoe_usage_daily is alive, so "no traffic" is proof.
        foreach (['2026-10-14', '2026-10-13'] as $day) {
            DB::table('pppoe_usage_daily')->insert([
                'usage_date_wib' => $day, 'router_id' => $this->router->id, 'pppoe_user' => 'hb', 'customer_id' => null,
                'download_bytes' => 0, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
                'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function customer(string $status, bool $online, bool $freshFlag = true): Customer
    {
        $n = ++$this->seq;
        $package = Package::firstOrCreate(['code' => 'MG-PKG'], ['name' => 'Guard', 'price' => 100000]);

        return Customer::create([
            'code' => 'MG-'.$n, 'name' => 'Nama MG'.$n, 'phone' => '0815'.abs(crc32('mg'.$n)), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-mg'.$n,
            'router_id' => $this->router->id, 'is_online' => $online,
            'pppoe_online_checked_at' => $freshFlag ? now()->subMinutes(2) : now()->subHours(3),
        ]);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function updatePayload(Customer $customer, string $status, array $extra = []): array
    {
        return array_merge([
            'name' => $customer->name, 'address' => $customer->address, 'package_id' => $customer->package_id,
            'status' => $status,
        ], $extra);
    }

    // ------------------------------------------------------------------
    // A1 destroy (archive)
    // ------------------------------------------------------------------

    public function test_suharto_regression_isolated_and_online_customer_cannot_be_archived(): void
    {
        $customer = $this->customer('isolated', true);

        $response = $this->actingAs($this->admin())->delete(route('customers.destroy', $customer));

        $response->assertSessionHasErrors('lifecycle_guard');
        $message = session('errors')->first('lifecycle_guard');
        $this->assertStringContainsString('Pelanggan masih dipakai, tidak bisa diarsip:', $message);
        $this->assertStringContainsString('Sesi PPPoE masih aktif (MG', $message);
        $this->assertStringContainsString('Jika yakin, minta superadmin override dengan alasan tertulis.', $message);
        $fresh = Customer::withTrashed()->find($customer->id);
        $this->assertNull($fresh->deleted_at);
        $this->assertSame('isolated', $fresh->status);
        $this->assertSame(0, Activity::where('description', 'customer_archived')->count());
    }

    public function test_unknown_status_is_denied_fail_closed(): void
    {
        // PPPoE flag stale + Grafana unavailable => unknown.
        $customer = $this->customer('isolated', false, false);

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer))
            ->assertSessionHasErrors('lifecycle_guard');

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
        $this->assertStringContainsString('Status PPPoE tidak diketahui', session('errors')->first('lifecycle_guard'));
    }

    public function test_a_guard_that_throws_denies(): void
    {
        $customer = $this->customer('isolated', false);
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canArchive')->andThrow(new \RuntimeException('boom'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer))
            ->assertSessionHasErrors('lifecycle_guard');

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
    }

    public function test_clear_customer_is_archived_without_override(): void
    {
        $customer = $this->customer('isolated', false);

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer))
            ->assertRedirect(route('customers.index'))
            ->assertSessionHasNoErrors();

        $fresh = Customer::withTrashed()->find($customer->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame('terminated', $fresh->status);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
        $this->assertSame(1, Activity::where('description', 'customer_archived')->count());
    }

    public function test_active_customer_still_refused_before_the_guard(): void
    {
        $customer = $this->customer('active', false);

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer))
            ->assertSessionHas('error');

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
    }

    public function test_superadmin_override_archives_and_logs_reason_and_guard_evidence(): void
    {
        $customer = $this->customer('isolated', true);
        $actor = $this->superadmin();

        $this->actingAs($actor)->delete(route('customers.destroy', $customer), [
            'override' => true,
            'override_reason' => self::REASON,
        ])->assertRedirect(route('customers.index'));

        $fresh = Customer::withTrashed()->find($customer->id);
        $this->assertNotNull($fresh->deleted_at);
        $this->assertSame('terminated', $fresh->status);

        $log = Activity::where('event', 'lifecycle_guard_override')->get();
        $this->assertCount(1, $log);
        $this->assertSame($customer->id, (int) $log[0]->subject_id);
        $this->assertSame($actor->id, (int) $log[0]->causer_id);
        $this->assertSame(self::REASON, $log[0]->properties['reason']);
        $this->assertSame('archive', $log[0]->properties['action']);
        $this->assertSame('deny', $log[0]->properties['guard']['verdict']);
        $this->assertContains('pppoe:pppoe_in_use', $log[0]->properties['guard']['reasons']);
    }

    public function test_override_is_rejected_for_non_superadmin(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->admin())->delete(route('customers.destroy', $customer), [
            'override' => true,
            'override_reason' => self::REASON,
        ])->assertForbidden();

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_override_is_rejected_with_short_reason(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->superadmin())->delete(route('customers.destroy', $customer), [
            'override' => true,
            'override_reason' => 'terlalu pendek',
        ])->assertSessionHasErrors(['lifecycle_guard', 'override_reason']);

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_reason_without_the_override_confirmation_does_not_override(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->superadmin())->delete(route('customers.destroy', $customer), [
            'override' => false,
            'override_reason' => self::REASON,
        ])->assertSessionHasErrors('lifecycle_guard');

        $this->assertNull(Customer::withTrashed()->find($customer->id)->deleted_at);
    }

    // ------------------------------------------------------------------
    // A2 update -> terminated
    // ------------------------------------------------------------------

    public function test_suharto_regression_status_update_to_terminated_is_denied_for_online_customer(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->admin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'terminated', ['name' => 'Nama Diganti', 'address' => 'Alamat Baru']))
            ->assertSessionHasErrors('lifecycle_guard');

        $fresh = $customer->fresh();
        $this->assertSame('isolated', $fresh->status);
        $this->assertSame($customer->name, $fresh->name, 'no partial update: other fields of the same request are not saved');
        $this->assertSame('RW 1', $fresh->address);
        $this->assertStringContainsString('tidak bisa diterminate:', session('errors')->first('lifecycle_guard'));
    }

    public function test_status_update_to_terminated_is_allowed_for_clear_customer(): void
    {
        $customer = $this->customer('isolated', false);

        $this->actingAs($this->admin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'terminated'))
            ->assertRedirect(route('customers.index'));

        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    public function test_other_updates_of_an_online_customer_are_not_gated(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->admin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'active', ['name' => 'Nama Baru']))
            ->assertRedirect(route('customers.index'));

        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame('Nama Baru', $customer->fresh()->name);
    }

    public function test_superadmin_override_terminates_through_update_and_logs(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->superadmin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'terminated', [
            'lifecycle_override' => true,
            'lifecycle_override_reason' => self::REASON,
        ]))->assertRedirect(route('customers.index'));

        $this->assertSame('terminated', $customer->fresh()->status);
        $log = Activity::where('event', 'lifecycle_guard_override')->get();
        $this->assertCount(1, $log);
        $this->assertSame('terminate', $log[0]->properties['action']);
        $this->assertSame(self::REASON, $log[0]->properties['reason']);
        $this->assertSame('deny', $log[0]->properties['guard']['verdict']);
    }

    public function test_update_override_rejected_for_non_superadmin_and_short_reason(): void
    {
        $customer = $this->customer('isolated', true);

        $this->actingAs($this->admin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'terminated', [
            'lifecycle_override' => true,
            'lifecycle_override_reason' => self::REASON,
        ]))->assertForbidden();
        $this->actingAs($this->superadmin())->put(route('customers.update', $customer), $this->updatePayload($customer, 'terminated', [
            'lifecycle_override' => true,
            'lifecycle_override_reason' => 'pendek',
        ]))->assertSessionHasErrors(['lifecycle_guard', 'lifecycle_override_reason']);

        $this->assertSame('isolated', $customer->fresh()->status);
        $this->assertSame(0, Activity::where('event', 'lifecycle_guard_override')->count());
    }

    // ------------------------------------------------------------------
    // A3 ticket dismantle approval
    // ------------------------------------------------------------------

    private function dismantleApproval(Customer $customer, User $admin): array
    {
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id, 'customer_name' => $customer->name,
            'gangguan' => 'Dismantle pelanggan', 'type' => 'request', 'status' => 'closed',
        ]);
        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.request', $ticket), [
            'lifecycle_action' => 'dismantle', 'reason' => 'Perangkat sudah ditarik',
        ]);

        return [$ticket, TicketActivity::where('action', 'customer_lifecycle_requested')->firstOrFail()];
    }

    public function test_ticket_dismantle_approval_is_refused_for_online_customer_and_stays_pending(): void
    {
        $customer = $this->customer('isolated', true);
        $admin = $this->admin();
        [$ticket, $request] = $this->dismantleApproval($customer, $admin);

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]))
            ->assertSessionHasErrors('lifecycle_guard');

        $this->assertSame('isolated', $customer->fresh()->status);
        $this->assertSame(0, TicketActivity::where('action', 'customer_lifecycle_applied')->count());
        $this->assertSame(1, TicketActivity::where('action', 'customer_lifecycle_requested')->count(), 'request is not lost');

        // The refusal must not deadlock: the pickup case is created so the device can be collected.
        $this->assertSame(1, DeviceRecoveryCase::where('ticket_id', $ticket->id)->count());
        $caseId = DeviceRecoveryCase::where('ticket_id', $ticket->id)->value('id');
        $this->assertStringContainsString('Kasus Pengambilan Perangkat #'.$caseId, session('errors')->first('lifecycle_guard'));

        // Re-approving while still in use stays refused and does not duplicate the case.
        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]))
            ->assertSessionHasErrors('lifecycle_guard');
        $this->assertSame(1, DeviceRecoveryCase::where('ticket_id', $ticket->id)->count());
        $this->assertSame('isolated', $customer->fresh()->status);
    }

    public function test_pending_dismantle_request_lets_admin_create_the_recovery_case_manually(): void
    {
        $customer = $this->customer('isolated', true);
        $admin = $this->admin();
        [$ticket] = $this->dismantleApproval($customer, $admin);

        $this->actingAs($admin)->post(route('device-recovery.create', $ticket))->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('device-recovery.create', $ticket))->assertSessionHasNoErrors();

        $this->assertSame(1, DeviceRecoveryCase::where('ticket_id', $ticket->id)->count());
        $this->assertSame('isolated', $customer->fresh()->status, 'creating the case never mutates the customer');
    }

    public function test_ticket_dismantle_approval_passes_for_clear_customer(): void
    {
        $customer = $this->customer('isolated', false);
        $admin = $this->admin();
        [$ticket, $request] = $this->dismantleApproval($customer, $admin);

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]))
            ->assertSessionHasNoErrors();

        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertSame(1, TicketActivity::where('action', 'customer_lifecycle_applied')->count());
    }

    public function test_ticket_dismantle_override_superadmin_only_and_logged(): void
    {
        $customer = $this->customer('isolated', true);
        $admin = $this->admin();
        [$ticket, $request] = $this->dismantleApproval($customer, $admin);

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]), [
            'override' => true, 'override_reason' => self::REASON,
        ])->assertForbidden();
        $this->assertSame('isolated', $customer->fresh()->status);

        $this->actingAs($this->superadmin())->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]), [
            'override' => true, 'override_reason' => 'pendek',
        ])->assertSessionHasErrors(['lifecycle_guard', 'override_reason']);
        $this->assertSame('isolated', $customer->fresh()->status);

        $this->actingAs($this->superadmin())->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]), [
            'override' => true, 'override_reason' => self::REASON,
        ])->assertSessionHasNoErrors();
        $this->assertSame('terminated', $customer->fresh()->status);
        $log = Activity::where('event', 'lifecycle_guard_override')->get();
        $this->assertCount(1, $log);
        $this->assertSame('ticket_dismantle_approve', $log[0]->properties['source']);
        $this->assertSame(self::REASON, $log[0]->properties['reason']);
    }

    public function test_isolir_approval_is_not_gated(): void
    {
        $customer = $this->customer('active', true);
        $admin = $this->admin();
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id, 'customer_name' => $customer->name, 'gangguan' => 'Isolir', 'type' => 'request',
        ]);
        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.request', $ticket), ['lifecycle_action' => 'isolir', 'reason' => 'Sudah disetujui NOC']);
        $request = TicketActivity::where('action', 'customer_lifecycle_requested')->firstOrFail();

        $this->actingAs($admin)->post(route('tickets.customer-lifecycle.approve', [$ticket, $request]))->assertSessionHasNoErrors();

        $this->assertSame('isolated', $customer->fresh()->status);
    }

    // ------------------------------------------------------------------
    // A7 archive commands: CLI never overrides
    // ------------------------------------------------------------------

    private function snapshotCandidate(Customer $customer): void
    {
        $customer->forceFill(['mikrotik_sync_status' => 'missing'])->save();
    }

    public function test_archive_not_on_mikrotik_skips_in_use_and_unknown_and_dry_run_shows_verdicts(): void
    {
        $online = $this->customer('isolated', true);
        $unknown = $this->customer('isolated', false, false);
        $idle = $this->customer('isolated', false);
        foreach ([$online, $unknown, $idle] as $c) {
            $this->snapshotCandidate($c);
        }

        $this->artisan('customers:archive-not-on-mikrotik', ['--use-snapshot' => true])
            ->expectsOutputToContain('customer='.$online->id.' code='.$online->code.' decision=deny')
            ->expectsOutputToContain('customer='.$unknown->id.' code='.$unknown->code.' decision=unknown')
            ->expectsOutputToContain('customer='.$idle->id.' code='.$idle->code.' decision=allow')
            ->expectsOutputToContain('allow=1 deny=1 unknown=1')
            ->assertSuccessful();
        $this->assertSame(0, Customer::onlyTrashed()->count(), 'dry run writes nothing');

        $this->artisan('customers:archive-not-on-mikrotik', ['--use-snapshot' => true, '--apply' => true, '--backup-confirmed' => true])
            ->expectsOutputToContain('result=skipped')
            ->expectsOutputToContain('Soft-deleted archive candidates: 1')
            ->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($online->id)->deleted_at);
        $this->assertNull(Customer::withTrashed()->find($unknown->id)->deleted_at);
        $this->assertNotNull(Customer::withTrashed()->find($idle->id)->deleted_at);
    }

    public function test_cleanup_delinquent_skips_in_use_customers_on_apply(): void
    {
        $online = $this->customer('isolated', true);
        $idle = $this->customer('isolated', false);
        foreach ([$online, $idle] as $c) {
            foreach (['2026-01-01', '2026-02-01', '2026-03-01'] as $period) {
                Invoice::create([
                    'customer_id' => $c->id, 'period' => $period, 'amount' => 100000, 'status' => 'unpaid',
                    'due_date' => Carbon::parse($period)->day(20)->toDateString(), 'generated_at' => now(),
                ]);
            }
        }

        $this->artisan('customers:cleanup-delinquent')
            ->expectsOutputToContain('decision=deny')
            ->expectsOutputToContain('allow=1 deny=1 unknown=0')
            ->assertSuccessful();
        $this->assertSame(0, Customer::onlyTrashed()->count());

        $this->artisan('customers:cleanup-delinquent', ['--apply' => true])
            ->expectsOutputToContain('Soft-deleted customers: 1')
            ->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($online->id)->deleted_at);
        $this->assertNotNull(Customer::withTrashed()->find($idle->id)->deleted_at);
    }

    public function test_cleanup_imported_skips_in_use_and_dry_run_shows_verdicts(): void
    {
        $online = $this->customer('isolated', true);
        $idle = $this->customer('isolated', false);
        $online->forceFill(['code' => 'IMP-ON1'])->save();
        $idle->forceFill(['code' => 'IMP-ID1'])->save();

        $this->artisan('customers:cleanup-imported')
            ->expectsOutputToContain('customer='.$online->id.' code=IMP-ON1 decision=deny')
            ->expectsOutputToContain('allow=1 deny=1 unknown=0')
            ->assertSuccessful();
        $this->assertSame(0, Customer::onlyTrashed()->count());

        $this->artisan('customers:cleanup-imported', ['--apply' => true])
            ->expectsOutputToContain('Soft-deleted imported rows: 1')
            ->expectsOutputToContain('result=skipped')
            ->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($online->id)->deleted_at);
        $this->assertNotNull(Customer::withTrashed()->find($idle->id)->deleted_at);
    }

    public function test_scope_customers_xlsx_skips_in_use_out_of_scope_customers(): void
    {
        $keep = $this->customer('active', false);
        $online = $this->customer('isolated', true);
        $idle = $this->customer('isolated', false);
        $path = SimpleXlsxWriter::create('scope-test.xlsx', ['ID Pelanggan', 'Status'], [[$keep->code, 'Aktif']]);

        $this->artisan('billing:scope-customers-xlsx', ['path' => $path, '--dry-run' => true])
            ->expectsOutputToContain('customer='.$online->id.' code='.$online->code.' decision=deny reasons=pppoe:pppoe_in_use result=dry-run-skip')
            ->expectsOutputToContain('skipped by lifecycle guard: 1')
            ->assertSuccessful();
        $this->assertSame(0, Customer::onlyTrashed()->count(), 'dry run writes nothing');

        $this->artisan('billing:scope-customers-xlsx', ['path' => $path])
            ->expectsOutputToContain('skipped by lifecycle guard: 1')
            ->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($keep->id)->deleted_at);
        $this->assertNull(Customer::withTrashed()->find($online->id)->deleted_at);
        $this->assertNotNull(Customer::withTrashed()->find($idle->id)->deleted_at);
    }

    // ------------------------------------------------------------------
    // Frontend contract
    // ------------------------------------------------------------------

    public function test_frontend_shows_guard_reason_and_superadmin_only_override(): void
    {
        $notice = (string) file_get_contents(base_path('resources/js/Components/LifecycleGuardNotice.tsx'));
        $this->assertStringContainsString('isSuperadmin ? (', $notice);
        $this->assertStringContainsString('Hanya superadmin yang dapat override dengan alasan tertulis.', $notice);
        $this->assertStringContainsString('LIFECYCLE_OVERRIDE_REASON_MIN = 20', $notice);
        $this->assertStringContainsString('type="checkbox"', $notice);

        foreach (['Customers/Index', 'Customers/Show', 'Customers/Edit'] as $page) {
            $source = (string) file_get_contents(base_path("resources/js/Pages/{$page}.tsx"));
            $this->assertStringContainsString('LifecycleGuardNotice', $source, $page);
            $this->assertStringContainsString('Tetap arsipkan (override)', $source, $page);
            $this->assertStringContainsString("auth.user.role === 'superadmin'", $source, $page);
        }
        $edit = (string) file_get_contents(base_path('resources/js/Pages/Customers/Edit.tsx'));
        $this->assertStringContainsString('Tetap terminate (override)', $edit);
        $this->assertStringContainsString('lifecycle_override_reason', $edit);

        $ticket = (string) file_get_contents(base_path('resources/js/Pages/Tickets/Show.tsx'));
        $this->assertStringContainsString('Tetap approve (override)', $ticket);
        $this->assertStringContainsString('errors.lifecycle_guard', $ticket);
    }
}
