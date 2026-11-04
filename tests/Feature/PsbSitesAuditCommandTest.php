<?php

namespace Tests\Feature;

use App\Services\PsbSiteRegistry;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbSitesAuditCommandTest extends TestCase
{
    public function test_site_audit_is_registered_and_read_only_by_contract(): void
    {
        $this->artisan('psb:sites-audit', ['--site' => 'ra'])
            ->assertSuccessful()
            ->expectsOutputToContain('Read-only audit:');
    }

    public function test_unknown_site_is_rejected_without_write_path(): void
    {
        $this->artisan('psb:sites-audit', ['--site' => 'does-not-exist'])
            ->assertFailed()
            ->expectsOutputToContain('PSB site tidak ditemukan');

        $this->assertTrue(app(PsbSiteRegistry::class)->byKey('ra') !== null);
    }

    public function test_full_olt_router_names_resolve_to_canonical_sites(): void
    {
        $registry = app(PsbSiteRegistry::class);

        $this->assertSame(513, $registry->byKey('Skynet-RA-OLT')['router_id']);
        $this->assertSame(514, $registry->byKey('Skynet-Krian-OLT')['router_id']);
        $this->assertSame(511, $registry->byKey('gadang')['router_id']);
    }
}
