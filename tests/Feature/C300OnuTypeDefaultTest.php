<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Owner-verified ZTE C300 behaviour (RA + Krian): FiberHome ONTs must be
 * registered with `onu <id> type ALL sn FHTT...`. The old ZTE-F660 /
 * ZTEG-F660 defaults registered a wrong type -> ONU never came online ->
 * PPPoE never connected while the UI still reported success.
 */
class C300OnuTypeDefaultTest extends TestCase
{
    private const FORBIDDEN_DEFAULTS = ['ZTE-F660', 'ZTEG-F660'];

    public function test_controller_provision_paths_default_to_all(): void
    {
        $source = (string) file_get_contents(base_path('app/Http/Controllers/OltC300Controller.php'));

        // Every provisioning entry point must default to ALL...
        $this->assertSame(
            0,
            substr_count($source, "\$request->onu_type ?? 'ZTE-F660'"),
            'OltC300Controller masih memakai default ZTE-F660',
        );
        $this->assertSame(
            0,
            substr_count($source, "\$request->onu_type ?? 'ZTEG-F660'"),
            'OltC300Controller masih memakai default ZTEG-F660',
        );
        $this->assertStringContainsString("\$request->onu_type ?? 'ALL'", $source);
        // ...and never in the vendor-detection list, which is not a default.
        $this->assertStringContainsString("'ZTE-F660'", $source, 'Daftar deteksi vendor tidak boleh dihapus');
    }

    public function test_zte_service_defaults_to_all(): void
    {
        $source = (string) file_get_contents(base_path('app/Services/OltZteC300Service.php'));

        foreach (self::FORBIDDEN_DEFAULTS as $forbidden) {
            $this->assertStringNotContainsString(
                "\$payload['onu_type'] ?? '".$forbidden."'",
                $source,
            );
        }
        $this->assertStringContainsString("\$payload['onu_type'] ?? 'ALL'", $source);
    }

    public function test_psb_wa_and_migration_send_all(): void
    {
        $psb = (string) file_get_contents(base_path('app/Services/Psb/PsbOltProvisioner.php'));
        $mig = (string) file_get_contents(base_path('app/Services/CustomerMigrationService.php'));

        $this->assertStringContainsString("'onu_type' => 'ALL'", $psb, 'PSB WA harus mengirim ALL');
        $this->assertStringContainsString("'onu_type' => 'ALL'", $mig, 'Migration harus mengirim ALL');
    }
}
