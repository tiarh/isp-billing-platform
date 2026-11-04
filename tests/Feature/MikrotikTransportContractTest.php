<?php

namespace Tests\Feature;

use App\Jobs\SyncRouterJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class MikrotikTransportContractTest extends TestCase
{
    public function test_routeros_transport_enables_tls_for_api_ssl_port_with_timeout(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/MikrotikService.php'));

        $this->assertStringContainsString("'ssl' => \$options['ssl'] ?? ((int) \$router->port === 8729)", $source);
        $this->assertStringContainsString("'socket_timeout' => \$timeout", $source);
        $this->assertStringNotContainsString('Log::info("password', $source);
        $this->assertStringNotContainsString('Log::info("pass', $source);
    }

    public function test_router_sync_is_unique_and_serialized_per_router(): void
    {
        $job = new SyncRouterJob(17);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('router-sync', $job->queue);
        $this->assertSame(600, $job->uniqueFor);
        $this->assertSame('router-sync:17', $job->uniqueId());
        $this->assertSame(1, count($job->middleware()));
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }
}
