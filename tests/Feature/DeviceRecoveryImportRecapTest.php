<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\StubsLifecycleGuard;
use Tests\TestCase;

/**
 * One-off `device-recovery:import-recap` (warehouse recap import). Fake
 * fixtures only; never WA/notifications/OLT jobs.
 */
#[Group('blocking-core')]
class DeviceRecoveryImportRecapTest extends TestCase
{
    use RefreshDatabase;
    use StubsLifecycleGuard;

    private User $admin;

    private Area $area;

    private Package $package;

    private int $seq = 0;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowCustomerLifecycleGuard();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->area = Area::create(['name' => 'Recap Area', 'code' => 'RCP']);
        $this->package = Package::create(['code' => 'RCP-PKG', 'name' => 'Recap package', 'price' => 100000]);
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_dry_run_writes_nothing_and_exits_zero(): void
    {
        $rows = $this->allActionRows();
        $before = $this->snapshot();

        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson($rows)])
            ->expectsOutputToContain('mode=dry-run rows=4 applied=0 would_apply=4')
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('active', Customer::find($rows[1]['customer_id'])->status);
    }

    public function test_apply_requires_an_admin_like_actor(): void
    {
        $file = $this->writeJson($this->allActionRows());
        $tech = User::factory()->create(['role' => 'teknisi']);

        $this->artisan('device-recovery:import-recap', ['file' => $file, '--apply' => true])->assertExitCode(1);
        $this->artisan('device-recovery:import-recap', ['file' => $file, '--apply' => true, '--actor' => $tech->id])->assertExitCode(1);
        $this->assertSame(0, DeviceRecoveryEvent::query()->where('source', 'recap_import')->count());
    }

    public function test_every_action_applies_without_jobs_or_notifications(): void
    {
        $rows = $this->allActionRows();
        $dup = DeviceRecoveryCase::find($rows[0]['case_id']);
        $existing = DeviceRecoveryCase::find($rows[1]['case_id']);
        $reusedCustomerId = $rows[2]['customer_id'];
        $newCustomerId = $rows[3]['customer_id'];

        Bus::fake();
        Queue::fake();
        Notification::fake();

        $this->runImport($rows)->assertExitCode(0);

        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
        Notification::assertNothingSent();

        // cancel_duplicate
        $this->assertSame('cancelled', $dup->fresh()->status);
        $this->assertSame('cancelled', $dup->ticket->fresh()->status);

        // receive_existing: active customer terminated, ticket closed
        $existing->refresh();
        $this->assertSame('received', $existing->status);
        $this->assertSame('2026-10-04 05:00:00', $existing->received_at->utc()->format('Y-m-d H:i:s'));
        $item = $existing->items()->firstOrFail();
        $this->assertSame('received', $item->status);
        $this->assertSame('good', $item->condition);
        $this->assertSame('FHTT0000A04F', $item->captured_serial_number);
        $this->assertSame('terminated', Customer::find($rows[1]['customer_id'])->status);
        $this->assertSame('closed', $existing->ticket->fresh()->status);
        $this->assertSame('warehouse_received', DeviceRecoveryEvent::query()
            ->where('recovery_case_id', $existing->id)->where('source', 'recap_import')->value('event_type'));
        $this->assertSame('SM-TEST-1', $existing->meta['movement_no']);
        $this->assertSame('warehouse_recap_import', $existing->meta['source']);

        // reused
        $reused = DeviceRecoveryCase::query()->where('customer_id', $reusedCustomerId)->firstOrFail();
        $this->assertSame('reused', $reused->status);
        $this->assertStringStartsWith('Sudah digunakan pelanggan lain: ', $reused->exception_reason);
        $this->assertSame('warehouse_recap_import', $reused->meta['reused']['source']);
        $this->assertSame((int) $rows[2]['reused_by_customer_id'], $reused->meta['reused']['customer_id']);
        $this->assertSame('closed', $reused->ticket->status);
        $this->assertSame('dismantle', $reused->ticket->type);
        $this->assertSame('other', $reused->ticket->dismantle_reason);
        $this->assertStringStartsWith('TCK-', $reused->ticket->code);
        $this->assertSame('active', Customer::find($rows[2]['reused_by_customer_id'])->status);
        $this->assertSame('active', Customer::find($reusedCustomerId)->status);
        $this->assertTrue(TicketActivity::query()->where('ticket_id', $reused->ticket_id)->where('action', 'created')->exists());

        // new_received
        $new = DeviceRecoveryCase::query()->where('customer_id', $newCustomerId)->firstOrFail();
        $this->assertSame('received', $new->status);
        $this->assertSame('2026-09-18 05:00:00', $new->received_at->utc()->format('Y-m-d H:i:s'));
        $newItem = $new->items()->firstOrFail();
        $this->assertSame('FHTT0000A010', $newItem->captured_serial_number);
        $this->assertSame('received', $newItem->status);
        $this->assertSame('closed', $new->ticket->status);
        $this->assertSame('2026-09-18 05:00:00', $new->ticket->resolved_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_apply_is_idempotent(): void
    {
        $rows = $this->allActionRows();
        $this->runImport($rows)->assertExitCode(0);
        $after = $this->snapshot();

        $this->runImport($rows)
            ->expectsOutputToContain('skipped=4')
            ->assertExitCode(0);

        $this->assertSame($after, $this->snapshot());
    }

    public function test_soft_deleted_customer_is_imported_and_terminated(): void
    {
        $customer = $this->customer('active', 'FHTTC18AA0001');
        $customer->delete();

        $this->runImport([[
            'row' => 'S1', 'action' => 'new_received', 'customer_id' => $customer->id,
            'serial' => 'FHTTC18AA0001', 'date' => '2026-10-01', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->assertExitCode(0);

        $case = DeviceRecoveryCase::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('received', $case->status);
        $this->assertSame('terminated', Customer::withTrashed()->find($customer->id)->status);
        $this->assertSame('closed', $case->ticket->status);
    }

    public function test_new_received_with_different_stored_serial_warns_and_records_stored_value(): void
    {
        $customer = $this->customer('terminated', '1FHTT0000A026');

        $this->runImport([[
            'row' => 'W1', 'action' => 'new_received', 'customer_id' => $customer->id,
            'serial' => 'FHTT0000A026', 'date' => '2026-09-25', 'sm' => 'SM-TEST-2', 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])
            ->expectsOutputToContain('WARN')
            ->assertExitCode(0);

        $case = DeviceRecoveryCase::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('1FHTT0000A026', $case->meta['stored_onu_serial']);
        $this->assertSame('FHTT0000A026', $case->items()->firstOrFail()->expected_serial_number);
        $this->assertSame('terminated', $customer->fresh()->status);
    }

    public function test_receive_existing_refuses_serial_mismatch(): void
    {
        $customer = $this->customer('active', 'FHTTC18BB0001');
        $case = $this->openCase($customer, 'FHTTC18BB0001');

        $this->runImport([[
            'row' => 'M1', 'action' => 'receive_existing', 'case_id' => $case->id, 'customer_id' => $customer->id,
            'serial' => 'FHTTC18BB9999', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])
            ->expectsOutputToContain('refused=1')
            ->assertExitCode(1);

        $this->assertSame('assigned', $case->fresh()->status);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame('pending', $case->items()->firstOrFail()->status);
    }

    public function test_receive_existing_with_null_expected_accepts_matching_customer_serial(): void
    {
        $customer = $this->customer('active', 'FHTTC18BB0101');
        $case = $this->openCase($customer, null);

        $this->runImport([[
            'row' => 'M2', 'action' => 'receive_existing', 'case_id' => $case->id, 'customer_id' => $customer->id,
            'serial' => 'fhttc18bb0101', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])
            ->expectsOutputToContain('diisi dari onu_serial customer')
            ->assertExitCode(0);

        $case = $case->fresh();
        $item = $case->items()->firstOrFail();
        $this->assertSame('received', $case->status);
        $this->assertTrue($case->meta['expected_filled_from_customer']);
        $this->assertSame('FHTTC18BB0101', $item->expected_serial_number);
        $this->assertSame('customer', $item->expected_source);
        $this->assertSame('FHTTC18BB0101', $item->captured_serial_number);
        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertTrue((bool) DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)
            ->where('event_type', 'warehouse_received')->firstOrFail()->metadata['expected_filled_from_customer']);
    }

    public function test_receive_existing_with_null_expected_refuses_when_customer_serial_differs_or_empty(): void
    {
        $different = $this->customer('active', 'FHTTC18BB0201');
        $caseA = $this->openCase($different, null);
        $empty = $this->customer('active', null);
        $caseB = $this->openCase($empty, null);

        $this->runImport([
            ['row' => 'M3', 'action' => 'receive_existing', 'case_id' => $caseA->id, 'customer_id' => $different->id,
                'serial' => 'FHTTC18BB0299', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
            ['row' => 'M4', 'action' => 'receive_existing', 'case_id' => $caseB->id, 'customer_id' => $empty->id,
                'serial' => 'FHTTC18BB0298', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
        ])
            ->expectsOutputToContain('refused=2')
            ->assertExitCode(1);

        foreach ([[$caseA, $different], [$caseB, $empty]] as [$case, $customer]) {
            $this->assertSame('assigned', $case->fresh()->status);
            $this->assertSame('active', $customer->fresh()->status);
            $item = $case->items()->firstOrFail();
            $this->assertSame('pending', $item->status);
            $this->assertNull($item->expected_serial_number);
        }
    }

    public function test_reused_dry_run_reports_terminate_customer_flag(): void
    {
        $customer = $this->customer('active', 'FHTTC18BB0301');
        $owner = $this->customer('active', 'FHTTC18BB0301');
        $row = ['row' => 'T3', 'action' => 'reused', 'customer_id' => $customer->id, 'serial' => 'FHTTC18BB0301',
            'reused_by_customer_id' => $owner->id, 'date' => '2026-10-06', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'];

        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson([$row])])
            ->expectsOutputToContain('status customer lama tidak diubah')
            ->assertExitCode(0);
        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson([$row + ['terminate_customer' => true]])])
            ->expectsOutputToContain('customer lama diterminate')
            ->assertExitCode(0);
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_receive_existing_refuses_non_open_case_and_customer_mismatch(): void
    {
        $customer = $this->customer('active', 'FHTTC18CC0001');
        $other = $this->customer('active', 'FHTTC18CC0002');
        $case = $this->openCase($customer, 'FHTTC18CC0001');

        $this->runImport([[
            'row' => 'C1', 'action' => 'receive_existing', 'case_id' => $case->id, 'customer_id' => $other->id,
            'serial' => 'FHTTC18CC0001', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->assertExitCode(1);

        $case->forceFill(['status' => 'carried'])->save();
        $this->runImport([[
            'row' => 'C2', 'action' => 'receive_existing', 'case_id' => $case->id, 'customer_id' => $customer->id,
            'serial' => 'FHTTC18CC0001', 'date' => '2026-10-04', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->assertExitCode(1);

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_cancel_duplicate_refuses_when_keep_case_is_not_received(): void
    {
        $customer = $this->customer('active', 'FHTTC18DD0001');
        $dup = $this->openCase($customer, 'FHTTC18DD0001');
        $keep = $this->openCase($customer, 'FHTTC18DD0001');

        $this->runImport([[
            'row' => 'D1', 'action' => 'cancel_duplicate', 'case_id' => $dup->id, 'customer_id' => $customer->id,
            'keep_case_id' => $keep->id, 'reason' => 'Duplikat: perangkat sudah diterima gudang',
        ]])->assertExitCode(1);

        $this->assertSame('assigned', $dup->fresh()->status);
        $this->assertSame('assigned', $dup->ticket->fresh()->status);
    }

    public function test_reused_refuses_when_owner_no_longer_holds_serial_or_is_not_live(): void
    {
        $customer = $this->customer('terminated', 'FHTTC18EE0001');
        $moved = $this->customer('active', 'FHTTC18EE7777');
        $gone = $this->customer('terminated', 'FHTTC18EE0001');
        $deleted = $this->customer('active', 'FHTTC18EE0001');
        $deleted->delete();

        foreach ([$moved, $gone, $deleted] as $i => $owner) {
            $this->runImport([[
                'row' => 'U'.$i, 'action' => 'reused', 'customer_id' => $customer->id, 'serial' => 'FHTTC18EE0001',
                'reused_by_customer_id' => $owner->id, 'date' => '2026-10-06', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
            ]])->assertExitCode(1);
        }

        $this->assertSame(0, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
        $this->assertSame(0, SupportTicket::query()->where('customer_id', $customer->id)->count());
    }

    public function test_new_received_without_serial_router_terminates_active_customer(): void
    {
        $customer = $this->customer('active', 'FHTTC18AB0001');
        $row = [
            'row' => 'N1', 'action' => 'new_received', 'customer_id' => $customer->id, 'serial' => null,
            'device_type' => 'router', 'device_label' => 'Router Tes', 'note' => 'Catatan pemilik tes',
            'date' => '2026-10-02', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ];

        $this->runImport([$row])->expectsOutputToContain('WARN')->assertExitCode(0);

        $case = DeviceRecoveryCase::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('received', $case->status);
        $item = $case->items()->firstOrFail();
        $this->assertSame('router', $item->device_type);
        $this->assertNull($item->captured_serial_number);
        $this->assertNull($item->expected_serial_number);
        $this->assertStringStartsWith('TCK-20261002-', $case->ticket->code);
        $this->assertSame('good', $item->condition);
        $this->assertSame('received', $item->status);
        $this->assertTrue($item->meta['serial_not_recorded']);
        $this->assertSame('Router Tes', $case->meta['recap_device_label']);
        $this->assertSame('Catatan pemilik tes', $case->meta['owner_note']);
        $this->assertSame('terminated', $customer->fresh()->status);
        $this->assertStringContainsString('Catatan pemilik tes', TicketActivity::query()
            ->where('ticket_id', $case->ticket_id)->where('action', 'created')->value('description'));

        // Re-run: customer now has a case -> skipped, nothing new.
        $count = DeviceRecoveryCase::count();
        $this->runImport([$row])->expectsOutputToContain('skipped=1')->assertExitCode(0);
        $this->assertSame($count, DeviceRecoveryCase::count());
    }

    public function test_new_received_without_serial_skips_customer_with_final_case(): void
    {
        $customer = $this->customer('terminated', null);
        $this->receivedCase($customer, 'FHTTC18AC0001');

        $this->runImport([[
            'row' => 'N2', 'action' => 'new_received', 'customer_id' => $customer->id, 'serial' => null,
            'date' => '2026-10-02', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->expectsOutputToContain('skipped=1')->assertExitCode(0);

        $this->assertSame(1, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
    }

    public function test_new_received_and_reused_refuse_when_customer_has_an_open_case(): void
    {
        $customer = $this->customer('active', 'FHTTC18AG0001');
        $open = $this->openCase($customer, 'FHTTC18AG0001');
        $owner = $this->customer('active', 'FHTTC18AG0002');
        $base = ['customer_id' => $customer->id, 'date' => '2026-10-02', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'];

        $rows = [
            ['row' => 'O1', 'action' => 'new_received', 'serial' => 'FHTTC18AG0002'] + $base,
            ['row' => 'O2', 'action' => 'new_received', 'serial' => null] + $base,
            ['row' => 'O3', 'action' => 'new_received', 'serial' => 'FHTTC18AG0001'] + $base,
            ['row' => 'O4', 'action' => 'reused', 'serial' => 'FHTTC18AG0002', 'reused_by_customer_id' => $owner->id] + $base,
        ];
        foreach ($rows as $row) {
            $this->runImport([$row])->expectsOutputToContain('refused=1')->assertExitCode(1);
        }

        $this->assertSame(1, DeviceRecoveryCase::query()->where('customer_id', $customer->id)->count());
        $this->assertSame('assigned', $open->fresh()->status);
        $this->assertSame('active', $customer->fresh()->status);
        $this->assertSame(1, SupportTicket::query()->where('customer_id', $customer->id)->count());
    }

    public function test_cancel_duplicate_refuses_sensitive_reason_in_dry_run_too(): void
    {
        $customer = $this->customer('terminated', 'FHTTC18AH0001');
        $dup = $this->openCase($customer, 'FHTTC18AH0001');
        $keep = $this->receivedCase($customer, 'FHTTC18AH0001');

        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson([[
            'row' => 'D2', 'action' => 'cancel_duplicate', 'case_id' => $dup->id, 'customer_id' => $customer->id,
            'keep_case_id' => $keep->id, 'reason' => 'password=hunter2secret',
        ]])])->expectsOutputToContain('refused=1')->assertExitCode(0);
    }

    public function test_new_received_warns_when_serial_belongs_to_live_customer(): void
    {
        $customer = $this->customer('terminated', null);
        $this->customer('active', 'FHTTC18AI0001');

        $this->runImport([[
            'row' => 'L1', 'action' => 'new_received', 'customer_id' => $customer->id, 'serial' => 'FHTTC18AI0001',
            'date' => '2026-10-02', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->expectsOutputToContain('reused')->assertExitCode(0);
    }

    public function test_reused_with_different_stored_serial_warns_and_keeps_old_customer_status(): void
    {
        $customer = $this->customer('active', '1FHTTC18AD0001');
        $owner = $this->customer('active', 'FHTTC18AD0001');

        $this->runImport([[
            'row' => 'U9', 'action' => 'reused', 'customer_id' => $customer->id, 'serial' => 'FHTTC18AD0001',
            'reused_by_customer_id' => $owner->id, 'date' => '2026-10-06', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->expectsOutputToContain('WARN')->assertExitCode(0);

        $case = DeviceRecoveryCase::query()->where('customer_id', $customer->id)->firstOrFail();
        $this->assertSame('reused', $case->status);
        $this->assertSame('1FHTTC18AD0001', $case->meta['stored_onu_serial']);
        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_reused_terminate_customer_flag_terminates_old_customer_only_when_true(): void
    {
        $keepCustomer = $this->customer('active', 'FHTTC18AE0001');
        $termCustomer = $this->customer('isolated', 'FHTTC18AE0002');
        $ownerA = $this->customer('active', 'FHTTC18AE0001');
        $ownerB = $this->customer('active', 'FHTTC18AE0002');

        $this->runImport([
            ['row' => 'T1', 'action' => 'reused', 'customer_id' => $keepCustomer->id, 'serial' => 'FHTTC18AE0001',
                'reused_by_customer_id' => $ownerA->id, 'date' => '2026-10-06', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
            ['row' => 'T2', 'action' => 'reused', 'customer_id' => $termCustomer->id, 'serial' => 'FHTTC18AE0002',
                'reused_by_customer_id' => $ownerB->id, 'terminate_customer' => true, 'date' => '2026-10-06', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
        ])->assertExitCode(0);

        $this->assertSame('active', $keepCustomer->fresh()->status);
        $this->assertSame('terminated', $termCustomer->fresh()->status);
        $this->assertSame('active', $ownerB->fresh()->status);
        $case = DeviceRecoveryCase::query()->where('customer_id', $termCustomer->id)->firstOrFail();
        $this->assertSame('reused', $case->status);
        $this->assertDatabaseHas('activity_log', [
            'subject_id' => $termCustomer->id,
            'event' => 'terminated_recap_import',
        ]);
        $this->assertStringContainsString('#'.$case->id, (string) DB::table('activity_log')
            ->where('subject_id', $termCustomer->id)->where('event', 'terminated_recap_import')->value('description'));
    }

    public function test_new_received_without_serial_uses_stored_serial_of_soft_deleted_customer(): void
    {
        $customer = $this->customer('terminated', 'FHTTC18AF0001');
        $customer->delete();

        $this->runImport([[
            'row' => 'N3', 'action' => 'new_received', 'customer_id' => $customer->id, 'serial' => null,
            'date' => '2026-10-02', 'sm' => null, 'warehouse' => 'Gudang Tes', 'pic' => 'TES',
        ]])->assertExitCode(0);

        $item = DeviceRecoveryCase::query()->where('customer_id', $customer->id)->firstOrFail()->items()->firstOrFail();
        $this->assertSame('FHTTC18AF0001', $item->expected_serial_number);
        $this->assertNull($item->captured_serial_number);
    }

    public function test_deny_defers_receive_existing_and_new_received_but_still_imports(): void
    {
        $this->denyCustomerLifecycleGuard();
        Bus::fake();
        $rows = $this->allActionRows();
        $existing = DeviceRecoveryCase::find($rows[1]['case_id']);
        $existingCustomerId = $rows[1]['customer_id'];
        // R4's customer is already terminated, so only R2 can defer; make R4 live too.
        Customer::query()->whereKey($rows[3]['customer_id'])->update(['status' => 'active']);

        $this->runImport($rows)->expectsOutputToContain('deferred=2')->assertExitCode(0);

        $this->assertSame('received', $existing->fresh()->status);
        $this->assertSame('closed', $existing->fresh()->ticket->fresh()->status);
        $this->assertSame('active', Customer::find($existingCustomerId)->status);
        $this->assertSame('active', Customer::find($rows[3]['customer_id'])->status);
        $pending = CustomerPendingTermination::query()->orderBy('id')->get();
        $this->assertSame(['recap_receive', 'recap_receive'], $pending->pluck('source')->all());
        $this->assertSame([(int) $existingCustomerId, (int) $rows[3]['customer_id']], $pending->pluck('customer_id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue($pending->every(fn ($row): bool => $row->hold_billing && $row->status === 'pending'));
        $event = DeviceRecoveryEvent::query()->where('recovery_case_id', $existing->id)->where('event_type', 'warehouse_received')->firstOrFail();
        $this->assertTrue($event->metadata['customer_termination_deferred']);
        Bus::assertNothingDispatched();
    }

    /** Customers whose local evidence says "in use": an unwrapped terminate would be refused by the backstop. */
    private function markEvidenceOnline(): void
    {
        Customer::query()->update(['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(2)]);
    }

    public function test_enforce_backstop_wrapper_lets_an_allowed_receive_and_reused_terminate_through(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $rows = $this->allActionRows();
        $reusedCustomerId = $rows[2]['customer_id'];
        Customer::query()->whereKey($reusedCustomerId)->update(['status' => 'isolated']);
        $rows[2]['terminate_customer'] = true;
        $this->markEvidenceOnline();

        $this->runImport($rows)->assertExitCode(0);

        $this->assertSame('terminated', Customer::find($rows[1]['customer_id'])->status, 'receive wrapper');
        $this->assertSame('terminated', Customer::find($reusedCustomerId)->status, 'reused wrapper');
        $this->assertSame(0, CustomerPendingTermination::query()->count());
        $this->assertSame(0, DB::table('activity_log')->where('event', 'lifecycle_backstop_violation')->count());
    }

    public function test_enforce_backstop_does_not_fire_when_the_guard_defers_recap_rows(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'enforce']);
        $this->denyCustomerLifecycleGuard();
        $old = $this->customer('active', 'FHTTEN00001');
        $owner = $this->customer('active', 'FHTTEN00001');
        $rows = [['row' => 'E1', 'action' => 'reused', 'customer_id' => $old->id, 'serial' => 'FHTTEN00001',
            'reused_by_customer_id' => $owner->id, 'terminate_customer' => true, 'date' => '2026-09-30', 'sm' => 'SM-E', 'warehouse' => 'Gudang Tes', 'pic' => 'TES']];
        $this->markEvidenceOnline();

        $this->runImport($rows)->expectsOutputToContain('deferred=1')->assertExitCode(0);

        $this->assertSame('active', $old->fresh()->status, 'guard refusal keeps the customer');
        $this->assertSame(1, CustomerPendingTermination::query()->count());
        $this->assertSame(0, DB::table('activity_log')->where('event', 'lifecycle_backstop_violation')->count());
    }

    public function test_log_mode_recap_terminate_proceeds_and_logs_no_violation_for_wrapped_writes(): void
    {
        config(['lifecycle_guard.backstop_mode' => 'log']);
        $rows = $this->allActionRows();
        $this->markEvidenceOnline();

        $this->runImport($rows)->assertExitCode(0);

        $this->assertSame('terminated', Customer::find($rows[1]['customer_id'])->status);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'lifecycle_backstop_violation')->count());
    }

    public function test_deny_defers_the_reused_old_customer_with_owner_link_and_replay_is_idempotent(): void
    {
        $this->denyCustomerLifecycleGuard();
        $old = $this->customer('active', 'FHTTRC00001');
        $owner = $this->customer('active', 'FHTTRC00001');
        $rows = [['row' => 'U1', 'action' => 'reused', 'customer_id' => $old->id, 'serial' => 'FHTTRC00001',
            'reused_by_customer_id' => $owner->id, 'terminate_customer' => true, 'date' => '2026-09-30', 'sm' => 'SM-T', 'warehouse' => 'Gudang Tes', 'pic' => 'TES']];
        $file = $this->writeJson($rows);

        $this->artisan('device-recovery:import-recap', ['file' => $file, '--apply' => true, '--actor' => $this->admin->id])
            ->expectsOutputToContain('deferred=1')
            ->assertExitCode(0);
        $this->artisan('device-recovery:import-recap', ['file' => $file, '--apply' => true, '--actor' => $this->admin->id])
            ->expectsOutputToContain('skipped=1')
            ->assertExitCode(0);

        $this->assertSame('active', $old->fresh()->status);
        $row = CustomerPendingTermination::query()->firstOrFail();
        $this->assertSame(1, CustomerPendingTermination::query()->count());
        $this->assertSame('recap_reused', $row->source);
        $this->assertSame((int) $owner->id, (int) $row->takeover_customer_id);
    }

    public function test_allow_still_terminates_and_dry_run_prints_the_guard_verdict(): void
    {
        $rows = $this->allActionRows();
        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson($rows)])
            ->expectsOutputToContain('guard=allow → terminated')
            ->assertExitCode(0);

        $this->denyCustomerLifecycleGuard();
        $this->artisan('device-recovery:import-recap', ['file' => $this->writeJson($rows)])
            ->expectsOutputToContain('→ DITUNDA')
            ->assertExitCode(0);
        $this->assertSame(0, CustomerPendingTermination::query()->count(), 'dry-run never writes');

        $this->allowCustomerLifecycleGuard();
        $this->runImport($rows)->assertExitCode(0);
        $this->assertSame('terminated', Customer::find($rows[1]['customer_id'])->status);
        $this->assertSame(0, CustomerPendingTermination::query()->count());
    }

    public function test_guard_that_cannot_run_defers_the_row_fail_closed(): void
    {
        $guard = Mockery::mock(CustomerLifecycleGuard::class)->makePartial();
        $guard->shouldReceive('canTerminate')->andThrow(new \RuntimeException('down'));
        $this->app->instance(CustomerLifecycleGuard::class, $guard);
        $rows = $this->allActionRows();

        $this->runImport($rows)->assertExitCode(0);

        $this->assertSame('active', Customer::find($rows[1]['customer_id'])->status);
        $this->assertGreaterThanOrEqual(1, CustomerPendingTermination::query()->count());
    }

    /** @return list<array<string, mixed>> */
    private function allActionRows(): array
    {
        $dupCustomer = $this->customer('terminated', 'FHTTC18F00001');
        $keep = $this->receivedCase($dupCustomer, 'FHTTC18F00001');
        $dup = $this->openCase($dupCustomer, 'FHTTC18F00001');

        $existingCustomer = $this->customer('active', 'FHTT0000A04F');
        $existing = $this->openCase($existingCustomer, 'FHTT0000A04F');

        $reusedCustomer = $this->customer('active', 'FHTTC18F00003');
        $owner = $this->customer('active', 'FHTTC18F00003');

        $newCustomer = $this->customer('terminated', 'FHTT0000A010');

        return [
            ['row' => 'R1', 'action' => 'cancel_duplicate', 'case_id' => $dup->id, 'customer_id' => $dupCustomer->id,
                'keep_case_id' => $keep->id, 'reason' => 'Duplikat: perangkat sudah diterima gudang'],
            ['row' => 'R2', 'action' => 'receive_existing', 'case_id' => $existing->id, 'customer_id' => $existingCustomer->id,
                'serial' => 'FHTT0000A04F', 'date' => '2026-10-04', 'sm' => 'SM-TEST-1', 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
            ['row' => 'R3', 'action' => 'reused', 'customer_id' => $reusedCustomer->id, 'serial' => 'FHTTC18F00003',
                'reused_by_customer_id' => $owner->id, 'date' => '2026-09-30', 'sm' => 'SM-TEST-3', 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
            ['row' => 'R4', 'action' => 'new_received', 'customer_id' => $newCustomer->id, 'serial' => 'FHTT0000A010',
                'date' => '2026-09-18', 'sm' => 'SM-TEST-4', 'warehouse' => 'Gudang Tes', 'pic' => 'TES'],
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function runImport(array $rows)
    {
        return $this->artisan('device-recovery:import-recap', [
            'file' => $this->writeJson($rows),
            '--apply' => true,
            '--actor' => $this->admin->id,
        ]);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function writeJson(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'recap');
        file_put_contents($path, json_encode(['source' => 'fake-recap.csv', 'rows' => $rows]));
        $this->files[] = $path;

        return $path;
    }

    /** @return array<string, int> */
    private function snapshot(): array
    {
        return [
            'cases' => DeviceRecoveryCase::count(),
            'items' => DeviceRecoveryItem::count(),
            'events' => DeviceRecoveryEvent::count(),
            'tickets' => SupportTicket::count(),
            'activities' => TicketActivity::count(),
            'terminated' => Customer::withTrashed()->where('status', 'terminated')->count(),
            'received' => DeviceRecoveryCase::where('status', 'received')->count(),
        ];
    }

    private function customer(string $status, ?string $serial): Customer
    {
        $this->seq++;

        return Customer::create([
            'code' => 'RCP-'.$this->seq,
            'name' => 'Recap customer '.$this->seq,
            'phone' => '0812000000'.$this->seq,
            'address' => 'Alamat '.$this->seq,
            'area_id' => $this->area->id,
            'package_id' => $this->package->id,
            'pppoe_user' => 'recap-pppoe-'.$this->seq,
            'onu_serial' => $serial,
            'status' => $status,
        ])->fresh();
    }

    private function openCase(Customer $customer, ?string $expectedSerial): DeviceRecoveryCase
    {
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'created_by' => $this->admin->id,
            'gangguan' => 'Dismantle',
            'subject' => 'Dismantle',
            'type' => 'dismantle',
            'status' => 'assigned',
            'priority' => 'normal',
        ]);
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'area_id' => $customer->area_id,
            'status' => 'assigned',
            'created_by' => $this->admin->id,
            'version' => 0,
        ]);
        $case->items()->create([
            'device_type' => 'onu',
            'expected_serial_number' => $expectedSerial,
            'expected_source' => $expectedSerial === null ? null : 'customer',
            'status' => 'pending',
            'condition' => 'unknown',
        ]);

        return $case->fresh();
    }

    private function receivedCase(Customer $customer, string $serial): DeviceRecoveryCase
    {
        $case = $this->openCase($customer, $serial);
        $case->items()->update(['captured_serial_number' => $serial, 'status' => 'received', 'condition' => 'good']);
        $case->forceFill(['status' => 'received', 'received_at' => now()])->save();

        return $case->fresh();
    }
}
