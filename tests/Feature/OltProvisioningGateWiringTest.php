<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Http\Controllers\OltController;
use App\Http\Middleware\CheckOltProvisioningApproval;
use App\Models\Olt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

#[Group('blocking-olt-mysql')]
class OltProvisioningGateWiringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        Config::set('olt-provisioning.dry_run', true);
        Config::set('olt-provisioning.kill_switch', false);
        Config::set('olt-provisioning.approval_window_seconds', 900);
        Config::set('olt-provisioning.approval_lock_seconds', 900);
        Cache::store('array')->flush();
    }

    public function test_default_dry_run_stops_downstream_before_device_write(): void
    {
        $downstreamCalled = false;
        $request = $this->requestWithOlt();

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function () use (&$downstreamCalled) {
            $downstreamCalled = true;

            return response()->json(['device_write' => true]);
        });

        $this->assertSame(423, $response->getStatusCode());
        $this->assertFalse($downstreamCalled);
        $this->assertSame('olt_provisioning_dry_run', $response->getData(true)['error_code']);
    }

    public function test_valid_live_approval_allows_downstream_after_gate(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $approvedAt = now()->timestamp;
        $approval = 'phase-e-wiring-approval';
        $payload = "OLT_PROVISIONING_APPROVED={$approval}\nOLT_PROVISIONING_APPROVED_AT={$approvedAt}";
        $request = $this->requestWithOlt([
            'approval' => $approval,
            'approved_at' => $approvedAt,
            'signature' => hash_hmac('sha256', $payload, (string) config('app.key')),
        ]);

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function (Request $request) {
            return response()->json([
                'device_write' => false,
                'gate_checked' => $request->attributes->get('olt_provisioning_gate_checked'),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['gate_checked']);
        $this->assertFalse($response->getData(true)['device_write']);
    }

    public function test_global_admin_live_confirmation_gets_server_side_approval(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $this->actingAs(User::factory()->make(['role' => 'noc']));
        $request = $this->requestWithOlt(['live_confirm' => 'PROVISION OLT']);

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function (Request $request) {
            return response()->json([
                'gate_checked' => $request->attributes->get('olt_provisioning_gate_checked'),
            ]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['gate_checked']);
    }

    public function test_live_confirmation_without_global_admin_does_not_issue_approval(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $this->actingAs(User::factory()->make(['role' => 'teknisi']));
        $request = $this->requestWithOlt(['live_confirm' => 'PROVISION OLT']);
        $downstreamCalled = false;

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function () use (&$downstreamCalled) {
            $downstreamCalled = true;

            return response()->json(['device_write' => true]);
        });

        $this->assertSame(423, $response->getStatusCode());
        $this->assertFalse($downstreamCalled);
    }

    public function test_invalid_live_approval_never_reaches_downstream(): void
    {
        Config::set('olt-provisioning.dry_run', false);
        $request = $this->requestWithOlt([
            'approval' => 'tampered',
            'approved_at' => now()->timestamp,
            'signature' => str_repeat('0', 64),
        ]);
        $downstreamCalled = false;

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function () use (&$downstreamCalled) {
            $downstreamCalled = true;

            return response()->json(['device_write' => true]);
        });

        $this->assertSame(423, $response->getStatusCode());
        $this->assertFalse($downstreamCalled);
        $this->assertSame('olt_provisioning_approval_required', $response->getData(true)['error_code']);
    }

    public function test_explicit_preview_bypasses_live_write_gate(): void
    {
        $request = $this->requestWithOlt(['dry_run' => true], '/api/olt-c300/provision-fiberhome');
        $downstreamCalled = false;

        $response = app(CheckOltProvisioningApproval::class)->handle($request, function () use (&$downstreamCalled) {
            $downstreamCalled = true;

            return response()->json(['dry_run' => true, 'device_write' => false]);
        });

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($downstreamCalled);
        $this->assertFalse($response->getData(true)['device_write']);
    }

    public function test_direct_controller_fallback_blocks_without_route_middleware(): void
    {
        foreach ([OltC300Controller::class, OltController::class] as $controllerClass) {
            $controller = app($controllerClass);
            $method = new ReflectionMethod($controllerClass, 'provisioningGate');
            $method->setAccessible(true);

            $response = $method->invoke($controller, new Olt(['id' => 7]), Request::create('/internal-olt-call', 'POST'), 'test-write');

            $this->assertSame(423, $response->getStatusCode(), $controllerClass);
            $this->assertSame('olt_provisioning_dry_run', $response->getData(true)['error_code'], $controllerClass);
        }
    }

    public function test_all_olt_write_routes_have_the_gate_and_read_routes_do_not(): void
    {
        $writeRoutes = [
            'api.olt-c300.provision',
            'api.olt-c300.activate',
            'api.olt-c300.reconfigure',
            'api.olt-c300.provision-fiberhome',
            'api.olts.onu.rename',
            'api.olts.onu.reboot',
            'api.olts.onu.admin-state',
            'api.olts.onu.delete',
            'api.olts.onu.authorize',
        ];

        foreach ($writeRoutes as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' route missing');
            $this->assertContains('olt-provisioning', $route->gatherMiddleware(), $name.' is not gated');
        }

        foreach (['api.olt-c300.detect', 'api.olt-c300.test-connection', 'api.olts.gpon-snapshot', 'api.olts.onu.detail'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name.' route missing');
            $this->assertNotContains('olt-provisioning', $route->gatherMiddleware(), $name.' read path was gated');
        }
    }

    private function requestWithOlt(array $input = [], string $path = '/api/olts/7/onu/reboot'): Request
    {
        $request = Request::create($path, 'POST', $input);
        $routePath = str_starts_with($path, '/api/olts/')
            ? '/api/olts/{olt}/onu/reboot'
            : $path;
        $route = new Route('POST', $routePath, []);
        $route->bind($request);
        $route->setParameter('olt', new Olt(['id' => 7, 'vendor' => 'zte-c300']));
        $request->setRouteResolver(static fn (): Route => $route);
        $request->setUserResolver(fn () => auth()->user());

        return $request;
    }
}
