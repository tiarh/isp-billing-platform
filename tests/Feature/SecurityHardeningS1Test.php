<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Package;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SafeRemoteSnapshotDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecurityHardeningS1Test extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_support_ticket_can_only_read_and_update_its_area(): void
    {
        $ownArea = Area::create(['code' => 'OWN', 'name' => 'Own Area']);
        $otherArea = Area::create(['code' => 'OTHER', 'name' => 'Other Area']);
        $package = Package::create(['code' => 'S1', 'name' => 'S1 Package', 'price' => 100000]);
        $ownCustomer = $this->customer('S1-OWN', $ownArea, $package);
        $otherCustomer = $this->customer('S1-OTHER', $otherArea, $package);
        $ticket = SupportTicket::create(['customer_id' => $otherCustomer->id, 'subject' => 'Other ticket']);
        SupportTicket::create(['customer_id' => $ownCustomer->id, 'subject' => 'Own ticket']);
        $user = User::factory()->create(['role' => 'admin']);
        $user->areas()->attach($ownArea);

        $this->actingAs($user)
            ->getJson(route('api.support-tickets.index'))
            ->assertOk()
            ->assertJsonFragment(['subject' => 'Own ticket'])
            ->assertJsonMissing(['subject' => 'Other ticket']);

        $this->actingAs($user)
            ->patchJson(route('api.support-tickets.update', $ticket), ['subject' => 'Tampered'])
            ->assertForbidden();
    }

    public function test_scoped_genieacs_user_cannot_open_foreign_or_unlinked_device(): void
    {
        $ownArea = Area::create(['code' => 'G-OWN', 'name' => 'Genie Own']);
        $otherArea = Area::create(['code' => 'G-OTHER', 'name' => 'Genie Other']);
        $package = Package::create(['code' => 'G-S1', 'name' => 'Genie Package', 'price' => 100000]);
        $otherCustomer = $this->customer('G-OTHER-C', $otherArea, $package);
        $foreign = GenieAcsDevice::create(['device_id' => 'foreign-device', 'customer_id' => $otherCustomer->id]);
        $unlinked = GenieAcsDevice::create(['device_id' => 'unlinked-device']);
        $user = User::factory()->create(['role' => 'admin']);
        $user->areas()->attach($ownArea);

        $this->actingAs($user)->getJson(route('api.genieacs.show', $foreign))->assertForbidden();
        $this->actingAs($user)->getJson(route('api.genieacs.show', $unlinked))->assertForbidden();
    }

    public function test_genieacs_parameter_name_rejects_task_or_shell_syntax(): void
    {
        $device = GenieAcsDevice::create(['device_id' => 'parameter-device']);
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->postJson(route('api.genieacs.parameter', $device), ['name' => 'Device.WiFi;rm -rf /', 'value' => 'x'])
            ->assertUnprocessable();
    }

    public function test_payment_gateway_config_requires_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($admin)->getJson(route('api.payment-gateway.config'))->assertForbidden();
        $this->actingAs($superadmin)->getJson(route('api.payment-gateway.config'))->assertOk();
    }

    public function test_snapshot_downloader_is_https_allowlist_only_without_redirects_or_private_literals(): void
    {
        config([
            'services.migration_snapshot.allowed_hosts' => ['snapshot.test'],
            'services.migration_snapshot.max_bytes' => 1024,
        ]);
        Http::fake([
            'https://snapshot.test/*' => Http::response(str_repeat('name,area\n', 20), 200),
        ]);

        $downloader = app(SafeRemoteSnapshotDownloader::class);

        $this->assertNull($downloader->download('http://snapshot.test/share/abc'));
        $this->assertNull($downloader->download('https://127.0.0.1/share/abc'));
        $this->assertNull($downloader->download('https://not-allowed.test/share/abc'));
        $this->assertSame(str_repeat('name,area\n', 20), $downloader->download('https://snapshot.test/share/abc'));
        Http::assertSentCount(1);
    }

    private function customer(string $code, Area $area, Package $package): Customer
    {
        return Customer::create([
            'code' => $code,
            'name' => $code,
            'phone' => '081234567890',
            'address' => 'Test address',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code),
            'status' => 'active',
        ]);
    }
}
