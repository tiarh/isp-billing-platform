<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class IsolirSiteVariantTest extends TestCase
{
    use RefreshDatabase;

    private const IN_SITE_IP = '192.0.2.77';

    private const OUTSIDE_IP = '198.51.100.9';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['isolation_portal_enabled', 'isolation_portal_mode', 'isolation_portal_wa_admin', 'isolation_portal_office_address', 'isolation_portal_office_hours', 'company_name', 'company_address', 'isolir_site_variants'] as $key) {
            Cache::forget("setting.$key");
        }

        config([
            'app.url' => 'http://localhost',
            'services.isolation_portal.enabled' => true,
        ]);

        Setting::set('isolation_portal_wa_admin', '081200000009');
    }

    /**
     * @return array<string, mixed>
     */
    private function variant(array $override = []): array
    {
        return array_merge([
            'brand' => 'Contoh Net',
            'tagline' => 'Internet jadi lebih baik',
            'cs_phone' => '0812-0000-0001',
            'bank_name' => 'BANKUJI',
            'bank_account' => '0000000000',
            'bank_holder' => 'Nama Uji',
            'logo' => 'images/isolir/gadang.webp',
            'source_cidrs' => ['192.0.2.0/24'],
        ], $override);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function setVariants(array $value): void
    {
        Setting::set('isolir_site_variants', $value);
    }

    private function getFrom(string $ip, string $uri = '/isolir')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->get($uri);
    }

    public function test_variant_is_chosen_by_source_cidr(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $this->getFrom(self::IN_SITE_IP)
            ->assertOk()
            ->assertViewIs('isolir.site')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Akun Anda diisolir')
            ->assertSee('Karena sudah waktunya pembayaran')
            ->assertSee('Customer Service')
            ->assertSee('Rekening Pembayaran')
            ->assertSee('BANKUJI')
            ->assertSee('0000000000')
            ->assertSee('Nama Uji')
            ->assertSee('Akses internet Anda saat ini dinonaktifkan')
            ->assertSee('Internet jadi lebih baik')
            ->assertSee('src="/images/isolir/gadang.webp"', false)
            ->assertDontSee('<script', false)
            ->assertDontSee('data-page=', false)
            ->assertDontSee('/build/assets/', false);
    }

    public function test_variant_page_is_small_and_has_no_login_link(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $body = (string) $this->getFrom(self::IN_SITE_IP)->assertOk()->getContent();

        $this->assertLessThan(12 * 1024, strlen($body));
        $this->assertStringNotContainsStringIgnoringCase('Login Pelanggan', $body);
        $this->assertStringNotContainsStringIgnoringCase('login', $body);
        $this->assertStringNotContainsString('/login', $body);
    }

    public function test_logo_file_is_small_and_served_from_public(): void
    {
        $path = public_path('images/isolir/gadang.webp');

        $this->assertFileExists($path);
        $this->assertLessThanOrEqual(20 * 1024, filesize($path));
    }

    public function test_wa_and_tel_links_are_built_from_digits(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $this->getFrom(self::IN_SITE_IP)
            ->assertOk()
            ->assertSee('href="https://wa.me/6281200000001"', false)
            ->assertSee('href="tel:+6281200000001"', false)
            ->assertSee('0812-0000-0001');
    }

    public function test_explicit_site_param_selects_variant_for_any_ip(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $this->getFrom(self::OUTSIDE_IP, '/isolir?site=demo')
            ->assertOk()
            ->assertViewIs('isolir.site')
            ->assertSee('Contoh Net');

        $this->getFrom(self::OUTSIDE_IP, '/isolir?site=unknown')
            ->assertOk()
            ->assertViewIs('isolir.general');

        $this->getFrom(self::OUTSIDE_IP, '/isolir?site[]=demo')
            ->assertOk()
            ->assertViewIs('isolir.general');
    }

    public function test_ip_outside_variant_gets_general_page_byte_identical(): void
    {
        $this->setVariants(['demo' => $this->variant()]);
        Setting::set('company_name', 'PT Skynet Uji');
        Setting::set('isolation_portal_office_address', 'Jl. Uji Coba No. 1');
        Setting::set('isolation_portal_office_hours', 'Senin-Sabtu 08.00-16.00');
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(12, 0));

        $expected = view('isolir.general', [
            'companyName' => 'PT Skynet Uji',
            'waAdmin' => '081200000009',
            'waLink' => 'https://wa.me/6281200000009',
            'telLink' => 'tel:+6281200000009',
            'officeAddress' => 'Jl. Uji Coba No. 1',
            'officeHours' => 'Senin-Sabtu 08.00-16.00',
        ])->render();

        $response = $this->getFrom(self::OUTSIDE_IP)->assertOk()->assertViewIs('isolir.general');

        $this->assertSame($expected, $response->getContent());
        $this->assertStringNotContainsString('BANKUJI', (string) $response->getContent());
        $this->assertStringNotContainsString('Contoh Net', (string) $response->getContent());
    }

    public function test_general_page_is_unchanged_when_setting_is_missing(): void
    {
        $this->getFrom(self::IN_SITE_IP)
            ->assertOk()
            ->assertViewIs('isolir.general')
            ->assertSee('Internet Anda sedang diisolir');
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidSettings(): array
    {
        $ok = [
            'brand' => 'Contoh Net', 'tagline' => '', 'cs_phone' => '081200000001',
            'bank_name' => 'BANKUJI', 'bank_account' => '0000000000', 'bank_holder' => 'Nama Uji',
            'logo' => '', 'source_cidrs' => ['192.0.2.0/24'],
        ];

        return [
            'broken json' => ['{not json'],
            'json scalar' => ['"text"'],
            'empty string' => [''],
            'variant not an object' => [json_encode(['demo' => 'x'])],
            'bad cidr' => [json_encode(['demo' => array_merge($ok, ['source_cidrs' => ['192.0.2.0/99']])])],
            'cidr garbage' => [json_encode(['demo' => array_merge($ok, ['source_cidrs' => ['not-an-ip']])])],
            'cidr not list of strings' => [json_encode(['demo' => array_merge($ok, ['source_cidrs' => [12]])])],
            'phone letters' => [json_encode(['demo' => array_merge($ok, ['cs_phone' => 'abc'])])],
            'phone too short' => [json_encode(['demo' => array_merge($ok, ['cs_phone' => '123'])])],
            'account letters' => [json_encode(['demo' => array_merge($ok, ['bank_account' => 'ABC123XYZ'])])],
            'account too short' => [json_encode(['demo' => array_merge($ok, ['bank_account' => '12'])])],
            'brand missing' => [json_encode(['demo' => array_diff_key($ok, ['brand' => 1])])],
            'brand too long' => [json_encode(['demo' => array_merge($ok, ['brand' => str_repeat('a', 61)])])],
            'logo path traversal' => [json_encode(['demo' => array_merge($ok, ['logo' => '../../.env'])])],
            'logo external url' => [json_encode(['demo' => array_merge($ok, ['logo' => 'https://evil.example/x.png'])])],
            'bad key' => [json_encode(['Bad Key!' => $ok])],
        ];
    }

    #[DataProvider('invalidSettings')]
    public function test_invalid_setting_falls_back_to_general_page_without_error(mixed $value): void
    {
        Setting::set('isolir_site_variants', $value);

        $this->getFrom(self::IN_SITE_IP)->assertOk()->assertViewIs('isolir.general');
        $this->getFrom(self::IN_SITE_IP, '/isolir?site=demo')->assertOk()->assertViewIs('isolir.general');
    }

    public function test_one_invalid_variant_does_not_break_a_valid_one(): void
    {
        $this->setVariants([
            'broken' => $this->variant(['source_cidrs' => ['nope'], 'brand' => 'Rusak']),
            'demo' => $this->variant(),
        ]);

        $this->getFrom(self::IN_SITE_IP)->assertOk()->assertViewIs('isolir.site')->assertSee('Contoh Net')->assertDontSee('Rusak');
    }

    public function test_setting_stored_as_json_text_also_works(): void
    {
        Setting::set('isolir_site_variants', json_encode(['demo' => $this->variant()]));

        $this->getFrom(self::IN_SITE_IP)->assertOk()->assertViewIs('isolir.site');
    }

    public function test_cidr_edges_and_ipv6(): void
    {
        $this->setVariants([
            'a' => $this->variant(['brand' => 'Satu', 'source_cidrs' => ['192.0.2.128/25']]),
            'b' => $this->variant(['brand' => 'Dua', 'source_cidrs' => ['203.0.113.5', '2001:db8::/32']]),
        ]);

        $this->getFrom('192.0.2.200')->assertViewIs('isolir.site')->assertSee('Satu');
        $this->getFrom('192.0.2.100')->assertViewIs('isolir.general');
        $this->getFrom('203.0.113.5')->assertViewIs('isolir.site')->assertSee('Dua');
        $this->getFrom('203.0.113.6')->assertViewIs('isolir.general');
        $this->getFrom('2001:db8::1')->assertViewIs('isolir.site')->assertSee('Dua');
        $this->getFrom('2001:db9::1')->assertViewIs('isolir.general');
    }

    public function test_values_are_html_escaped(): void
    {
        $this->setVariants(['demo' => $this->variant([
            'brand' => '<script>alert(1)</script>',
            'tagline' => '"><img src=x onerror=alert(2)>',
            'bank_name' => '<b>BANK</b>',
            'bank_holder' => '<i>Uji</i>',
        ])]);

        $this->getFrom(self::IN_SITE_IP)
            ->assertOk()
            ->assertViewIs('isolir.site')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x', false)
            ->assertDontSee('<b>BANK</b>', false)
            ->assertDontSee('<i>Uji</i>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('&lt;b&gt;BANK&lt;/b&gt;', false);
    }

    public function test_captive_still_redirects_to_isolir_and_variant_renders_there(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $this->getFrom(self::IN_SITE_IP, '/isolated/captive')->assertStatus(302)->assertRedirect('/isolir');
        $this->getFrom(self::IN_SITE_IP, '/isolir')->assertViewIs('isolir.site');
    }

    public function test_captive_passes_valid_site_key_through_and_drops_junk(): void
    {
        $this->setVariants(['demo' => $this->variant(['source_cidrs' => []])]);

        $this->get('/isolated/captive?site=demo')->assertStatus(302)->assertRedirect('/isolir?site=demo');
        $this->get('/isolated/captive?site='.rawurlencode('x/../y'))->assertRedirect('/isolir');
        $this->get('/isolated/captive?site[]=demo')->assertRedirect('/isolir');
    }

    public function test_site_param_works_standalone_with_foreign_host_and_referer_and_empty_cidrs(): void
    {
        $this->setVariants(['demo' => $this->variant(['source_cidrs' => []])]);

        $response = $this->withHeaders(['Referer' => 'http://foreign.example/page'])
            ->getFrom(self::OUTSIDE_IP, 'http://foreign.example/isolir?site=demo');

        $response->assertOk()
            ->assertViewIs('isolir.site')
            ->assertSee('Contoh Net')
            ->assertSee('src="/images/isolir/gadang.webp"', false);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->isRedirection());
    }

    public function test_portal_disabled_is_still_404_for_variant_clients(): void
    {
        config(['services.isolation_portal.enabled' => false]);
        Cache::forget('setting.isolation_portal_enabled');
        $this->setVariants(['demo' => $this->variant()]);

        $this->getFrom(self::IN_SITE_IP)->assertNotFound();
        $this->getFrom(self::IN_SITE_IP, '/isolir?site=demo')->assertNotFound();
    }

    public function test_variant_page_keeps_the_isolir_portal_throttle(): void
    {
        $this->setVariants(['demo' => $this->variant()]);

        $this->assertContains('throttle:isolir-portal', app('router')->getRoutes()->getByName('public.isolir')->gatherMiddleware());

        for ($i = 0; $i < 30; $i++) {
            $this->getFrom(self::IN_SITE_IP)->assertOk();
        }
    }
}
