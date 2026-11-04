<?php

namespace Tests\Feature;

use App\Models\GenieAcsDevice;
use App\Models\Setting;
use App\Models\User;
use App\Services\GenieAcsService;
use App\Support\GenieAcsParameterPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * SEC2a: audit S5 (GenieACS parameter allowlist at the HTTP endpoint) and
 * S17 (`role` not mass assignable). S7 (PSB KTP area check) is covered by
 * ScopedAdminAccessPsbDraftTest in the stacked PR #375. No TOTP / step-up.
 */
#[Group('blocking-core')]
class SecuritySec2aGenieAcsRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_genieacs_parameter_policy_allows_only_wifi_paths(): void
    {
        foreach ([
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.5.KeyPassphrase',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.KeyPassphrase',
            'Device.WiFi.SSID.1.SSID',
            'Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
            'Device.WiFi.Radio.2.Channel',
        ] as $allowed) {
            $this->assertTrue(GenieAcsParameterPolicy::allows($allowed), $allowed);
        }

        foreach ([
            'InternetGatewayDevice.ManagementServer.URL',
            'InternetGatewayDevice.ManagementServer.Password',
            'Device.ManagementServer.URL',
            'InternetGatewayDevice.WANDevice.1.WANConnectionDevice.1.WANPPPConnection.1.Username',
            'InternetGatewayDevice.UserInterface.X_HW_WebUserInfo.1.Password',
            'InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID.Extra',
            'XInternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID',
            'VirtualParameters.wifiPassword',
        ] as $denied) {
            $this->assertFalse(GenieAcsParameterPolicy::allows($denied), $denied);
        }

        $this->assertTrue(GenieAcsParameterPolicy::isSecret('Device.WiFi.AccessPoint.1.Security.KeyPassphrase'));
        $this->assertFalse(GenieAcsParameterPolicy::isSecret('Device.WiFi.SSID.1.SSID'));
    }

    public function test_genieacs_management_server_write_is_rejected_and_audited(): void
    {
        $device = GenieAcsDevice::create(['device_id' => 'acs-hijack-device']);
        $user = User::factory()->create(['role' => 'admin']);
        $this->mock(GenieAcsService::class)->shouldNotReceive('setParameter');

        $this->actingAs($user)
            ->postJson(route('api.genieacs.parameter', $device), [
                'name' => 'InternetGatewayDevice.ManagementServer.URL',
                'value' => 'http://evil.example:7547',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'parameter_not_allowed');

        $this->assertDatabaseHas('activity_log', [
            'description' => 'genieacs.parameter_rejected',
            'causer_id' => $user->id,
        ]);
    }

    public function test_genieacs_wifi_write_is_forwarded_and_secret_value_is_not_logged(): void
    {
        $device = GenieAcsDevice::create(['device_id' => 'wifi-device']);
        $user = User::factory()->create(['role' => 'admin']);
        $this->mock(GenieAcsService::class)
            ->shouldReceive('setParameter')
            ->once()
            ->withArgs(fn ($d, $name, $value) => $d->is($device)
                && $name === 'Device.WiFi.AccessPoint.1.Security.KeyPassphrase'
                && $value === 'rahasia-wifi-123')
            ->andReturn(['success' => true]);

        $this->actingAs($user)
            ->postJson(route('api.genieacs.parameter', $device), [
                'name' => 'Device.WiFi.AccessPoint.1.Security.KeyPassphrase',
                'value' => 'rahasia-wifi-123',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('activity_log', ['description' => 'genieacs.parameter_set']);
        $this->assertStringNotContainsString(
            'rahasia-wifi-123',
            (string) DB::table('activity_log')->where('description', 'genieacs.parameter_set')->value('properties')
        );
    }

    /**
     * The allowlist guards the HTTP endpoint only. Internal callers (PSB
     * activation, migration, jobs) use GenieAcsService directly and must keep
     * working for any path.
     */
    public function test_service_level_set_parameter_is_not_restricted_for_internal_callers(): void
    {
        Setting::set('genieacs_url', 'http://acs.test:7557');
        Http::fake(['acs.test:7557/*' => Http::response(['ok' => true], 200)]);
        $device = GenieAcsDevice::create(['device_id' => 'internal-device']);

        $result = app(GenieAcsService::class)->setParameter(
            $device,
            'InternetGatewayDevice.ManagementServer.PeriodicInformInterval',
            300,
        );

        $this->assertTrue($result['queued']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/tasks')
            && ($request->data()['parameterValues'][0][0] ?? null) === 'InternetGatewayDevice.ManagementServer.PeriodicInformInterval');
    }

    public function test_role_is_not_mass_assignable(): void
    {
        $this->assertNotContains('role', (new User)->getFillable());

        $user = User::factory()->create(['role' => 'teknisi']);
        $user->fill(['role' => 'superadmin', 'name' => 'Renamed'])->save();

        $this->assertSame('teknisi', $user->fresh()->role);
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_user_management_still_sets_role_explicitly(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->post(route('users.store'), [
            'name' => 'Teknisi Baru',
            'email' => 'teknisi-baru@example.test',
            'password' => Canary::of('pw123'),
            'password_confirmation' => Canary::of('pw123'),
            'role' => 'teknisi',
        ])->assertRedirect(route('users.index'));

        $created = User::query()->where('email', 'teknisi-baru@example.test')->firstOrFail();
        $this->assertSame('teknisi', $created->role);

        $this->actingAs($superadmin)->patch(route('users.update', $created), [
            'name' => 'Teknisi Baru',
            'email' => 'teknisi-baru@example.test',
            'role' => 'noc',
        ])->assertRedirect(route('users.index'));

        $this->assertSame('noc', $created->fresh()->role);
    }

    public function test_totp_routes_are_exposed_to_ziggy(): void
    {
        // The Totp / TotpChallenge / TotpStepUp pages call route('totp.*') client-side;
        // config/ziggy.php `only` must list them or route() throws.
        $this->assertContains('totp.*', config('ziggy.only'));
    }
}
