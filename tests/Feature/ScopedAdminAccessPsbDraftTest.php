<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\User;
use App\Services\PsbOrchestratorService;
use App\Support\AreaScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Salvaged from closed PR #150 (177410f2 "scope PSB drafts by area",
 * 53244276 "lock PSB staging to global admins"): a scoped (per-area) admin
 * must not list or act on PSB drafts (KTP, phone, coordinates) of other
 * areas, and fleet-wide PSB export/settings stay global-admin only.
 */
#[Group('blocking-psb')]
class ScopedAdminAccessPsbDraftTest extends TestCase
{
    use RefreshDatabase;

    private Area $own;

    private Area $foreign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->own = Area::create(['code' => 'OWN', 'name' => 'Own Area']);
        $this->foreign = Area::create(['code' => 'FOR', 'name' => 'Foreign Area']);
    }

    public function test_scoped_admin_panel_only_lists_own_area_drafts(): void
    {
        $ownDraft = $this->draft('OWN', $this->own->id);
        $foreignDraft = $this->draft('FOREIGN', $this->foreign->id);
        $orphanDraft = $this->draft('ORPHAN', null);
        $viaCustomer = $this->draft('VIA-CUSTOMER', null, $this->customer('C-OWN', $this->own)->id);
        $viaForeignCustomer = $this->draft('VIA-FOREIGN', null, $this->customer('C-FOR', $this->foreign)->id);

        $ids = collect($this->actingAs($this->scopedAdmin())
            ->getJson(route('psb-drafts.panel', ['site' => '__all__']))
            ->assertOk()
            ->json('drafts'))->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(collect([$ownDraft->id, $viaCustomer->id])->sort()->values()->all(), $ids);
        $this->assertNotContains($foreignDraft->id, $ids);
        $this->assertNotContains($orphanDraft->id, $ids);
        $this->assertNotContains($viaForeignCustomer->id, $ids);

        $globalIds = collect($this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson(route('psb-drafts.panel', ['site' => '__all__']))
            ->assertOk()
            ->json('drafts'))->pluck('id')->all();
        $this->assertCount(5, $globalIds);
    }

    public function test_scoped_admin_cannot_act_on_foreign_or_unassigned_drafts(): void
    {
        $scoped = $this->scopedAdmin();

        foreach ([$this->draft('FOREIGN', $this->foreign->id), $this->draft('ORPHAN', null)] as $draft) {
            foreach (['psb-drafts.apply', 'psb-drafts.retry', 'psb-drafts.apply-correction', 'psb-drafts.cancel-correction', 'psb-drafts.sync-customer-media'] as $name) {
                $this->actingAs($scoped)
                    ->postJson(route($name, ['id' => $draft->id]))
                    ->assertForbidden();
            }

            $fresh = $draft->fresh();
            $this->assertSame(PsbDraft::STATUS_OLT_OK, $fresh->status);
        }
    }

    public function test_fleet_wide_psb_export_and_settings_are_global_admin_only(): void
    {
        $scoped = $this->scopedAdmin();

        foreach (['psb-drafts.export', 'psb-drafts.import-starlite', 'psb-drafts.settings', 'psb-drafts.targets'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('global-admin', $route->gatherMiddleware(), $name);
        }

        $this->actingAs($scoped)->get(route('psb-drafts.export'))->assertForbidden();
        $this->actingAs($scoped)->postJson(route('psb-drafts.settings'), [])->assertForbidden();
        $this->actingAs($scoped)->postJson(route('psb-drafts.targets'), [])->assertForbidden();
    }

    public function test_scoped_admin_cannot_assign_area_to_unassigned_draft_but_global_admin_can(): void
    {
        $blocked = $this->draft('BLOCKED', null);
        $blocked->forceFill(['status' => PsbDraft::STATUS_FAILED, 'last_error' => 'Perlu area: x'])->save();

        $this->actingAs($this->scopedAdmin())
            ->postJson(route('psb-drafts.set-area', ['id' => $blocked->id]), ['area_id' => $this->own->id])
            ->assertForbidden();
        $this->assertNull($blocked->fresh()->area_id);
        $this->assertArrayNotHasKey('area_manual', (array) $blocked->fresh()->meta);

        $this->mock(PsbOrchestratorService::class, function ($mock) {
            $mock->shouldReceive('processDraft')->once();
        });
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('psb-drafts.set-area', ['id' => $blocked->id]), ['area_id' => $this->own->id])
            ->assertOk();
        $this->assertSame($this->own->id, (int) $blocked->fresh()->area_id);
    }

    public function test_sensitive_psb_media_is_area_checked_for_scoped_admin(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('psb/ktp.jpg', 'x');

        $own = $this->draft('OWN', $this->own->id);
        $foreign = $this->draft('FOREIGN', $this->foreign->id);
        $orphan = $this->draft('ORPHAN', null);
        foreach ([$own, $foreign, $orphan] as $d) {
            $d->forceFill(['ktp_photo_path' => 'psb/ktp.jpg'])->save();
        }
        $scoped = $this->scopedAdmin();
        $url = fn (PsbDraft $d) => URL::temporarySignedRoute('psb-drafts.media', now()->addMinutes(5), ['draft' => $d->id, 'kind' => 'ktp']);

        $this->actingAs($scoped)->get($url($own))->assertOk();
        $this->actingAs($scoped)->get($url($foreign))->assertForbidden();
        $this->actingAs($scoped)->get($url($orphan))->assertForbidden();

        $global = User::factory()->create(['role' => 'admin']);
        $this->actingAs($global)->get($url($foreign))->assertOk();
        $this->actingAs($global)->get($url($orphan))->assertOk();
    }

    public function test_kpi_dashboard_health_and_panel_settings_are_hidden_from_scoped_admin(): void
    {
        $scoped = $this->scopedAdmin();

        $this->actingAs($scoped)->get(route('psb-drafts.dashboard'))->assertForbidden();
        $this->actingAs($scoped)->get(route('psb-drafts.health'))->assertForbidden();
        $this->actingAs($scoped)->getJson(route('psb-drafts.health-json'))->assertForbidden();

        $this->actingAs($scoped)->getJson(route('psb-drafts.panel', ['site' => '__all__']))
            ->assertOk()
            ->assertJsonPath('can_manage', false)
            ->assertJsonPath('settings.psb_wa_group_ids', [])
            ->assertJsonPath('routers', [])
            ->assertJsonPath('olts', []);

        $global = User::factory()->create(['role' => 'admin']);
        $this->actingAs($global)->getJson(route('psb-drafts.health-json'))->assertOk();
        $this->actingAs($global)->getJson(route('psb-drafts.panel'))->assertOk()->assertJsonPath('can_manage', true);
    }

    public function test_noc_superadmin_and_unscoped_admin_keep_kpi_dashboard_and_health_access(): void
    {
        $users = [
            'noc' => User::factory()->create(['role' => 'noc']),
            'superadmin' => User::factory()->create(['role' => 'superadmin']),
            'unscoped admin' => User::factory()->create(['role' => 'admin']),
        ];

        foreach ($users as $label => $user) {
            $this->actingAs($user)->get(route('psb-drafts.dashboard'))->assertOk();
            $this->actingAs($user)->get(route('psb-drafts.health'))->assertOk();
            $this->actingAs($user)->getJson(route('psb-drafts.health-json'))->assertOk();
            $this->actingAs($user)->getJson(route('psb-drafts.panel'))
                ->assertOk()
                ->assertJsonPath('can_manage', true);
        }

        $scoped = $this->scopedAdmin();
        $this->actingAs($scoped)->get(route('psb-drafts.dashboard'))->assertForbidden();
        $this->actingAs($scoped)->get(route('psb-drafts.health'))->assertForbidden();
    }

    /**
     * Scoping is HTTP-user only: WA jobs, console commands and the orchestrator
     * call these helpers (or none) without a web user and must not be narrowed.
     */
    public function test_area_scope_psb_helpers_are_noops_without_a_web_user(): void
    {
        $orphan = $this->draft('ORPHAN', null);
        $foreign = $this->draft('FOREIGN', $this->foreign->id);

        AreaScope::authorizePsbDraft($orphan, null);
        AreaScope::authorizePsbDraft($foreign, null);
        $this->assertSame(2, AreaScope::applyToPsbDrafts(PsbDraft::query(), null)->count());

        $global = User::factory()->create(['role' => 'admin']);
        AreaScope::authorizePsbDraft($orphan, $global);
        $this->assertSame(2, AreaScope::applyToPsbDrafts(PsbDraft::query(), $global)->count());
    }

    private function scopedAdmin(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->areas()->attach($this->own);

        return $user;
    }

    private function customer(string $code, Area $area): Customer
    {
        $package = Package::query()->firstOrCreate(['code' => 'BASIC'], ['name' => 'Basic', 'price' => 100000]);

        return Customer::create([
            'code' => $code,
            'name' => 'Customer '.$code,
            'phone' => '0811111111',
            'address' => 'Address '.$code,
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => strtolower($code),
            'status' => 'active',
        ]);
    }

    private function draft(string $messageId, ?int $areaId, ?int $customerId = null): PsbDraft
    {
        return PsbDraft::query()->create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => '120363-activation@g.us',
            'message_id' => 'MSG-'.$messageId,
            'full_name' => 'PELANGGAN '.$messageId,
            'area_id' => $areaId,
            'customer_id' => $customerId,
        ]);
    }
}
