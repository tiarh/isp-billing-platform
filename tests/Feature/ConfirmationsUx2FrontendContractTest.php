<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Http\Middleware\CheckOltProvisioningApproval;
use App\Models\Olt;
use App\Models\User;
use App\Services\Olt\OltProvisioningGuard;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PR-UX2 (Fase 2, konfirmasi): S3, T2, S5, R2, R3, S4.
 *
 * S4 is a server-side change: `live_confirm` must equal the phrase of the
 * action being executed (REBOOT ONU, DELETE ONU, RENAME ONU, RECONFIGURE OLT,
 * otherwise PROVISION OLT). The UI forwards exactly what the operator typed.
 */
#[Group('blocking-olt-mysql')]
class ConfirmationsUx2FrontendContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('cache.default', 'array');
        Config::set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        Config::set('olt-provisioning.dry_run', false);
        Config::set('olt-provisioning.kill_switch', false);
        Config::set('olt-provisioning.approval_window_seconds', 900);
        Config::set('olt-provisioning.approval_lock_seconds', 900);
        Cache::store('array')->flush();
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(base_path($path));
    }

    // ---- S4 server: per-action phrase ---------------------------------------

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routePhrases(): array
    {
        return [
            'reboot' => ['api.olts.onu.reboot', '/api/olts/{olt}/onu/reboot', 'REBOOT ONU'],
            'delete' => ['api.olts.onu.delete', '/api/olts/{olt}/onu', 'DELETE ONU'],
            'rename' => ['api.olts.onu.rename', '/api/olts/{olt}/onu/rename', 'RENAME ONU'],
            'reconfigure' => ['api.olt-c300.reconfigure', '/api/olt-c300/reconfigure', 'RECONFIGURE OLT'],
            'provision' => ['api.olt-c300.provision', '/api/olt-c300/provision', 'PROVISION OLT'],
            'fiberhome' => ['api.olt-c300.provision-fiberhome', '/api/olt-c300/provision-fiberhome', 'PROVISION OLT'],
        ];
    }

    #[DataProvider('routePhrases')]
    public function test_route_gate_accepts_only_the_phrase_of_that_action(string $routeName, string $uri, string $phrase): void
    {
        $this->actingAs(User::factory()->make(['role' => 'superadmin']));

        $passed = $this->runMiddleware($routeName, $uri, $phrase);
        $this->assertSame(200, $passed['status'], "{$routeName} must accept '{$phrase}'.");
        $this->assertTrue($passed['downstream']);

        foreach (['PROVISION OLT', 'REBOOT ONU', 'DELETE ONU', 'RENAME ONU', 'RECONFIGURE OLT', 'reboot onu', '', ' '.$phrase] as $other) {
            if ($other === $phrase) {
                continue;
            }

            $blocked = $this->runMiddleware($routeName, $uri, $other);
            $this->assertSame(423, $blocked['status'], "{$routeName} must reject '{$other}'.");
            $this->assertFalse($blocked['downstream'], "{$routeName} reached the device path with '{$other}'.");
        }
    }

    public function test_reboot_can_no_longer_be_authorised_with_the_provisioning_phrase(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'superadmin']));

        $result = $this->runMiddleware('api.olts.onu.reboot', '/api/olts/{olt}/onu/reboot', OltProvisioningGuard::LIVE_CONFIRMATION);

        $this->assertSame(423, $result['status']);
        $this->assertFalse($result['downstream']);
    }

    public function test_correct_phrase_without_global_admin_is_still_blocked(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'teknisi']));

        $result = $this->runMiddleware('api.olts.onu.reboot', '/api/olts/{olt}/onu/reboot', 'REBOOT ONU');

        $this->assertSame(423, $result['status']);
        $this->assertFalse($result['downstream']);
    }

    public function test_controller_fallback_gate_matches_phrase_per_operation(): void
    {
        $this->actingAs(User::factory()->make(['role' => 'superadmin']));
        $controller = app(OltC300Controller::class);
        $method = new ReflectionMethod(OltC300Controller::class, 'provisioningGate');
        $method->setAccessible(true);
        $olt = new Olt(['id' => 7, 'vendor' => 'zte-c300']);

        $blocked = $method->invoke($controller, $olt, $this->request(['live_confirm' => 'PROVISION OLT']), 'reconfigure');
        $this->assertNotNull($blocked);
        $this->assertSame(423, $blocked->getStatusCode());

        $allowed = $method->invoke($controller, $olt, $this->request(['live_confirm' => 'RECONFIGURE OLT']), 'reconfigure');
        $this->assertNull($allowed);

        $provision = $method->invoke($controller, $olt, $this->request(['live_confirm' => 'PROVISION OLT']), 'provision');
        $this->assertNull($provision);
    }

    public function test_phrase_map_falls_back_to_provisioning_phrase(): void
    {
        $this->assertSame('PROVISION OLT', OltProvisioningGuard::liveConfirmationFor('api.olts.onu.authorize'));
        $this->assertSame('PROVISION OLT', OltProvisioningGuard::liveConfirmationFor(null));
        $this->assertFalse(OltProvisioningGuard::matchesLiveConfirmation(['PROVISION OLT'], null));
        $this->assertFalse(OltProvisioningGuard::matchesLiveConfirmation(null, null));
    }

    // ---- S4 client: forward the typed value --------------------------------

    public function test_ui_forwards_the_typed_phrase_instead_of_a_hardcoded_constant(): void
    {
        $confirm = $this->source('resources/js/Components/ConfirmDestructive.tsx');
        $this->assertStringContainsString('onConfirm: (typedValue: string) => void;', $confirm);
        $this->assertStringContainsString('onClick={() => onConfirm(typedValue)}', $confirm);
        $this->assertStringContainsString('confirmDisabled', $confirm);

        $c300 = $this->source('resources/js/Pages/OltC300/Index.tsx');
        $show = $this->source('resources/js/Pages/Olts/Show.tsx');

        foreach ([$c300, $show] as $source) {
            $this->assertDoesNotMatchRegularExpression("/live_confirm:\\s*(dryRun \\? undefined : )?'/", $source);
        }

        $this->assertStringContainsString('void onuAction(\'reboot\', ref, typed);', $show);
        $this->assertStringContainsString('void handleReconfigure(typed);', $c300);
        $this->assertStringContainsString('void handleProvision(typed);', $c300);
        $this->assertStringContainsString('void submitRenameOnu(typed);', $c300);
        $this->assertStringContainsString('void handleHuaweiProvision(false, typed);', $c300);
    }

    // ---- S3, T2, S5, R2, R3 -------------------------------------------------

    public function test_migration_import_apply_and_correction_need_confirmation_and_reason(): void
    {
        $source = $this->source('resources/js/Pages/CustomerMigrations/Index.tsx');

        $this->assertStringContainsString('const IMPORT_REASON_MIN = 8;', $source);
        $this->assertStringContainsString("onClick={() => setPendingImportAction('apply')}", $source);
        $this->assertStringContainsString("onClick={() => setPendingImportAction('correct')}", $source);
        $this->assertStringNotContainsString('onClick={applySelected}', $source);
        $this->assertStringNotContainsString('onClick={bulkCorrectSelected}', $source);
        $this->assertStringContainsString('!applyReasonValid', $source);
        $this->assertStringContainsString('!bulkReasonValid', $source);

        $controller = $this->source('app/Http/Controllers/CustomerMigrationController.php');
        $this->assertGreaterThanOrEqual(2, substr_count($controller, "'reason' => ['required', 'string', 'min:8', 'max:500']"));
    }

    public function test_ticket_lifecycle_approve_shows_before_after_and_confirms(): void
    {
        $source = $this->source('resources/js/Pages/Tickets/Show.tsx');

        $this->assertStringContainsString('onClick={() => setPendingApproval(item)}', $source);
        $this->assertStringContainsString('{pendingApproval.before?.status || \'-\'} → {pendingApproval.after?.status || \'-\'}', $source);
        $this->assertStringNotContainsString("onClick={() => router.post(route('tickets.customer-lifecycle.approve'", $source);
    }

    public function test_vlan_mapping_delete_is_confirmed(): void
    {
        $source = $this->source('resources/js/Pages/OltC300/Index.tsx');

        $this->assertStringContainsString('onClick={() => setVlanDeleteTarget(mapping)}', $source);
        $this->assertStringNotContainsString('onClick={() => deleteVlanMapping(mapping.id)}', $source);
        $this->assertStringContainsString('title="Hapus VLAN mapping?"', $source);
    }

    public function test_router_sync_and_router_olt_target_deletes_are_confirmed(): void
    {
        $show = $this->source('resources/js/Pages/Routers/Show.tsx');
        $this->assertStringContainsString("onClick={() => setPendingSync('radius')}", $show);
        $this->assertStringContainsString("onClick={() => setPendingSync('profiles')}", $show);
        $this->assertStringNotContainsString('onClick={handleRadiusSync}', $show);
        $this->assertStringNotContainsString('onClick={handleSyncProfiles}', $show);
        $this->assertStringContainsString('typedConfirmation={routerData.name}', $show);

        $this->assertStringContainsString('typedConfirmation={routerToDelete?.name}', $this->source('resources/js/Pages/Routers/Index.tsx'));
        $this->assertStringContainsString('typedConfirmation={olt.name}', $this->source('resources/js/Pages/Olts/Show.tsx'));
    }

    public function test_internal_wa_flows_do_not_use_http_live_confirm(): void
    {
        // PSB WA activation and migration WA issue server-side approvals and never
        // send live_confirm, so the per-action phrases cannot affect them.
        foreach (['app/Services/Psb/PsbOltProvisioner.php', 'app/Services/CustomerMigrationService.php', 'app/Services/HuaweiPsbProvisioningService.php'] as $path) {
            $this->assertStringNotContainsString('live_confirm', $this->source($path), $path);
            $this->assertStringContainsString('issueInternalApproval', $this->source($path), $path);
        }
    }

    /**
     * @return array{status: int, downstream: bool}
     */
    private function runMiddleware(string $routeName, string $uri, string $liveConfirm): array
    {
        $request = $this->request(['live_confirm' => $liveConfirm], str_replace('{olt}', '7', $uri));
        $route = new Route('POST', $uri, []);
        $route->name($routeName);
        $route->bind($request);
        // Bound model for every route so the gate never needs a DB lookup.
        $route->setParameter('olt', new Olt(['id' => 7, 'vendor' => 'zte-c300']));
        $request->setRouteResolver(static fn (): Route => $route);

        $downstream = false;
        $response = app(CheckOltProvisioningApproval::class)->handle($request, function () use (&$downstream) {
            $downstream = true;

            return response()->json(['ok' => true]);
        });

        return ['status' => $response->getStatusCode(), 'downstream' => $downstream];
    }

    private function request(array $input, string $path = '/internal-olt-call'): Request
    {
        $request = Request::create($path, 'POST', $input);
        $request->setUserResolver(fn () => auth()->user());

        return $request;
    }
}
