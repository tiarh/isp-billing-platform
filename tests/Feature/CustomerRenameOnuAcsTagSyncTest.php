<?php

namespace Tests\Feature;

use App\Jobs\ProcessGenieAcsInformJob;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\GenieAcsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerRenameOnuAcsTagSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(string $code, string $pppoe, string $serial): array
    {
        $package = Package::firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic', 'price' => 100000]);
        $customer = Customer::create([
            'code' => $code,
            'name' => 'Nama Lama',
            'address' => 'Alamat',
            'package_id' => $package->id,
            'pppoe_user' => $pppoe,
            'onu_serial' => $serial,
            'status' => 'active',
        ]);

        return [$package, $customer];
    }

    private function update(Customer $customer, Package $package, array $changes)
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->put(route('customers.update', $customer), array_merge([
            'name' => $customer->name,
            'address' => $customer->address,
            'package_id' => $package->id,
            'status' => $customer->status,
            'pppoe_user' => $customer->pppoe_user,
        ], $changes));
    }

    public function test_name_only_change_on_customer_with_pppoe_does_not_touch_acs(): void
    {
        Queue::fake();
        [$package, $customer] = $this->makeCustomer('CUST-RN-1', 'rn_user_rt01', 'ZTEGRENAME01');

        $this->update($customer, $package, ['name' => 'Nama Baru'])->assertRedirect(route('customers.index'));

        Queue::assertNothingPushed();
    }

    public function test_pppoe_change_queues_one_job_with_the_new_preferred_tag_not_the_name(): void
    {
        Queue::fake();
        [$package, $customer] = $this->makeCustomer('CUST-RN-2', 'rn_user_rt01', 'zteg-rename 02');

        $this->update($customer, $package, ['name' => 'Nama Baru', 'pppoe_user' => 'rn_user_rt02'])
            ->assertRedirect(route('customers.index'));

        $expected = app(GenieAcsService::class)->preferredCustomerTag($customer->fresh());
        $this->assertNotSame('', $expected);
        $this->assertStringContainsStringIgnoringCase('rt02', $expected);
        Queue::assertPushed(ProcessGenieAcsInformJob::class, 1);
        Queue::assertPushed(ProcessGenieAcsInformJob::class, fn (ProcessGenieAcsInformJob $job) => $job->payload['serial'] === 'ZTEGRENAME02'
            && $job->payload['tag'] === $expected);
    }

    public function test_serial_shared_with_another_customer_is_skipped(): void
    {
        Queue::fake();
        [$package, $customer] = $this->makeCustomer('CUST-RN-3', 'rn_user_rt01', 'ZTEGSHARED01');
        $this->makeCustomer('CUST-RN-4', 'other_user', 'ZTEG-SHARED01');

        $this->update($customer, $package, ['pppoe_user' => 'rn_user_rt02'])->assertRedirect(route('customers.index'));

        Queue::assertNothingPushed();
    }

    public function test_acs_device_linked_to_another_customer_is_skipped(): void
    {
        Queue::fake();
        [$package, $customer] = $this->makeCustomer('CUST-RN-5', 'rn_user_rt01', 'ZTEGOTHERDEV1');
        [, $other] = $this->makeCustomer('CUST-RN-6', 'other_user2', 'ZTEGUNRELATED1');
        GenieAcsDevice::create(['device_id' => 'DEV-OTHER', 'serial_number' => 'ZTEGOTHERDEV1', 'customer_id' => $other->id]);

        $this->update($customer, $package, ['pppoe_user' => 'rn_user_rt02'])->assertRedirect(route('customers.index'));

        Queue::assertNothingPushed();
    }

    public function test_queued_job_pushes_new_tag_through_real_genieacs_service(): void
    {
        Queue::fake();
        Setting::set('genieacs_url', 'http://acs.test:7557', 'string', 'genieacs');
        [$package, $customer] = $this->makeCustomer('CUST-RN-7', 'rn_user_rt01', 'ZTEGREAL0001');
        GenieAcsDevice::create(['device_id' => 'DEV-REAL-1', 'serial_number' => 'ZTEGREAL0001', 'customer_id' => $customer->id]);
        Http::fake(['acs.test:7557/*' => Http::response([['_id' => 'DEV-REAL-1', '_tags' => ['RN_USER_RT01']]], 200)]);

        $this->update($customer, $package, ['pppoe_user' => 'rn_user_rt02'])->assertRedirect(route('customers.index'));

        $job = null;
        Queue::assertPushed(ProcessGenieAcsInformJob::class, function (ProcessGenieAcsInformJob $j) use (&$job) {
            $job = $j;

            return true;
        });
        $job->handle(app(GenieAcsService::class));

        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && str_contains($r->url(), '/devices/DEV-REAL-1/tags/RN_USER_RT02'));
        // The old PPPoE tag is the one replaced; the new preferred tag is the only one kept.
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/tags/RN_USER_RT01'));
    }
}
