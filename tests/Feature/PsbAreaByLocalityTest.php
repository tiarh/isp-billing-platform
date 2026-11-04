<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Area;
use App\Models\AreaAlias;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\HuaweiAwarePsbOrchestratorService;
use App\Services\KtpOcrService;
use App\Services\MikrotikService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\Support\PsbEdges\AllowAllDocumentGate;
use Tests\TestCase;

/**
 * PSB B2: area comes from locality (manual > alias/name/code > OLT area only when
 * the policy allows > null). No area -> FAILED "Perlu area" before any device write.
 */
#[Group('blocking-psb')]
class PsbAreaByLocalityTest extends TestCase
{
    use RefreshDatabase;

    private AreaGuardOltController $oltEdge;

    public function test_kelampok_resolves_to_klampok_area_via_alias_and_never_creates_starlite(): void
    {
        $klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        AreaAlias::create(['area_id' => $klampok->id, 'alias' => 'Kelampok', 'alias_key' => 'kelampok']);
        $this->raRouterAndOlt();

        $draft = $this->draft('Kelampok');
        $this->service()->applySite($draft);

        $draft->refresh();
        $this->assertSame($klampok->id, (int) $draft->area_id);
        $this->assertSame('resolved', $draft->meta['area_resolution']['status']);
        $this->assertSame('alias', $draft->meta['area_resolution']['source']);
        $this->assertSame('kelampok', $draft->meta['area_resolution']['matched_key']);
        $this->assertSame('ra', (string) $draft->site_profile_key);
        $this->assertNull(Area::where('code', 'randuagung-starlite')->first());
    }

    public function test_ra_site_with_existing_starlite_area_is_not_auto_assigned(): void
    {
        $starlite = Area::create(['name' => 'Randuagung Starlite', 'code' => 'randuagung-starlite']);
        $rdg2 = Area::create(['name' => 'SKYNET-RANDUAGUNG2', 'code' => 'RDGNEW']);
        AreaAlias::create(['area_id' => $rdg2->id, 'alias' => 'gondang', 'alias_key' => 'gondang']);
        $this->raRouterAndOlt();

        $known = $this->draft('Gondang');
        $this->service()->applySite($known);
        $this->assertSame($rdg2->id, (int) $known->fresh()->area_id);

        $unknown = $this->draft('Desa Tak Dikenal');
        $this->service()->applySite($unknown);
        $this->assertNull($unknown->fresh()->area_id);
        $this->assertNotSame($starlite->id, (int) $unknown->fresh()->area_id);
    }

    public function test_unknown_daerah_is_failed_perlu_area_with_no_device_write(): void
    {
        Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $this->raRouterAndOlt();
        $mikrotik = new AreaGuardMikrotikFake;
        $service = $this->service($mikrotik);

        $draft = $this->draft('Barengkrajan');
        $result = $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertStringStartsWith('Perlu area', (string) $draft->last_error);
        $this->assertNull($draft->area_id);
        $this->assertSame('unmatched', $draft->meta['area_resolution']['status']);
        $this->assertSame('none', $draft->meta['area_resolution']['source']);
        $this->assertSame($draft->id, $result->id);
        $this->assertSame(0, $mikrotik->calls, 'router must not be touched');
        $this->assertSame(0, $this->deviceCalls($mikrotik), 'no OLT lookup/provision/secret');
        $this->assertNull($draft->secret_at);
        $this->assertNull($draft->olt_at);
        $this->assertStringContainsString('Perlu area', $service->lastReply);
        $this->assertStringContainsString('Barengkrajan', $service->lastReply);
    }

    public function test_manual_area_wins_over_locality_match(): void
    {
        $klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $other = Area::create(['name' => 'SKYNET-LAINNYA', 'code' => 'lainnya']);
        $this->raRouterAndOlt();

        $draft = $this->draft('Klampok', ['area_manual' => $other->id]);
        $this->service()->applySite($draft);

        $draft->refresh();
        $this->assertNotSame($klampok->id, (int) $draft->area_id);
        $this->assertSame($other->id, (int) $draft->area_id);
        $this->assertSame('manual', $draft->meta['area_resolution']['source']);
    }

    public function test_manual_area_unblocks_an_unknown_daerah(): void
    {
        $other = Area::create(['name' => 'SKYNET-LAINNYA', 'code' => 'lainnya']);
        $this->raRouterAndOlt();
        $service = $this->service($mikrotik = new AreaGuardMikrotikFake);

        $draft = $this->draft('Barengkrajan', ['area_manual' => $other->id]);
        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame($other->id, (int) $draft->area_id);
        $this->assertStringStartsNotWith('Perlu area', (string) $draft->last_error);
    }

    public function test_ambiguous_locality_blocks_and_lists_candidates(): void
    {
        $a = Area::create(['name' => 'LAWANG', 'code' => 'lwg-a']);
        $b = Area::create(['name' => 'SKYNET-LAWANG', 'code' => 'lwg-b']);
        $this->raRouterAndOlt();
        $mikrotik = new AreaGuardMikrotikFake;
        $service = $this->service($mikrotik);

        $draft = $this->draft('Lawang');
        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertStringStartsWith('Perlu area', (string) $draft->last_error);
        $this->assertNull($draft->area_id);
        $this->assertSame('ambiguous', $draft->meta['area_resolution']['status']);
        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            array_column($draft->meta['area_resolution']['candidates'], 'area_id')
        );
        $this->assertSame(0, $mikrotik->calls);
        $this->assertStringContainsString('lebih dari satu area', $service->lastReply);
    }

    public function test_olt_area_fallback_only_when_policy_allows(): void
    {
        $oltArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $this->raRouterAndOlt($oltArea->id);

        // Default policy = block: no silent OLT fallback.
        $blocked = $this->draft('Barengkrajan');
        $this->service()->applySite($blocked);
        $this->assertNull($blocked->fresh()->area_id);

        Setting::set('psb_area_unmatched_policy', 'olt');
        $allowed = $this->draft('Barengkrajan');
        $this->service()->applySite($allowed);
        $allowed->refresh();
        $this->assertSame($oltArea->id, (int) $allowed->area_id);
        $this->assertSame('olt_fallback', $allowed->meta['area_resolution']['source']);

        // Explicit "block" again, and an OLT without area never yields one.
        Setting::set('psb_area_unmatched_policy', 'block');
        $again = $this->draft('Barengkrajan');
        $this->service()->applySite($again);
        $this->assertNull($again->fresh()->area_id);

        Olt::query()->update(['area_id' => null]);
        Setting::set('psb_area_unmatched_policy', 'olt');
        $noOltArea = $this->draft('Barengkrajan');
        $this->service()->applySite($noOltArea);
        $this->assertNull($noOltArea->fresh()->area_id);
    }

    public function test_ra_unknown_is_blocked_even_when_olt_has_area_by_default(): void
    {
        $oltArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $this->raRouterAndOlt($oltArea->id);
        $mikrotik = new AreaGuardMikrotikFake;
        $service = $this->service($mikrotik);

        $draft = $this->draft('Barengkrajan');
        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame('ra', (string) $draft->site_profile_key);
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertStringStartsWith('Perlu area', (string) $draft->last_error);
        $this->assertNull($draft->area_id);
        $this->assertSame(0, $mikrotik->calls);
        $this->assertSame(0, $this->deviceCalls($mikrotik));
    }

    public function test_existing_area_is_kept_once_a_secret_was_written(): void
    {
        $klampok = Area::create(['name' => 'SKYNET-KLAMPOK', 'code' => 'klampok']);
        $old = Area::create(['name' => 'SKYNET-LAMA', 'code' => 'lama']);
        $this->raRouterAndOlt();

        $draft = $this->draft('Klampok');
        $draft->forceFill(['secret_at' => now(), 'area_id' => $old->id])->save();
        $this->service()->applySite($draft);

        $draft->refresh();
        $this->assertSame($old->id, (int) $draft->area_id);
        $this->assertNotSame($klampok->id, (int) $draft->area_id);
        $this->assertSame('existing', $draft->meta['area_resolution']['source']);
    }

    public function test_legacy_secret_draft_without_area_uses_olt_area_else_blocks_with_neutral_text(): void
    {
        $oltArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $this->raRouterAndOlt($oltArea->id);

        $draft = $this->draft('Barengkrajan');
        $draft->forceFill(['secret_at' => now()])->save();
        $this->service()->applySite($draft);
        $this->assertSame($oltArea->id, (int) $draft->fresh()->area_id);
        $this->assertSame('olt_fallback', $draft->fresh()->meta['area_resolution']['source']);

        Olt::query()->update(['area_id' => null]);
        $service = $this->service();
        $blocked = $this->draft('Barengkrajan');
        $blocked->forceFill(['secret_at' => now()])->save();
        $service->processDraft($blocked);

        $this->assertStringStartsWith('Perlu area', (string) $blocked->fresh()->last_error);
        $this->assertStringNotContainsString('Tidak ada perubahan', $service->lastReply);
    }

    public function test_huawei_gadang_unknown_village_blocks_only_when_olt_has_no_area(): void
    {
        $router = new Router(['name' => 'SKYNET-GADANG', 'ip_address' => '198.18.2.16', 'username' => 't', 'password' => Canary::of('t'), 'is_active' => true]);
        $router->id = 511;
        $router->save();
        $olt = new Olt([
            'name' => 'OLT GADANG 3', 'code' => 'OLT-GADANG-3', 'vendor' => 'huawei_ma5608t',
            'management_ip' => '198.18.4.11', 'router_id' => 511, 'area_id' => null,
        ]);
        $olt->id = 11;
        $olt->save();
        $package = Package::create([
            'router_id' => 511, 'code' => 'GADANG-200M', 'name' => 'Gadang 200M', 'mikrotik_profile' => 'GADANG-200M',
            'rate_limit' => '200M/200M', 'price' => 200000, 'is_active' => true,
        ]);

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'AREA FIXTURE',
            'daerah' => 'Gadang Utara',
            'olt_id' => 11,
            'package_id' => $package->id,
            'sn' => 'FHTTAREA7777',
            'ktp_photo_path' => 'psb/ktp_fixture.jpg',
            'router_photo_path' => 'psb/sn_fixture.jpg',
            'meta' => ['note' => 'huawei area guard fixture'],
        ]);
        $huawei = app(HuaweiAwarePsbOrchestratorService::class);
        $huawei->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertStringStartsWith('Perlu area', (string) $draft->last_error);
        $this->assertNull($draft->area_id);
        $this->assertNull($draft->secret_at);
        $this->assertNull($draft->pon_port);
    }

    public function test_non_ra_unknown_locality_falls_back_to_olt_area_by_default(): void
    {
        $krianArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $this->krianRouterAndOlt($krianArea->id);

        $draft = $this->krianDraft('Barengkrajan');
        $this->service()->applySite($draft);

        $draft->refresh();
        $this->assertSame('krian', (string) $draft->site_profile_key);
        $this->assertSame($krianArea->id, (int) $draft->area_id);
        $this->assertSame('olt_fallback', $draft->meta['area_resolution']['source']);
    }

    public function test_non_ra_unknown_locality_is_blocked_when_olt_has_no_area(): void
    {
        $this->krianRouterAndOlt(null);
        $mikrotik = new AreaGuardMikrotikFake;
        $service = $this->service($mikrotik);

        $draft = $this->krianDraft('Barengkrajan');
        $service->processDraft($draft);

        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->status);
        $this->assertStringStartsWith('Perlu area', (string) $draft->last_error);
        $this->assertNull($draft->area_id);
        $this->assertSame(0, $mikrotik->calls);
    }

    public function test_setting_block_overrides_non_ra_default(): void
    {
        $krianArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $this->krianRouterAndOlt($krianArea->id);
        Setting::set('psb_area_unmatched_policy', 'block');

        $draft = $this->krianDraft('Barengkrajan');
        $this->service()->applySite($draft);

        $this->assertNull($draft->fresh()->area_id);
    }

    public function test_locality_match_beats_olt_fallback_even_when_policy_allows(): void
    {
        Setting::set('psb_area_unmatched_policy', 'olt');
        $oltArea = Area::create(['name' => 'SKYNET-KRIAN', 'code' => 'skynet-krian']);
        $kalirejo = Area::create(['name' => 'KALIREJO', 'code' => 'skynet-kalirejo']);
        $this->raRouterAndOlt($oltArea->id);

        $draft = $this->draft('Kalirejo');
        $this->service()->applySite($draft);

        $this->assertSame($kalirejo->id, (int) $draft->fresh()->area_id);
        $this->assertSame('name', $draft->fresh()->meta['area_resolution']['source']);
    }

    public function test_set_area_endpoint_stores_manual_area_and_retries_past_the_guard(): void
    {
        $other = Area::create(['name' => 'SKYNET-LAINNYA', 'code' => 'lainnya']);
        $this->raRouterAndOlt();
        $service = $this->service(new AreaGuardMikrotikFake);
        $this->app->instance(PsbOrchestratorService::class, $service);
        $admin = User::factory()->create(['role' => 'admin']);

        $draft = $this->draft('Barengkrajan');
        $service->processDraft($draft);
        $this->assertStringStartsWith('Perlu area', (string) $draft->fresh()->last_error);

        $this->actingAs($admin)->getJson('/api/psb-wa/panel?site=ra')
            ->assertOk()
            ->assertJsonPath('drafts.0.needs_area', true)
            ->assertJsonPath('areas.0.id', $other->id);

        $this->actingAs($admin)->postJson("/psb-wa/{$draft->id}/set-area", ['area_id' => 999999])
            ->assertStatus(422);

        $this->actingAs($admin)->postJson("/psb-wa/{$draft->id}/set-area", ['area_id' => $other->id])
            ->assertOk();

        $draft->refresh();
        $this->assertSame($other->id, (int) $draft->meta['area_manual']);
        $this->assertSame($other->id, (int) $draft->area_id);
        $this->assertSame('manual', $draft->meta['area_resolution']['source']);
        $this->assertStringStartsNotWith('Perlu area', (string) $draft->last_error);
    }

    public function test_set_area_rejects_non_blocked_drafts_and_enforces_area_scope(): void
    {
        $mine = Area::create(['name' => 'SKYNET-MINE', 'code' => 'mine']);
        $other = Area::create(['name' => 'SKYNET-OTHER', 'code' => 'other']);
        $admin = User::factory()->create(['role' => 'admin']);

        $waiting = $this->draft('Barengkrajan');
        $waiting->forceFill(['status' => PsbDraft::STATUS_WAITING_SN, 'area_id' => $other->id])->save();
        $this->actingAs($admin)->postJson("/psb-wa/{$waiting->id}/set-area", ['area_id' => $mine->id])
            ->assertStatus(422);
        $this->assertArrayNotHasKey('area_manual', (array) $waiting->fresh()->meta);

        // A scoped admin cannot relabel a blocked draft that sits in another area,
        // nor assign an area outside their scope.
        $scoped = User::factory()->create(['role' => 'admin']);
        $scoped->areas()->attach($mine);
        $blocked = $this->draft('Barengkrajan');
        $blocked->forceFill([
            'status' => PsbDraft::STATUS_FAILED,
            'last_error' => 'Perlu area: x',
            'area_id' => $other->id,
        ])->save();

        $this->actingAs($scoped)->postJson("/psb-wa/{$blocked->id}/set-area", ['area_id' => $mine->id])
            ->assertForbidden();
        $this->actingAs($scoped)->postJson("/psb-wa/{$blocked->id}/set-area", ['area_id' => $other->id])
            ->assertForbidden();
        $this->assertArrayNotHasKey('area_manual', (array) $blocked->fresh()->meta);
    }

    public function test_set_area_writes_an_activity_log(): void
    {
        $other = Area::create(['name' => 'SKYNET-LAINNYA', 'code' => 'lainnya']);
        $this->raRouterAndOlt();
        $this->app->instance(PsbOrchestratorService::class, $this->service());
        $admin = User::factory()->create(['role' => 'admin']);
        $draft = $this->draft('Barengkrajan');
        $draft->forceFill(['status' => PsbDraft::STATUS_FAILED, 'last_error' => 'Perlu area: x'])->save();

        $this->actingAs($admin)->postJson("/psb-wa/{$draft->id}/set-area", ['area_id' => $other->id])->assertOk();

        $this->assertDatabaseHas('activity_log', ['description' => 'psb.set_area', 'subject_id' => $draft->id]);
    }

    public function test_set_area_endpoint_is_admin_only(): void
    {
        $other = Area::create(['name' => 'SKYNET-LAINNYA', 'code' => 'lainnya']);
        $draft = $this->draft('Barengkrajan');
        $user = User::factory()->create(['role' => 'technician']);

        $this->actingAs($user)->postJson("/psb-wa/{$draft->id}/set-area", ['area_id' => $other->id])
            ->assertForbidden();
        $this->assertArrayNotHasKey('area_manual', (array) $draft->fresh()->meta);
    }

    public function test_ensure_starlite_command_refuses_backfill_drafts(): void
    {
        $this->artisan('psb:ensure-randuagung-starlite-area', ['--backfill-drafts' => true])
            ->assertExitCode(1);
        $this->artisan('psb:ensure-randuagung-starlite-area', ['--backfill-drafts' => true, '--apply' => true])
            ->assertExitCode(1);
        $this->assertNull(Area::where('code', 'randuagung-starlite')->first());
    }

    /** @param array<string, mixed> $meta */
    private function draft(string $daerah, array $meta = []): PsbDraft
    {
        return PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'AREA FIXTURE',
            'daerah' => $daerah,
            'sn' => 'FHTTAREA'.random_int(1000, 9999),
            'meta' => ['forced_router_id' => 513] + $meta,
        ]);
    }

    private function krianDraft(string $daerah): PsbDraft
    {
        return PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'AREA FIXTURE',
            'daerah' => $daerah,
            'sn' => 'FHTTAREA'.random_int(1000, 9999),
            'meta' => ['forced_router_id' => 514],
        ]);
    }

    private function krianRouterAndOlt(?int $oltAreaId): void
    {
        $router = new Router([
            'name' => 'Skynet-Krian-OLT',
            'ip_address' => '198.18.2.15',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = 514;
        $router->save();

        $olt = new Olt([
            'name' => 'OLT KRIAN',
            'code' => 'OLT-9',
            'vendor' => 'zte_c300',
            'management_ip' => '198.18.4.10',
            'router_id' => 514,
            'area_id' => $oltAreaId,
        ]);
        $olt->id = 9;
        $olt->save();
    }

    private function raRouterAndOlt(?int $oltAreaId = null): void
    {
        $router = new Router([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.14',
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $router->id = 513;
        $router->save();

        $olt = new Olt([
            'name' => 'OLT RA',
            'code' => 'OLT-1',
            'vendor' => 'zte_c300',
            'management_ip' => '198.18.4.2',
            'router_id' => 513,
            'area_id' => $oltAreaId,
        ]);
        $olt->id = 1;
        $olt->save();
    }

    private function service(?MikrotikService $mikrotik = null): AreaGuardHarness
    {
        $this->oltEdge = new AreaGuardOltController;
        $this->app->instance(OltC300Controller::class, $this->oltEdge);

        return new AreaGuardHarness(
            new EvolutionApiService,
            $mikrotik ?: new AreaGuardMikrotikFake,
            new PsbMessageParser,
            new PsbWaMessages,
            new KtpOcrService,
            new RadiusUserService,
            new AllowAllDocumentGate,
        );
    }

    /** OLT lookups/provisioning plus router sessions: devices touched (stays 0 when blocked). */
    private function deviceCalls(AreaGuardMikrotikFake $mikrotik): int
    {
        return $this->oltEdge->calls + $mikrotik->calls;
    }
}

class AreaGuardHarness extends PsbOrchestratorService
{
    public string $lastReply = '';

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->lastReply = $text;

        return true;
    }

    public function applySite(PsbDraft $draft): array
    {
        return $this->applySiteToDraft($draft);
    }
}

/** Counts OLT lookups and provisioning (must stay 0 when a draft is blocked), then runs the real controller. */
class AreaGuardOltController extends OltC300Controller
{
    public int $calls = 0;

    public function listUncfg(Request $request)
    {
        $this->calls++;

        return parent::listUncfg($request);
    }

    public function provisionFiberhome(Request $request)
    {
        $this->calls++;

        return parent::provisionFiberhome($request);
    }
}

class AreaGuardMikrotikFake extends MikrotikService
{
    public int $calls = 0;

    public function connect(Router $router, array $options = []): self
    {
        $this->calls++;

        return $this;
    }

    public function disconnect(): void {}
}
