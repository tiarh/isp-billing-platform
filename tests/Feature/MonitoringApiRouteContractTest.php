<?php

namespace Tests\Feature;

use App\Http\Controllers\CustomerTrafficController;
use Illuminate\Routing\Route;
use Tests\TestCase;

class MonitoringApiRouteContractTest extends TestCase
{
    public function test_customer_traffic_read_routes_are_mounted_under_v1_with_session_auth(): void
    {
        $routes = app('router')->getRoutes();

        foreach ([
            'api.monitoring.traffic.index' => ['GET', '/api/v1/monitoring/traffic', 'index'],
            'api.monitoring.traffic.options' => ['GET', '/api/v1/monitoring/traffic/options', 'options'],
        ] as $name => [$method, $uri, $controllerMethod]) {
            $route = $routes->getByName($name);

            $this->assertInstanceOf(Route::class, $route, $name);
            $this->assertSame($uri, '/'.$route->uri(), $name);
            $this->assertContains($method, $route->methods(), $name);
            $this->assertSame(CustomerTrafficController::class, $route->getControllerClass(), $name);
            $this->assertSame($controllerMethod, $route->getActionMethod(), $name);

            $middleware = $route->gatherMiddleware();
            $this->assertContains('web', $middleware, $name);
            $this->assertContains('auth', $middleware, $name);
            $this->assertContains('verified', $middleware, $name);
            $this->assertContains('throttle:api', $middleware, $name);
        }
    }

    public function test_customer_traffic_read_routes_are_not_left_on_the_legacy_uri(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if ($route->getControllerClass() !== CustomerTrafficController::class) {
                continue;
            }

            $this->assertNotSame('/api/monitoring/traffic', '/'.$route->uri());
            $this->assertNotSame('/api/monitoring/traffic/options', '/'.$route->uri());
        }
    }

    public function test_monitoring_panel_keeps_named_route_callers(): void
    {
        $panel = (string) file_get_contents(base_path('resources/js/Pages/Monitoring/CustomerTrafficPanel.tsx'));

        $this->assertStringContainsString("route('api.monitoring.traffic.options')", $panel);
        $this->assertStringContainsString("route('api.monitoring.traffic.index')", $panel);
    }

    public function test_legacy_customer_traffic_uri_is_not_a_public_alias(): void
    {
        $this->getJson('/api/monitoring/traffic')->assertNotFound();
        $this->getJson('/api/monitoring/traffic/options')->assertNotFound();
    }
}
