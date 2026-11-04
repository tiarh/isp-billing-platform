<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\TechnicianMember;
use App\Services\PsbDraftCustomerSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class Sprint2FieldDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_psb_draft_media_syncs_to_customer_without_overwriting_manual_files(): void
    {
        $package = Package::create(['code' => 'PKG-200M', 'name' => '200M', 'price' => 200000, 'rate_limit' => '200M']);
        $area = Area::create(['name' => 'Randuagung', 'code' => 'RA']);
        $customer = Customer::create([
            'code' => 'CUST-PSB-1',
            'name' => 'Pelanggan PSB',
            'address' => 'Jl. PSB Test',
            'pppoe_user' => 'psb.user',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'pending_installation',
            'ktp_photo_url' => 'manual/ktp.jpg',
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'customer_id' => $customer->id,
            'full_name' => 'Pelanggan PSB',
            'sn' => ' fhtt1234 ',
            'odp' => 'ODP 9.4',
            'nik' => '3500000000000001',
            'ktp_photo_path' => 'psb/2026/07/ktp_ok.jpg',
            'router_photo_path' => 'psb/2026/07/sn_ok.jpg',
            'meta' => ['photo_class' => ['ktp_score' => 80]],
        ]);

        $result = app(PsbDraftCustomerSyncService::class)->sync($draft);
        $customer->refresh();

        $this->assertSame('synced', $result['status']);
        $this->assertSame('manual/ktp.jpg', $customer->getRawOriginal('ktp_photo_url'));
        $this->assertSame('psb/2026/07/sn_ok.jpg', $customer->getRawOriginal('odp_photo_url'));
        $this->assertSame('FHTT1234', $customer->onu_serial);
        $this->assertSame('ODP 9.4', $customer->odp_name);
        $this->assertSame('3500000000000001', $customer->nik);
        $this->assertSame('synced', $draft->fresh()->meta['psb_customer_sync']['status']);
    }

    public function test_attendance_caption_can_map_one_photo_to_multiple_technicians(): void
    {
        $budi = TechnicianMember::create(['name' => 'Budi', 'is_active' => true]);
        $agus = TechnicianMember::create(['name' => 'Agus Contoh', 'is_active' => true]);

        $method = new ReflectionMethod(AttendanceController::class, 'resolveAttendanceTechnicians');
        $method->setAccessible(true);
        $rows = $method->invoke(new AttendanceController, 'Absen pagi Budi, Agus Contoh', 'WA Sender');

        $this->assertSame([$budi->id, $agus->id], collect($rows)->pluck('technician_id')->all());
        $this->assertSame(['accepted', 'accepted'], collect($rows)->pluck('review_status')->all());
    }

    public function test_attendance_ambiguous_shared_photo_requires_review(): void
    {
        $method = new ReflectionMethod(AttendanceController::class, 'resolveAttendanceTechnicians');
        $method->setAccessible(true);
        $rows = $method->invoke(new AttendanceController, 'absen berdua satu hp', 'Nomor Teknisi');

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]['technician_id']);
        $this->assertSame('pending_review', $rows[0]['review_status']);
    }
}
