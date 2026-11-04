<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltSuperadminWriteFlowFrontendContractTest extends TestCase
{
    public function test_all_olt_write_ui_paths_require_confirmation_and_live_confirm(): void
    {
        $c300 = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));
        $show = (string) file_get_contents(base_path('resources/js/Pages/Olts/Show.tsx'));

        foreach (['PROVISION OLT', 'RECONFIGURE OLT', 'RENAME ONU'] as $phrase) {
            $this->assertStringContainsString($phrase, $c300);
        }
        foreach (['DELETE ONU', 'RENAME ONU', 'REBOOT ONU', 'live_confirm: deleteConfirmation', 'live_confirm: renameConfirmation', 'live_confirm: liveConfirm'] as $phrase) {
            $this->assertStringContainsString($phrase, $show);
        }
        // S4: no client may hardcode the provisioning phrase as live_confirm.
        $this->assertStringNotContainsString("live_confirm: 'PROVISION OLT'", $show);
        $this->assertStringNotContainsString("live_confirm: 'PROVISION OLT'", $c300);

        $this->assertStringContainsString('setZteProvisionConfirmOpen(true)', $c300);
        $this->assertStringContainsString('setReconfigConfirmOpen(true)', $c300);
        $this->assertStringContainsString('setRenameConfirmOpen(true)', $c300);
        $this->assertStringContainsString('setRebootDialogOpen(true)', $show);
        $this->assertStringContainsString('setDeleteDialogOpen(true)', $show);
    }

    public function test_olt_write_routes_keep_server_side_provisioning_gate(): void
    {
        $routes = (string) file_get_contents(base_path('routes/web.php'));
        $user = (string) file_get_contents(base_path('app/Models/User.php'));

        $this->assertStringContainsString('return $this->role === \'superadmin\';', $user);
        $this->assertStringContainsString('return $this->isSuperAdmin()', $user);

        foreach ([
            "Route::post('/provision'",
            "Route::post('/activate'",
            "Route::post('/reconfigure'",
            "Route::post('/provision-fiberhome'",
            '/api/olts/{olt}/onu/rename',
            '/api/olts/{olt}/onu/reboot',
            '/api/olts/{olt}/onu/admin-state',
            '/api/olts/{olt}/onu',
            '/api/olts/{olt}/onu/authorize',
        ] as $path) {
            $position = strpos($routes, $path);
            $this->assertNotFalse($position, "Route {$path} must remain present.");
            $segment = substr($routes, $position, 320);
            $this->assertStringContainsString("middleware('olt-provisioning')", $segment, "Route {$path} must remain gated.");
        }
    }

    public function test_vlan_area_context_uses_existing_olt_area_relation(): void
    {
        $controller = (string) file_get_contents(base_path('app/Http/Controllers/OltC300Controller.php'));
        $frontend = (string) file_get_contents(base_path('resources/js/Pages/OltC300/Index.tsx'));

        $this->assertStringContainsString("with('area:id,name')", $controller);
        $this->assertStringContainsString("'area_id' => \$o->area_id", $controller);
        $this->assertStringContainsString("'area_name' => \$o->area?->name", $controller);
        $this->assertStringContainsString('<TabsTrigger value="vlan-olt"', $frontend);
        $this->assertStringContainsString("Area: \${activeVlanOlt?.area_name || 'belum diatur'}", $frontend);
        $this->assertStringContainsString('vlanMappings', $frontend);
    }
}
