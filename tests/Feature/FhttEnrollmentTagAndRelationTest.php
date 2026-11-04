<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Services\FhttEnrollmentService;
use App\Services\GenieAcsService;
use App\Services\OltSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionClass;
use Tests\TestCase;

#[Group('blocking-core')]
class FhttEnrollmentTagAndRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_tag_pushes_pppoe_user_as_tag_and_links_customer(): void
    {
        $fake = (new ReflectionClass(FhttTagGenieFake::class))->newInstanceWithoutConstructor();
        $this->app->instance(GenieAcsService::class, $fake);

        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'CUST-FH-1', 'name' => 'Fhtt Cust', 'address' => 'A', 'package_id' => $package->id,
            'pppoe_user' => 'fhtt_user', 'onu_serial' => 'FHTT00000001', 'status' => 'active',
        ]);
        $device = GenieAcsDevice::create(['device_id' => 'DEV-FH-1', 'serial_number' => 'FHTT00000001', 'customer_id' => null]);

        $stats = app(FhttEnrollmentService::class)->autoTagUnmatched(10);

        $this->assertSame(1, $stats['tagged']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame([['FHTT00000001', 'fhtt_user']], $fake->calls);
        $this->assertSame($customer->id, $device->fresh()->customer_id);
    }

    public function test_auto_tag_counts_failed_when_acs_does_not_accept_the_tag(): void
    {
        $fake = (new ReflectionClass(FhttTagGenieFake::class))->newInstanceWithoutConstructor();
        $fake->pushed = false;
        $this->app->instance(GenieAcsService::class, $fake);

        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        Customer::create([
            'code' => 'CUST-FH-2', 'name' => 'Fhtt Cust', 'address' => 'A', 'package_id' => $package->id,
            'pppoe_user' => 'fhtt_user2', 'onu_serial' => 'FHTT00000002', 'status' => 'active',
        ]);
        $device = GenieAcsDevice::create(['device_id' => 'DEV-FH-2', 'serial_number' => 'FHTT00000002']);

        $stats = app(FhttEnrollmentService::class)->autoTagUnmatched(10);

        $this->assertSame(0, $stats['tagged']);
        $this->assertSame(1, $stats['failed']);
        $this->assertNull($device->fresh()->customer_id);
    }

    public function test_rematerialize_only_visits_olts_that_have_fhtt_onus(): void
    {
        $withFhtt = Olt::create(['name' => 'OLT F', 'code' => 'OLT-F', 'vendor' => 'zte_c300']);
        $without = Olt::create(['name' => 'OLT N', 'code' => 'OLT-N', 'vendor' => 'zte_c300']);
        OltOnu::create(['olt_id' => $withFhtt->id, 'onu_ref' => 'a', 'serial_number' => 'FHTT00000009']);
        OltOnu::create(['olt_id' => $without->id, 'onu_ref' => 'b', 'serial_number' => 'ZTEG00000009']);

        $builder = (new ReflectionClass(FhttMaterializeFake::class))->newInstanceWithoutConstructor();
        $this->app->instance(OltSnapshotBuilder::class, $builder);

        $count = app(FhttEnrollmentService::class)->rematerializeSnapshots();

        $this->assertSame(1, $count);
        $this->assertSame([$withFhtt->id], $builder->olts);
    }
}

class FhttTagGenieFake extends GenieAcsService
{
    /** @var list<array{0: string, 1: string}> */
    public array $calls = [];

    public bool $pushed = true;

    public function pushTagForSerial(string $serial, string $tag): array
    {
        $this->calls[] = [$serial, $tag];

        return ['pushed' => $this->pushed, 'tag' => $tag];
    }
}

class FhttMaterializeFake extends OltSnapshotBuilder
{
    /** @var list<int> */
    public array $olts = [];

    public function materialize(Olt $olt, bool $syncCustomers = true): array
    {
        $this->olts[] = $olt->id;

        return [];
    }
}
