<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Services\EvolutionApiService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** recap:preview prints a reply to the terminal: no WA, no files, no writes, flags not consulted. */
#[Group('blocking-psb')]
class RecapPreviewCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:12', 'Asia/Jakarta'));
        File::deleteDirectory(storage_path('app/recap'));
        $area = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $package = Package::create(['code' => 'P10', 'name' => '10 Mbps', 'price' => 100000]);
        $olt = Olt::create(['name' => 'RA', 'code' => 'RA-1', 'vendor' => 'zte_c300']);
        Customer::create([
            'code' => 'PV-1', 'name' => 'Budi Contoh', 'address' => 'Dusun', 'package_id' => $package->id, 'area_id' => $area->id,
            'pppoe_user' => 'budi1', 'status' => 'active', 'join_date' => '2026-01-01', 'olt_id' => $olt->id, 'olt_port_label' => '1/1/1:1',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'serial_number' => 'ZTEG00000001', 'state' => 'offline', 'phase_state' => 'LOS',
            'state_collected_at' => now()->subMinutes(3), 'inventory_collected_at' => now()->subMinutes(5),
        ]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_it_prints_the_reply_with_stats_and_sends_or_writes_nothing(): void
    {
        Queue::fake();
        Http::fake();
        $evo = \Mockery::mock(EvolutionApiService::class);
        $evo->shouldNotReceive('sendToGroup');
        $evo->shouldNotReceive('sendDocument');
        $evo->shouldNotReceive('forChannel');
        $this->app->instance(EvolutionApiService::class, $evo);

        $this->artisan('recap:preview', ['text' => '#rekap onu klampok', '--stats' => true])
            ->expectsOutputToContain('DRY: nothing is sent')
            ->expectsOutputToContain('*REKAP KESEHATAN ONU - KLAMPOK*')
            ->expectsOutputToContain('collect:')
            ->expectsOutputToContain('olt RA')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist(storage_path('app/recap'));
    }

    public function test_the_flags_are_not_consulted_and_usage_is_shown_for_a_missing_area(): void
    {
        $this->artisan('recap:preview', ['text' => '#rekap onu'])
            ->expectsOutputToContain('Format: *#rekap onu <daerah|semua>*')
            ->assertSuccessful();
    }

    public function test_now_option_and_invalid_input(): void
    {
        $this->artisan('recap:preview', ['text' => '#rekap onu semua', '--now' => '2026-10-15 10:30'])
            ->expectsOutputToContain('Posisi: 15/10/2026 10:30 WIB')
            ->assertSuccessful();

        $this->artisan('recap:preview', ['text' => '#rekap onu semua', '--now' => 'bukan tanggal'])->assertFailed();
        $this->artisan('recap:preview', ['text' => '#psb bantu'])->assertFailed();
        $this->artisan('recap:preview', ['text' => 'halo'])->assertFailed();
    }

    public function test_an_unknown_area_prints_the_not_found_text(): void
    {
        $this->artisan('recap:preview', ['text' => '#rekap onu zzzzqqq'])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertSuccessful();
    }
}
