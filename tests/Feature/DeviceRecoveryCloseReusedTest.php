<?php

namespace Tests\Feature;

use App\Jobs\DeleteDismantledOnuJob;
use App\Jobs\EnsureCustomerCurrentInvoiceJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryReuseCloser;
use App\Services\DeviceRecoveryService;
use App\Services\PsbDraftCustomerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Owner 2026-10-09: an open pickup case whose ONU becomes another live
 * customer's onu_serial is closed as `reused` ("Dipakai ulang"); hook live,
 * daily sweep report-only until enabled. Never deletes the ONU or changes a
 * customer.
 */
#[Group('blocking-core')]
class DeviceRecoveryCloseReusedTest extends TestCase
{
    use RefreshDatabase;

    private DeviceRecoveryService $service;

    private User $admin;

    private User $technician;

    private Area $area;

    private Package $package;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['device-recovery.mode' => 'write']);
        Bus::fake([DeleteDismantledOnuJob::class, EnsureCustomerCurrentInvoiceJob::class]);

        $this->service = app(DeviceRecoveryService::class);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->technician = User::factory()->create(['role' => 'teknisi']);
        $this->area = Area::create(['name' => 'Reuse Area', 'code' => 'RUS']);
        $this->package = Package::create(['code' => 'RUS-PKG', 'name' => 'Reuse package', 'price' => 100000]);
    }

    public function test_psb_finalize_sync_closes_old_customer_case_as_reused(): void
    {
        $old = $this->customer('isolated', 'FHTT-0000-A01A');
        $case = $this->openCase($old);
        $new = $this->customer('pending_installation', null);
        $draft = PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => '120363-activation@g.us',
            'message_id' => 'MSG-REUSE-1',
            'full_name' => 'PELANGGAN BARU',
            'sn' => 'FHTT0000A01A',
            'customer_id' => $new->id,
        ]);

        app(PsbDraftCustomerSyncService::class)->sync($draft);

        $case->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->status);
        $this->assertSame('ONU dipakai ulang oleh pelanggan '.$new->code.' (#'.$new->id.')', $case->exception_reason);
        $this->assertSame($new->id, $case->meta['reused']['customer_id']);
        $this->assertFalse($case->meta['reused']['reuse_after_pickup']);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->where('event_type', 'reused_closed')->count());
        // Neither customer changes; the ONU is never deleted.
        $this->assertSame('isolated', $old->fresh()->status);
        $this->assertSame('pending_installation', $new->fresh()->status);
        Bus::assertNotDispatched(DeleteDismantledOnuJob::class);
    }

    public function test_reused_onu_takeover_closes_even_a_carried_case_once(): void
    {
        $old = $this->customer('isolated', 'FHTT0000A01B');
        $case = $this->carriedCase($old, 'FHTT0000A01B');

        // AutoActivateOnlineCustomers takeover: the new pending account holds
        // the serial, then old -> terminated and new -> active (Eloquent).
        $new = $this->customer('pending_installation', 'FHTT0000A01B');
        $old->update(['status' => 'terminated']);
        $new->update(['status' => 'active']);

        $case->refresh();
        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->status);
        $this->assertTrue($case->meta['reused']['reuse_after_pickup']);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->meta['reused']['from_status']);
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('event_type', 'reused_closed')->count());
        $this->assertSame(DeviceRecoveryService::ITEM_CARRIED, DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->value('status'));
    }

    public function test_customer_edit_setting_serial_closes_case(): void
    {
        $old = $this->customer('active', 'ZXIC0000A0F8');
        $case = $this->openCase($old);
        $other = $this->customer('active', 'ZXICFF000000');

        $other->update(['onu_serial' => 'zxic-0000a0f8']);

        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->fresh()->status);
    }

    public function test_own_customer_saves_and_terminated_owner_do_not_close(): void
    {
        $old = $this->customer('active', 'FHTT0000A01C');
        $case = $this->openCase($old);

        $old->update(['status' => 'isolated']);
        $old->update(['onu_serial' => 'FHTT-0000-A01C']);
        $this->customer('terminated', 'FHTT0000A01C');

        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);
        $this->assertSame(0, DeviceRecoveryEvent::query()->where('event_type', 'reused_closed')->count());
    }

    public function test_final_cases_are_never_reopened_or_closed(): void
    {
        $old = $this->customer('active', 'FHTT0000A01D');
        $case = $this->openCase($old);
        $case = $this->service->cancel($case, $this->admin, 'Pelanggan batal dismantle', 'cancel-'.$case->id);

        $this->customer('active', 'FHTT0000A01D');

        $this->assertSame(DeviceRecoveryService::STATUS_CANCELLED, $case->fresh()->status);
    }

    public function test_falls_back_to_case_customer_serial_when_items_have_none(): void
    {
        $old = $this->customer('isolated', 'FHTT0000A01E');
        $case = $this->openCase($old);
        DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->update(['expected_serial_number' => null]);

        $this->customer('pending_installation', 'FHTT0000A01E');

        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->fresh()->status);
    }

    public function test_kill_switch_off_disables_hook_and_sweep(): void
    {
        Setting::set('device_recovery_close_reused_enabled', '0', 'boolean', 'ops');
        $old = $this->customer('isolated', 'FHTT0000A01F');
        $case = $this->openCase($old);

        $this->customer('active', 'FHTT0000A01F');
        $this->artisan('device-recovery:close-reused', ['--apply' => true])->assertSuccessful();

        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);
    }

    public function test_daily_sweep_is_dry_run_until_apply_setting_and_idempotent(): void
    {
        $old = $this->customer('isolated', 'FHTT0000A020');
        $case = $this->openCase($old);
        // A writer that bypasses Eloquent events (raw insert/import).
        DB::table('customers')->insert([
            'code' => 'RAW-1', 'name' => 'Raw', 'phone' => '0812999', 'address' => 'Raw',
            'area_id' => $this->area->id, 'package_id' => $this->package->id,
            'pppoe_user' => 'raw-1', 'onu_serial' => 'fhtt-0000-A020', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);

        $this->artisan('device-recovery:close-reused')->expectsOutputToContain('mode=dry-run matched=1 closed=0')->assertSuccessful();
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);

        Setting::set('device_recovery_close_reused_apply', '1', 'boolean', 'ops');
        $this->artisan('device-recovery:close-reused', ['--dry-run' => true])->expectsOutputToContain('mode=dry-run matched=1 closed=0')->assertSuccessful();
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);

        $this->artisan('device-recovery:close-reused')->expectsOutputToContain('mode=apply matched=1 closed=1')->assertSuccessful();
        $this->assertSame(DeviceRecoveryService::STATUS_REUSED, $case->fresh()->status);
        $this->assertSame('daily_sweep', $case->fresh()->meta['reused']['source']);

        $this->artisan('device-recovery:close-reused')->expectsOutputToContain('mode=apply matched=0 closed=0')->assertSuccessful();
        $this->assertSame(1, DeviceRecoveryEvent::query()->where('event_type', 'reused_closed')->count());
    }

    public function test_reused_case_is_final_but_lets_the_ticket_close(): void
    {
        $old = $this->customer('isolated', 'FHTT0000A021');
        $case = $this->openCase($old);
        $this->customer('active', 'FHTT0000A021');
        $case->refresh();

        $this->service->assertTicketCanClose($case->ticket);
        $this->assertSame(['group' => 'Dipakai ulang', 'substatus' => 'Dipakai ulang'], $this->service->statusPresentation('reused'));

        $this->expectException(ValidationException::class);
        $this->service->cancel($case, $this->admin, 'Coba batalkan case final', 'cancel-final-'.$case->id);
    }

    public function test_closer_ignores_customers_without_serial_and_read_only_mode(): void
    {
        $old = $this->customer('isolated', 'FHTT0000A022');
        $case = $this->openCase($old);
        $closer = app(DeviceRecoveryReuseCloser::class);

        $this->assertSame([], $closer->closeForCustomer($this->customer('active', null)));
        config(['device-recovery.mode' => 'read_only']);
        $this->customer('active', 'FHTT0000A022');

        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->fresh()->status);
    }

    private function customer(string $status, ?string $serial): Customer
    {
        $this->seq++;

        return Customer::create([
            'code' => 'RUS-'.$this->seq,
            'name' => 'Reuse customer '.$this->seq,
            'phone' => '0813000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'reuse-'.$this->seq,
            'onu_serial' => $serial,
            'status' => $status,
        ]);
    }

    private function openCase(Customer $customer): DeviceRecoveryCase
    {
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'dismantle',
        ]);

        return $this->service->createFromApprovedDismantle($ticket, $this->admin);
    }

    private function carriedCase(Customer $customer, string $serial): DeviceRecoveryCase
    {
        $case = $this->openCase($customer);
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $this->service->assign($case, $this->admin, $this->technician->id, null, 'assign-'.$case->id);
        $case = $this->service->start($case, $this->technician, 'start-'.$case->id);
        $case = $this->service->capture($case, $item, $this->technician, $serial, 'good', null, [], 'capture-'.$case->id);
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        return $case;
    }
}
