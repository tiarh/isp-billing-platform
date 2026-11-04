<?php

namespace Tests\Feature;

use App\Exceptions\PsbLockBusyException;
use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Models\CustomerMigration;
use App\Models\PsbStarliteEntry;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\PsbStarliteReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;
use Tests\TestCase;

class AgP0VerifyPortTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_wa_job_handles_lock_busy_and_retains_ack()
    {
        Queue::fake();

        $payload = [
            'message_id' => 'MSG123',
            'group_jid' => '123@g.us',
            'text' => '#migrasi tes',
        ];

        $this->mock(CustomerMigrationService::class, function (MockInterface $mock) use ($payload) {
            $mock->shouldReceive('reply')
                ->with('123@g.us', \Mockery::on(function ($msg) {
                    return str_contains($msg, 'Data migrasi diterima') && str_contains($msg, 'MSG123');
                }))
                ->once();

            $mock->shouldReceive('handleInbound')
                ->with($payload)
                ->once()
                ->andThrow(new PsbLockBusyException('Target lock busy'));

            $mock->shouldReceive('reply')
                ->with('123@g.us', \Mockery::on(function ($msg) {
                    return str_contains($msg, 'Antrian migrasi sibuk, otomatis dicoba lagi sekitar 20 detik');
                }))
                ->once();
        });

        $job = \Mockery::mock(ProcessCustomerMigrationWaJob::class.'[release,attempts]', [$payload])->makePartial();
        $job->shouldReceive('attempts')->andReturn(1);
        $job->shouldReceive('release')->withArgs(function ($delay) {
            return $delay >= 15 && $delay <= 25; // job auto-resume 15-25 detik
        })->once();

        $service = app(CustomerMigrationService::class);
        $job->handle($service);
    }

    public function test_psb_starlite_dashboard_rw_rt_consistency()
    {
        PsbStarliteEntry::create([
            'installed_on' => now('Asia/Jakarta')->toDateString(),
            'rw' => '01',
            'pppoe_user' => 'CUSTOMER_RT02_RW01_01',
            'sn' => 'FHTT-AGP0-001',
            'install_status' => 'Success',
            'meta' => [
                'service_classification' => 'Reguler',
                'mikrotik_profile' => 'STARTLITE',
            ],
        ]);
        PsbStarliteEntry::create([
            'installed_on' => now('Asia/Jakarta')->toDateString(),
            'rw' => '01',
            'pppoe_user' => 'CUSTOMER_RT03_RW01_02',
            'sn' => 'FHTT-AGP0-002',
            'install_status' => 'Success',
        ]);
        PsbStarliteEntry::create([
            'installed_on' => now('Asia/Jakarta')->toDateString(),
            'rw' => '02',
            'pppoe_user' => 'CUSTOMER_NO_GROUP_03',
            'sn' => 'FHTT-AGP0-003',
            'install_status' => 'Success',
        ]);

        Cache::flush();
        $service = new PsbStarliteReportService;
        $data = $service->dashboard();

        $byRw = collect($data['by_rw'])->keyBy('rw');

        $this->assertTrue($byRw->has('01'));
        $this->assertEquals(2, $byRw['01']['total']);
        $this->assertEquals(2, $byRw['01']['success']);
        $this->assertCount(2, $byRw['01']['rts']);

        $this->assertArrayHasKey('02', $byRw['01']['rts']);
        $this->assertEquals(1, $byRw['01']['rts']['02']['total']);

        $this->assertTrue($byRw->has('02'));
        $this->assertEquals(1, $byRw['02']['total']);
        $this->assertArrayHasKey('(kosong)', $byRw['02']['rts']);

        $unified = $service->unifiedRows();

        $hasServiceClass = false;
        $hasProfile = false;
        foreach ($unified as $row) {
            if (array_key_exists('service_classification', $row) && $row['service_classification'] === 'Reguler') {
                $hasServiceClass = true;
            }
            if (array_key_exists('mikrotik_profile', $row) && $row['mikrotik_profile'] === 'STARTLITE') {
                $hasProfile = true;
            }
        }
        $this->assertTrue($hasServiceClass, 'service_classification field is missing or invalid');
        $this->assertTrue($hasProfile, 'mikrotik_profile field is missing or invalid');
    }

    public function test_migration_index_exposes_dynamic_rw_rt_summary(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'test',
            'message_id' => 'mig-rw-rt-1',
            'full_name' => 'MIG RW4 RT1',
            'new_sn' => 'FHTTRWRT001',
            'rw' => '4',
            'rt' => '1',
            'created_at' => now('Asia/Jakarta'),
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'test',
            'message_id' => 'mig-rw-rt-2',
            'full_name' => 'MIG RW4 RT2',
            'new_sn' => 'FHTTRWRT002',
            'rw' => '04',
            'rt' => '02',
            'created_at' => now('Asia/Jakarta'),
        ]);

        $this->actingAs($user)
            ->get(route('customer-migrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CustomerMigrations/Index')
                ->where('stats.by_rw.0.rw', '04')
                ->where('stats.by_rw.0.total', 2)
                ->where('stats.by_rw.0.synced', 1)
                ->where('stats.by_rw.0.rts.01.total', 1)
                ->where('stats.by_rw.0.rts.02.total', 1)
            );
    }

    public function test_migration_rw_rt_summary_uses_filtered_rows_not_only_today(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'test',
            'message_id' => 'mig-rw-rt-old-1',
            'full_name' => 'MIG OLD RW5 RT3',
            'new_sn' => 'FHTTOLD001',
            'rw' => '5',
            'rt' => '3',
            'created_at' => now('Asia/Jakarta')->subDays(2),
        ]);

        $this->actingAs($user)
            ->get(route('customer-migrations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CustomerMigrations/Index')
                ->where('stats.by_rw.0.rw', '05')
                ->where('stats.by_rw.0.total', 1)
                ->where('stats.by_rw.0.rts.03.total', 1)
            );
    }
}
