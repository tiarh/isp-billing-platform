<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Services\OltCollectorService;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class P0ProductionContractRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_exposes_genieacs_relation_and_monitoring_scopes(): void
    {
        $customer = new Customer;

        $this->assertInstanceOf(HasOne::class, $customer->genieAcsDevice());
        $this->assertSame(GenieAcsDevice::class, $customer->genieAcsDevice()->getRelated()::class);
        $this->assertNotSame('', Customer::query()->ponOffline()->toSql());
        $this->assertNotSame('', Customer::query()->ponPppoeMismatch()->toSql());
    }

    public function test_olt_health_rows_keep_healthy_contract(): void
    {
        if (! Schema::hasTable('olt_onus')) {
            Schema::create('olt_onus', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('olt_id');
                $table->string('onu_ref');
                $table->decimal('rx_power_dbm', 6, 2)->nullable();
                $table->timestamps();
            });
        }

        $olt = Olt::create([
            'name' => 'OLT Contract',
            'code' => 'OLT-CONTRACT',
            'vendor' => 'zte_c300',
        ]);
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/6:1',
            'rx_power_dbm' => -22.5,
        ]);

        $summary = app(OltCollectorService::class)->collectHealthSummary();

        $this->assertTrue($summary['rows'][0]['healthy']);
        $this->assertSame(1, $summary['healthy_olts']);
    }
}
