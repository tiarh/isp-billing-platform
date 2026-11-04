<?php

namespace Tests\Feature;

use App\Models\Olt;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OltCollectorProvisionGraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('olt_onus')) {
            Schema::create('olt_onus', function ($table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->index();
                $table->string('onu_ref')->nullable();
                $table->string('pon_port')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('onu_name')->nullable();
                $table->string('serial_source')->nullable();
                $table->string('name_source')->nullable();
                $table->string('state')->nullable();
                $table->string('phase_state')->nullable();
                $table->decimal('rx_power_dbm', 8, 2)->nullable();
                $table->timestamp('state_collected_at')->nullable();
                $table->timestamp('rx_collected_at')->nullable();
                $table->timestamp('inventory_collected_at')->nullable();
                $table->integer('distance_m')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_purge_preserves_recently_observed_onu_but_removes_stale_row(): void
    {
        $olt = Olt::create([
            'name' => 'OLT Test',
            'code' => 'OLT-TEST',
            'vendor' => 'zte_c300',
        ]);
        $fresh = OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/3:22',
            'pon_port' => '1/2/3',
            'serial_number' => 'FHTTNEW123',
            'onu_name' => 'CUSTOMER_TEST',
            'serial_source' => 'provision',
            'name_source' => 'migration',
            'state' => 'online',
            'state_collected_at' => now(),
        ]);
        $stale = OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/3:15',
            'pon_port' => '1/2/3',
            'serial_number' => 'ZXICOLD123',
            'state' => 'offline',
            'state_collected_at' => now()->subHour(),
        ]);
        $stale->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $purged = app(OltCollectorService::class)->purgeStaleOnus($olt, [], '1/2/3');

        $this->assertSame(1, $purged);
        $this->assertDatabaseHas('olt_onus', ['id' => $fresh->id]);
        $this->assertDatabaseMissing('olt_onus', ['id' => $stale->id]);
    }

    public function test_inventory_without_distance_preserves_existing_optical_distance(): void
    {
        $olt = Olt::create([
            'name' => 'OLT Huawei Test',
            'code' => 'OLT-HUAWEI-TEST',
            'vendor' => 'huawei_ma5608t',
        ]);
        $onu = OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '0/0/7:27',
            'pon_port' => '0/0/7',
            'serial_number' => 'FHTTTEST123',
            'state' => 'online',
            'distance_m' => 1912,
        ]);

        $method = new \ReflectionMethod(OltCollectorService::class, 'persistInventoryOnus');
        $method->setAccessible(true);
        $method->invoke(
            app(OltCollectorService::class),
            $olt,
            [[
                'onu_ref' => '0/0/7:27',
                'pon_port' => '0/0/7',
                'serial_number' => 'FHTTTEST123',
                'state' => 'online',
                'distance_m' => null,
            ]],
            microtime(true),
            'huawei_inventory',
            true,
        );

        $this->assertSame(1912, $onu->fresh()->distance_m);
    }
}
