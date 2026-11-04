<?php

namespace Tests\Feature;

use App\Jobs\IsolateCustomerJob;
use App\Jobs\ReconnectCustomerJob;
use App\Models\Customer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class RouterEnforcementThrottleTest extends TestCase
{
    public function test_isolate_and_reconnect_jobs_keep_contract_and_add_router_limiter(): void
    {
        $jobs = [
            new IsolateCustomerJob(new Customer(['router_id' => 107]), 1001),
            new ReconnectCustomerJob(new Customer(['router_id' => 107]), 1002),
        ];

        foreach ($jobs as $job) {
            $this->assertSame(3, $job->tries);
            $this->assertSame([60, 180, 600], $job->backoff);
            $this->assertSame(900, $job->uniqueFor);
            $this->assertSame('network-enforcement', $job->queue);

            $middleware = $job->middleware();

            $this->assertCount(2, $middleware);
            $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
            $this->assertInstanceOf(RateLimited::class, $middleware[1]);
            $this->assertSame('router-api', $this->readLimiterName($middleware[1]));
        }
    }

    public function test_fifty_jobs_for_one_router_share_ten_per_minute_bucket_and_release_overflow(): void
    {
        RateLimiter::clear('router:107');

        $nextCalls = 0;
        $released = 0;
        $failed = 0;
        $middleware = new RateLimited('router-api');

        for ($index = 0; $index < 50; $index++) {
            $job = new FakeRouterEnforcementQueueJob(new Customer(['router_id' => 107]));
            $middleware->handle($job, function () use (&$nextCalls): void {
                $nextCalls++;
            });
            $released += $job->releaseCount;
            $failed += $job->failCount;
        }

        $this->assertSame(10, $nextCalls);
        $this->assertSame(40, $released);
        $this->assertSame(0, $failed);
    }

    public function test_router_limiter_is_ten_per_minute_and_scoped_by_router(): void
    {
        $limiter = RateLimiter::limiter('router-api');

        $this->assertNotNull($limiter);

        $routerA = $this->resolveLimit($limiter, new FakeRouterEnforcementQueueJob(new Customer(['router_id' => 107])));
        $routerB = $this->resolveLimit($limiter, new FakeRouterEnforcementQueueJob(new Customer(['router_id' => 108])));

        $this->assertSame(10, $routerA->maxAttempts);
        $this->assertSame(60, $routerA->decaySeconds);
        $this->assertSame('router:107', $routerA->key);
        $this->assertSame('router:108', $routerB->key);
    }

    private function resolveLimit(\Closure $limiter, object $job): Limit
    {
        $resolved = $limiter($job);
        $limits = is_array($resolved) ? $resolved : [$resolved];

        $this->assertCount(1, $limits);
        $this->assertInstanceOf(Limit::class, $limits[0]);

        return $limits[0];
    }

    private function readLimiterName(RateLimited $middleware): string
    {
        $property = new \ReflectionProperty($middleware, 'limiterName');

        return (string) $property->getValue($middleware);
    }
}

final class FakeRouterEnforcementQueueJob
{
    public int $releaseCount = 0;

    public int $failCount = 0;

    public function __construct(public Customer $customer) {}

    public function release(?int $delay = null): void
    {
        $this->releaseCount++;
    }

    public function fail(?\Throwable $exception = null): void
    {
        $this->failCount++;
    }
}
