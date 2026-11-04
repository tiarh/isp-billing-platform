<?php

namespace Tests\Unit;

use App\Models\DeviceRecoveryCase;
use App\Models\User;
use App\Policies\DeviceRecoveryCasePolicy;
use App\Services\DeviceRecoveryService;
use PHPUnit\Framework\TestCase;

class DeviceRecoveryServiceTest extends TestCase
{
    public function test_serial_normalization_matches_existing_inventory_convention(): void
    {
        $service = new DeviceRecoveryService;

        $this->assertSame('HWTC12345678', $service->normalizeSerial(' hwtc-1234 5678 '));
        $this->assertNull($service->normalizeSerial(null));
        $this->assertNull($service->normalizeSerial('---'));
    }

    public function test_status_presentation_keeps_assignment_separate_from_physical_pickup(): void
    {
        $service = new DeviceRecoveryService;

        $this->assertSame([
            'group' => 'Menunggu penugasan',
            'substatus' => 'Menunggu penugasan',
        ], $service->statusPresentation(DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT));
        $this->assertSame([
            'group' => 'Perangkat sedang diambil',
            'substatus' => 'Ditugaskan',
        ], $service->statusPresentation(DeviceRecoveryService::STATUS_ASSIGNED));
        $this->assertSame([
            'group' => 'Sudah diambil',
            'substatus' => 'Diterima gudang',
        ], $service->statusPresentation(DeviceRecoveryService::STATUS_RECEIVED));
        $this->assertSame([
            'group' => 'Perlu tinjauan',
            'substatus' => 'Exception disetujui',
        ], $service->statusPresentation(DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED));
    }

    public function test_assigned_technician_can_view_and_start_only_own_case(): void
    {
        $policy = new DeviceRecoveryCasePolicy;
        $technician = (new User)->forceFill(['role' => 'teknisi']);
        $technician->id = 12;
        $case = new DeviceRecoveryCase(['assigned_to' => 12]);

        $this->assertTrue($policy->view($technician, $case));
        $this->assertTrue($policy->start($technician, $case));

        $case->assigned_to = 13;
        $this->assertFalse($policy->view($technician, $case));
        $this->assertFalse($policy->start($technician, $case));
    }

    public function test_technician_cannot_assign_receive_resolve_or_cancel(): void
    {
        $policy = new DeviceRecoveryCasePolicy;
        $technician = (new User)->forceFill(['role' => 'teknisi']);
        $technician->id = 12;
        $case = new DeviceRecoveryCase(['assigned_to' => 12, 'area_id' => 4]);

        $this->assertFalse($policy->assign($technician, $case));
        $this->assertFalse($policy->receive($technician, $case));
        $this->assertFalse($policy->resolveException($technician, $case));
        $this->assertFalse($policy->cancel($technician, $case));
    }
}
