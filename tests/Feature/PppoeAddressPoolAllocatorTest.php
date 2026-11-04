<?php

namespace Tests\Feature;

use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Services\MikrotikService;
use App\Services\PppoeAddressPoolAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class PppoeAddressPoolAllocatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_ra_last_remote_allocates_next_remote_in_same_block(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254', next: 12);

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1'],
        ]);

        $this->assertSame('198.18.11.1', $allocated['local']);
        $this->assertSame('198.18.11.244', $allocated['remote']);
    }

    public function test_ra_full_block_falls_back_to_next_block(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254', next: 12);
        $this->block($router, 12, '198.18.12.1', '198.18.12.2', '198.18.12.254');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.11.254', 'local_address' => '198.18.11.1'],
        ]);

        $this->assertSame('198.18.12.1', $allocated['local']);
        $this->assertSame('198.18.12.2', $allocated['remote']);
    }

    public function test_ra_block_13_is_used_after_block_12_and_block_14_after_block_13(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 12, '198.18.12.1', '198.18.12.2', '198.18.12.254', next: 13);
        $this->block($router, 13, '198.18.13.1', '198.18.13.2', '198.18.13.254', next: 14);
        $this->block($router, 14, '198.18.14.1', '198.18.14.2', '198.18.14.254');

        $fromThirteen = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.12.254', 'local_address' => '198.18.12.1'],
        ]);

        $fromFourteen = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.12.254', 'local_address' => '198.18.12.1'],
            ['remote_address' => '198.18.13.254', 'local_address' => '198.18.13.1'],
        ]);

        $this->assertSame('198.18.13.1', $fromThirteen['local']);
        $this->assertSame('198.18.13.2', $fromThirteen['remote']);
        $this->assertSame('198.18.14.1', $fromFourteen['local']);
        $this->assertSame('198.18.14.2', $fromFourteen['remote']);
    }

    public function test_gadang_last_remote_allocates_next_remote_in_same_block(): void
    {
        $router = $this->router('Gadang');
        $this->block($router, 50, '198.18.36.1', '198.18.36.2', '198.18.36.254', next: 51);

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.36.103', 'local_address' => '198.18.36.1'],
        ]);

        $this->assertSame('198.18.36.1', $allocated['local']);
        $this->assertSame('198.18.36.104', $allocated['remote']);
    }

    public function test_gadang_full_block_falls_back_to_next_block(): void
    {
        $router = $this->router('Gadang');
        $this->block($router, 50, '198.18.36.1', '198.18.36.2', '198.18.36.254', next: 51);
        $this->block($router, 51, '198.18.37.1', '198.18.37.2', '198.18.37.254');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.36.254', 'local_address' => '198.18.36.1'],
        ]);

        $this->assertSame('198.18.37.1', $allocated['local']);
        $this->assertSame('198.18.37.2', $allocated['remote']);
    }

    public function test_short_static_full_block_falls_back_to_next_short_block(): void
    {
        $router = $this->router('Short Static');
        $this->block($router, 12, '12.1', '12.2', '12.254', next: 13, format: 'short_static');
        $this->block($router, 13, '13.1', '13.2', '13.254', format: 'short_static');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '12.254', 'local_address' => '12.1'],
        ]);

        $this->assertSame('short_static', $allocated['address_format']);
        $this->assertSame('13.1', $allocated['local']);
        $this->assertSame('13.2', $allocated['remote']);
    }

    public function test_remote_already_used_is_skipped(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1'],
            ['remote_address' => '198.18.11.244', 'local_address' => '198.18.11.1'],
        ]);

        $this->assertSame('198.18.11.245', $allocated['remote']);
    }

    public function test_pointer_exhausted_reuses_dismantle_hole_from_live_set(): void
    {
        // A2: pointer semua block habis, tapi live set menunjukkan banyak IP bekas
        // dismantle yang free — reuse, jangan "Blok penuh" palsu.
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254', next: 12);
        $this->block($router, 12, '198.18.12.1', '198.18.12.2', '198.18.12.254');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.11.254', 'local_address' => '198.18.11.1'],
            ['remote_address' => '198.18.12.254', 'local_address' => '198.18.12.1'],
        ]);

        $this->assertSame('198.18.11.2', $allocated['remote']);
        $this->assertSame('198.18.11.1', $allocated['local']);
    }

    public function test_all_blocks_truly_full_fails_honestly_with_router_name(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254', next: 12);
        $this->block($router, 12, '198.18.12.1', '198.18.12.2', '198.18.12.254');

        $live = [];
        foreach ([11, 12] as $octet) {
            for ($host = 2; $host <= 254; $host++) {
                $live[] = ['remote_address' => "198.18.{$octet}.{$host}", 'local_address' => "198.18.{$octet}.1"];
            }
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blok PPPoE penuh di router RA-OLT');

        $this->allocator()->allocate($router, $live);
    }

    public function test_release_failed_allocation_returns_same_remote_next_time(): void
    {
        // A1: write MT gagal → pointer di-rollback → alokasi berikutnya dapat IP yang sama.
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');
        $live = [['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1']];

        $first = $this->allocator()->allocate($router, $live);
        $this->assertSame('198.18.11.244', $first['remote']);

        $this->allocator()->releaseFailedAllocation($router, (int) $first['block_id'], $first['remote']);

        $again = $this->allocator()->allocate($router, $live);
        $this->assertSame('198.18.11.244', $again['remote']);
    }

    public function test_short_static_block_reads_full_ipv4_live_secrets(): void
    {
        // A3: block short_static tapi live MT menyimpan full IPv4 — used-set tetap terbaca
        // (mapping via oktet ke-3), tidak collision.
        $router = $this->router('Short Static Mixed');
        $this->block($router, 12, '12.1', '12.2', '12.254', format: 'short_static');

        $allocated = $this->allocator()->allocate($router, [
            ['remote_address' => '198.18.12.243', 'local_address' => '198.18.12.1'],
        ]);

        $this->assertSame('12.244', $allocated['remote']);
    }

    public function test_block_area_matches_normalized_daerah(): void
    {
        // A4: block customer_area `kalirejo` harus match `Daerah: Skynet-Kalirejo`.
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');
        $block->forceFill(['customer_area' => 'kalirejo'])->save();

        $allocated = $this->allocator()->allocate(
            $router,
            [['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1']],
            null,
            null,
            'Skynet-Kalirejo'
        );

        $this->assertSame('198.18.11.244', $allocated['remote']);
    }

    public function test_site_scoped_block_requires_matching_site_key(): void
    {
        // Regression: a pool block scoped to a site (site_profile_key=krian) must
        // NOT be returned when the caller passes siteKey=null. This is the exact
        // migration bug where cross-router migration requested site=null against
        // a site-scoped Krian-OLT block and got no local/remote address.
        $router = $this->router('SKYNET-RA-OLT');
        $block = $this->block($router, 11, '198.19.1.1', '198.19.1.2', '198.19.1.254');
        $block->forceFill(['site_profile_key' => 'krian'])->save();

        $withNull = $this->allocator()->allocate($router, [], null, '10MB', 'Krian');
        $this->assertNull($withNull, 'block scoped to krian must not match site=null');

        $withSite = $this->allocator()->allocate($router, [], 'krian', '10MB', 'Krian');
        $this->assertNotNull($withSite);
        $this->assertSame('198.19.1.1', $withSite['local']);
    }

    public function test_global_block_matches_any_site_key(): void
    {
        $router = $this->router('SKYNET-RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');

        $allocated = $this->allocator()->allocate($router, [], null, '10MB', null);
        $this->assertSame('198.18.11.2', $allocated['remote']);
    }

    public function test_empty_live_set_never_reuses_holes(): void
    {
        // A6: live-read kosong (tidak reliable) → pointer-forward saja; ketika pointer
        // habis JANGAN reuse hole (bisa collision) — fail jujur.
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');
        $block->forceFill(['last_remote_address' => '198.18.11.254', 'last_remote_source' => 'db_pointer'])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Blok PPPoE penuh');

        $this->allocator()->allocate($router, []);
    }

    public function test_db_pointer_prevents_duplicate_when_live_snapshot_is_stale(): void
    {
        $router = $this->router('RA-OLT');
        $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');
        $liveSnapshot = [
            ['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1'],
        ];

        $first = $this->allocator()->allocate($router, $liveSnapshot);
        $second = $this->allocator()->allocate($router, $liveSnapshot);

        $this->assertSame('198.18.11.244', $first['remote']);
        $this->assertSame('198.18.11.245', $second['remote']);
    }

    public function test_reconcile_updates_capacity_warning_and_last_remote_from_live_snapshot(): void
    {
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.4');

        $rows = $this->allocator()->reconcileBlocks($router, [
            ['remote_address' => '198.18.11.2', 'local_address' => '198.18.11.1'],
            ['remote_address' => '198.18.11.3', 'local_address' => '198.18.11.1'],
        ]);

        $this->assertSame('warning', $rows[0]['status']);
        $block->refresh();
        $this->assertSame(3, $block->capacity);
        $this->assertSame(2, $block->used_count);
        $this->assertSame(1, $block->available_count);
        $this->assertSame('198.18.11.3', $block->last_remote_address);
        $this->assertSame('live_mikrotik', $block->last_remote_source);
        $this->assertNotNull($block->last_remote_synced_at);
    }

    public function test_reconcile_dry_run_does_not_save_counters(): void
    {
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.4');

        $rows = $this->allocator()->reconcileBlocks($router, [
            ['remote_address' => '198.18.11.2', 'local_address' => '198.18.11.1'],
            ['remote_address' => '198.18.11.3', 'local_address' => '198.18.11.1'],
            ['remote_address' => '198.18.11.4', 'local_address' => '198.18.11.1'],
        ], dryRun: true);

        $this->assertSame('full', $rows[0]['status']);
        $block->refresh();
        $this->assertNull($block->last_remote_address);
        $this->assertNull($block->last_synced_at);
    }

    public function test_reconcile_keeps_db_pointer_when_live_snapshot_is_stale(): void
    {
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.254');
        $block->forceFill(['last_remote_address' => '198.18.11.244'])->save();

        $this->allocator()->reconcileBlocks($router, [
            ['remote_address' => '198.18.11.243', 'local_address' => '198.18.11.1'],
        ]);

        $block->refresh();
        $this->assertSame('198.18.11.244', $block->last_remote_address);
        $this->assertSame('db_pointer_guard', $block->last_remote_source);
    }

    public function test_reconcile_command_reads_mikrotik_without_device_write(): void
    {
        $router = $this->router('RA-OLT');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.4');
        FakePppoeBlockMikrotikService::$secretsByRouter = [
            'RA-OLT' => [
                ['remote_address' => '198.18.11.2', 'local_address' => '198.18.11.1'],
                ['remote_address' => '198.18.11.3', 'local_address' => '198.18.11.1'],
            ],
        ];
        FakePppoeBlockMikrotikService::$timeoutRouters = [];
        FakePppoeBlockMikrotikService::$connects = [];
        FakePppoeBlockMikrotikService::$writeCalls = 0;

        $this->app->bind(MikrotikService::class, fn () => new FakePppoeBlockMikrotikService);

        $this->artisan('pppoe:blocks-reconcile', ['--router_id' => $router->id, '--timeout' => 5])
            ->assertExitCode(0);

        $block->refresh();
        $this->assertSame('198.18.11.3', $block->last_remote_address);
        $this->assertSame(['RA-OLT'], FakePppoeBlockMikrotikService::$connects);
        $this->assertSame(0, FakePppoeBlockMikrotikService::$writeCalls);
    }

    public function test_reconcile_command_handles_router_timeout_without_saving(): void
    {
        $router = $this->router('RA-Timeout');
        $block = $this->block($router, 11, '198.18.11.1', '198.18.11.2', '198.18.11.4');
        FakePppoeBlockMikrotikService::$secretsByRouter = [];
        FakePppoeBlockMikrotikService::$timeoutRouters = ['RA-Timeout'];
        FakePppoeBlockMikrotikService::$connects = [];

        $this->app->bind(MikrotikService::class, fn () => new FakePppoeBlockMikrotikService);

        $this->artisan('pppoe:blocks-reconcile', ['--router_id' => $router->id, '--timeout' => 5])
            ->assertExitCode(0);

        $block->refresh();
        $this->assertNull($block->last_synced_at);
        $this->assertSame(['RA-Timeout'], FakePppoeBlockMikrotikService::$connects);
    }

    private function allocator(): PppoeAddressPoolAllocator
    {
        return app(PppoeAddressPoolAllocator::class);
    }

    private function router(string $name): Router
    {
        return Router::create([
            'name' => $name,
            'ip_address' => '198.18.9.'.random_int(2, 200),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
    }

    private function block(
        Router $router,
        int $index,
        string $local,
        string $remoteStart,
        string $remoteEnd,
        ?int $next = null,
        string $format = 'full_ipv4'
    ): PppoeAddressPoolBlock {
        return PppoeAddressPoolBlock::create([
            'router_id' => $router->id,
            'block_name' => 'block-'.$index,
            'block_index' => $index,
            'address_format' => $format,
            'prefix_template' => $format === 'short_static' ? (string) $index : preg_replace('/\.\d+$/', '', $local),
            'local_address' => $local,
            'remote_start' => $remoteStart,
            'remote_end' => $remoteEnd,
            'next_block_index' => $next,
            'allocator_mode' => 'last_remote_pointer',
            'priority' => $index,
            'is_active' => true,
            'warning_threshold' => 10,
        ]);
    }
}

class FakePppoeBlockMikrotikService extends MikrotikService
{
    public static array $secretsByRouter = [];

    public static array $timeoutRouters = [];

    public static array $connects = [];

    public static int $writeCalls = 0;

    private ?Router $fakeRouter = null;

    public function connect(Router $router, array $options = []): self
    {
        $this->fakeRouter = $router;
        self::$connects[] = $router->name;

        if (in_array($router->name, self::$timeoutRouters, true)) {
            throw new \RuntimeException('timeout');
        }

        return $this;
    }

    public function getPPPSecretsWithAddresses(): array
    {
        return self::$secretsByRouter[$this->fakeRouter?->name ?? ''] ?? [];
    }

    public function disconnect(): void
    {
        $this->fakeRouter = null;
    }
}
