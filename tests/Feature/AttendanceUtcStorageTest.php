<?php

namespace Tests\Feature;

use App\Console\Commands\HealWaGateway;
use App\Http\Controllers\AttendanceController;
use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Attendance rows are stored as UTC while the photo path/name stays WIB.
 * T6 (storage), T8 (wa:heal catch-up window) and the Laravel behaviour pin.
 */
#[Group('blocking-core')]
class AttendanceUtcStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_webhook_attendance_is_stored_as_utc_with_wib_photo_name(): void
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

        // 07:15 WIB on 2026-10-13 == 00:15 UTC the same day.
        app(AttendanceController::class)->processAttendanceMessage([
            'key' => [
                'id' => 'MSG-UTC-1',
                'remoteJid' => 'absensi@g.us',
                'participant' => '628123456789@s.whatsapp.net',
            ],
            'message' => ['imageMessage' => ['caption' => 'absen pagi']],
            'messageTimestamp' => Carbon::parse('2026-10-13 07:15:00', 'Asia/Jakarta')->timestamp,
        ], 'Nomor Teknisi');

        $row = DB::table('attendances')->where('message_id', 'MSG-UTC-1')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-10-13 00:15:00', (string) $row->created_at);
        $this->assertStringContainsString('_071500', (string) $row->photo_url);
        $this->assertStringContainsString('attendance/2026/10/13/', (string) $row->photo_url);
    }

    public function test_webhook_attendance_crossing_midnight_keeps_wib_folder_but_utc_created_at(): void
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

        // 02:30 WIB on 2026-10-13 == 19:30 UTC on 2026-10-12.
        app(AttendanceController::class)->processAttendanceMessage([
            'key' => [
                'id' => 'MSG-UTC-2',
                'remoteJid' => 'absensi@g.us',
                'participant' => '628123456789@s.whatsapp.net',
            ],
            'message' => ['imageMessage' => ['caption' => 'lembur']],
            'messageTimestamp' => Carbon::parse('2026-10-13 02:30:00', 'Asia/Jakarta')->timestamp,
        ], 'Nomor Teknisi');

        $row = DB::table('attendances')->where('message_id', 'MSG-UTC-2')->first();
        $this->assertSame('2026-10-12 19:30:00', (string) $row->created_at);
        $this->assertStringContainsString('attendance/2026/10/13/', (string) $row->photo_url);
    }

    public function test_heal_catchup_sees_early_morning_wib_row_as_todays_activity(): void
    {
        // 06:30 WIB on 2026-10-13 == 23:30 UTC on 2026-10-12.
        Carbon::setTestNow(Carbon::parse('2026-10-13 06:30:00', 'Asia/Jakarta'));
        Cache::forget('wa:attendance_catchup_hour');

        // 05:00 WIB today == 22:00 UTC yesterday: already "today" in WIB.
        DB::table('attendances')->insert([
            'sender_name' => 'PAGI',
            'message_id' => 'MSG-HEAL-1',
            'created_at' => '2026-10-12 22:00:00',
            'updated_at' => '2026-10-12 22:00:00',
        ]);

        $this->assertFalse($this->shouldCatchUp());
    }

    public function test_heal_catchup_ignores_yesterday_evening_wib_row(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-13 06:30:00', 'Asia/Jakarta'));
        Cache::forget('wa:attendance_catchup_hour');

        // 20:00 WIB yesterday == 13:00 UTC yesterday: not today in WIB.
        DB::table('attendances')->insert([
            'sender_name' => 'MALAM',
            'message_id' => 'MSG-HEAL-2',
            'created_at' => '2026-10-12 13:00:00',
            'updated_at' => '2026-10-12 13:00:00',
        ]);

        $this->assertTrue($this->shouldCatchUp());
    }

    public function test_laravel_stores_wib_carbon_wall_clock_without_conversion(): void
    {
        // Pins the framework behaviour that caused the bug: a non-UTC Carbon
        // is stored as its wall clock, so callers must ->utc() first.
        $wib = Carbon::parse('2026-10-13 07:15:00', 'Asia/Jakarta');

        $naive = Attendance::create(['sender_name' => 'NAIVE', 'message_id' => 'PIN-1', 'created_at' => $wib]);
        $fixed = Attendance::create(['sender_name' => 'FIXED', 'message_id' => 'PIN-2', 'created_at' => $wib->copy()->utc()]);

        $this->assertSame('2026-10-13 07:15:00', (string) DB::table('attendances')->where('id', $naive->id)->value('created_at'));
        $this->assertSame('2026-10-13 00:15:00', (string) DB::table('attendances')->where('id', $fixed->id)->value('created_at'));
    }

    private function shouldCatchUp(): bool
    {
        $command = app(HealWaGateway::class);
        $method = new \ReflectionMethod($command, 'shouldCatchUpToday');
        $method->setAccessible(true);

        return $method->invoke($command);
    }
}
