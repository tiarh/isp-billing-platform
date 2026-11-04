<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The FiberHome C300 activation flow uses `ip-host 1 dhcp enable ...`.
 *
 * Verified against the live OLT: `ip-host 1 dhcp ?` and `ip-host 1 dhcp-enable ?`
 * both expose `disable`/`enable`, so `dhcp` is the OLT-accepted short form of
 * `dhcp-enable` (running-config normalises to `dhcp-enable`). Keep the operator's
 * working form. Step 3 must still be verified (not fire-and-forget) so a real
 * reject cannot report a false success.
 */
class OltC300FiberhomeIphostCommandTest extends TestCase
{
    private function source(): string
    {
        return file_get_contents(base_path('app/Http/Controllers/OltC300Controller.php'));
    }

    public function test_fiberhome_uses_the_operator_verified_dhcp_syntax(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'ip-host 1 dhcp enable ping-response enable traceroute-response enable',
            $source,
        );
    }

    public function test_pon_onu_mng_step_is_verified_not_fire_and_forget(): void
    {
        $source = $this->source();

        // Step 3 collects each command result and fails on a rejected service map.
        $this->assertStringContainsString('pon_onu_mng_service_reject', $source);
        $this->assertStringContainsString("str_starts_with(\$row['cmd'], 'service ')", $source);
        $this->assertStringContainsString("'step3_warnings' =>", $source);
    }
}
