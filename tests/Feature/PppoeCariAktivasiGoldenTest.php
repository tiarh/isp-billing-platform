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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Golden (byte-for-byte) snapshot of the Aktivasi/PSB-group `#pppoe cari` replies. Written
 * against the pre-extraction code and kept as the proof that moving the search into
 * PppoeSearchService did not change a single byte of what the PSB group receives.
 */
#[Group('blocking-psb')]
class PppoeCariAktivasiGoldenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
        Setting::set('psb_pppoe_search_timeout_seconds', 4);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_empty_query_usage_text(): void
    {
        $draft = $this->service(new PppoeGoldenMikrotikFake)->handlePppoeQuery('aktivasi@g.us', '  ');
        $this->assertSingleReply();

        $this->assertSame('pppoe_query', $draft->status);
        $this->assertSame(
            "⚠️ *Format cari PPPoE*\nGunakan: `#pppoe cari <nama/user/telp>`\n\n_Contoh:_\n`#pppoe cari djoko comboran`\n`#pppoe cari 0812...`",
            PppoeGoldenHarness::$lastReply
        );
    }

    public function test_not_found_text(): void
    {
        $draft = $this->service(new PppoeGoldenMikrotikFake)->handlePppoeQuery('aktivasi@g.us', 'zzz-nope');
        $this->assertSingleReply();

        $this->assertSame('pppoe_not_found', $draft->status);
        $this->assertSame("❌ *Pencarian PPPoE*\nTidak ditemukan pelanggan dengan kata kunci: *zzz-nope*", PppoeGoldenHarness::$lastReply);
    }

    public function test_single_match_detail_text(): void
    {
        $c = $this->customer('Kartono', 'KARTONO_RW4', '081234567890');
        $fake = new PppoeGoldenMikrotikFake(
            secret: ['name' => 'KARTONO_RW4', 'profile' => '20M'],
            active: [['name' => 'KARTONO_RW4', 'address' => '198.18.11.244', 'uptime' => '1h2m']]
        );

        $draft = $this->service($fake)->handlePppoeQuery('aktivasi@g.us', 'kartono');
        $this->assertSingleReply();

        $this->assertSame('pppoe_found', $draft->status);
        $this->assertSame(
            "🔍 *Detail PPPoE Pelanggan*\n\n".
            "🆔 *Customer:* #{$c->id}\n".
            "👤 *Nama:* Kartono\n".
            "🔑 *User PPPoE:* `KARTONO_RW4`\n".
            "🔐 *Pass PPPoE:* tersedia di MikroTik (tidak ditampilkan)\n".
            "📦 *Profile/Paket:* 20M\n".
            "🌐 *Local/Remote:* `198.18.11.1` / `198.18.11.244`\n".
            "📍 *Alamat/Area:* RW 4 Randuagung\n".
            "🌐 *Router:* RA-OLT\n".
            "⚡ *Status:* ACTIVE\n".
            '🔗 *Link:* '.url('/customers/'.$c->id)."\n".
            "➡️ *Lanjut:* jika pelanggan ini benar, gunakan data router/secret di atas. Jika session tidak aktif, cek MikroTik RA-OLT > PPP Active.\n\n".
            "*Live Read MikroTik*\n".
            "Secret Live: ada (20M)\n".
            "Active Connection: online (198.18.11.244)\n".
            "Last Seen/Snapshot: active uptime 1h2m\n".
            'Action: session aktif; gunakan data di atas untuk verifikasi pelanggan.',
            PppoeGoldenHarness::$lastReply
        );
        $this->assertStringNotContainsString('canary-pw', PppoeGoldenHarness::$lastReply);
    }

    public function test_multi_match_list_text(): void
    {
        $a = $this->customer('Budi Satu', 'BUDI_A', '081200000001');
        $b = $this->customer('Budi Dua', 'BUDI_B', '081200000002');
        $fake = new PppoeGoldenMikrotikFake(
            secret: ['name' => 'X', 'profile' => '20M'],
            active: [['name' => 'BUDI_A', 'address' => '198.18.11.244', 'uptime' => '1h2m']]
        );

        $draft = $this->service($fake)->handlePppoeQuery('aktivasi@g.us', 'budi');
        $this->assertSingleReply();

        $this->assertSame('pppoe_multi_match', $draft->status);
        $item = function (int $n, Customer $c, string $name, string $user, string $live): string {
            return "{$n}. *{$name}* (#{$c->id})\n   • User: `{$user}` | Pass: tersedia di MikroTik (tidak ditampilkan)\n".
                "   • Profile: 20M | IP: `198.18.11.1` / `198.18.11.244`\n   • Area/Alamat: RW 4 Randuagung\n   • Router: RA-OLT\n".
                "   - Live: {$live}\n\n";
        };
        $this->assertSame(
            "🔍 *Ditemukan beberapa pelanggan:* (max 5)\n\n".
            $item(1, $a, 'Budi Satu', 'BUDI_A', 'secret ada (20M), active online (198.18.11.244), seen active uptime 1h2m').
            $item(2, $b, 'Budi Dua', 'BUDI_B', 'secret ada (20M), active offline/tidak aktif, seen belum ada snapshot').
            '_Ketik kata kunci lengkap jika ingin detail tunggal._',
            PppoeGoldenHarness::$lastReply
        );
    }

    public function test_router_snapshot_text(): void
    {
        $router = Router::create(['name' => 'RA-OLT', 'ip_address' => '198.18.9.1', 'username' => 'admin', 'password' => Canary::of('pw')]);
        RouterStagedCustomer::create([
            'router_id' => $router->id,
            'pppoe_user' => 'KANTOR_DAPUR',
            'profile' => 'STARTLITE',
            'status' => 'unmatched',
            'raw_payload' => ['local-address' => '198.18.11.1', 'remote-address' => '198.18.11.88'],
            'last_seen_at' => Carbon::parse('2026-10-15 05:00:00', 'UTC'),
        ]);

        $draft = $this->service(new PppoeGoldenMikrotikFake)->handlePppoeQuery('aktivasi@g.us', 'dapur');
        $this->assertSingleReply();

        $this->assertSame('pppoe_router_snapshot_found', $draft->status);
        $this->assertSame(
            "🔍 *Pencarian PPPoE*\n".
            "Pelanggan eBilling belum ketemu, tapi secret/router snapshot ditemukan untuk: *dapur*\n\n".
            "1. `KANTOR_DAPUR`\n".
            "   Router: RA-OLT | Profile: STARTLITE\n".
            "   IP: `198.18.11.1` / `198.18.11.88` | Status: unmatched\n".
            "   Last sync: 15/10/2026 12:00 WIB\n\n".
            "Catatan: ini read-only dari snapshot MikroTik. Belum ada link customer eBilling.\n".
            'Next: cek/link customer manual dulu, atau pakai data ini untuk preview migrasi.',
            PppoeGoldenHarness::$lastReply
        );
    }

    /** Exactly one reply() per Aktivasi query, addressed to the asking group. */
    private function assertSingleReply(): void
    {
        $this->assertSame(1, PppoeGoldenHarness::$replyCount);
        $this->assertSame('aktivasi@g.us', PppoeGoldenHarness::$lastGroup);
    }

    private function service(MikrotikService $mikrotik): PppoeGoldenHarness
    {
        PppoeGoldenHarness::$lastReply = '';
        PppoeGoldenHarness::$lastGroup = '';
        PppoeGoldenHarness::$replyCount = 0;

        return new PppoeGoldenHarness(
            new EvolutionApiService,
            $mikrotik,
            new PsbMessageParser,
            new PsbWaMessages,
            new KtpOcrService,
            new RadiusUserService,
            app(PsbDocumentGateService::class),
        );
    }

    private function customer(string $name, string $pppoe, string $phone): Customer
    {
        $router = Router::query()->where('name', 'RA-OLT')->first() ?? Router::create([
            'name' => 'RA-OLT',
            'ip_address' => '198.18.9.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
        ]);
        $package = Package::query()->where('code', 'PKG-GOLD')->first() ?? Package::create([
            'code' => 'PKG-GOLD',
            'name' => '20 Mbps',
            'price' => 150000,
            'mikrotik_profile' => '20M',
            'is_active' => true,
        ]);

        return Customer::create([
            'code' => 'C-'.$pppoe,
            'name' => $name,
            'phone' => $phone,
            'address' => 'RW 4 Randuagung',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'pppoe_user' => $pppoe,
            'pppoe_password' => 'canary-pw',
            'pppoe_local_address' => '198.18.11.1',
            'pppoe_remote_address' => '198.18.11.244',
            'status' => 'active',
        ]);
    }
}

class PppoeGoldenHarness extends PsbOrchestratorService
{
    public static string $lastReply = '';

    public static string $lastGroup = '';

    public static int $replyCount = 0;

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        self::$lastReply = $text;
        self::$lastGroup = $groupJid;
        self::$replyCount++;

        return true;
    }
}

class PppoeGoldenMikrotikFake extends MikrotikService
{
    public int $writeCalls = 0;

    /**
     * @param  array<string, mixed>|null  $secret
     * @param  list<array<string, mixed>>  $active
     */
    public function __construct(private ?array $secret = null, private array $active = []) {}

    public function connect(Router $router, array $options = []): self
    {
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
}
