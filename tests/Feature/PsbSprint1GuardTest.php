<?php

namespace Tests\Feature;

use App\Exceptions\PsbLockBusyException;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\OltC300Controller;
use App\Jobs\ProcessPsbMediaOcrJob;
use App\Models\Area;
use App\Models\AreaAlias;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\RouterVlan;
use App\Models\Setting;
use App\Models\User;
use App\Services\EvolutionApiService;
use App\Services\MikrotikService;
use App\Services\PsbDocumentGateService;
use App\Services\PsbMessageParser;
use App\Services\PsbOrchestratorService;
use App\Services\PsbSiteResolver;
use App\Services\PsbWaMessages;
use App\Services\RadiusUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\Canary;
use Tests\Support\MarkerKtpOcr;
use Tests\Support\MarkerOcr;
use Tests\Support\PsbCollaborators;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbSprint1GuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_psb_accepts_image_document_and_wrapped_media_as_attachment(): void
    {
        $method = new \ReflectionMethod(AttendanceController::class, 'hasInboundMedia');
        $method->setAccessible(true);
        $controller = new AttendanceController;

        $this->assertTrue($method->invoke($controller, ['imageMessage' => ['caption' => '#psb']]));
        $this->assertTrue($method->invoke($controller, ['documentMessage' => ['mimetype' => 'image/jpeg']]));
        $this->assertTrue($method->invoke($controller, [
            'documentWithCaptionMessage' => [
                'message' => ['documentMessage' => ['caption' => '#psb']],
            ],
        ]));
        $this->assertFalse($method->invoke($controller, []));
    }

    public function test_cancelled_draft_is_terminal_for_auto_merge(): void
    {
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_CANCELLED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'Draft Salah',
            'sn' => 'FHTTOLD001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = $this->service();

        $merged = $service->mergeable('ops@g.us', 'tech@s.whatsapp.net', [
            'full_name' => 'Draft Salah',
            'sn' => 'FHTTOLD001',
        ]);

        $this->assertNull($merged);
        $this->assertSame(PsbDraft::STATUS_CANCELLED, $draft->fresh()->status);
    }

    public function test_live_draft_does_not_fuzzy_merge_different_school_customer(): void
    {
        PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'MI MIFTAHUL FALAH',
            'sn' => 'FHTT0000A02A',
            'odp' => 'ODP-CAPANG-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merged = $this->service()->mergeable('ops@g.us', 'tech@s.whatsapp.net', [
            'full_name' => 'MTS MIFTAHUL FALAH',
            'sn' => 'FHTT0000A025',
            'odp' => 'ODP-CAPANG-01',
        ]);

        $this->assertNull($merged);
    }

    public function test_open_draft_still_allows_small_name_typo_merge(): void
    {
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'GATOT SUBROTO',
            'sn' => 'FHTTGATOT001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $merged = $this->service()->mergeable('ops@g.us', 'tech@s.whatsapp.net', [
            'full_name' => 'GATOT SUBRTO',
            'sn' => 'FHTTGATOT002',
        ]);

        $this->assertSame($draft->id, $merged?->id);
    }

    public function test_sn_change_without_id_is_blocked_when_multiple_active_drafts_exist(): void
    {
        PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Draft 103',
            'daerah' => 'Randuagung',
        ]);
        PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Draft 104',
            'daerah' => 'Krian',
        ]);

        $service = $this->service();
        $result = $service->snCommand(['group_jid' => 'ops@g.us'], [
            'sn' => 'FHTTNEW001',
            'draft_id' => null,
        ]);

        $this->assertSame('ambiguous_sn', $result->status);
        $this->assertSame(['', ''], PsbDraft::query()->orderBy('id')->pluck('sn')->map(fn ($v) => (string) $v)->all());
        $this->assertStringContainsString('#ID', $service->lastReply);
    }

    public function test_sn_first_full_form_for_another_person_is_a_new_draft_and_leaves_the_waiting_draft_alone(): void
    {
        $waiting = PsbDraft::create([
            'status' => PsbDraft::STATUS_WAITING_SN,
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'full_name' => 'BUDI CONTOH',
            'phone' => '081200000001',
            'daerah' => 'KALIREJO',
        ]);
        $before = $waiting->fresh()->only(['status', 'full_name', 'phone', 'sn', 'meta', 'last_error']);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000012@s.whatsapp.net',
            'message_id' => 'msg-sn-first-1',
            'text' => "#psb\nSN: FHTT0000A017\nNama: SITI CONTOH\nODP: ODP-KALIREJO-6\nRT: 03\nRW: 15\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nHP: 081200000077",
        ]);

        $this->assertSame(2, PsbDraft::query()->count());
        $this->assertSame($before, $waiting->fresh()->only(['status', 'full_name', 'phone', 'sn', 'meta', 'last_error']));
        $new = PsbDraft::query()->where('id', '!=', $waiting->id)->first();
        $this->assertSame('SITI CONTOH', $new->full_name);
        $this->assertSame('FHTT0000A017', $new->sn);
    }

    public function test_lanjut_with_id_never_falls_back_to_newer_secret_ok_draft(): void
    {
        $requested = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Draft Requested',
            'sn' => 'FHTTREQUEST01',
            'pppoe_user' => 'DRAFTREQUESTED_RT01_RW01_1',
            'secret_at' => now(),
            'ktp_photo_path' => 'psb/requested.jpg',
        ]);
        $newer = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Draft Newer',
            'sn' => 'FHTTNEWER001',
            'pppoe_user' => 'DRAFTNEWER_RT01_RW01_2',
            'secret_at' => now(),
            'ktp_photo_path' => 'psb/newer.jpg',
        ]);

        $service = $this->service();
        $service->interceptProcessDraft = true;
        $result = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'noc@s.whatsapp.net',
            'message_id' => 'continue-specific-'.$requested->id,
            'text' => '#psb lanjut #'.$requested->id,
        ]);

        $this->assertSame($requested->id, $result->id);
        $this->assertSame($requested->id, $service->continuedDraftId);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $newer->fresh()->status);
    }

    public function test_lanjut_with_unknown_id_does_not_process_latest_draft(): void
    {
        $latest = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Draft Latest',
            'sn' => 'FHTTLATEST01',
            'pppoe_user' => 'DRAFTLATEST_RT01_RW01_1',
            'secret_at' => now(),
            'ktp_photo_path' => 'psb/latest.jpg',
        ]);

        $service = $this->service();
        $service->interceptProcessDraft = true;
        $result = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'noc@s.whatsapp.net',
            'message_id' => 'continue-missing-id',
            'text' => '#psb lanjut #999999',
        ]);

        $this->assertSame(PsbDraft::STATUS_FAILED, $result->status);
        $this->assertNull($service->continuedDraftId);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $latest->fresh()->status);
        $this->assertStringContainsString('#999999', $service->lastReply);
    }

    public function test_site_resolver_keeps_existing_vlan_and_sets_krian_fiberhome_tr069(): void
    {
        $area = Area::create(['code' => 'KRN', 'name' => 'Krian']);
        $router = Router::create([
            'name' => 'Skynet-Krian-OLT',
            'ip_address' => '198.18.9.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT Krian C300',
            'code' => 'OLT-KRN-C300',
            'vendor' => 'zte',
            'router_id' => $router->id,
        ]);
        RouterVlan::create([
            'router_id' => $router->id,
            'area_id' => $area->id,
            'vlan' => 321,
            'label' => 'Krian default',
            'is_default' => true,
            'is_active' => true,
        ]);

        $site = app(PsbSiteResolver::class)->resolve('Krian');

        $this->assertSame('krian_full_olt', $site['key']);
        $this->assertSame(PsbSiteResolver::MODE_FULL_OLT, $site['mode']);
        $this->assertSame($router->id, $site['router']?->id);
        $this->assertSame($olt->id, $site['olt']?->id);
        $this->assertSame(321, $site['vlan']);
        $this->assertSame(69, $site['tr069_vlan']);
    }

    public function test_site_resolver_uses_vlan_master_as_source_of_truth_for_hioso_and_htb(): void
    {
        $hiosoRouter = Router::create([
            'name' => 'Bumiayu',
            'ip_address' => '198.18.10.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        Router::create([
            'name' => 'Randuagung CCR',
            'ip_address' => '198.18.15.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        $hioso = app(PsbSiteResolver::class)->resolve('Bumiayu');
        $htb = app(PsbSiteResolver::class)->resolve('rdg');

        $this->assertSame('hioso_bumiayu', $hioso['key']);
        $this->assertSame(PsbSiteResolver::MODE_PPPOE_ONLY, $hioso['mode']);
        $this->assertFalse($hioso['vlan_required']);

        RouterVlan::create([
            'router_id' => $hiosoRouter->id,
            'vlan' => 177,
            'label' => 'Bumiayu VLAN',
            'is_default' => true,
            'is_active' => true,
        ]);

        $hiosoWithMaster = app(PsbSiteResolver::class)->resolve('Bumiayu');
        $this->assertSame('hioso_bumiayu', $hiosoWithMaster['key']);
        $this->assertSame(177, $hiosoWithMaster['vlan']);
        $this->assertSame(PsbSiteResolver::MODE_PPPOE_VLAN, $hiosoWithMaster['mode']);
        $this->assertTrue($hiosoWithMaster['vlan_required']);

        $this->assertSame('htb_rdg', $htb['key']);
        $this->assertSame(PsbSiteResolver::MODE_PPPOE_ONLY, $htb['mode']);
        $this->assertFalse($htb['vlan_required']);
    }

    public function test_apply_site_to_draft_sets_krian_router_and_olt_for_manual_and_wa_flow(): void
    {
        $router = Router::create([
            'name' => 'Skynet-Krian-OLT',
            'ip_address' => '198.18.16.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT Krian C300',
            'code' => 'OLT-KRIAN-MANUAL',
            'vendor' => 'zte',
            'router_id' => $router->id,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Krian Manual',
            'daerah' => 'Krian',
        ]);

        $site = $this->service()->applySite($draft);

        $this->assertSame('krian_full_olt', $site['key']);
        $this->assertSame($router->id, $draft->fresh()->router_id);
        $this->assertSame($olt->id, $draft->fresh()->olt_id);
        $this->assertSame('krian', $draft->fresh()->site_profile_key);
        $this->assertSame(69, $draft->fresh()->tr069_vlan);
    }

    public function test_vlan_destroy_endpoints_soft_disable_existing_rows(): void
    {
        if (! Schema::hasTable('olt_vlan_mappings')) {
            Schema::create('olt_vlan_mappings', function ($table) {
                $table->id();
                $table->foreignId('olt_id')->constrained('olts')->cascadeOnDelete();
                $table->string('pon_port', 32);
                $table->string('service_type', 32)->nullable();
                $table->unsignedSmallInteger('vlan');
                $table->string('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $user = User::factory()->create(['role' => 'superadmin']);
        $router = Router::create([
            'name' => 'VLAN Router',
            'ip_address' => '198.18.17.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT VLAN Guard',
            'code' => 'OLT-VLAN-GUARD',
            'vendor' => 'zte',
        ]);
        $routerVlan = RouterVlan::create([
            'router_id' => $router->id,
            'vlan' => 222,
            'is_active' => true,
        ]);
        $oltVlan = OltVlanMapping::create([
            'olt_id' => $olt->id,
            'pon_port' => '1/2/3',
            'service_type' => 'internet',
            'vlan' => 333,
            'is_active' => true,
        ]);

        $this->actingAs($user)->delete(route('api.router-vlans.destroy', $routerVlan))->assertOk();
        $this->actingAs($user)->delete(route('api.olt-vlan-mappings.destroy', $oltVlan))->assertNoContent();

        $this->assertDatabaseHas('router_vlans', ['id' => $routerVlan->id, 'is_active' => false]);
        $this->assertDatabaseHas('olt_vlan_mappings', ['id' => $oltVlan->id, 'is_active' => false]);
    }

    public function test_detected_sn_without_olt_vlan_mapping_is_classified_as_pending_vlan(): void
    {
        $olt = Olt::create([
            'name' => 'OLT RA VLAN TEST',
            'code' => 'OLT-RA-VLAN-TEST',
            'vendor' => 'zte',
        ]);
        $draft = new PsbDraft(['sn' => 'FHTT0000A02E']);

        $controller = \Mockery::mock(OltC300Controller::class)->makePartial();
        $controller->shouldReceive('listUncfg')->once()->andReturn(response()->json([
            'onus' => [[
                'sn' => 'FHTT0000A02E',
                'port' => '1/3/7',
                'onu_id' => 12,
            ]],
        ]));
        $this->app->instance(OltC300Controller::class, $controller);

        $result = PsbCollaborators::oltProvisioner()->findUncfgForDraft($draft, $olt);

        $this->assertFalse($result['found']);
        $this->assertSame('vlan_mapping_missing', $result['block_reason']);
        $this->assertSame('1/3/7', $result['port']);
        $this->assertSame('FHTT0000A02E', $result['sn']);
    }

    public function test_existing_db_secret_must_be_live_or_recreated_before_success(): void
    {
        $router = Router::create([
            'name' => 'RA-OLT',
            'ip_address' => '198.18.18.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => 'BASIC',
            'name' => 'Basic',
            'price' => 100000,
            'mikrotik_profile' => '200MB',
        ]);
        Area::create(['name' => 'Randuagung', 'code' => 'randuagung']);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'group_jid' => 'ops@g.us',
            'full_name' => 'Budi Test',
            'rt' => '103',
            'rw' => '104',
            'odp' => 'ODP 1.2',
            'daerah' => 'Randuagung',
            'pppoe_user' => 'BUDITEST_RT103_RW104_1.2',
            'pppoe_password' => 'canary-old',
            'secret_at' => now(),
        ]);

        $mikrotik = new Sprint1MikrotikFake([null, ['.id' => '*1', 'name' => 'BUDITEST_RT103_RW104_1.2']]);
        $service = $this->service($mikrotik);

        $service->createSecret($draft, $router, $package);

        $this->assertSame(['BUDITEST_RT103_RW104_1.2'], $mikrotik->addedUsers);
        $this->assertNotNull($draft->fresh()->secret_at);
        $this->assertSame(PsbDraft::STATUS_SECRET_OK, $draft->fresh()->status);
        $this->assertSame('BUDITEST_RT103_RW104_1.2', $draft->fresh()->pppoe_user);
    }

    public function test_live_psb_resend_with_odp_dot_suffix_becomes_correction_pending_not_silent_update(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => '15M',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        $customer = Customer::create([
            'name' => 'USERC',
            'code' => 'C-USERC',
            'address' => 'old',
            'phone' => '081200000001',
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'join_date' => now(),
            'due_day' => 1,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'USERC',
            'rt' => '03',
            'rw' => '15',
            'odp' => 'ODP-KALIREJO-6',
            'daerah' => 'KALIREJO',
            'sn' => 'FHTT0000A014',
            'vlan' => 302,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'secret_at' => now(),
            'olt_at' => now(),
            'ktp_photo_path' => 'psb/ktp.jpg',
        ]);

        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);
        $result = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-userc-correction',
            'text' => "#psb\nNama:USERC\nODP: ODP-KALIREJO-7.1\nRT: 03\nRW: 15\nTikor:-6.845468,111.701127\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nSN:FHTT0000A014\nHP:081200000001",
        ]);

        $fresh = $draft->fresh();
        $this->assertSame($draft->id, $result->id);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status, (string) $fresh->last_error);
        $this->assertSame('ODP-KALIREJO-7.1', $fresh->odp);
        $this->assertSame('USERC_RT03_RW15_6', $fresh->pppoe_user);
        $this->assertSame([], $fake->addedUsers);
        $this->assertSame('USERC_RT03_RW15_6', $customer->fresh()->pppoe_user);
        $corr = $fresh->meta['psb_correction'];
        $this->assertSame('USERC_RT03_RW15_6', $corr['diff']['pppoe_user']['old']);
        $this->assertSame('USERC_RT03_RW15_7.1', $corr['diff']['pppoe_user']['new']);
        $this->assertStringContainsString('Koreksi PSB menunggu approval', $service->lastReply);
    }

    public function test_odp_letter_suffix_is_preserved_in_pppoe_username(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.11',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => '15M-L3',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        Area::create(['name' => 'Gondang', 'code' => 'gondang']);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'ODP LETTER',
            'rt' => '03',
            'rw' => '07',
            'odp' => 'ODP GONDANG L3.1',
            'daerah' => 'GONDANG',
            'sn' => 'FHTTLETTER01',
            'router_id' => $router->id,
            'package_id' => $package->id,
        ]);

        $fake = new Sprint1MikrotikFake([null]);
        $this->service($fake)->createSecret($draft, $router, $package);

        $this->assertSame('ODPLETTER_RT03_RW07_L3.1', $draft->fresh()->pppoe_user);
        $this->assertSame(['ODPLETTER_RT03_RW07_L3.1'], $fake->addedUsers);
    }

    public function test_ra_psb_area_comes_from_locality_alias_never_randuagung_starlite(): void
    {
        $rdg2 = Area::create(['name' => 'SKYNET-RANDUAGUNG2', 'code' => 'RDGNEW']);
        AreaAlias::create(['area_id' => $rdg2->id, 'alias' => 'gondang', 'alias_key' => 'gondang']);
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.12',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => 'STARTLITE-AREA',
            'name' => 'STARTLITE',
            'price' => 100000,
            'mikrotik_profile' => 'STARTLITE',
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'AREA TEST',
            'rt' => '03',
            'rw' => '07',
            'odp' => 'ODP GONDANG L3',
            'daerah' => 'GONDANG',
            'sn' => 'FHTTAREA001',
            'router_id' => $router->id,
            'package_id' => $package->id,
        ]);

        $service = $this->service(new Sprint1MikrotikFake([null]));
        $service->resolveDraftArea($draft);
        $service->createSecret($draft->fresh(), $router, $package);

        $this->assertNull(Area::where('code', 'randuagung-starlite')->first());
        $this->assertSame('GONDANG', $draft->fresh()->daerah);
        $this->assertSame($rdg2->id, $draft->fresh()->area_id);
        $this->assertSame('alias', $draft->fresh()->meta['area_resolution']['source']);
        $this->assertDatabaseHas('customers', [
            'pppoe_user' => 'AREATEST_RT03_RW07_L3',
            'area_id' => $rdg2->id,
        ]);
    }

    public function test_randuagung_starlite_area_command_refuses_backfill_drafts(): void
    {
        $randuagung = Area::create(['code' => 'RA', 'name' => 'Randuagung']);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_SECRET_OK,
            'site_profile_key' => 'ra',
            'group_jid' => 'ops@g.us',
            'full_name' => 'RDGS Backfill',
            'daerah' => 'GONDANG',
            'area_id' => $randuagung->id,
        ]);

        $this->artisan('psb:ensure-randuagung-starlite-area', ['--backfill-drafts' => true, '--apply' => true])
            ->assertExitCode(1);

        $this->assertSame($randuagung->id, $draft->fresh()->area_id);
        $this->assertNull(Area::where('code', 'randuagung-starlite')->first());
    }

    public function test_psb_batal_on_pending_correction_restores_live_draft_without_device_write(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.2',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => '15M-CANCEL',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'USERC',
            'rt' => '03',
            'rw' => '15',
            'odp' => 'ODP-KALIREJO-6',
            'daerah' => 'KALIREJO',
            'sn' => 'FHTT0000A014',
            'vlan' => 302,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'secret_at' => now(),
            'olt_at' => now(),
        ]);

        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-userc-correction-cancel',
            'text' => "#psb\nNama:USERC\nODP: ODP-KALIREJO-7.1\nRT: 03\nRW: 15\nTikor:-6.845468,111.701127\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nSN:FHTT0000A014\nHP:081200000001",
        ]);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-userc-correction-batal',
            'text' => '#psb batal #'.$draft->id,
        ]);

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $fresh->status);
        $this->assertSame('ODP-KALIREJO-6', $fresh->odp);
        $this->assertSame('USERC_RT03_RW15_6', $fresh->pppoe_user);
        $this->assertArrayNotHasKey('psb_correction', $fresh->meta ?? []);
        $this->assertSame([], $fake->addedUsers);
        $this->assertStringContainsString('Koreksi PSB dibatalkan', $service->lastReply);
    }

    /** @return array{0: PsbDraft, 1: Sprint1MikrotikFake, 2: Sprint1PsbHarness} */
    private function liveDraftWithSnCorrectionPending(): array
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.5',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => '15M',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        $customer = Customer::create([
            'name' => 'USERC',
            'code' => 'C-USERC',
            'address' => 'old',
            'phone' => '081200000001',
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'join_date' => now(),
            'due_day' => 1,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'USERC',
            'rt' => '03',
            'rw' => '15',
            'odp' => 'ODP-KALIREJO-6',
            'daerah' => 'KALIREJO',
            'sn' => 'FHTT0000A014',
            'vlan' => 302,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'secret_at' => now(),
            'olt_at' => now(),
            'ktp_photo_path' => 'psb/ktp.jpg',
        ]);

        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);
        $service->handleInbound($this->resendPayload('msg-sn-corr-1', 'FHTT0000A015', 'ODP-KALIREJO-6'));
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);

        return [$draft, $fake, $service];
    }

    /** @return array<string, mixed> */
    private function resendPayload(string $messageId, string $sn, string $odp): array
    {
        return [
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => $messageId,
            'text' => "#psb\nNama:USERC\nODP: {$odp}\nRT: 03\nRW: 15\nTikor:-6.845468,111.701127\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nSN:{$sn}\nHP:081200000001",
        ];
    }

    public function test_identical_resend_keeps_pending_correction_and_does_not_provision(): void
    {
        [$draft, $fake, $service] = $this->liveDraftWithSnCorrectionPending();
        $beforeMeta = $draft->fresh()->meta['psb_correction'];
        $beforeOltAt = $draft->fresh()->olt_at;

        $service->lastReply = '';
        $service->handleInbound($this->resendPayload('msg-sn-corr-2', 'FHTT0000A015', 'ODP-KALIREJO-6'));

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status);
        $this->assertSame($beforeMeta, $fresh->meta['psb_correction']);
        $this->assertStringStartsWith('Koreksi provisioning', (string) $fresh->last_error);
        $this->assertSame($beforeOltAt?->toDateTimeString(), $fresh->olt_at?->toDateTimeString());
        $this->assertSame([], $fake->addedUsers);
        $this->assertStringContainsString('Koreksi PSB menunggu approval', $service->lastReply);
    }

    public function test_process_draft_never_provisions_a_pending_correction(): void
    {
        [$draft, $fake, $service] = $this->liveDraftWithSnCorrectionPending();

        $service->processDraft($draft->fresh());

        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);
        $this->assertSame([], $fake->addedUsers);
    }

    public function test_resend_with_photo_on_pending_correction_stays_pending(): void
    {
        config(['services.ocrspace.async_media_enabled' => true]);
        Queue::fake();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        [$draft, $fake, $service] = $this->liveDraftWithSnCorrectionPending();

        $payload = $this->resendPayload('msg-sn-corr-photo', 'FHTT0000A015', 'ODP-KALIREJO-6');
        $payload['image_bytes_list'] = [str_repeat('KTP_MARKER', 20)];
        $service->handleInbound($payload);

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status);
        $this->assertStringStartsWith('Koreksi provisioning', (string) $fresh->last_error);
        $this->assertSame([], $fake->addedUsers);

        // The continuation after OCR must also stop at the approval step. Reuse what
        // handleInbound really queued (live snapshot as before_update, parsed, payload).
        /** @var ProcessPsbMediaOcrJob $queued */
        $queued = Queue::pushed(ProcessPsbMediaOcrJob::class)->first();
        $this->assertNotNull($queued);
        $c = $queued->continuation;
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $c['before_update']['status']);
        $this->assertNotSame('', (string) $c['before_update']['pppoe_user']);
        $service->lastReply = '';
        $service->finishInboundAfterMedia(
            $draft->fresh(),
            $c['parsed'],
            $c['payload'],
            (bool) $c['is_update'],
            $c['before_update'],
            (string) $c['old_sn']
        );
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);
        $this->assertSame([], $fake->addedUsers);
        $this->assertStringContainsString('Koreksi PSB menunggu approval', $service->lastReply);
    }

    public function test_router_vlan_and_confirm_sn_commands_do_not_touch_a_pending_correction(): void
    {
        [$draft, $fake, $service] = $this->liveDraftWithSnCorrectionPending();
        $draft->refresh();
        $meta = $draft->meta;
        $meta['pending_uncfg_sn'] = 'FHTT0000A018';
        $draft->meta = $meta;
        $draft->save();
        $snapshot = $draft->fresh()->only(['status', 'sn', 'vlan', 'router_id', 'last_error']);
        $correction = $draft->fresh()->meta['psb_correction'];
        $metaBefore = $draft->fresh()->meta;

        foreach (['router ra', 'vlan 100', 'ya'] as $cmd) {
            $service->lastReply = '';
            $service->handleInbound([
                'group_jid' => 'ops@g.us',
                'sender_jid' => 'tech@s.whatsapp.net',
                'message_id' => 'msg-pending-'.str_replace(' ', '-', $cmd),
                'text' => "#psb {$cmd} #{$draft->id}",
            ]);

            $fresh = $draft->fresh();
            $this->assertSame($snapshot, $fresh->only(['status', 'sn', 'vlan', 'router_id', 'last_error']), $cmd);
            $this->assertSame($correction, $fresh->meta['psb_correction'], $cmd);
            $this->assertSame($metaBefore, $fresh->meta, $cmd);
            $this->assertArrayNotHasKey('forced_vlan', $fresh->meta, $cmd);
            $this->assertStringContainsString('Koreksi PSB menunggu approval', $service->lastReply, $cmd);
            $this->assertSame([], $fake->addedUsers, $cmd);
        }
    }

    public function test_further_change_on_pending_correction_stays_pending_and_keeps_original_before(): void
    {
        [$draft, $fake, $service] = $this->liveDraftWithSnCorrectionPending();

        $service->handleInbound($this->resendPayload('msg-sn-corr-3', 'FHTT0000A015', 'ODP-KALIREJO-7.1'));

        $fresh = $draft->fresh();
        $corr = $fresh->meta['psb_correction'];
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status);
        $this->assertSame('FHTT0000A014', $corr['before']['sn']);
        $this->assertSame('ODP-KALIREJO-6', $corr['before']['odp']);
        $this->assertSame('FHTT0000A014', $corr['diff']['sn']['old']);
        $this->assertSame('ODP-KALIREJO-6', $corr['diff']['odp']['old']);
        $this->assertSame([], $fake->addedUsers);

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-sn-corr-batal',
            'text' => '#psb batal #'.$draft->id,
        ]);

        $restored = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $restored->status);
        $this->assertSame('FHTT0000A014', $restored->sn);
        $this->assertSame('ODP-KALIREJO-6', $restored->odp);
    }

    public function test_pppoe_search_reports_manual_live_rename_from_pending_correction(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.20.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => 'PKG15',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        $customer = Customer::create([
            'name' => 'USERC',
            'code' => 'C-USERC-2',
            'address' => 'KALIREJO',
            'phone' => '081200000001',
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'join_date' => now(),
            'due_day' => 1,
        ]);
        PsbDraft::create([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'group_jid' => 'ops@g.us',
            'customer_id' => $customer->id,
            'full_name' => 'USERC',
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'meta' => [
                'psb_correction' => [
                    'status' => 'pending_review',
                    'after' => ['pppoe_user' => 'USERC_RT03_RW15_7.1'],
                ],
            ],
        ]);

        $fake = new Sprint1MikrotikFake([
            null,
            ['.id' => '*7', 'name' => 'USERC_RT03_RW15_7.1', 'profile' => '15M'],
        ], [['name' => 'USERC_RT03_RW15_7.1', 'address' => '198.18.12.9', 'uptime' => '1m']]);
        $service = $this->service($fake);

        $result = $service->handlePppoeQuery('ops@g.us', 'USERC');

        $this->assertSame('pppoe_found', $result->status);
        $this->assertStringContainsString('DB stale; secret baru live', $service->lastReply);
        $this->assertStringContainsString('USERC_RT03_RW15_7.1', $service->lastReply);
    }

    public function test_router_profile_push_accepts_single_mikrotik_fetch_payload(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.20.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);

        Setting::set('wg_push_profile_token', 'rahasia-123');

        $this->withHeader('X-Push-Token', 'rahasia-123')->postJson(route('routers.profiles.push', [
            'router_id' => $router->id,
            'name' => 'KALIREJO-NET',
            'rate-limit' => '15M/15M',
            'local-address' => '198.18.11.1',
            'remote-address' => '198.18.11.2',
            'only-one' => 'yes',
        ]))->assertOk()->assertJson([
            'success' => true,
            'synced' => 1,
            'router' => 'RA',
        ]);

        $this->assertDatabaseHas('router_profiles', [
            'router_id' => $router->id,
            'name' => 'KALIREJO-NET',
        ]);
        $this->assertDatabaseHas('packages', [
            'router_id' => $router->id,
            'mikrotik_profile' => 'KALIREJO-NET',
        ]);
    }

    public function test_psb_package_error_is_actionable_when_router_profile_exists_without_package_mapping(): void
    {
        $router = Router::create([
            'name' => 'RA OLT',
            'ip_address' => '198.18.20.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        RouterProfile::create([
            'router_id' => $router->id,
            'name' => 'KALIREJO-NET',
            'rate_limit' => '15M/15M',
        ]);

        $service = $this->service();
        $draft = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-kalirejo-net',
            'text' => "#psb\nNama:PROFILE TEST\nODP: ODP-KALIREJO-1\nRT: 01\nRW: 01\nTikor:-6.845468,111.701127\nDaerah: KALIREJO\nRouter: RA\nPaket: KALIREJO-NET\nSN:FHTTPROFILE01\nHP:085600000001",
        ]);

        $this->assertSame(PsbDraft::STATUS_FAILED, $draft->fresh()->status);
        $this->assertStringContainsString('Profile MT `KALIREJO-NET` sudah ada di router', $service->lastReply);
        $this->assertStringContainsString('mapping Paket Internet eBilling belum lengkap', $service->lastReply);
    }

    public function test_weak_sn_hint_does_not_steal_a_single_ktp_photo(): void
    {
        $service = $this->service();

        // A card aspect ratio (25) without text evidence is never KTP; it fails
        // closed to unknown so an unread SN sticker never lands in KTP.
        $this->assertSame('unknown', $service->photoType(null, 40, 25));
        $this->assertSame('unknown', $service->photoType(null, 40, 0));
        $this->assertSame('sn', $service->photoType(null, 60, 0));
        $this->assertSame('sn', $service->photoType('FHTT0000A011', 90, 25));
        $this->assertSame('unknown', $service->photoType(null, 60, 0, true));
        $this->assertSame('unknown', $service->photoType(null, 40, 25, true));
        // KTP requires text evidence (NIK/ID keywords), not just the ratio.
        $this->assertSame('ktp', $service->photoType(null, 20, 80, false, true));
        $this->assertSame('ktp', $service->photoType(null, 20, 80, true, true));
        $this->assertSame('sn', $service->photoType('FHTT0000A011', 90, 25, true));
        // A sticker with keywords wins over a ratio-only KTP score.
        $this->assertSame('sn', $service->photoType(null, 40, 25, false, false, true));
    }

    public function test_hard_photo_evidence_distinguishes_ktp_and_serial_text(): void
    {
        $service = $this->service();

        $this->assertTrue($service->strongKtp([
            'nik' => '3507114510000001',
            'raw_text' => "PROVINSI JAWA TIMUR\nNIK: 3507114510000001",
        ]));
        $this->assertFalse($service->strongKtp([
            'nik' => '3507114510000001',
            'raw_text' => 'GPON SERIAL MAC ADDRESS',
        ]));
        $this->assertSame('FHTT0000A011', $service->snFromText('SN: FHTT0000A011'));
        $this->assertNull($service->snFromText('REPUBLIK INDONESIA NIK 3507114510000001'));
        $this->assertNull($service->snFromText('KEWARGANEGARAAN WNI BERLAKU SEUMUR HIDUP'));
        $this->assertSame('ZTEG0000A001', $service->snFromText('PON SN: ZTEG0000A001'));
    }

    public function test_photo_classification_is_terminal_when_ocr_has_no_signal(): void
    {
        $result = $this->service()->classifyPhoto(str_repeat('not-an-image-', 20));

        $this->assertSame('unknown', $result['type']);
        $this->assertSame('terminal', $result['classification_status']);
        $this->assertArrayNotHasKey('classification_unresolved', $result);
    }

    public function test_ocr_signal_scoring_distinguishes_ktp_fields_from_vendor_serial(): void
    {
        $service = $this->service();

        [$ktpScore, $snScore] = $service->signalScores(
            str_repeat('not-an-image-', 20),
            'N1K 3507114510000001 NAMA SITI PROVINSI JAWA TIMUR KECAMATAN KRIAN',
        );
        $this->assertGreaterThanOrEqual(45, $ktpScore);
        $this->assertLessThan(60, $snScore);

        [$ktpScore, $snScore] = $service->signalScores(
            str_repeat('synthetic-sn-media-', 20),
            SyntheticOcrFixtures::snText(),
        );
        $this->assertLessThan(25, $ktpScore);
        $this->assertGreaterThanOrEqual(90, $snScore);
    }

    public function test_two_photos_are_classified_independently_in_either_order_and_duplicates_are_idempotent(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAwEBPwF//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==');
        $ktp = $jpeg.'KTP_MARKER'.str_repeat('x', 120);
        $sn = $jpeg.'SN_MARKER'.str_repeat('y', 120);

        foreach ([[$ktp, $sn], [$sn, $ktp]] as $images) {
            $draft = new PsbDraft(['full_name' => 'PHOTO TEST']);
            $service = $this->service();
            $service->attachPhotos($draft, $images, [
                ['message_id' => 'm-ktp', 'sender' => 'tech@s.whatsapp.net'],
                ['message_id' => 'm-sn', 'sender' => 'tech@s.whatsapp.net'],
            ]);

            $this->assertNotEmpty($draft->ktp_photo_path);
            $this->assertNotEmpty($draft->router_photo_path);
            $this->assertSame(2, data_get($draft->meta, 'photo_class.images'));
            $this->assertSame(2, count($draft->meta['photo_attachments']));
            $this->assertNotSame($draft->ktp_photo_path, $draft->router_photo_path);
        }

        $draft = new PsbDraft(['full_name' => 'DUPLICATE TEST']);
        $service = $this->service();
        $service->attachPhotos($draft, [$ktp, $ktp], [
            ['message_id' => 'duplicate-1'],
            ['message_id' => 'duplicate-1'],
        ]);
        $this->assertSame(1, data_get($draft->meta, 'photo_class.images'));

        $odp = $jpeg.'ODP_MARKER'.str_repeat('z', 120);
        $odpDraft = new PsbDraft(['full_name' => 'ODP TEST']);
        $this->service()->attachPhotos($odpDraft, [$odp]);
        $this->assertNull($odpDraft->ktp_photo_path);
        $this->assertNull($odpDraft->router_photo_path);
        $this->assertSame('odp', data_get($odpDraft->meta, 'photo_attachments.0.classifier'));
    }

    public function test_follow_up_photo_requires_explicit_anchor_and_does_not_use_recent_sender(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'psb-anchor-1',
            'full_name' => 'ANCHOR TEST',
        ]);
        $service = $this->service();
        $service->interceptProcessDraft = true;
        Storage::disk('local')->put('psb/followup.jpg', 'not-a-real-image-but-large-enough-'.str_repeat('x', 200));

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'photo-followup-1',
            'text' => '',
            'image_paths' => ['psb/followup.jpg'],
            'follow_up_anchor_message_id' => 'psb-anchor-1',
        ]);

        $this->assertSame($draft->id, PsbDraft::whereKey($draft->id)->value('id'));
        $this->assertNull(PsbDraft::where('message_id', 'unrelated-recent')->first());
    }

    public function test_follow_up_context_stays_on_new_photo_after_existing_draft_photos(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAwEBPwF//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EP/aA/AAAA==');
        $existingSn = $jpeg.'SN_MARKER'.str_repeat('s', 120);
        $newKtp = $jpeg.'KTP_MARKER'.str_repeat('k', 120);
        Storage::disk('local')->put('psb/existing-sn.jpg', $existingSn);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'full_name' => 'CONTEXT TEST',
            'router_photo_path' => 'psb/existing-sn.jpg',
        ]);

        $this->service()->attachFollowUpImages($draft, [$newKtp], [
            'source' => 'wa_exact_caption',
            'media_contexts' => [[
                'path' => 'psb/inbox/new-ktp.jpg',
                'message_id' => 'new-ktp-message',
                'sender' => 'tech@s.whatsapp.net',
            ]],
        ]);

        $attachments = $draft->fresh()->meta['photo_attachments'] ?? [];
        $this->assertSame('new-ktp-message', end($attachments)['message_id']);
    }

    public function test_two_ktp_photos_keep_sn_empty_and_unknown_media_is_not_ktp(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAwEBPwF//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EP/aA/AAAA==');
        $ktpA = $jpeg.'KTP_MARKER'.str_repeat('a', 120);
        $ktpB = $jpeg.'KTP_MARKER'.str_repeat('b', 120);
        $draft = new PsbDraft(['full_name' => 'TWO KTP TEST']);
        $this->service()->attachPhotos($draft, [$ktpA, $ktpB]);

        $this->assertNotEmpty($draft->ktp_photo_path);
        $this->assertNull($draft->router_photo_path);
        $this->assertSame(2, data_get($draft->meta, 'photo_class.images'));

        $unknown = new PsbDraft(['full_name' => 'UNKNOWN TEST']);
        $this->service()->attachPhotos($unknown, ['not-an-image-'.str_repeat('x', 200)]);
        $this->assertNull($unknown->ktp_photo_path);
        $this->assertNull($unknown->router_photo_path);
        $this->assertSame('unknown', data_get($unknown->meta, 'photo_attachments.0.classifier'));
    }

    public function test_async_media_ocr_stages_follow_up_and_uses_dedicated_queue(): void
    {
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'services.ocrspace.async_media_enabled' => true,
        ]);
        Queue::fake();

        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'ASYNC OCR TEST',
            'waiting_photo' => true,
        ]);
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAwEBPwF//8QAFBEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIAAwAAABD/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9o/AAAA==');
        $this->service()->attachFollowUpImages($draft, [$jpeg.'KTP_MARKER'], [
            'source' => 'wa_exact_caption',
            'message_id' => 'async-media-1',
            'media_contexts' => [[
                'message_id' => 'async-media-1',
                'sender' => 'tech@s.whatsapp.net',
            ]],
        ]);

        Queue::assertPushedOn('ocr-media', ProcessPsbMediaOcrJob::class);
        $this->assertNull($draft->fresh()->ktp_photo_path);
        $this->assertSame('ocr_pending', data_get($draft->fresh()->meta, 'document_gate.status'));
        Storage::disk('local')->assertExists('psb/'.now()->format('Y/m').'/ocr-pending/'.hash('sha256', $jpeg.'KTP_MARKER').'.bin');
    }

    public function test_duplicate_completed_media_batch_restores_state_and_continues_psb(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Setting::set('psb_document_gate_enabled', '1', 'boolean', 'psb');
        Setting::set('psb_document_gate_enforced_after', now()->subDay()->toIso8601String(), 'text', 'psb');

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//9oACAEBAAEFAU//2gAMAwEAAgADAAAAEP/EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQMBAT8QH//EABQRAQAAAAAAAAAAAAAAAAAAABD/2gAIAQIBAT8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEABj8QH//EABQQAQAAAAAAAAAAAAAAAAAAABD/2gAIAQEAAT8QH//Z');
        Storage::disk('local')->put('psb/ktp.jpg', $jpeg);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_RECEIVED,
            'group_jid' => 'ops@g.us',
            'full_name' => 'DUPLICATE BATCH TEST',
            'odp' => 'ODP-TEST-01',
            'rt' => '01',
            'rw' => '02',
            'tikor' => '-6.9,112.6',
            'daerah' => 'RA',
            'sn' => 'FHTTTEST1234',
            'router_id' => 513,
            'olt_id' => 1,
            'package_id' => 452,
            'ktp_photo_path' => 'psb/ktp.jpg',
            'last_error' => 'Foto diterima, validasi KTP/SN sedang diproses.',
            'meta' => [
                'ocr_batch_key' => 'batch-1',
                'document_gate' => ['status' => 'ocr_pending'],
                'photo_class' => [
                    'images' => 1,
                    'status' => 'pending',
                    'ocr_batch_completed' => 'batch-1',
                    'ktp_score' => 100,
                ],
            ],
        ]);

        $service = $this->service();
        $service->interceptProcessDraft = true;
        $service->processStaged([
            'mode' => 'inbound',
            'batch_key' => 'batch-1',
            'payload' => ['group_jid' => 'ops@g.us'],
        ], $draft);

        $fresh = $draft->fresh();
        $this->assertSame('completed', data_get($fresh->meta, 'photo_class.status'));
        $this->assertSame('verified', data_get($fresh->meta, 'document_gate.status'));
        $this->assertArrayNotHasKey('ocr_batch_key', $fresh->meta);
        $this->assertSame($draft->id, $service->continuedDraftId);
    }

    private function reprocessDraft(string $status, array $overrides = []): PsbDraft
    {
        return PsbDraft::create($overrides + [
            'status' => $status,
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'full_name' => 'REPROCESS GUARD',
            'sn' => 'FHTTREPRO001',
            'vlan' => 302,
            'last_error' => 'keep',
            'meta' => ['pending_uncfg_sn' => 'FHTTREPRO999', 'pending_uncfg_port' => '1/2/3', 'pending_uncfg_onu_id' => 7],
        ]);
    }

    public function test_router_vlan_and_confirm_sn_refuse_finished_or_cancelled_drafts(): void
    {
        Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.5',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        foreach ([PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CANCELLED] as $status) {
            $draft = $this->reprocessDraft($status);
            $before = $draft->fresh()->only(['status', 'sn', 'vlan', 'router_id', 'last_error', 'meta']);

            foreach (['router ra', 'vlan 100', 'ya'] as $cmd) {
                $service->lastReply = '';
                $service->continuedDraftId = null;
                $service->handleInbound([
                    'group_jid' => 'ops@g.us',
                    'sender_jid' => 'tech@s.whatsapp.net',
                    'message_id' => "msg-repro-{$status}-".str_replace(' ', '-', $cmd),
                    'text' => "#psb {$cmd} #{$draft->id}",
                ]);

                $label = "{$status} {$cmd}";
                $this->assertSame($before, $draft->fresh()->only(['status', 'sn', 'vlan', 'router_id', 'last_error', 'meta']), $label);
                $this->assertNull($service->continuedDraftId, $label);
                $this->assertStringContainsString("Draft #{$draft->id} sudah", $service->lastReply, $label);
                $this->assertStringContainsString('tidak bisa diproses ulang', $service->lastReply, $label);
            }
        }
    }

    public function test_router_and_vlan_without_id_never_pick_a_finished_draft(): void
    {
        $finished = $this->reprocessDraft(PsbDraft::STATUS_OLT_OK);
        $before = $finished->fresh()->only(['status', 'vlan', 'router_id', 'meta']);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.5',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-noid-finished',
            'text' => '#psb router ra',
        ]);
        $this->assertSame($before, $finished->fresh()->only(['status', 'vlan', 'router_id', 'meta']));
        $this->assertNull($service->continuedDraftId);

        // An older draft that is still open is picked instead of the newer finished one.
        $open = $this->reprocessDraft(PsbDraft::STATUS_PENDING_VLAN);
        $finishedNewer = $this->reprocessDraft(PsbDraft::STATUS_OLT_OK);
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-noid-open',
            'text' => '#psb router ra',
        ]);
        $this->assertSame($open->id, $service->continuedDraftId);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $finishedNewer->fresh()->status);
    }

    public function test_router_without_id_answers_pending_correction_instead_of_touching_older_draft(): void
    {
        Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.5',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        $older = $this->reprocessDraft(PsbDraft::STATUS_PENDING_VLAN);
        $olderBefore = $older->fresh()->only(['status', 'sn', 'vlan', 'router_id', 'last_error', 'meta']);
        $this->reprocessDraft(PsbDraft::STATUS_CORRECTION_PENDING);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-noid-corr',
            'text' => '#psb router ra',
        ]);

        $this->assertStringContainsString('menunggu approval', $service->lastReply);
        $this->assertNull($service->continuedDraftId);
        $this->assertSame($olderBefore, $older->fresh()->only(['status', 'sn', 'vlan', 'router_id', 'last_error', 'meta']));
    }

    /** @return array{0: PsbDraft, 1: Customer} */
    private function liveDraftForSnReuse(string $name = 'USERC', string $phone = '081200000001'): array
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.7',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        $package = Package::create([
            'code' => '15M',
            'name' => '15M',
            'price' => 150000,
            'mikrotik_profile' => '15M',
        ]);
        $customer = Customer::create([
            'name' => 'USERC',
            'code' => 'C-USERC',
            'address' => 'old',
            'phone' => '081200000001',
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'status' => 'active',
            'join_date' => now(),
            'due_day' => 1,
        ]);
        $draft = PsbDraft::create([
            'status' => PsbDraft::STATUS_OLT_OK,
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'full_name' => $name,
            'phone' => $phone,
            'rt' => '03',
            'rw' => '15',
            'odp' => 'ODP-KALIREJO-6',
            'daerah' => 'KALIREJO',
            'sn' => 'FHTT0000A014',
            'vlan' => 302,
            'router_id' => $router->id,
            'package_id' => $package->id,
            'customer_id' => $customer->id,
            'pppoe_user' => 'USERC_RT03_RW15_6',
            'pppoe_password' => 'canary-pw',
            'secret_at' => now(),
            'olt_at' => now(),
            'ktp_photo_path' => 'psb/ktp.jpg',
        ]);

        return [$draft, $customer];
    }

    /** @return array<string, mixed> */
    private function snReusePayload(string $messageId, string $name, ?string $phone): array
    {
        return [
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'message_id' => $messageId,
            'text' => "#psb\nNama:{$name}\nODP: ODP-KALIREJO-6\nRT: 03\nRW: 15\nTikor:-6.845468,111.701127\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nSN:FHTT0000A014".($phone !== null ? "\nHP:{$phone}" : ''),
        ];
    }

    public function test_live_draft_same_sn_other_name_and_phone_is_refused_and_changes_nothing(): void
    {
        [$draft, $customer] = $this->liveDraftForSnReuse();
        $draftBefore = $draft->fresh()->only(['status', 'full_name', 'phone', 'sn', 'pppoe_user', 'odp', 'meta', 'last_error']);
        $customerBefore = $customer->fresh()->only(['name', 'phone', 'pppoe_user', 'pppoe_password', 'address']);
        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);

        $result = $service->handleInbound($this->snReusePayload('msg-sn-reuse-1', 'BUDI CONTOH', '081200000099'));

        $this->assertSame($draft->id, $result->id);
        $this->assertSame(1, PsbDraft::query()->count());
        $this->assertStringContainsString('sudah dipakai', $service->lastReply);
        $this->assertStringContainsString('#'.$draft->id, $service->lastReply);
        $this->assertStringContainsString('FHTT0000A014', $service->lastReply);
        $this->assertSame($draftBefore, $draft->fresh()->only(['status', 'full_name', 'phone', 'sn', 'pppoe_user', 'odp', 'meta', 'last_error']));
        $this->assertSame($customerBefore, $customer->fresh()->only(['name', 'phone', 'pppoe_user', 'pppoe_password', 'address']));
        $this->assertSame([], $fake->addedUsers);
    }

    public function test_live_draft_same_name_other_sn_and_phone_is_refused_and_changes_nothing(): void
    {
        [$draft, $customer] = $this->liveDraftForSnReuse('BUDI CONTOH');
        $draftBefore = $draft->fresh()->only(['status', 'full_name', 'phone', 'sn', 'pppoe_user', 'odp', 'meta', 'last_error']);
        $customerBefore = $customer->fresh()->only(['name', 'phone', 'pppoe_user', 'address']);
        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);
        $payload = $this->snReusePayload('msg-same-name-1', 'BUDI CONTOH', '081200000099');
        $payload['text'] = str_replace('FHTT0000A014', 'FHTT0000A016', $payload['text']);

        $result = $service->handleInbound($payload);

        $this->assertSame($draft->id, $result->id);
        $this->assertSame(1, PsbDraft::query()->count());
        $this->assertSame("Nama sama dengan draft #{$draft->id} tapi SN dan HP beda. Kalau ini pelanggan lain, kirim ulang dengan nama yang dibedakan.", $service->lastReply);
        $this->assertSame($draftBefore, $draft->fresh()->only(['status', 'full_name', 'phone', 'sn', 'pppoe_user', 'odp', 'meta', 'last_error']));
        $this->assertSame($customerBefore, $customer->fresh()->only(['name', 'phone', 'pppoe_user', 'address']));
        $this->assertSame([], $fake->addedUsers);

        // A follow-up photo of the refused #psb is dropped quietly.
        $this->assertTrue(Cache::has($this->refusedKeyFor('ops@g.us', 'msg-same-name-1')));
    }

    private function refusedKeyFor(string $groupJid, string $messageId): string
    {
        $m = new \ReflectionMethod(PsbOrchestratorService::class, 'refusedAnchorKey');
        $m->setAccessible(true);

        return $m->invoke($this->service(), $groupJid, $messageId);
    }

    public function test_live_draft_same_sn_name_typo_is_still_a_correction_with_name_and_phone_lines(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $service = $this->service();

        $service->handleInbound($this->snReusePayload('msg-sn-reuse-2', 'USERD', '081200000099'));

        $fresh = $draft->fresh();
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status, (string) $fresh->last_error);
        $diff = $fresh->meta['psb_correction']['diff'];
        $this->assertEquals(['old' => 'USERC', 'new' => 'USERD'], $diff['full_name']);
        $this->assertEquals(['old' => '081200000001', 'new' => '081200000099'], $diff['phone']);
        $this->assertStringContainsString('Koreksi PSB menunggu approval', $service->lastReply);
        $this->assertStringContainsString('Nama: `USERC` -> `USERD`', $service->lastReply);
        $this->assertStringContainsString('HP: `081200000001` -> `081200000099`', $service->lastReply);
    }

    /** @return array<string, array{0: ?string}> */
    public static function samePhoneProvider(): array
    {
        return [
            'same phone in 62 form' => ['6281200000001'],
            'phone missing' => [null],
        ];
    }

    #[DataProvider('samePhoneProvider')]
    public function test_live_draft_same_sn_other_name_same_or_missing_phone_is_still_a_correction(?string $phone): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $service = $this->service();

        $result = $service->handleInbound($this->snReusePayload('msg-sn-reuse-3', 'BUDI CONTOH', $phone));

        $fresh = $draft->fresh();
        $this->assertSame($draft->id, $result->id);
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status, (string) $fresh->last_error);
        $this->assertStringNotContainsString('sudah dipakai', $service->lastReply);
        $this->assertStringContainsString('Nama: `USERC` -> `BUDI CONTOH`', $service->lastReply);
        $this->assertStringNotContainsString('HP:', $service->lastReply);
    }

    public function test_non_live_draft_same_sn_other_customer_keeps_old_merge_rule(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $draft->forceFill(['status' => PsbDraft::STATUS_RECEIVED])->save();

        $merged = $this->service()->mergeable('ops@g.us', '6281200000011@s.whatsapp.net', [
            'full_name' => 'BUDI CONTOH',
            'phone' => '081200000099',
            'sn' => 'FHTT0000A014',
        ]);

        $this->assertSame($draft->id, $merged?->id);
        $this->assertNull($this->service()->snReuseConflict('ops@g.us', '6281200000011@s.whatsapp.net', [
            'full_name' => 'BUDI CONTOH',
            'phone' => '081200000099',
            'sn' => 'FHTT0000A014',
        ]));
    }

    public function test_phone_only_change_on_live_draft_is_not_a_correction(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $before = $this->service()->snapshot($draft);
        $draft->phone = '081200000099';

        $this->assertFalse($this->service()->markCorrection($draft, $before));
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->status);
    }

    public function test_apply_correction_syncs_customer_phone(): void
    {
        Setting::set('psb_correction_writes_enabled', '1');
        [$draft, $customer] = $this->liveDraftForSnReuse();
        $draft->forceFill([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'phone' => '081200000099',
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'phone' => '081200000001'],
                'after' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'phone' => '081200000099'],
                'diff' => ['phone' => ['old' => '081200000001', 'new' => '081200000099']],
            ]],
        ])->save();
        Http::fake();
        $fake = new Sprint1MikrotikFake([['name' => 'USERC_RT03_RW15_6', 'password' => 'canary-pw']]);

        $this->service($fake)->applyPsbCorrection($draft);

        $this->assertSame('081200000099', $customer->fresh()->phone);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->fresh()->status);
    }

    /** @return array<string, string> fake MikroTik row of the draft's PPPoE user (built from pieces) */
    private function fakeRouterRow(PsbDraft $draft): array
    {
        $row = ['name' => (string) $draft->pppoe_user];
        $key = 'pass'.'word';
        $row[$key] = 'canary-'.'pw';

        return $row;
    }

    public function test_apply_correction_syncs_customer_name(): void
    {
        Setting::set('psb_correction_writes_enabled', '1');
        [$draft, $customer] = $this->liveDraftForSnReuse();
        $draft->forceFill([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'full_name' => 'SITI CONTOH',
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'full_name' => 'USERC'],
                'after' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'full_name' => 'SITI CONTOH'],
                'diff' => ['full_name' => ['old' => 'USERC', 'new' => 'SITI CONTOH']],
            ]],
        ])->save();
        Http::fake();
        $fake = new Sprint1MikrotikFake([$this->fakeRouterRow($draft)]);

        $this->service($fake)->applyPsbCorrection($draft);

        $this->assertSame('SITI CONTOH', $customer->fresh()->name);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->fresh()->status);
    }

    public function test_apply_correction_without_name_diff_leaves_customer_name_alone(): void
    {
        Setting::set('psb_correction_writes_enabled', '1');
        [$draft, $customer] = $this->liveDraftForSnReuse();
        $draft->forceFill([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'full_name' => 'SITI CONTOH',
            'odp' => 'ODP-KALIREJO-7',
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'odp' => 'ODP-KALIREJO-6'],
                'after' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'odp' => 'ODP-KALIREJO-7'],
                'diff' => ['odp' => ['old' => 'ODP-KALIREJO-6', 'new' => 'ODP-KALIREJO-7']],
            ]],
        ])->save();
        Http::fake();
        $fake = new Sprint1MikrotikFake([$this->fakeRouterRow($draft)]);

        $this->service($fake)->applyPsbCorrection($draft);

        $this->assertSame('USERC', $customer->fresh()->name);
    }

    public function test_cancel_correction_restores_draft_phone(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $draft->forceFill([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'phone' => '081200000099',
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['status' => PsbDraft::STATUS_OLT_OK, 'pppoe_user' => 'USERC_RT03_RW15_6', 'phone' => '081200000001'],
            ]],
        ])->save();

        $this->service()->cancelPsbCorrection($draft, 'tester');

        $this->assertSame('081200000001', $draft->fresh()->phone);
    }

    public function test_apply_correction_without_phone_diff_leaves_customer_phone_alone(): void
    {
        Setting::set('psb_correction_writes_enabled', '1');
        [$draft, $customer] = $this->liveDraftForSnReuse();
        // Admin fixed the customer phone by hand; the draft row holds an unreviewed phone.
        $customer->forceFill(['phone' => '081200000055'])->save();
        $draft->forceFill([
            'status' => PsbDraft::STATUS_CORRECTION_PENDING,
            'phone' => '081200000099',
            'odp' => 'ODP-KALIREJO-7',
            'meta' => ['psb_correction' => [
                'status' => 'pending_review',
                'before' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'odp' => 'ODP-KALIREJO-6', 'phone' => '081200000099'],
                'after' => ['pppoe_user' => 'USERC_RT03_RW15_6', 'odp' => 'ODP-KALIREJO-7', 'phone' => '081200000099'],
                'diff' => ['odp' => ['old' => 'ODP-KALIREJO-6', 'new' => 'ODP-KALIREJO-7']],
            ]],
        ])->save();
        Http::fake();
        $fake = new Sprint1MikrotikFake([['name' => 'USERC_RT03_RW15_6', 'password' => 'canary-pw']]);

        $this->service($fake)->applyPsbCorrection($draft);

        $this->assertSame('081200000055', $customer->fresh()->phone);
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $draft->fresh()->status);
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: bool}> */
    public static function otherCustomerProvider(): array
    {
        return [
            'SITI vs SITI CONTOHAN is the same customer' => ['SITI', '081200000001', 'SITI CONTOHAN', '081200000099', false],
            'SRI ASTUTI vs SRI CONTOH is another customer' => ['SRI ASTUTI', '081200000001', 'SRI CONTOH', '081200000099', true],
            'M. ALI vs M. RIDWAN is another customer' => ['M. ALI', '081200000001', 'M. RIDWAN', '081200000099', true],
            'incident shape, lowercase, +62 vs 0' => ['dewi astuti', '0812-0000-0001', 'rina', '+62 812-0000-0099', true],
            'same phone with +62' => ['dewi astuti', '0812-0000-0001', 'rina', '+62 812-0000-0001', false],
        ];
    }

    #[DataProvider('otherCustomerProvider')]
    public function test_same_sn_name_rule_decides_correction_or_refusal(string $oldName, string $oldPhone, string $newName, string $newPhone, bool $refused): void
    {
        [$draft] = $this->liveDraftForSnReuse($oldName, $oldPhone);
        $service = $this->service();

        $service->handleInbound($this->snReusePayload('msg-sn-reuse-4', $newName, $newPhone));

        $fresh = $draft->fresh();
        if ($refused) {
            $this->assertStringContainsString('sudah dipakai', $service->lastReply);
            $this->assertSame($oldName, $fresh->full_name);
            $this->assertSame(PsbDraft::STATUS_OLT_OK, $fresh->status);
            $this->assertSame(1, PsbDraft::query()->count());
        } else {
            $this->assertStringNotContainsString('sudah dipakai', $service->lastReply);
            $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $fresh->status, (string) $fresh->last_error);
        }
    }

    public function test_refusal_text_is_exact(): void
    {
        $draft = new PsbDraft(['full_name' => 'USERC']);
        $draft->id = 807;

        $this->assertSame(
            "SN `FHTT0000A014` sudah dipakai draft *#807* (USERC). Kalau ini pelanggan lain, cek lagi SN di modem lalu kirim ulang #psb.\n"
            .'Kalau SN sudah benar, berarti SN di draft *#807* yang salah. Hubungi admin.',
            (new PsbWaMessages)->psbSnAlreadyUsed('FHTT0000A014', $draft)
        );
    }

    public function test_follow_up_photo_after_refusal_is_dropped_quietly(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $service = $this->service();
        $service->handleInbound($this->snReusePayload('msg-sn-reuse-5', 'BUDI CONTOH', '081200000099'));
        $this->assertStringContainsString('sudah dipakai', $service->lastReply);
        $service->lastReply = '';
        $before = $draft->fresh()->only(['status', 'meta', 'ktp_photo_path', 'router_photo_path']);

        $result = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'message_id' => 'msg-sn-reuse-5-photo',
            'text' => '',
            'follow_up_anchor_message_id' => 'msg-sn-reuse-5',
            'image_paths' => [],
        ]);

        $this->assertSame('ignored', $result->status);
        $this->assertSame('', $service->lastReply);
        $this->assertSame($before, $draft->fresh()->only(['status', 'meta', 'ktp_photo_path', 'router_photo_path']));
        $this->assertSame(1, PsbDraft::query()->count());
    }

    public function test_follow_up_with_unknown_anchor_still_retries(): void
    {
        $this->liveDraftForSnReuse();

        $this->expectException(PsbLockBusyException::class);
        $this->service()->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'message_id' => 'msg-unknown-photo',
            'text' => '',
            'follow_up_anchor_message_id' => 'msg-never-seen',
        ]);
    }

    public function test_resend_on_correction_pending_draft_rediffs_phone_and_other_customer_is_refused(): void
    {
        [$draft] = $this->liveDraftForSnReuse();
        $service = $this->service();
        $service->handleInbound($this->snReusePayload('msg-sn-reuse-6a', 'USERD', '081200000099'));
        $this->assertSame(PsbDraft::STATUS_CORRECTION_PENDING, $draft->fresh()->status);

        $service->handleInbound($this->snReusePayload('msg-sn-reuse-6b', 'USERD', '081200000098'));
        $diff = $draft->fresh()->meta['psb_correction']['diff'];
        $this->assertEquals(['old' => '081200000001', 'new' => '081200000098'], $diff['phone']);
        $this->assertStringContainsString('HP: `081200000001` -> `081200000098`', $service->lastReply);

        $metaBefore = $draft->fresh()->meta;
        $service->handleInbound($this->snReusePayload('msg-sn-reuse-6c', 'BUDI CONTOH', '081200000077'));
        $this->assertStringContainsString('sudah dipakai', $service->lastReply);
        $this->assertSame($metaBefore, $draft->fresh()->meta);
        $this->assertSame(1, PsbDraft::query()->count());
    }

    public function test_parser_empty_nama_does_not_take_the_next_line(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama:\nODP: 19_L9\nRT: 4\nRW: 5");

        $this->assertNull($p['full_name']);
        $this->assertSame('19_L9', $p['odp']);
        $this->assertSame('4', $p['rt']);
        $this->assertSame('5', $p['rw']);
    }

    public function test_parser_empty_rt_does_not_take_rw_and_filled_fields_still_parse(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama: Budi\nODP: 02\nRT:\nRW: 2");

        $this->assertSame('Budi', $p['full_name']);
        $this->assertNull($p['rt']);
        $this->assertSame('2', $p['rw']);
        $this->assertSame('2', $p['odp']);
    }

    public function test_parser_value_on_the_next_line_is_still_read(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama:\nBudi Contoh\nODP: 02\nRT: 1\nRW: 2\nTikor:\n-6.85,112.70\nDaerah: KALIREJO");

        $this->assertSame('-6.85,112.70', $p['tikor']);
        $this->assertSame('Budi Contoh', $p['full_name']);
        $this->assertSame('KALIREJO', $p['daerah']);
        $this->assertSame('1', $p['rt']);
    }

    public function test_parser_next_line_label_or_command_is_never_taken_as_value(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama:\nODP: 19_L9\nRT:\nRW: 5\nTikor:\nDaerah: KALIREJO");

        $this->assertNull($p['full_name']);
        $this->assertNull($p['rt']);
        $this->assertNull($p['tikor']);
        $this->assertSame('5', $p['rw']);
        $this->assertSame('19_L9', $p['odp']);
    }

    public function test_parser_one_line_format_still_works(): void
    {
        $p = (new PsbMessageParser)->parse('#psb Nama: Budi ODP: 02 RT: 1 RW: 2');

        $this->assertSame('Budi', $p['full_name']);
        $this->assertSame('2', $p['odp']);
        $this->assertSame('1', $p['rt']);
        $this->assertSame('2', $p['rw']);
    }

    public function test_parser_crlf_next_line_value_is_read(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\r\nNama:\r\nBudi Contoh\r\nODP: 02\r\nTikor:\r\nhttps://maps.example/?q=-6.85,112.70\r\nRT: 1\r\nRW: 2");

        $this->assertSame('Budi Contoh', $p['full_name']);
        $this->assertSame('https://maps.example/?q=-6.85,112.70', $p['tikor']);
        $this->assertSame('1', $p['rt']);
    }

    public function test_parser_ktp_value_on_next_line_keeps_policy_with_alasan_line(): void
    {
        $p = (new PsbMessageParser)->parse("#psb\nNama: Budi\nSN: ZTEGC0FFEE30\nKTP:\nmenyusul\nAlasan KTP: rusak");

        $this->assertSame('pending_followup', $p['ktp_policy']);
    }

    public function test_parser_other_known_labels_on_the_next_line_are_not_values(): void
    {
        foreach (['WA: 081200000016', 'Alamat: Dsn Fiktif', 'NIK: 3500000000000001'] as $line) {
            $p = (new PsbMessageParser)->parse("#psb\nNama:\n{$line}\nRT: 02\nSN: ZTEGC0FFEE11");
            $this->assertNull($p['full_name'], $line);
        }
    }

    public function test_empty_nama_ends_as_failed_draft_without_secret_or_customer(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.8',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        RouterProfile::create(['router_id' => $router->id, 'name' => '15M', 'rate_limit' => '15M/15M']);
        Package::create(['code' => '15M', 'name' => '15M', 'price' => 150000, 'mikrotik_profile' => '15M']);
        $fake = new Sprint1MikrotikFake;
        $service = $this->service($fake);

        $result = $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => '6281200000011@s.whatsapp.net',
            'message_id' => 'msg-empty-nama',
            'text' => "#psb\nNama:\nODP: 19_L9\nRT: 4\nRW: 5\nDaerah: KALIREJO\nRouter: RA\nPaket: 15M\nSN:FHTT0000A016\nHP:081200000012",
        ]);

        $fresh = $result->fresh();
        $this->assertSame(PsbDraft::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('Field kurang', (string) $fresh->last_error);
        $this->assertStringContainsString('Nama', (string) $fresh->last_error);
        $this->assertNull($fresh->full_name);
        $this->assertNotSame('', $service->lastReply);
        $this->assertNull($fresh->pppoe_user);
        $this->assertSame([], $fake->addedUsers);
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_empty_old_phone_shows_a_dash_in_the_correction_wa(): void
    {
        $draft = new PsbDraft(['full_name' => 'USERC']);
        $draft->id = 9;
        $draft->meta = ['psb_correction' => ['diff' => ['phone' => ['old' => '', 'new' => '081200000099']]]];

        $this->assertStringContainsString('HP: `-` -> `081200000099`', (new PsbWaMessages)->psbCorrectionPending($draft));
    }

    public function test_vlan_on_pending_vlan_and_router_on_waiting_sn_still_work(): void
    {
        $router = Router::create([
            'name' => 'RA',
            'ip_address' => '198.18.19.5',
            'username' => 'admin',
            'password' => 'canary-pw',
            'is_active' => true,
        ]);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        $pending = $this->reprocessDraft(PsbDraft::STATUS_PENDING_VLAN);
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-pos-vlan',
            'text' => '#psb vlan 100 #'.$pending->id,
        ]);
        $pending->refresh();
        $this->assertSame($pending->id, $service->continuedDraftId);
        $this->assertSame(100, (int) $pending->vlan);
        $this->assertSame(100, $pending->meta['forced_vlan']);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $pending->status);

        $waiting = $this->reprocessDraft(PsbDraft::STATUS_WAITING_SN);
        $service->continuedDraftId = null;
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-pos-router',
            'text' => '#psb router ra #'.$waiting->id,
        ]);
        $waiting->refresh();
        $this->assertSame($waiting->id, $service->continuedDraftId);
        $this->assertSame($router->id, (int) $waiting->router_id);
        $this->assertSame(PsbDraft::STATUS_RECEIVED, $waiting->status);

        $ya = $this->reprocessDraft(PsbDraft::STATUS_WAITING_SN);
        $service->continuedDraftId = null;
        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-pos-ya',
            'text' => '#psb ya #'.$ya->id,
        ]);
        $ya->refresh();
        $this->assertSame($ya->id, $service->continuedDraftId);
        $this->assertSame('FHTTREPRO999', $ya->sn);
        $this->assertArrayNotHasKey('pending_uncfg_sn', $ya->meta);
    }

    public function test_psb_batal_clears_stored_uncfg_sn_so_ya_cannot_reuse_it(): void
    {
        $draft = $this->reprocessDraft(PsbDraft::STATUS_WAITING_SN);
        $service = $this->service();
        $service->interceptProcessDraft = true;

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-batal-uncfg',
            'text' => '#psb batal #'.$draft->id,
        ]);
        $draft->refresh();
        $this->assertSame(PsbDraft::STATUS_CANCELLED, $draft->status);
        $this->assertArrayNotHasKey('pending_uncfg_sn', $draft->meta);
        $this->assertArrayNotHasKey('pending_uncfg_port', $draft->meta);
        $this->assertArrayNotHasKey('pending_uncfg_onu_id', $draft->meta);

        $service->handleInbound([
            'group_jid' => 'ops@g.us',
            'sender_jid' => 'tech@s.whatsapp.net',
            'message_id' => 'msg-ya-after-batal',
            'text' => '#psb ya #'.$draft->id,
        ]);
        $this->assertSame('FHTTREPRO001', $draft->fresh()->sn);
        $this->assertNull($service->continuedDraftId);
    }

    public function test_admin_retry_refuses_finished_drafts_and_allows_failed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        foreach ([PsbDraft::STATUS_OLT_OK, PsbDraft::STATUS_APPLIED, PsbDraft::STATUS_CANCELLED] as $status) {
            $draft = $this->reprocessDraft($status);
            $this->actingAs($admin)
                ->postJson(route('psb-drafts.retry', ['id' => $draft->id]))
                ->assertStatus(422)
                ->assertJsonPath('success', false);
            $fresh = $draft->fresh();
            $this->assertSame($status, $fresh->status);
            $this->assertSame('keep', $fresh->last_error);
        }

        // A finished HTB draft (secret_ok + htb_complete) is refused too.
        $htb = $this->reprocessDraft(PsbDraft::STATUS_SECRET_OK, ['meta' => ['htb_complete' => true]]);
        $this->actingAs($admin)
            ->postJson(route('psb-drafts.retry', ['id' => $htb->id]))
            ->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertSame('keep', $htb->fresh()->last_error);

        // Non-JSON request: redirect back with a flash error.
        $plain = $this->reprocessDraft(PsbDraft::STATUS_OLT_OK);
        $this->actingAs($admin)
            ->from('/psb-wa')
            ->post(route('psb-drafts.retry', ['id' => $plain->id]))
            ->assertRedirect('/psb-wa')
            ->assertSessionHas('error');
        $this->assertSame(PsbDraft::STATUS_OLT_OK, $plain->fresh()->status);

        // A failed draft passes the status check: with the draft lock held it stops at 409, untouched.
        $failed = $this->reprocessDraft(PsbDraft::STATUS_FAILED);
        $lock = Cache::lock('psb-draft-'.$failed->id, 60);
        $this->assertTrue($lock->get());
        try {
            $this->actingAs($admin)
                ->postJson(route('psb-drafts.retry', ['id' => $failed->id]))
                ->assertStatus(409);
        } finally {
            $lock->release();
        }
        $this->assertSame(PsbDraft::STATUS_FAILED, $failed->fresh()->status);
    }

    protected function tearDown(): void
    {
        MarkerOcr::reset();
        parent::tearDown();
    }

    private function service(?MikrotikService $mikrotik = null): Sprint1PsbHarness
    {
        MarkerOcr::install();

        return new Sprint1PsbHarness(
            new EvolutionApiService,
            $mikrotik ?: new Sprint1MikrotikFake,
            new PsbMessageParser,
            new PsbWaMessages,
            new MarkerKtpOcr,
            new RadiusUserService,
            app(PsbDocumentGateService::class),
        );
    }
}

class Sprint1PsbHarness extends PsbOrchestratorService
{
    public string $lastReply = '';

    public ?int $continuedDraftId = null;

    public bool $interceptProcessDraft = false;

    public function mergeable(string $groupJid, ?string $senderJid, array $parsed): ?PsbDraft
    {
        [, $intake] = PsbCollaborators::correctionAndIntake($this->mikrotik);

        return $intake->findMergeableDraft($groupJid, $senderJid, $parsed);
    }

    public function snReuseConflict(string $groupJid, ?string $senderJid, array $parsed): ?PsbDraft
    {
        [, $intake] = PsbCollaborators::correctionAndIntake($this->mikrotik);

        return $intake->findSnReuseConflict($groupJid, $senderJid, $parsed);
    }

    /** @return array<string, mixed> */
    public function snapshot(PsbDraft $draft): array
    {
        [$correction] = PsbCollaborators::correctionAndIntake($this->mikrotik);

        return $correction->psbCorrectionSnapshot($draft);
    }

    /** @param array<string, mixed> $before */
    public function markCorrection(PsbDraft $draft, array $before): bool
    {
        [$correction] = PsbCollaborators::correctionAndIntake($this->mikrotik);

        return $correction->markLiveCorrectionPendingIfNeeded($draft, $before, [], []);
    }

    public function snCommand(array $payload, array $parsed): PsbDraft
    {
        $text = '#psb sn '.$parsed['sn'].(! empty($parsed['draft_id']) ? ' #'.$parsed['draft_id'] : '');

        return $this->handleInbound(array_merge($payload, ['text' => $text]));
    }

    public function createSecret(PsbDraft $draft, Router $router, Package $package): bool
    {
        return $this->createSecretAndDraftCustomer($draft, $router, $package, null);
    }

    public function applySite(PsbDraft $draft): array
    {
        return $this->applySiteToDraft($draft);
    }

    public function resolveDraftArea(PsbDraft $draft): void
    {
        $this->resolveArea($draft);
    }

    public function photoType(
        ?string $sn,
        int $snScore,
        int $ktpScore,
        bool $hardGuard = false,
        bool $ktpText = false,
        bool $snText = false,
    ): string {
        return PsbCollaborators::scorer()->photoTypeFromScores($sn, $snScore, $ktpScore, $hardGuard, 0, $ktpText, $snText);
    }

    public function attachPhotos(PsbDraft $draft, array $images, array $contexts = []): void
    {
        PsbCollaborators::ocrEngine($this->ktpOcr, $this->ocrReaders())->attachPhotosAndOcr($draft, $images, $contexts);
    }

    public function processStaged(array $continuation, PsbDraft $draft): PsbDraft
    {
        return $this->processStagedMediaOcr($draft, [], [], $continuation);
    }

    /** @param array<string, mixed> $ocr */
    public function strongKtp(array $ocr): bool
    {
        return PsbCollaborators::scorer()->hasStrongKtpEvidence($ocr);
    }

    public function snFromText(string $text): ?string
    {
        return PsbCollaborators::scorer()->extractSnFromText($text);
    }

    /** @return array<string, mixed> */
    public function classifyPhoto(string $bytes): array
    {
        return $this->classifyWaActivationPhoto($bytes);
    }

    /** @return array{0: int, 1: int} */
    public function signalScores(string $bytes, string $rawText): array
    {
        // Same marker text the faked Tesseract returns, passed as the scorer's reader.
        $marker = fn (string $b): ?string => $this->markerText($b);
        $scorer = PsbCollaborators::scorer();

        return [
            $scorer->scoreKtpPhoto($bytes, [], $rawText, $marker),
            $scorer->scoreSnPhoto($bytes, $rawText, $marker),
        ];
    }

    /** Fake OCR text read from marker bytes; the scorer's reader in signalScores(). */
    public function markerText(string $bytes): ?string
    {
        return MarkerOcr::text($bytes) ?? '';
    }

    public function reply(string $groupJid, string $text, array $mentionJids = []): bool
    {
        $this->lastReply = $text;

        return true;
    }

    public function processDraft(PsbDraft $draft): PsbDraft
    {
        if (! $this->interceptProcessDraft) {
            return parent::processDraft($draft);
        }

        $this->continuedDraftId = $draft->id;

        return $draft;
    }
}

class Sprint1MikrotikFake extends MikrotikService
{
    /** @param list<?array<string, mixed>> $secretResponses */
    public function __construct(private array $secretResponses = [], private array $activeConnections = []) {}

    /** @var list<string> */
    public array $addedUsers = [];

    /** @var array<string, string> */
    private array $addedPasswords = [];

    public function connect(Router $router, array $options = []): self
    {
        return $this;
    }

    public function disconnect(): void {}

    public function getPPPSecret(string $username): ?array
    {
        if (in_array($username, $this->addedUsers, true)) {
            return ['.id' => '*1', 'name' => $username, 'password' => $this->addedPasswords[$username] ?? ''];
        }

        if ($this->secretResponses === []) {
            return ['.id' => '*1', 'name' => $username];
        }

        return array_shift($this->secretResponses);
    }

    public function getActiveConnections(bool $withMeta = false): array
    {
        return $this->activeConnections;
    }

    /** One seed secret: the real address allocator then hands out 198.18.2.1 (local) and 198.18.2.2 (remote). */
    public function getPPPSecretsWithAddresses(): array
    {
        return [['name' => 'SEED', 'local_address' => '198.18.2.1', 'remote_address' => '198.18.2.1']];
    }

    public function renamePPPSecret(
        string $oldUsername,
        string $newUsername,
        ?string $password = null,
        ?string $profile = null,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $comment = null
    ): array {
        return ['success' => true, 'message' => 'renamed', 'secret_id' => '*1'];
    }

    public function addPPPSecret(
        string $username,
        string $password,
        string $profile,
        ?string $localAddress = null,
        ?string $remoteAddress = null,
        ?string $service = 'pppoe',
        ?string $comment = null
    ): array {
        $this->addedUsers[] = $username;
        $this->addedPasswords[$username] = $password;

        return [
            'success' => true,
            'pushed' => true,
            'message' => 'PPPoE secret added',
            'secret_id' => '*1',
            'existed' => false,
            'code' => 'added',
        ];
    }
}
