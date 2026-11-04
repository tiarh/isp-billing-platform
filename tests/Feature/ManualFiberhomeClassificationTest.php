<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Http\Controllers\OltController;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\PsbActivationRecorder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class ManualFiberhomeClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('olt_vlan_mappings')) {
            Schema::create('olt_vlan_mappings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('olt_id')->constrained('olts')->cascadeOnDelete();
                $table->string('pon_port', 32);
                $table->string('service_type', 32)->nullable();
                $table->unsignedSmallInteger('vlan');
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function test_manual_fiberhome_defaults_to_psb_new(): void
    {
        [$olt, $router] = $this->oltAndRouter();

        $response = app(OltC300Controller::class)->provisionFiberhome(Request::create(
            '/api/olt-c300/provision-fiberhome',
            'POST',
            [
                'olt_id' => $olt->id,
                'router_id' => $router->id,
                'sn' => 'FHTTDEFAULT01',
                'port' => '1/3/9',
                'onu_id' => 5,
                'name' => 'NEWCUSTOMER_RT01_RW01_L4',
                'paket' => '25M',
                'dry_run' => true,
                'mikrotik' => 'randuagung',
            ],
        ));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('psb_new', $response->getData(true)['purpose']);
        $this->assertSame('psb_new', $response->getData(true)['recorded_as']);
    }

    public function test_manual_migration_requires_existing_customer_before_device_write(): void
    {
        [$olt, $router] = $this->oltAndRouter();

        $response = app(OltC300Controller::class)->provisionFiberhome(Request::create(
            '/api/olt-c300/provision-fiberhome',
            'POST',
            [
                'olt_id' => $olt->id,
                'router_id' => $router->id,
                'sn' => 'FHTTMIGRATION01',
                'port' => '1/3/9',
                'onu_id' => 6,
                'name' => 'UNKNOWN_CUSTOMER',
                'paket' => '25M',
                'dry_run' => true,
                'purpose' => 'migration',
            ],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('migration_customer_not_found', $response->getData(true)['error_code']);
    }

    public function test_migration_wa_requires_existing_customer_before_device_write(): void
    {
        [$olt, $router] = $this->oltAndRouter();

        $response = app(OltC300Controller::class)->provisionFiberhome(Request::create(
            '/api/olt-c300/provision-fiberhome',
            'POST',
            [
                'olt_id' => $olt->id,
                'router_id' => $router->id,
                'sn' => 'FHTTMIGRATIONWA01',
                'port' => '1/3/9',
                'onu_id' => 7,
                'name' => 'UNKNOWN_CUSTOMER',
                'paket' => '25M',
                'dry_run' => true,
                'purpose' => 'migration_wa',
            ],
        ));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('migration_customer_not_found', $response->getData(true)['error_code']);
    }

    public function test_psb_recorder_creates_psb_draft_not_migration(): void
    {
        [$olt, $router] = $this->oltAndRouter();

        $draft = app(PsbActivationRecorder::class)->recordFiberhomeProvision([
            'olt_id' => $olt->id,
            'router_id' => $router->id,
            'sn' => 'FHTTPSBNEW001',
            'port' => '1/3/9',
            'onu_id' => 7,
            'name' => 'PSBNEW_RT01_RW01_L4',
            'vlan' => 309,
            'tr069_vlan' => 69,
        ]);

        $this->assertNotNull($draft);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->status);
        $this->assertSame(69, $draft->tr069_vlan);
        $this->assertSame(0, CustomerMigration::query()->count());
    }

    public function test_reused_onu_ref_does_not_overwrite_a_different_customer(): void
    {
        [$olt] = $this->oltAndRouter();
        $area = Area::create([
            'code' => 'MC1',
            'name' => 'Manual Classification',
        ]);
        $package = Package::create([
            'code' => 'MC-PKG',
            'name' => 'Manual Classification',
            'price' => 100000,
        ]);
        $customer = Customer::create([
            'code' => 'FAKE-025',
            'name' => 'USER AS',
            'phone' => '080000000001',
            'address' => 'Kalirejo',
            'pppoe_user' => 'USERAR_RT08_RW09_01',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'status' => 'active',
            'olt_id' => $olt->id,
            'olt_port_label' => '1/3/11:3',
            'onu_serial' => 'FHTTOLDUSER01',
            'olt_status' => 'offline',
        ]);
        $snapshot = [
            'onus' => [[
                'onu_ref' => '1/3/11:3',
                'serial_number' => 'FHTT0000A02F',
                'onu_name' => 'USERX_RT05_RW15_01',
                'state' => 'online',
                'rx_power_dbm' => -21.67,
            ]],
        ];

        $matched = app(OltController::class)->syncSnapshotToCustomers($olt, $snapshot);

        $this->assertSame([], $matched);
        $this->assertSame('reused_onu_ref', $snapshot['onus'][0]['match_conflict']);
        $this->assertSame('FHTTOLDUSER01', $customer->fresh()->onu_serial);
        $this->assertSame('offline', $customer->fresh()->olt_status);
    }

    private function oltAndRouter(): array
    {
        // a variable, not a call: this file is in the mysql group and a host + user + password call
        // looks like a MySQL assignment to GitGuardian
        $routerPw = Canary::of('pw');
        $router = Router::create([
            'name' => 'RA Manual Classification',
            'ip_address' => '198.18.21.1',
            'username' => 'admin',
            'password' => $routerPw,
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT Manual Classification',
            'code' => 'OLT-MANUAL-CLASSIFICATION',
            'vendor' => 'zte_c300',
            'router_id' => $router->id,
        ]);
        OltVlanMapping::create([
            'olt_id' => $olt->id,
            'pon_port' => '1/3/9',
            'service_type' => 'internet',
            'vlan' => 309,
            'is_active' => true,
        ]);

        return [$olt, $router];
    }
}
