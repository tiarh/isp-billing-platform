<?php

namespace Tests\Feature;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class AttendanceExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_export_rejects_missing_token(): void
    {
        config(['services.attendance_export.token' => 'secret-token']);

        $this->getJson(route('api.attendances.export'))
            ->assertUnauthorized();
    }

    public function test_attendance_export_rejects_wrong_token(): void
    {
        config(['services.attendance_export.token' => 'secret-token']);

        $this->getJson(route('api.attendances.export'), [
            'Authorization' => 'Bearer wrong-token',
        ])->assertUnauthorized();
    }

    public function test_attendance_export_returns_daily_records(): void
    {
        config([
            'services.attendance_export.token' => 'secret-token',
            'services.attendance_export.drive_public_url' => 'https://drive.example.test/files',
        ]);

        $day = Carbon::parse('2026-07-08', 'Asia/Jakarta');

        Attendance::create([
            'sender_name' => 'BUDI',
            'sender_number' => '628123@g.us',
            'caption' => 'Masuk',
            'photo_url' => 'ABSENSI/JULI 2026/8 JULI/BUDI_071500.jpg',
            'message_id' => 'MSG-001',
            'created_at' => $day->copy()->setTime(7, 15)->utc(),
        ]);

        Attendance::create([
            'sender_name' => 'ANI',
            'sender_number' => '628123@g.us',
            'caption' => null,
            'photo_url' => 'ABSENSI/JULI 2026/8 JULI/ANI_080000.jpg',
            'message_id' => 'MSG-002',
            'created_at' => $day->copy()->setTime(8, 0)->utc(),
        ]);

        Attendance::create([
            'sender_name' => 'OLD',
            'sender_number' => '628123@g.us',
            'caption' => null,
            'photo_url' => 'ABSENSI/JULI 2026/7 JULI/OLD_080000.jpg',
            'message_id' => 'MSG-OLD',
            'created_at' => $day->copy()->subDay()->setTime(8, 0)->utc(),
        ]);

        $response = $this->getJson(route('api.attendances.export', ['date' => '2026-07-08']), [
            'X-Attendance-Token' => 'secret-token',
        ])->assertOk();

        $response->assertJsonPath('date', '2026-07-08');
        $response->assertJsonPath('timezone', 'Asia/Jakarta');
        $response->assertJsonPath('total', 2);
        $response->assertJsonPath('unique_senders', 2);
        $response->assertJsonCount(2, 'attendances');
        $response->assertJsonPath('attendances.0.sender_name', 'BUDI');
        $response->assertJsonPath(
            'attendances.0.photo_url',
            'https://drive.example.test/files/ABSENSI/JULI%202026/8%20JULI/BUDI_071500.jpg'
        );
    }

    public function test_attendance_export_returns_service_unavailable_when_token_not_configured(): void
    {
        config(['services.attendance_export.token' => '']);

        $this->getJson(route('api.attendances.export'))
            ->assertStatus(503);
    }

    public function test_attendance_export_rejects_token_in_query_string(): void
    {
        config(['services.attendance_export.token' => 'secret-token']);

        $this->getJson(route('api.attendances.export', ['token' => 'secret-token']))
            ->assertUnauthorized();
    }

    public function test_attendance_export_does_not_expose_sender_number(): void
    {
        config([
            'services.attendance_export.token' => 'secret-token',
            'services.attendance_export.drive_public_url' => 'https://drive.example.test/files',
        ]);

        Attendance::create([
            'sender_name' => 'BUDI',
            'sender_number' => '120363000000000102@g.us',
            'photo_url' => 'ABSENSI/JULI 2026/8 JULI/BUDI_071500.jpg',
            'message_id' => 'MSG-001',
            'created_at' => Carbon::parse('2026-07-08 07:15:00', 'Asia/Jakarta')->utc(),
        ]);

        $this->getJson(route('api.attendances.export', ['date' => '2026-07-08']), [
            'X-Attendance-Token' => 'secret-token',
        ])
            ->assertOk()
            ->assertJsonMissingPath('attendances.0.sender_number');
    }

    public function test_attendance_export_keeps_evening_checkin_on_its_wib_day_and_offset(): void
    {
        config([
            'services.attendance_export.token' => 'secret-token',
            'services.attendance_export.drive_public_url' => 'https://drive.example.test/files',
        ]);

        // 20:00 WIB = 13:00 UTC, stored as UTC.
        Attendance::create([
            'sender_name' => 'MALAM',
            'sender_number' => '628123@g.us',
            'photo_url' => 'ABSENSI/JULI 2026/8 JULI/MALAM_200000.jpg',
            'message_id' => 'MSG-EVE',
            'created_at' => Carbon::parse('2026-07-08 13:00:00', 'UTC'),
        ]);

        $response = $this->getJson(route('api.attendances.export', ['date' => '2026-07-08']), [
            'X-Attendance-Token' => 'secret-token',
        ])->assertOk();

        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('attendances.0.checked_in_at', '2026-07-08T20:00:00+07:00');

        $this->getJson(route('api.attendances.export', ['date' => '2026-07-09']), [
            'X-Attendance-Token' => 'secret-token',
        ])->assertOk()->assertJsonPath('total', 0);
    }
}
