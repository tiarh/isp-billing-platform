<?php

namespace Tests\Feature;

use App\Http\Controllers\AttendanceController;
use App\Models\Attendance;
use App\Services\AttendanceExportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttendanceNextcloudFallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_attendance_photo_falls_back_to_app_storage_when_nextcloud_password_missing(): void
    {
        config([
            'filesystems.default' => 'public',
            'services.nextcloud.pass' => '',
        ]);
        Storage::fake('public');

        $controller = app(AttendanceController::class);
        $method = new \ReflectionMethod($controller, 'storeAttendancePhoto');
        $method->setAccessible(true);

        $path = $method->invoke($controller, 'photo-bytes', 'Teknisi UAT', Carbon::parse('2026-07-28 07:15:00', 'Asia/Jakarta'));

        $this->assertStringStartsWith('storage:attendance/2026/07/28/Teknisi_UAT_071500', $path);
        Storage::disk('public')->assertExists(substr($path, 8));
    }

    public function test_attendance_export_supports_storage_photo_urls(): void
    {
        config(['filesystems.default' => 'public']);

        $url = app(AttendanceExportService::class)->publicPhotoUrl('storage:attendance/2026/07/28/UAT.jpg');

        $this->assertStringContainsString('/storage/attendance/2026/07/28/UAT.jpg', $url);
    }

    public function test_same_phone_with_different_photos_is_accepted_as_separate_attendance(): void
    {
        config([
            'filesystems.default' => 'public',
            'services.evolution_api.base_url' => 'https://evo.test',
            'services.evolution_api.instance_name' => 'ops',
            'services.evolution_api.api_key' => 'key',
            'services.nextcloud.pass' => '',
        ]);
        Storage::fake('public');
        Http::fake([
            'https://evo.test/chat/getBase64FromMediaMessage/ops' => Http::response([
                'base64' => base64_encode('photo-bytes'),
            ]),
        ]);

        $controller = app(AttendanceController::class);
        $base = [
            'key' => [
                'remoteJid' => 'absensi@g.us',
                'participant' => '628123456789@s.whatsapp.net',
            ],
            'message' => ['imageMessage' => ['caption' => 'absen pagi']],
            'messageTimestamp' => Carbon::parse('2026-07-28 07:15:00', 'Asia/Jakarta')->timestamp,
        ];

        $controller->processAttendanceMessage(array_replace_recursive($base, ['key' => ['id' => 'MSG-1']]), 'Nomor Teknisi');
        $controller->processAttendanceMessage(array_replace_recursive($base, ['key' => ['id' => 'MSG-2']]), 'Nomor Teknisi');

        $this->assertSame(2, Attendance::where('sender_jid', '628123456789@s.whatsapp.net')->count());
        $this->assertSame(['accepted', 'accepted'], Attendance::orderBy('id')->pluck('review_status')->all());
    }
}
