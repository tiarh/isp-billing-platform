<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryItem;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TicketTechnicianWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_ticket_with_assignee_notifies_technician_directly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create([
            'role' => 'teknisi',
            'phone' => '628123456789',
        ]);

        $notifier = Mockery::mock(NotificationService::class);
        $notifier->shouldReceive('sendTicketCreatedToGroup')->once()->andReturn(true);
        $notifier->shouldReceive('sendTicketAssignment')
            ->once()
            ->with(Mockery::on(fn (SupportTicket $ticket): bool => $ticket->assigned_to === $technician->id))
            ->andReturn(true);
        app()->instance(NotificationService::class, $notifier);

        $response = $this->actingAs($admin)->post(route('tickets.store'), [
            'gangguan' => 'Internet pelanggan lambat',
            'keterangan' => 'Tiket dibuat bersama teknisi penanggung jawab',
            'type' => 'incident',
            'priority' => 'normal',
            'assigned_to' => $technician->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('warning');
        $this->assertDatabaseHas('support_tickets', [
            'assigned_to' => $technician->id,
            'status' => 'assigned',
        ]);
    }

    public function test_technician_can_close_ticket_and_upload_photo_in_one_request(): void
    {
        Storage::fake('private');

        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = SupportTicket::create([
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'gangguan' => 'Internet mati',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'incident',
        ]);

        $notifier = Mockery::mock(NotificationService::class);
        $notifier->shouldReceive('sendTicketClosedToGroup')->once()->andReturn(true);
        app()->instance(NotificationService::class, $notifier);

        $response = $this->actingAs($technician)->post(route('tickets.technician-update', $ticket), [
            'kendala' => 'Kabel dropcore putus',
            'action_taken' => 'Kabel diganti dan koneksi diuji',
            'status' => 'closed',
            'root_cause' => 'drop_los',
            'photos' => [UploadedFile::fake()->image('hasil-kerja.jpg', 100, 100)],
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('errors');
        $ticket = $ticket->fresh();

        $this->assertSame('closed', $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertCount(1, $ticket->photos);
        Storage::disk('private')->assertExists($ticket->photos[0]);
        $this->assertDatabaseHas('ticket_activities', [
            'ticket_id' => $ticket->id,
            'action' => 'status_changed',
        ]);
    }

    public function test_dismantle_ticket_cannot_close_while_recovery_is_pending(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $area = Area::create(['name' => 'Recovery Area', 'code' => 'REC']);
        $package = Package::create(['code' => 'REC-PKG', 'name' => 'Recovery package', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'REC-CUSTOMER',
            'name' => 'Recovery customer',
            'phone' => '08123456789',
            'address' => 'Recovery address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'rec-customer',
            'status' => 'terminated',
        ]);
        $ticket = SupportTicket::create([
            'customer_id' => $customer->id,
            'area_id' => $area->id,
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'gangguan' => 'Dismantle dengan perangkat pending',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'request',
        ]);
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => $customer->id,
            'area_id' => $area->id,
            'status' => 'assigned',
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
        ]);
        DeviceRecoveryItem::create([
            'recovery_case_id' => $case->id,
            'device_type' => 'onu',
            'expected_serial_number' => 'HWTC-REC-001',
            'status' => DeviceRecoveryService::ITEM_PENDING,
            'condition' => DeviceRecoveryService::CONDITION_UNKNOWN,
        ]);

        $this->actingAs($technician)
            ->post(route('tickets.technician-update', $ticket), ['status' => 'closed'])
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->assertSame('assigned', $ticket->fresh()->status);
    }

    public function test_technician_photo_below_minimum_dimensions_is_rejected_without_closing_ticket(): void
    {
        Storage::fake('private');

        $admin = User::factory()->create(['role' => 'admin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = SupportTicket::create([
            'assigned_to' => $technician->id,
            'created_by' => $admin->id,
            'gangguan' => 'Internet mati',
            'status' => 'assigned',
            'priority' => 'normal',
            'type' => 'incident',
        ]);

        $response = $this->actingAs($technician)->post(route('tickets.technician-update', $ticket), [
            'status' => 'closed',
            'photos' => [UploadedFile::fake()->image('terlalu-kecil.jpg', 99, 99)],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors(['photos.0']);
        $ticket = $ticket->fresh();

        $this->assertSame('assigned', $ticket->status);
        $this->assertEmpty($ticket->photos);
        Storage::disk('private')->assertDirectoryEmpty('tickets');
    }

    public function test_active_ticket_list_excludes_closed_tickets_and_archive_lists_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $open = SupportTicket::create([
            'created_by' => $admin->id,
            'gangguan' => 'Open ticket',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ]);
        $closed = SupportTicket::create([
            'created_by' => $admin->id,
            'gangguan' => 'Closed ticket',
            'status' => 'closed',
            'priority' => 'normal',
            'type' => 'incident',
        ]);

        $this->actingAs($admin)
            ->get(route('tickets.index'))
            ->assertInertia(fn ($page) => $page
                ->where('viewMode', 'active')
                ->where('tickets.data.0.id', $open->id)
                ->where('tickets.data', fn ($data) => collect($data)->pluck('id')->doesntContain($closed->id)));

        $this->actingAs($admin)
            ->get(route('tickets.index', ['status' => 'archive']))
            ->assertInertia(fn ($page) => $page
                ->where('viewMode', 'archive')
                ->where('tickets.data.0.id', $closed->id));
    }

    public function test_admin_edit_saves_customer_snapshot_and_preserves_due_at_when_due_at_is_omitted(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $dueAt = now()->addDay()->startOfSecond();
        $ticket = SupportTicket::create([
            'created_by' => $admin->id,
            'customer_name' => 'Nama Lama',
            'customer_phone' => '0800000000',
            'customer_coords' => '-7.1,112.7',
            'gangguan' => 'Gangguan lama',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
            'due_at' => $dueAt,
        ]);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), [
            'customer_name' => 'Nama Baru',
            'customer_phone' => '0812345678',
            'customer_coords' => '-7.2,112.8',
            'gangguan' => 'Gangguan diperbarui',
        ])->assertRedirect();

        $ticket = $ticket->fresh();
        $this->assertSame('Nama Baru', $ticket->customer_name);
        $this->assertSame('0812345678', $ticket->customer_phone);
        $this->assertSame('-7.2,112.8', $ticket->customer_coords);
        $this->assertSame('Gangguan diperbarui', $ticket->gangguan);
        $this->assertSame($dueAt->timestamp, $ticket->due_at->timestamp);
    }

    public function test_ticket_deadline_input_is_interpreted_as_wib_and_stored_as_utc(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = SupportTicket::create([
            'created_by' => $admin->id,
            'gangguan' => 'Deadline timezone test',
            'status' => 'open',
            'priority' => 'normal',
            'type' => 'incident',
        ]);

        $this->actingAs($admin)->put(route('tickets.update', $ticket), [
            'due_at' => '2026-08-22T07:30',
        ])->assertRedirect();

        $this->assertSame(
            CarbonImmutable::parse('2026-08-22 00:30:00', 'UTC')->timestamp,
            $ticket->fresh()->due_at->timestamp,
        );
    }
}
