<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Canary;
use Tests\TestCase;

class PsbPppoeLiveSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_pppoe_search_includes_live_secret_and_active_connection_read_only(): void
    {
        Setting::set('psb_pppoe_search_timeout_seconds', 4);
        $customer = $this->customer('Kartono', 'KARTONO_RW4');
        $fake = new PsbPppoeSearchMikrotikFake(
            secret: ['name' => 'KARTONO_RW4', 'profile' => '20M'],
            active: [['name' => 'KARTONO_RW4', 'address' => '198.18.11.244', 'uptime' => '1h2m']]
        );

        $draft = $this->service($fake)->handlePppoeQuery('aktivasi@g.us', 'kartono');

        $this->assertSame('pppoe_found', $draft->status);
        $this->assertStringContainsString('Secret Live: ada (20M)', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('Active Connection: online (198.18.11.244)', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('Last Seen/Snapshot: active uptime 1h2m', PsbPppoeSearchHarness::$lastReply);
        $this->assertSame([['timeout' => 4, 'attempts' => 1]], $fake->connectOptions);
        $this->assertSame(0, $fake->writeCalls);
        $this->assertSame('KARTONO_RW4', $customer->fresh()->pppoe_user);
    }

    public function test_pppoe_search_falls_back_honestly_when_router_times_out(): void
    {
        Setting::set('psb_pppoe_search_timeout_seconds', 4);
        $this->customer('Poniti', 'PONITI_RW4');
        $fake = new PsbPppoeSearchMikrotikFake(timeout: true);

        $draft = $this->service($fake)->handlePppoeQuery('aktivasi@g.us', 'poniti');

        $this->assertSame('pppoe_found', $draft->status);
        $this->assertStringContainsString('Secret Live: timeout/gagal baca live', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('Active Connection: tidak terkonfirmasi', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('pakai snapshot DB dulu', PsbPppoeSearchHarness::$lastReply);
        $this->assertSame(0, $fake->writeCalls);
    }

    public function test_pppoe_search_finds_unlinked_router_snapshot_without_live_scan(): void
    {
        $router = Router::create([
            'name' => 'RA-OLT',
            'ip_address' => '198.18.9.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
        ]);
        RouterStagedCustomer::create([
            'router_id' => $router->id,
            'pppoe_user' => 'KANTOR_DAPUR',
            'profile' => 'STARTLITE',
            'status' => 'unmatched',
            'raw_payload' => [
                'local-address' => '198.18.11.1',
                'remote-address' => '198.18.11.88',
            ],
            'last_seen_at' => now()->subMinutes(3),
        ]);
        $fake = new PsbPppoeSearchMikrotikFake;

        $draft = $this->service($fake)->handlePppoeQuery('aktivasi@g.us', 'dapur');

        $this->assertSame('pppoe_router_snapshot_found', $draft->status);
        $this->assertStringContainsString('KANTOR_DAPUR', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('belum ketemu', PsbPppoeSearchHarness::$lastReply);
        $this->assertStringContainsString('RA-OLT', PsbPppoeSearchHarness::$lastReply);
        $this->assertSame([], $fake->connectOptions);
        $this->assertSame(0, $fake->writeCalls);
    }

    private function service(MikrotikService $mikrotik): PsbPppoeSearchHarness
    {
        PsbPppoeSearchHarness::$lastReply = '';

        return new PsbPppoeSearchHarness(
            new EvolutionApiService,
            $mikrotik,
            new PsbMessageParser,
            new PsbWaMessages,
            new KtpOcrService,
            new RadiusUserService,
            app(PsbDocumentGateService::class),
        );
    }

    private function customer(string $name, string $pppoe): Customer
    {
        $router = Router::create([
            'name' => 'RA-OLT',
            'ip_address' => '198.18.9.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'last_health_check_at' => now()->subMinutes(7),
        ]);
        $package = Package::create([
            'code' => 'PKG-'.$pppoe,
            'name' => '20 Mbps',
            'price' => 150000,
            'mikrotik_profile' => '20M',
            'is_active' => true,
        ]);

        return Customer::create([
            'code' => 'C-'.$pppoe,
            'name' => $name,
            'phone' => '081234567890',
            'address' => 'RW 4 Randuagung',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'pppoe_user' => $pppoe,
            'pppoe_password' => 'canary-pw',
            'pppoe_local_address' => '198.18.11.1',
            'pppoe_remote_address' => '198.18.11.244',
            'mikrotik_sync_checked_at' => now()->subMinutes(5),
            'status' => 'active',
        ]);
    }
}

class PsbPppoeSearchHarness extends PsbOrchestratorService
{
    public static string $lastReply = '';

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        self::$lastReply = $text;

        return true;
    }
}

class PsbPppoeSearchMikrotikFake extends MikrotikService
{
    /** @var list<array<string, mixed>> */
    public array $connectOptions = [];

    public int $writeCalls = 0;

    /**
     * @param  array<string, mixed>|null  $secret
     * @param  list<array<string, mixed>>  $active
     */
    public function __construct(
        private ?array $secret = null,
        private array $active = [],
        private bool $timeout = false,
    ) {}

    public function connect(Router $router, array $options = []): self
    {
        $this->connectOptions[] = $options;
        if ($this->timeout) {
            throw new \RuntimeException('RouterOS stream timeout');
        }

        return $this;
    }

    public function disconnect(): void {}

    public function getPPPSecret(string $username): ?array
    {
        return $this->secret;
    }

    public function getActiveConnections(bool $withMeta = false): array
    {
        return $this->active;
    }

    public function addPPPSecret(
        string $username,
        string $password,
        string $profile,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $service = 'pppoe',
        ?string $comment = null
    ): array {
        $this->writeCalls++;

        return ['success' => false];
    }
}
