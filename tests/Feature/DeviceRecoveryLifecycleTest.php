<?php

namespace Tests\Feature;

use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryReconciliationService;
use App\Services\DeviceRecoveryService;
use App\Services\EvolutionApiService;
use App\Services\NotificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeviceRecoveryLifecycleTest extends TestCase
{
    private DeviceRecoveryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        $this->createBaseTables();
        $this->runRecoveryMigrations();
        $this->service = new DeviceRecoveryService;
    }

    protected function tearDown(): void
    {
        DB::disconnect('sqlite');
        parent::tearDown();
    }

    public function test_approved_dismantle_runs_recovery_lifecycle_without_mutating_ticket_or_customer(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);

        $case = $this->service->createFromApprovedDismantle($ticket, $actor, 'other');
        $sameCase = $this->service->createFromApprovedDismantle($ticket, $actor, 'other');

        $this->assertSame($case->id, $sameCase->id);
        $this->assertSame(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT, $case->status);
        $this->assertSame('HWTC1234', $case->items->first()->expected_serial_number);
        $this->assertSame('closed', $ticket->fresh()->status);
        $this->assertSame('terminated', DB::table('customers')->where('id', 10)->value('status'));
        $this->assertSame(1, $case->events()->where('event_type', 'case_created')->count());

        $case = $this->service->assign(
            $case,
            $actor,
            $technician->id,
            CarbonImmutable::now()->addHour(),
            'assign-case-20',
        );
        $this->assertSame(DeviceRecoveryService::STATUS_SCHEDULED, $case->status);

        $case = $this->service->start($case, $technician, 'start-case-20');
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $this->service->capture($case, $item, $technician, 'hwtc 1234', 'good', null, [], 'capture-case-20');
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);
        $this->assertSame('HWTC1234', $case->items->first()->captured_serial_number);

        $case = $this->service->receive($case, $actor, 'receive-case-20');
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame(DeviceRecoveryService::ITEM_RECEIVED, $case->items->first()->status);
        $this->assertSame(5, $case->events()->count());
    }

    public function test_recovery_requires_approved_dismantle(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');

        $this->expectException(ValidationException::class);
        $this->service->createFromApprovedDismantle($ticket, $actor);
    }

    public function test_recovery_rejects_ticket_without_customer_before_creating_case(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $ticket = SupportTicket::create([
            'id' => 20,
            'code' => 'TCK-20',
            'customer_id' => null,
            'area_id' => 4,
            'status' => 'closed',
            'type' => 'other',
            'priority' => 'normal',
        ]);
        $this->approvedDismantle($ticket->id, $actor->id);

        try {
            $this->service->createFromApprovedDismantle($ticket, $actor);
            $this->fail('A ticket without a customer must not create a recovery case.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('device_recovery_cases', 0);
    }

    public function test_existing_recovery_still_requires_current_approved_dismantle(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => 10,
            'area_id' => 4,
            'status' => DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT,
            'created_by' => $actor->id,
            'version' => 0,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->createFromApprovedDismantle($ticket, $actor);
    }

    public function test_create_idempotency_key_cannot_replay_across_tickets(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $this->customer(11, 'CUST-11', 'Pelanggan 11', 'HWTC-5678', 'terminated');
        $firstTicket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $secondTicket = $this->ticket(21, 11, 'TCK-21', 'closed');
        $this->approvedDismantle($firstTicket->id, $actor->id);
        $this->approvedDismantle($secondTicket->id, $actor->id);

        $this->service->createFromApprovedDismantle($firstTicket, $actor, 'other', 'shared-create-key');

        $this->expectException(ValidationException::class);
        $this->service->createFromApprovedDismantle($secondTicket, $actor, 'other', 'shared-create-key');
    }

    public function test_capture_mismatch_enters_exception_without_mutating_customer(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');

        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-case-20');
        $case = $this->service->start($case, $technician, 'start-case-20');
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();
        $case = $this->service->capture($case, $item, $technician, 'DIFFERENT-SN', 'good', 'SN tidak cocok', [], 'capture-case-20');

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame(DeviceRecoveryService::CONDITION_MISMATCH, $case->items->first()->condition);
    }

    public function test_recovery_photo_requires_signed_url_and_case_binding(): void
    {
        Storage::fake('private');
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => 10,
            'area_id' => 4,
            'status' => DeviceRecoveryService::STATUS_CARRIED,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'version' => 1,
        ]);
        $item = DeviceRecoveryItem::create([
            'recovery_case_id' => $case->id,
            'device_type' => 'onu',
            'status' => DeviceRecoveryService::ITEM_CARRIED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'photo_paths' => ['device-recovery/photo.jpg'],
        ]);
        Storage::disk('private')->put('device-recovery/photo.jpg', 'fake-image');

        $signed = URL::signedRoute('device-recovery.photo', [
            'recoveryCase' => $case->id,
            'item' => $item->id,
            'index' => 0,
        ]);

        $this->actingAs($actor)->get($signed)->assertOk();
        $this->actingAs($actor)
            ->getJson(route('device-recovery.show', $case))
            ->assertOk()
            ->assertJsonMissingPath('items.0.photo_paths')
            ->assertJsonPath('items.0.photos.0.index', 0);
        $this->actingAs($actor)->get(route('device-recovery.photo', [
            'recoveryCase' => $case->id,
            'item' => $item->id,
            'index' => 0,
        ]))->assertForbidden();
        $this->actingAs($actor)->get(URL::temporarySignedRoute('device-recovery.photo', now()->subSecond(), [
            'recoveryCase' => $case->id,
            'item' => $item->id,
            'index' => 0,
        ]))->assertForbidden();
        $this->actingAs($actor)->get(URL::signedRoute('device-recovery.photo', [
            'recoveryCase' => $case->id,
            'item' => $item->id,
            'index' => 1,
        ]))->assertNotFound();

        $item->update(['photo_paths' => ['other-private/secret.txt']]);
        Storage::disk('private')->put('other-private/secret.txt', 'must-not-leak');
        $this->actingAs($actor)->get(URL::signedRoute('device-recovery.photo', [
            'recoveryCase' => $case->id,
            'item' => $item->id,
            'index' => 0,
        ]))->assertNotFound();
    }

    public function test_recovery_detail_redacts_legacy_credential_like_text(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => 10,
            'area_id' => 4,
            'status' => DeviceRecoveryService::STATUS_CARRIED,
            'created_by' => $actor->id,
            'version' => 1,
        ]);
        $item = DeviceRecoveryItem::create([
            'recovery_case_id' => $case->id,
            'device_type' => 'router',
            'status' => DeviceRecoveryService::ITEM_CARRIED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
            'notes' => 'password=legacy-secret',
        ]);
        DeviceRecoveryEvent::create([
            'recovery_case_id' => $case->id,
            'item_id' => $item->id,
            'actor_id' => $actor->id,
            'event_type' => 'legacy_note',
            'from_status' => DeviceRecoveryService::STATUS_PICKUP_STARTED,
            'to_status' => DeviceRecoveryService::STATUS_CARRIED,
            'reason' => 'token=legacy-token',
            'source' => 'test',
            'idempotency_key' => 'legacy-credential-detail',
        ]);

        $response = $this->actingAs($actor)->getJson(route('device-recovery.show', $case));
        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringNotContainsString('legacy-secret', $body);
        $this->assertStringNotContainsString('legacy-token', $body);
        $this->assertStringContainsString('password=[REDACTED]', $body);
        $this->assertStringContainsString('token=[REDACTED]', $body);
        $this->assertStringNotContainsString('photo_paths', $body);
        $this->assertStringNotContainsString('metadata', $body);
        $this->assertStringNotContainsString('device-recovery/photo.jpg', $body);
    }

    public function test_capture_requires_reason_and_duplicate_key_is_idempotent(): void
    {
        [$actor, $technician, $case, $item] = $this->startedCase();

        try {
            $this->service->capture($case, $item, $technician, 'HWTC-1234', 'damaged', null, [], 'capture-invalid');
            $this->fail('A damaged device without a reason must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $case = $this->service->capture($case, $item, $technician, 'HWTC-1234', 'good', null, [], 'capture-once');
        $eventCount = $case->events()->count();
        $sameCase = $this->service->capture($case, $item, $technician, 'HWTC-1234', 'good', null, [], 'capture-once');

        $this->assertSame($case->id, $sameCase->id);
        $this->assertSame($eventCount, $sameCase->events()->count());

        try {
            $this->service->capture($sameCase, $item, $technician, 'HWTC-9999', 'damaged', 'Tidak boleh ditimpa', [], 'capture-new-key');
            $this->fail('A captured item must reject a new capture key.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($eventCount, $sameCase->fresh()->events()->count());
        $this->assertSame('HWTC1234', $item->fresh()->captured_serial_number);
    }

    public function test_credential_like_reason_is_rejected_before_recovery_persistence(): void
    {
        [, $technician, $case, $item] = $this->startedCase();
        $eventCount = $case->events()->count();

        try {
            $this->service->capture(
                $case,
                $item,
                $technician,
                'HWTC-1234',
                'good',
                implode('_', ['pppoe', 'password']).'='.bin2hex(random_bytes(8)),
                [],
                'capture-credential-like-reason',
            );
            $this->fail('Credential-like recovery notes must be rejected before persistence.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($eventCount, $case->fresh()->events()->count());
        $this->assertSame(DeviceRecoveryService::ITEM_PENDING, $item->fresh()->status);
        $this->assertNull($item->fresh()->captured_serial_number);
    }

    public function test_each_transition_retry_is_idempotent_without_duplicate_events(): void
    {
        [$actor, $technician, $case, $item] = $this->startedCase();

        $sameStartedCase = $this->service->start($case, $technician, 'start-started');
        $this->assertSame($case->id, $sameStartedCase->id);
        $this->assertSame(3, $sameStartedCase->events()->count());

        $captured = $this->service->capture($sameStartedCase, $item, $technician, 'HWTC-1234', 'good', null, [], 'capture-retry');
        $retriedCapture = $this->service->capture($captured, $item, $technician, 'HWTC-1234', 'good', null, [], 'capture-retry');
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $retriedCapture->status);
        $this->assertSame(4, $retriedCapture->events()->count());

        $received = $this->service->receive($retriedCapture, $actor, 'receive-retry');
        $retriedReceive = $this->service->receive($received, $actor, 'receive-retry');
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $retriedReceive->status);
        $this->assertSame(DeviceRecoveryService::ITEM_RECEIVED, $retriedReceive->items->first()->status);
        $this->assertSame(5, $retriedReceive->events()->count());
    }

    public function test_good_capture_rejects_empty_or_malformed_serial_and_keeps_item_pending(): void
    {
        [, $technician, $case, $item] = $this->startedCase();

        try {
            $this->service->capture($case, $item, $technician, '---', 'good', null, [], 'capture-malformed');
            $this->fail('A good device without a usable serial must be rejected.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(DeviceRecoveryService::ITEM_PENDING, $item->fresh()->status);
    }

    public function test_idempotency_key_cannot_be_reused_for_a_different_operation(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $case = $this->service->assign($case, $actor, $technician->id, null, 'shared-operation-key');

        $this->expectException(ValidationException::class);
        $this->service->schedule($case, $actor, CarbonImmutable::now()->addHour(), 'shared-operation-key');
    }

    public function test_active_serial_cannot_be_captured_in_another_open_recovery_case(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $this->customer(11, 'CUST-11', 'Pelanggan 11', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $secondTicket = $this->ticket(21, 11, 'TCK-21', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $this->approvedDismantle($secondTicket->id, $actor->id);

        $firstCase = $this->service->createFromApprovedDismantle($ticket, $actor);
        $firstCase = $this->service->assign($firstCase, $actor, $technician->id, null, 'assign-first');
        $firstCase = $this->service->start($firstCase, $technician, 'start-first');
        $firstItem = DeviceRecoveryItem::query()->where('recovery_case_id', $firstCase->id)->firstOrFail();
        $this->service->capture($firstCase, $firstItem, $technician, 'HWTC-1234', 'good', null, [], 'capture-first');

        $secondCase = $this->service->createFromApprovedDismantle($secondTicket, $actor);
        $secondCase = $this->service->assign($secondCase, $actor, $technician->id, null, 'assign-second');
        $secondCase = $this->service->start($secondCase, $technician, 'start-second');
        $secondItem = DeviceRecoveryItem::query()->where('recovery_case_id', $secondCase->id)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->service->capture($secondCase, $secondItem, $technician, 'HWTC-1234', 'good', null, [], 'capture-second');
    }

    public function test_one_case_can_track_multiple_devices_and_waits_for_each_capture(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);

        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $secondItem = $this->service->addItem($case, $actor, 'router', 'router-5678', 'add-second-item');
        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-multiple');
        $case = $this->service->start($case, $technician, 'start-multiple');
        $firstItem = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->whereKeyNot($secondItem->id)->firstOrFail();

        $case = $this->service->capture($case, $firstItem, $technician, 'HWTC-1234', 'good', null, [], 'capture-first-multiple');
        $this->assertSame(DeviceRecoveryService::STATUS_PICKUP_STARTED, $case->status);
        $case = $this->service->capture($case, $secondItem, $technician, 'ROUTER-5678', 'good', null, [], 'capture-second-multiple');
        $this->assertSame(DeviceRecoveryService::STATUS_CARRIED, $case->status);

        $case = $this->service->receive($case, $actor, 'receive-multiple');
        $this->assertSame(DeviceRecoveryService::STATUS_RECEIVED, $case->status);
        $this->assertSame(2, $case->items->where('status', DeviceRecoveryService::ITEM_RECEIVED)->count());
    }

    public function test_item_can_be_corrected_before_pickup_and_not_after_pickup_starts(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $item = $case->items->firstOrFail();

        $updated = $this->service->updateItem($case, $item, $actor, 'router', 'router-5678', 'update-item-20');
        $sameItem = $this->service->updateItem($case, $item, $actor, 'router', 'router-5678', 'update-item-20');

        $this->assertSame($updated->id, $sameItem->id);
        $this->assertSame('router', $updated->device_type);
        $this->assertSame('ROUTER5678', $updated->expected_serial_number);
        $this->assertSame(2, $case->events()->count());

        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-update-item');
        $case = $this->service->start($case, $technician, 'start-update-item');

        $this->expectException(ValidationException::class);
        $this->service->updateItem($case, $updated, $actor, 'onu', 'HWTC-9999', 'update-after-start');
    }

    public function test_superadmin_override_requires_reason_and_is_audited(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-superadmin-override');

        try {
            $this->service->start($case, $actor, 'start-superadmin-without-reason');
            $this->fail('A superadmin override must require a reason.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $case = $this->service->start($case, $actor, 'start-superadmin-with-reason', 'Urgent replacement approved');
        $startEvent = $case->events()->where('event_type', 'pickup_started')->firstOrFail();
        $this->assertSame('Urgent replacement approved', $startEvent->reason);
        $this->assertTrue((bool) data_get($startEvent->metadata, 'superadmin_override'));

        $item = $case->items->firstOrFail();
        try {
            $this->service->capture($case, $item, $actor, 'HWTC-1234', 'good', null, [], 'capture-superadmin-without-reason');
            $this->fail('A superadmin capture override must require a reason.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $case = $this->service->capture($case, $item, $actor, 'HWTC-1234', 'good', 'Supervisor verified onsite', [], 'capture-superadmin-with-reason');
        $captureEvent = $case->events()->where('event_type', 'serial_captured')->firstOrFail();
        $this->assertTrue((bool) data_get($captureEvent->metadata, 'superadmin_override'));
    }

    public function test_recovery_export_is_available_without_credentials(): void
    {
        $actor = $this->user(1, 'superadmin');
        $actor->forceFill(['email_verified_at' => now()]);
        $this->area(4);
        $this->customer(10, '=CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, '+TCK-20', 'closed');
        $case = DeviceRecoveryCase::create([
            'ticket_id' => $ticket->id,
            'customer_id' => 10,
            'area_id' => 4,
            'status' => DeviceRecoveryService::STATUS_CARRIED,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'version' => 1,
        ]);
        DeviceRecoveryItem::create([
            'recovery_case_id' => $case->id,
            'device_type' => 'onu',
            'expected_serial_number' => 'HWTC-1234',
            'captured_serial_number' => 'HWTC-1234',
            'status' => DeviceRecoveryService::ITEM_CARRIED,
            'condition' => DeviceRecoveryService::CONDITION_GOOD,
        ]);

        $response = $this->actingAs($actor)->get(route('inventaris.export', ['type' => 'recovery']));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('=== Perangkat Kembali ===', $csv);
        $this->assertStringContainsString("'=CUST-10", $csv);
        $this->assertStringContainsString("'+TCK-20", $csv);
        $this->assertStringContainsString('HWTC-1234', $csv);
        $this->assertStringNotContainsString('pppoe_password', strtolower($csv));
        $this->assertStringNotContainsString('credential', strtolower($csv));
    }

    public function test_exception_does_not_hide_pending_items_from_capture_or_close(): void
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $secondItem = $this->service->addItem($case, $actor, 'router', 'router-5678', 'add-exception-item');
        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-exception-items');
        $case = $this->service->start($case, $technician, 'start-exception-items');
        $firstItem = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->whereKeyNot($secondItem->id)->firstOrFail();
        $case = $this->service->capture($case, $firstItem, $technician, null, 'lost', 'Perangkat tidak ditemukan', [], 'capture-exception-first');

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION, $case->status);
        $this->assertSame('Perangkat tidak ditemukan', $case->exception_reason);
        try {
            $this->service->resolveException($case, $actor, 'accept', 'Tidak boleh menutup saat pending', 'accept-pending');
            $this->fail('Exception acceptance must not bypass pending items.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $case = $this->service->capture($case, $secondItem, $technician, 'ROUTER-5678', 'good', null, [], 'capture-exception-second');
        $this->assertSame('Perangkat tidak ditemukan', $case->exception_reason);
        $case = $this->service->resolveException($case, $actor, 'accept', 'Exception disetujui setelah semua hasil dicatat', 'accept-complete');

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $case->status);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, $case->items->firstWhere('id', $firstItem->id)->status);
        $this->assertSame(DeviceRecoveryService::ITEM_CARRIED, $case->items->firstWhere('id', $secondItem->id)->status);
        $this->service->assertTicketCanClose($ticket);
    }

    public function test_reconciliation_reports_ambiguous_stale_snapshots_without_writes(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);

        Schema::table('customers', function (Blueprint $table): void {
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->string('olt_port_label')->nullable();
            $table->string('olt_status')->nullable();
            $table->timestamp('olt_last_synced_at')->nullable();
        });
        DB::table('customers')->where('id', 10)->update([
            'olt_id' => 77,
            'olt_port_label' => 'gpon-onu_1/1/1:1',
            'olt_status' => 'online',
        ]);
        Schema::create('olt_onus', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->string('onu_ref')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('onu_name')->nullable();
            $table->string('state')->nullable();
            $table->string('phase_state')->nullable();
            $table->float('rx_power_dbm')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->timestamp('inventory_collected_at')->nullable();
            $table->timestamp('state_collected_at')->nullable();
        });
        Schema::create('genieacs_devices', function (Blueprint $table): void {
            $table->id();
            $table->string('device_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->timestamp('last_inform_at')->nullable();
            $table->timestamp('last_seen_sync_at')->nullable();
            $table->timestamp('last_ping_at')->nullable();
            $table->boolean('last_ping_success')->nullable();
        });
        $staleAt = now()->subDays(2);
        DB::table('olt_onus')->insert([
            ['olt_id' => 77, 'onu_ref' => '1/1/1:1', 'serial_number' => 'HWTC-1234', 'onu_name' => 'ONU-A', 'state' => 'online', 'phase_state' => 'O5', 'inventory_collected_at' => $staleAt, 'state_collected_at' => $staleAt],
            ['olt_id' => 77, 'onu_ref' => '1/1/1:2', 'serial_number' => 'HWTC1234', 'onu_name' => 'ONU-B', 'state' => 'online', 'phase_state' => 'O5', 'inventory_collected_at' => $staleAt, 'state_collected_at' => $staleAt],
        ]);
        DB::table('genieacs_devices')->insert([
            ['device_id' => 'dev-a', 'customer_id' => 10, 'serial_number' => 'HWTC-1234', 'manufacturer' => 'FiberHome', 'model' => 'HG6245D', 'last_seen_sync_at' => now(), 'last_inform_at' => now()],
            ['device_id' => 'dev-b', 'customer_id' => 10, 'serial_number' => 'HWTC1234', 'manufacturer' => 'FiberHome', 'model' => 'HG6245D', 'last_seen_sync_at' => now(), 'last_inform_at' => now()],
        ]);

        $before = [
            'olt' => DB::table('olt_onus')->count(),
            'genieacs' => DB::table('genieacs_devices')->count(),
        ];
        $reconciliation = (new DeviceRecoveryReconciliationService)->forCase($case->fresh(), $this->service);

        $this->assertTrue($reconciliation['olt_onu']['matched']);
        $this->assertTrue($reconciliation['olt_onu']['ambiguous']);
        $this->assertSame(2, $reconciliation['olt_onu']['candidate_count']);
        $this->assertTrue($reconciliation['olt_onu']['stale']);
        $this->assertTrue($reconciliation['genieacs']['matched']);
        $this->assertTrue($reconciliation['genieacs']['ambiguous']);
        $this->assertSame(2, $reconciliation['genieacs']['candidate_count']);
        $this->assertFalse($reconciliation['genieacs']['stale']);
        $this->assertSame($before, [
            'olt' => DB::table('olt_onus')->count(),
            'genieacs' => DB::table('genieacs_devices')->count(),
        ]);
    }

    public function test_exception_acceptance_preserves_exception_and_allows_ticket_close(): void
    {
        [$actor, $technician, $case, $item] = $this->startedCase();
        $ticket = SupportTicket::query()->findOrFail(20);
        $case = $this->service->capture($case, $item, $technician, null, 'lost', 'Perangkat tidak ditemukan', [], 'capture-lost');

        try {
            $this->service->assertTicketCanClose($ticket);
            $this->fail('An unresolved exception must block ticket closing.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $case = $this->service->resolveException($case, $actor, 'accept', 'Disetujui supervisor untuk ditutup', 'accept-lost');

        $this->assertSame(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED, $case->status);
        $this->assertSame(DeviceRecoveryService::ITEM_EXCEPTION, $case->items->first()->status);
        $this->assertNull($case->items->first()->verified_at);
        $this->service->assertTicketCanClose($ticket);
    }

    public function test_exception_acceptance_rejects_an_item_without_a_final_result(): void
    {
        [$actor, , $case, $item] = $this->startedCase();
        $item->forceFill(['status' => DeviceRecoveryService::ITEM_EXCEPTION])->save();
        $case->forceFill(['status' => DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED])->save();
        DeviceRecoveryItem::create([
            'recovery_case_id' => $case->id,
            'device_type' => 'router',
            'status' => 'unexpected',
            'condition' => DeviceRecoveryService::CONDITION_UNKNOWN,
        ]);

        $this->expectException(ValidationException::class);
        $this->service->assertTicketCanClose($case->ticket, false);
    }

    public function test_assignment_cannot_be_changed_after_pickup_started(): void
    {
        [$actor, $technician, $case] = $this->startedCase();
        $otherTechnician = $this->user(3, 'teknisi');

        $this->expectException(ValidationException::class);
        $this->service->assign($case, $actor, $otherTechnician->id, null, 'reassign-after-start');
    }

    public function test_cancellation_is_rejected_after_pickup_started(): void
    {
        [$actor, , $case] = $this->startedCase();
        $eventCount = $case->events()->count();

        $this->expectException(ValidationException::class);
        try {
            $this->service->cancel($case, $actor, 'Tidak jadi diambil', 'cancel-after-start');
        } finally {
            $this->assertSame(DeviceRecoveryService::STATUS_PICKUP_STARTED, $case->fresh()->status);
            $this->assertSame($eventCount, $case->fresh()->events()->count());
        }
    }

    public function test_read_only_mode_exposes_page_and_blocks_recovery_writes(): void
    {
        [$actor, , $case] = $this->startedCase();
        config(['device-recovery.mode' => 'read_only']);

        $eventsBefore = DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->count();

        $this->actingAs($actor)
            ->get(route('device-recovery.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('readOnly', true));

        $this->actingAs($actor)
            ->post(route('device-recovery.cancel', $case), [
                'reason' => 'Read-only guard test',
                'idempotency_key' => 'read-only-cancel',
            ])
            ->assertStatus(423)
            ->assertJsonPath('error_code', 'device_recovery_read_only');

        $this->assertSame($eventsBefore, DeviceRecoveryEvent::query()->where('recovery_case_id', $case->id)->count());
        $this->assertSame(DeviceRecoveryService::STATUS_PICKUP_STARTED, $case->fresh()->status);
    }

    public function test_all_recovery_write_routes_have_the_read_only_guard(): void
    {
        $routeNames = [
            'device-recovery.create',
            'device-recovery.add-item',
            'device-recovery.update-item',
            'device-recovery.assign',
            'device-recovery.schedule',
            'device-recovery.start',
            'device-recovery.capture',
            'device-recovery.receive',
            'device-recovery.quick-record',
            'device-recovery.resolve-exception',
            'device-recovery.cancel',
            'device-recovery.report',
        ];

        foreach ($routeNames as $routeName) {
            $this->assertContains(
                'device-recovery.writable',
                app('router')->getRoutes()->getByName($routeName)->gatherMiddleware(),
                $routeName.' must be protected by the read-only rollout guard.',
            );
        }
    }

    public function test_index_enforces_a_bounded_page_size_without_device_source_fan_out(): void
    {
        $actor = $this->user(1, 'superadmin');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $this->service->createFromApprovedDismantle($ticket, $actor);

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $this->actingAs($actor)
            ->get(route('device-recovery.index', ['limit' => 50]))
            ->assertOk();

        $deviceQueries = array_filter($queries, static fn (string $query): bool => str_contains($query, 'olt_onus') || str_contains($query, 'genieacs_devices'));
        $this->assertSame([], array_values($deviceQueries));

        // validate() redirects (302) a browser request back with errors; ask
        // for JSON so the out-of-range limit surfaces as 422 on `limit`.
        $this->actingAs($actor)
            ->getJson(route('device-recovery.index', ['limit' => 51]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('limit');
    }

    public function test_pickup_schedule_is_entered_in_wib_stored_utc_and_round_trips_without_drift(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00', 'UTC'));
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendRecoveryAssignment')->andReturn(true));

        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);

        // The admin types 14:00 in the datetime-local input: that is WIB.
        $this->actingAs($actor)->post(route('device-recovery.assign', $case), [
            'assigned_to' => $technician->id,
            'scheduled_at' => '2026-10-20T14:00',
            'idempotency_key' => 'wib-assign-1',
        ])->assertSessionHasNoErrors();

        $stored = fn () => DB::table('device_recovery_cases')->where('id', $case->id)->value('scheduled_at');
        $this->assertSame('2026-10-20 07:00:00', $stored());

        // Reload: the edit form pre-fills from the JSON payload (+07:00 offset).
        for ($i = 1; $i <= 3; $i++) {
            $iso = $this->actingAs($actor)->getJson(route('device-recovery.show', $case))
                ->assertOk()
                ->json('scheduled_at');
            $this->assertSame('2026-10-20T14:00:00+07:00', $iso);

            // Browser form value derived from that payload (toWibDateTimeLocal), saved again.
            $this->actingAs($actor)->post(route('device-recovery.schedule', $case), [
                'scheduled_at' => substr($iso, 0, 16),
                'idempotency_key' => 'wib-schedule-'.$i,
            ])->assertSessionHasNoErrors();

            $this->assertSame('2026-10-20 07:00:00', $stored(), "drifted after save #{$i}");
        }

        $this->travelBack();
    }

    public function test_pickup_schedule_and_assign_with_explicit_offset_store_the_same_instant(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00', 'UTC'));
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendRecoveryAssignment')->andReturn(true));

        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);

        $stored = fn () => DB::table('device_recovery_cases')->where('id', $case->id)->value('scheduled_at');

        // 14:00 +07:00 and 07:00 Z are the same instant: stored as 07:00 UTC either way.
        foreach (['2026-10-20T14:00:00+07:00', '2026-10-20T07:00:00Z'] as $i => $value) {
            $this->actingAs($actor)->post(route('device-recovery.assign', $case), [
                'assigned_to' => $technician->id,
                'scheduled_at' => $value,
                'idempotency_key' => 'offset-assign-'.$i,
            ])->assertSessionHasNoErrors();
            $this->assertSame('2026-10-20 07:00:00', $stored(), "assign with {$value}");

            $this->actingAs($actor)->post(route('device-recovery.schedule', $case), [
                'scheduled_at' => $value,
                'idempotency_key' => 'offset-schedule-'.$i,
            ])->assertSessionHasNoErrors();
            $this->assertSame('2026-10-20 07:00:00', $stored(), "schedule with {$value}");
        }

        $this->travelBack();
    }

    public function test_recovery_assignment_wa_shows_the_wib_time_the_user_entered(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00', 'UTC'));
        $this->mock(NotificationService::class, fn ($mock) => $mock->shouldReceive('sendRecoveryAssignment')->andReturn(true));

        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);

        $this->actingAs($actor)->post(route('device-recovery.assign', $case), [
            'assigned_to' => $technician->id,
            'scheduled_at' => '2026-10-20T14:00',
            'idempotency_key' => 'wib-wa-assign',
        ])->assertSessionHasNoErrors();

        $case = $case->fresh(['ticket', 'customer', 'assignee', 'items']);
        $case->setRelation('assignee', (new User)->forceFill(['name' => 'Teknisi', 'phone' => '081234567890']));

        $sent = null;
        $evolution = \Mockery::mock(EvolutionApiService::class)->makePartial();
        $evolution->shouldReceive('send')->once()->andReturnUsing(function (string $to, string $message) use (&$sent): array {
            $sent = $message;

            return ['success' => true];
        });
        $evolution->sendRecoveryAssignment($case);

        $this->assertNotNull($sent);
        $this->assertStringContainsString('Jadwal: 20/10/2026 14:00 WIB', $sent);

        $this->travelBack();
    }

    private function user(int $id, string $role): User
    {
        DB::table('users')->insert([
            'id' => $id,
            'name' => ucfirst($role),
            'email' => $role.$id.'@example.test',
            'password' => password_hash((string) $id, PASSWORD_BCRYPT),
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::query()->findOrFail($id);
    }

    private function area(int $id): void
    {
        DB::table('areas')->insert([
            'id' => $id,
            'name' => 'Area '.$id,
            'code' => 'A'.$id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function customer(int $id, string $code, string $name, string $serial, string $status): void
    {
        DB::table('customers')->insert([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'area_id' => 4,
            'onu_serial' => $serial,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ticket(int $id, int $customerId, string $code, string $status): SupportTicket
    {
        DB::table('support_tickets')->insert([
            'id' => $id,
            'code' => $code,
            'customer_id' => $customerId,
            'area_id' => 4,
            'status' => $status,
            'type' => 'other',
            'priority' => 'normal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SupportTicket::query()->findOrFail($id);
    }

    private function approvedDismantle(int $ticketId, int $actorId): void
    {
        DB::table('ticket_activities')->insert([
            'ticket_id' => $ticketId,
            'user_id' => $actorId,
            'action' => 'customer_lifecycle_applied',
            'old_value' => json_encode(['customer' => ['status' => 'active']]),
            'new_value' => json_encode(['lifecycle_action' => 'dismantle']),
            'description' => 'Approved dismantle',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: User, 1: User, 2: DeviceRecoveryCase, 3: DeviceRecoveryItem}
     */
    private function startedCase(): array
    {
        $actor = $this->user(1, 'superadmin');
        $technician = $this->user(2, 'teknisi');
        $this->area(4);
        $this->customer(10, 'CUST-10', 'Pelanggan 10', 'HWTC-1234', 'terminated');
        $ticket = $this->ticket(20, 10, 'TCK-20', 'closed');
        $this->approvedDismantle($ticket->id, $actor->id);
        $case = $this->service->createFromApprovedDismantle($ticket, $actor);
        $case = $this->service->assign($case, $actor, $technician->id, null, 'assign-started');
        $case = $this->service->start($case, $technician, 'start-started');
        $item = DeviceRecoveryItem::query()->where('recovery_case_id', $case->id)->firstOrFail();

        return [$actor, $technician, $case, $item];
    }

    private function createBaseTables(): void
    {
        Schema::create('customer_pending_terminations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            $table->string('status', 20)->default('pending');
            $table->json('last_reasons')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->string('role');
            $table->timestamps();
        });
        Schema::create('areas', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->timestamps();
        });
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('onu_serial')->nullable();
            $table->string('status')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->string('status');
            $table->string('type');
            $table->string('priority');
            $table->string('report_id')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
        });
        Schema::create('splitter', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('area_id')->nullable();
            $table->unsignedBigInteger('parent_odp_odc_id')->nullable();
            $table->integer('used_ports')->default(0);
        });
        // DeviceRecoveryOltCleanupService and request middleware read feature
        // flags through Setting::get(); mirror the production settings table.
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string');
            $table->string('group')->default('general');
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('ticket_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('action');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    private function runRecoveryMigrations(): void
    {
        foreach (glob(base_path('database/migrations/*device_recovery*.php')) as $path) {
            $migration = require $path;
            $migration->up();
        }
    }
}
