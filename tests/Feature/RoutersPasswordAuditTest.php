<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\RouterPppoeCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Tests\TestCase;

class RoutersPasswordAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_exactly_maps_four_categories_and_never_prints_passwords(): void
    {
        $routerPassword = 'test-only-router-password-a';
        $dbPassword = 'test-only-database-password-a';
        $package = $this->package();
        $router = Router::create([
            'name' => '  RANDUAGUNG  ',
            'ip_address' => '198.18.2.10',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);

        $empty = $this->customer($package, 'EMPTY', 'empty-user', ['router_id' => $router->id]);
        $same = $this->customer($package, 'SAME', 'same-user', ['router_id' => $router->id, 'pppoe_password' => $routerPassword]);
        $different = $this->customer($package, 'DIFFERENT', 'different-user', ['router_id' => $router->id, 'pppoe_password' => $dbPassword]);
        $missing = $this->customer($package, 'MISSING', 'missing-user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true,
            'use_radius' => false,
            'source' => 'mikrotik_local',
            'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(3);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['.id' => '*1', 'name' => 'empty-user', 'password' => 'test-only-router-password-b', 'profile' => 'default', 'disabled' => 'no', 'service' => 'pppoe'],
            ['.id' => '*2', 'name' => 'same-user', 'password' => $routerPassword, 'profile' => 'default', 'disabled' => 'no', 'service' => 'pppoe'],
            ['.id' => '*3', 'name' => 'different-user', 'password' => 'test-only-router-password-c', 'profile' => 'default', 'disabled' => 'no', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', [
            '--routers' => 'RANDUAGUNG',
            '--dry-run' => true,
            '--details' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FILL_DATABASE', $output);
        $this->assertStringContainsString('MATCH', $output);
        $this->assertStringContainsString('UPDATE_DATABASE', $output);
        $this->assertStringContainsString('NOT_FOUND', $output);
        $this->assertStringContainsString('BLOCKED_PASSWORD_MAPPING', $output);
        $this->assertStringContainsString('snapshot_fingerprint', $output);
        $this->assertStringNotContainsString($routerPassword, $output);
        $this->assertStringNotContainsString($dbPassword, $output);

        $this->assertSame('', (string) $empty->fresh()->pppoe_password);
        $this->assertSame($routerPassword, (string) $same->fresh()->pppoe_password);
        $this->assertSame($dbPassword, (string) $different->fresh()->pppoe_password);
        $this->assertSame('', (string) $missing->fresh()->pppoe_password);
    }

    public function test_non_pppoe_vpn_secrets_do_not_block_pppoe_mapping(): void
    {
        $package = $this->package();
        $router = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.17',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $this->customer($package, 'PPPOE-ONLY', 'pppoe-user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(3);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['name' => 'pppoe-user', 'password' => 'test-only-router-password-i', '.id' => '*1', 'service' => 'pppoe'],
            ['name' => 'vpn-user-a', 'password' => 'test-only-vpn-password-a', '.id' => '*2', 'service' => 'ovpn'],
            ['name' => 'vpn-user-b', 'password' => 'test-only-vpn-password-b', '.id' => '*3', 'service' => 'any'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', [
            '--routers' => 'skynet-ra-olt',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('READY_FOR_APPROVAL', $output);
        $this->assertStringContainsString('"pppoe_secret_count": 1', $output);
        $this->assertStringNotContainsString('test-only-vpn-password-a', $output);
        $this->assertStringNotContainsString('test-only-vpn-password-b', $output);
    }

    public function test_pppoe_username_case_variant_is_blocked_instead_of_auto_matched(): void
    {
        $routerPassword = 'test-only-case-variant-password';
        $package = $this->package();
        $router = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.19',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, 'CASE-VARIANT', 'CaseSensitiveUser', [
            'router_id' => $router->id,
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(1);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['name' => 'casesensitiveuser', 'password' => $routerPassword, '.id' => '*1', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', [
            '--routers' => 'skynet-ra-olt',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('case_variant_conflict', $output);
        $this->assertStringContainsString('CONFLICT', $output);
        $this->assertStringNotContainsString($routerPassword, $output);
        $this->assertSame('', (string) $customer->fresh()->pppoe_password);
    }

    public function test_same_pppoe_username_on_another_router_is_not_selected_for_target(): void
    {
        $routerPassword = 'test-only-multi-router-password';
        $package = $this->package();
        $targetRouter = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.20',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $otherRouter = Router::create([
            'name' => 'KRIAN',
            'ip_address' => '198.18.2.21',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $targetCustomer = $this->customer($package, 'TARGET-ROUTER', 'shared-user', [
            'router_id' => $targetRouter->id,
        ]);
        // customers.pppoe_user is globally unique in the schema, so the other
        // router's customer cannot literally reuse "shared-user". It still
        // proves the router scoping: if the audit selected customers outside
        // the target router, this customer would have no secret on the target
        // and the run would be BLOCKED_PASSWORD_MAPPING (not_found) and list it.
        $otherCustomer = $this->customer($package, 'OTHER-ROUTER', 'shared-user-krian', [
            'router_id' => $otherRouter->id,
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(1);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['name' => 'shared-user', 'password' => $routerPassword, '.id' => '*1', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', [
            '--routers' => 'SKYNET-RA-OLT',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('READY_FOR_APPROVAL', $output);
        $this->assertStringContainsString('"customer_id": '.$targetCustomer->id, $output);
        $this->assertStringNotContainsString('"customer_id": '.$otherCustomer->id, $output);
        $this->assertStringNotContainsString($routerPassword, $output);
    }

    public function test_router_outside_allowlist_is_not_called_and_case_normalization_is_safe(): void
    {
        $package = $this->package();
        $target = Router::create([
            'name' => 'Skynet-RA-OLT',
            'ip_address' => '198.18.2.11',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        Router::create([
            'name' => 'not-in-stage-one',
            'ip_address' => '198.18.2.12',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $this->customer($package, 'ONE', 'one-user', ['router_id' => $target->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(1);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['name' => 'one-user', 'password' => 'test-only-router-password-d', '.id' => '*1', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('routers:password-audit', [
            '--routers' => ' SKYNET-RA-OLT,not-in-stage-one ',
            '--json' => true,
        ])
            ->expectsOutputToContain('OUT_OF_SCOPE_PENDING_ACCESS')
            ->assertExitCode(0);
    }

    public function test_radius_or_unreadable_auth_source_blocks_target_without_database_mutation(): void
    {
        $package = $this->package();
        $router = Router::create([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.2.13',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, 'BLOCKED', 'blocked-user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => true, 'source' => 'radius', 'rows' => 1,
        ]);
        $mikrotik->shouldNotReceive('getPppoeSecretCount');
        $mikrotik->shouldNotReceive('getPppoeSecretsWithPasswords');
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $this->artisan('routers:password-audit', ['--routers' => 'gadang', '--json' => true])
            ->expectsOutputToContain('BLOCKED_ROUTER_AUTH_SOURCE')
            ->assertExitCode(1);

        $this->assertSame('', (string) $customer->fresh()->pppoe_password);
    }

    public function test_gadang_selector_uses_explicit_sky_net_gadang_alias_and_router_id(): void
    {
        $package = $this->package();
        $router = Router::create([
            'name' => 'SKYNET-GADANG',
            'ip_address' => '198.18.2.18',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, 'ALIAS', 'alias-user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => true, 'source' => 'radius', 'rows' => 1,
        ]);
        $mikrotik->shouldNotReceive('getPppoeSecretCount');
        $mikrotik->shouldNotReceive('getPppoeSecretsWithPasswords');
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', ['--routers' => 'gadang', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('BLOCKED_ROUTER_AUTH_SOURCE', $output);
        $this->assertStringContainsString('"router_id": '.$router->id, $output);
        $this->assertStringContainsString('"router_name": "SKYNET-GADANG"', $output);
        $this->assertStringNotContainsString('TARGET_ROUTER_NOT_FOUND', $output);
        $this->assertSame('', (string) $customer->fresh()->pppoe_password);
    }

    public function test_orphan_router_secret_blocks_audit_without_database_mutation(): void
    {
        $package = $this->package();
        $router = Router::create([
            'name' => 'randuagung',
            'ip_address' => '198.18.2.16',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, 'ORPHAN', 'mapped-user', ['router_id' => $router->id]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->once()->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->once()->andReturn(2);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->once()->andReturn([
            ['name' => 'mapped-user', 'password' => 'test-only-router-password-g', '.id' => '*1', 'service' => 'pppoe'],
            ['name' => 'orphan-user', 'password' => 'test-only-router-password-h', '.id' => '*2', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $exitCode = Artisan::call('routers:password-audit', [
            '--routers' => 'randuagung',
            '--dry-run' => true,
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('BLOCKED_PASSWORD_MAPPING', $output);
        $this->assertStringContainsString('"orphan_count": 1', $output);
        $this->assertStringNotContainsString('test-only-router-password-g', $output);
        $this->assertStringNotContainsString('test-only-router-password-h', $output);
        $this->assertSame('', (string) $customer->fresh()->pppoe_password);
    }

    public function test_approved_reconcile_updates_only_changed_password_rows(): void
    {
        $routerPassword = 'test-only-router-password-e';
        $package = $this->package();
        $router = Router::create([
            'name' => 'KRIAN',
            'ip_address' => '198.18.2.14',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $empty = $this->customer($package, 'EMPTY-APPLY', 'empty-apply', ['router_id' => $router->id]);
        $same = $this->customer($package, 'SAME-APPLY', 'same-apply', [
            'router_id' => $router->id,
            'pppoe_password' => $routerPassword,
            'phone' => '081234567891',
        ]);
        $beforeName = $empty->name;
        $beforePhone = $empty->phone;

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->times(3)->andReturnSelf();
        $mikrotik->shouldReceive('getPppAuthenticationSource')->times(3)->andReturn([
            'readable' => true, 'use_radius' => false, 'source' => 'mikrotik_local', 'rows' => 1,
        ]);
        $mikrotik->shouldReceive('getPppoeSecretCount')->times(3)->andReturn(2);
        $mikrotik->shouldReceive('getPppoeSecretsWithPasswords')->times(3)->andReturn([
            ['name' => 'empty-apply', 'password' => $routerPassword, '.id' => '*1', 'service' => 'pppoe'],
            ['name' => 'same-apply', 'password' => $routerPassword, '.id' => '*2', 'service' => 'pppoe'],
        ]);
        $mikrotik->shouldReceive('disconnect')->times(3);
        $this->app->instance(MikrotikService::class, $mikrotik);

        $service = app(RouterPppoeCredentialService::class);
        $snapshot = $service->audit(['KRIAN']);
        $manifest = tempnam(sys_get_temp_dir(), 'pppoe-backup-');
        file_put_contents($manifest, "backup-sha\nrun={$snapshot['run_id']}\n");

        $result = $service->reconcileApproved(
            $snapshot,
            'APPROVE DB PASSWORD RECONCILIATION '.$snapshot['run_id'],
            $manifest
        );

        $this->assertSame(1, $result['changed_rows']);
        $this->assertSame(1, $result['total_rows_checked']);
        $this->assertFalse($result['raw_passwords_returned']);
        $this->assertSame($routerPassword, $empty->fresh()->pppoe_password);
        $this->assertSame($routerPassword, $same->fresh()->pppoe_password);
        $this->assertSame($beforeName, $empty->fresh()->name);
        $this->assertSame($beforePhone, $empty->fresh()->phone);
        $this->assertNotSame($routerPassword, (string) \DB::table('customers')->where('id', $empty->id)->value('pppoe_password'));

        @unlink($manifest);
    }

    public function test_customer_password_change_requires_router_write_and_readback_before_encrypted_db_update(): void
    {
        $routerPassword = 'test-only-router-password-f';
        $package = $this->package();
        $router = Router::create([
            'name' => 'gadang',
            'ip_address' => '198.18.2.15',
            'username' => 'audit',
            'password' => 'test-only-router-auth-placeholder',
            'port' => 8728,
            'is_active' => true,
        ]);
        $customer = $this->customer($package, 'CHANGE', 'change-user', [
            'router_id' => $router->id,
            'mikrotik_profile' => 'default',
            'pppoe_password' => 'test-only-old-password',
        ]);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->andReturnSelf();
        $mikrotik->shouldReceive('addPPPSecret')->once()->andReturn(['success' => true]);
        $mikrotik->shouldReceive('getPPPSecret')->once()->andReturn([
            'name' => 'change-user', 'password' => $routerPassword, 'profile' => 'default',
        ]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $result = app(RouterPppoeCredentialService::class)->changeCustomerPassword($customer, $routerPassword);

        $this->assertTrue($result['changed']);
        $this->assertTrue($result['verified']);
        $this->assertArrayNotHasKey('password', $result);
        $this->assertSame($routerPassword, $customer->fresh()->pppoe_password);
        $this->assertNotSame($routerPassword, (string) \DB::table('customers')->where('id', $customer->id)->value('pppoe_password'));
    }

    private function package(): Package
    {
        return Package::create([
            'name' => 'Password Audit',
            'code' => 'PKG-PASSWORD-AUDIT-'.uniqid(),
            'price' => 100000,
        ]);
    }

    private function customer(Package $package, string $code, string $username, array $overrides = []): Customer
    {
        return Customer::create(array_merge([
            'code' => $code,
            'name' => $code,
            'phone' => '080000000000',
            'address' => 'Audit Address',
            'pppoe_user' => $username,
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }
}
