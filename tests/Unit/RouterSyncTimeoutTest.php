<?php

namespace Tests\Unit;

use App\Models\Router;
use App\Services\RouterSyncService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Pins today's fullSync API timeout values (they must not change by cleanups). */
#[Group('blocking-core')]
class RouterSyncTimeoutTest extends TestCase
{
    /** @return array<string, array{int, int}> */
    public static function counts(): array
    {
        return [
            'empty router' => [0, 35],
            'mid router' => [400, 57],
            'huge router' => [2000, 120],
        ];
    }

    #[DataProvider('counts')]
    public function test_recommended_sync_timeout(int $pppoeCount, int $expected): void
    {
        $router = new Router;
        $router->total_pppoe_count = $pppoeCount;

        $this->assertSame($expected, app(RouterSyncService::class)->recommendedSyncTimeout($router));
    }
}
