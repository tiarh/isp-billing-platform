<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltFrontendContractTest extends TestCase
{
    public function test_olt_list_uses_solid_headers_accessible_actions_and_icon_components(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Olts/Index.tsx'));

        $this->assertStringContainsString('LockKeyhole', $source);
        $this->assertStringContainsString('CircleHelp', $source);
        $this->assertStringContainsString('aria-label={`Buka detail ${olt.name}`}', $source);
        $this->assertStringContainsString('aria-label={`Edit ${olt.name}`}', $source);
        $this->assertStringContainsString('aria-label={`Hapus ${olt.name}`}', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString("icon: '", $source);
    }

    public function test_olt_provisioning_requires_explicit_password_and_redacts_command_logs(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OltC300Controller.php'));
        $source = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $this->assertSame(4, substr_count($controller, "'password' => 'required|string|max:255'"));
        $this->assertStringNotContainsString('$password = $request->password ?: $name;', $controller);
        $this->assertStringNotContainsString('setPassword(sn)', $source);
        $this->assertStringNotContainsString('password || name', $source);
        $this->assertStringNotContainsString('reconfigPassword || reconfigName', $source);
        $this->assertStringContainsString('function redactPppoeText', $source);
        $this->assertStringContainsString("getProvisionCommands(provPort, result.onu_id, name, provVlan, sn, '[REDACTED]', tr069Vlan)", $source);
        $this->assertStringContainsString("getReconfigCommands(reconfigPort, parseInt(reconfigOnuId), reconfigName, vlan, reconfigSn, '[REDACTED]', tr069Vlan)", $source);
    }
}
