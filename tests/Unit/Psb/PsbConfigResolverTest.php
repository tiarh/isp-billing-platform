<?php

namespace Tests\Unit\Psb;

use App\Models\Package;
use App\Models\Setting;
use App\Services\Psb\PsbConfigResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbConfigResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_group_jid_forms(): void
    {
        $r = new PsbConfigResolver;

        $this->assertSame('', $r->normalizeGroupJid('   '));
        $this->assertSame('120363000000000001@g.us', $r->normalizeGroupJid('120363000000000001'));
        $this->assertSame('120363000000000001@g.us', $r->normalizeGroupJid(" 1203630000\n00000001@G.US "));
        $this->assertSame('120363000000000001@g.us', $r->normalizeGroupJid('120363000000000001@wrong.suffix'));
        $this->assertSame('abc@x', $r->normalizeGroupJid('abc@x'));
        $this->assertSame('plain', $r->normalizeGroupJid('plain'));
    }

    public function test_allowed_groups_accept_array_json_and_comma_text_without_duplicates(): void
    {
        $r = new PsbConfigResolver;

        Setting::set('psb_wa_group_ids', ['120363000000000001', '120363000000000001@g.us', ''], 'json', 'psb');
        $this->assertSame(['120363000000000001@g.us'], $r->allowedGroupJids());

        Setting::set('psb_wa_group_ids', '120363000000000001, 120363000000000002', 'text', 'psb');
        $this->assertSame(['120363000000000001@g.us', '120363000000000002@g.us'], $r->allowedGroupJids());

        Setting::set('psb_wa_group_ids', '["120363000000000003@g.us"]', 'text', 'psb');
        $this->assertSame(['120363000000000003@g.us'], $r->allowedGroupJids());
    }

    public function test_is_allowed_group(): void
    {
        $r = new PsbConfigResolver;

        $this->assertFalse($r->isAllowedGroup('120363000000000001@g.us'), 'nothing configured');

        Setting::set('psb_wa_group_ids', ['120363000000000001@g.us'], 'json', 'psb');
        $this->assertTrue($r->isAllowedGroup('120363000000000001@g.us'));
        $this->assertTrue($r->isAllowedGroup('120363000000000001'));
        $this->assertFalse($r->isAllowedGroup('120363000000000009@g.us'));
        $this->assertFalse($r->isAllowedGroup(''));
    }

    public function test_write_switches(): void
    {
        $r = new PsbConfigResolver;

        $this->assertTrue($r->writesEnabled(), 'default on');
        foreach (['0', 'false', 'off', 'no'] as $off) {
            Setting::set('psb_writes_enabled', $off, 'text', 'psb');
            $this->assertFalse($r->writesEnabled(), $off);
        }

        $this->assertFalse($r->correctionWritesEnabled(), 'default off');
        foreach (['1', 'true', 'on', 'yes'] as $on) {
            Setting::set('psb_correction_writes_enabled', $on, 'text', 'psb');
            $this->assertTrue($r->correctionWritesEnabled(), $on);
        }

        $this->assertTrue($r->psbBlockUnknownSite(), 'default on');
        Setting::set('psb_block_unknown_site', '0', 'text', 'psb');
        $this->assertFalse($r->psbBlockUnknownSite());
    }

    public function test_package_speed_and_profile_matching(): void
    {
        $r = new PsbConfigResolver;

        $this->assertSame(100, $r->packageSpeedFromHint('100'));
        $this->assertSame(200, $r->packageSpeedFromHint(' 200 mbps '));
        $this->assertNull($r->packageSpeedFromHint('STARTLITE'));

        $this->assertTrue($r->profileMatchesSpeed('100M', 100));
        $this->assertTrue($r->profileMatchesSpeed('100MB/100MB', 100));
        $this->assertFalse($r->profileMatchesSpeed('1000M', 100));
        $this->assertTrue($r->profileMatchesSpeed('STARTLITE', 200));
        $this->assertFalse($r->profileMatchesSpeed('STARTLITE', 100));
        $this->assertFalse($r->profileMatchesSpeed(null, 100));
    }

    public function test_package_mapping_error_texts(): void
    {
        $r = new PsbConfigResolver;

        $this->assertStringContainsString('belum memiliki MikroTik Profile', (string) $r->packageMappingError('100M', new Package(['mikrotik_profile' => ''])));
        $this->assertStringContainsString('tanpa default 200M', (string) $r->packageMappingError('100M', new Package(['mikrotik_profile' => '200MB'])));
        $this->assertNull($r->packageMappingError('100M', new Package(['mikrotik_profile' => '100M'])));
        $this->assertNull($r->packageMappingError('STARTLITE', new Package(['mikrotik_profile' => '200MB'])));
    }

    public function test_resolve_package_from_hint_prefers_matching_speed_and_skips_inactive(): void
    {
        $r = new PsbConfigResolver;

        $this->assertNull($r->resolvePackageFromHint('   '));

        Package::create(['name' => 'P100 off', 'code' => 'P100OFF', 'price' => 1, 'mikrotik_profile' => '100M', 'is_active' => false]);
        $active = Package::create(['name' => 'P100', 'code' => 'P100', 'price' => 1, 'mikrotik_profile' => '100M', 'is_active' => true]);
        Package::create(['name' => 'P1000', 'code' => 'P1000', 'price' => 1, 'mikrotik_profile' => '1000M', 'is_active' => true]);

        $this->assertSame($active->id, $r->resolvePackageFromHint('100M')?->id);
        $this->assertSame($active->id, $r->resolvePackageFromHint((string) $active->id)?->id, 'numeric hint = package id');
        $this->assertNull($r->resolvePackageFromHint('300M'));
    }

    public function test_acs_url_reads_setting_then_config(): void
    {
        $r = new PsbConfigResolver;

        config(['site.acs_url' => 'http://acs.example.test:7547']);
        $this->assertSame('http://acs.example.test:7547', $r->acsUrl());

        Setting::set('psb_acs_url', 'http://other.example.test', 'text', 'psb');
        $this->assertSame('http://other.example.test', $r->acsUrl());
    }
}
