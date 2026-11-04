<?php

namespace Tests\Feature;

use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ResolveGuidedMigrationLookupJob;
use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class GuidedMigrationLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('olt_onus')) {
            Schema::create('olt_onus', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('olt_id')->nullable();
                $table->string('onu_ref')->nullable();
                $table->string('pon_port')->nullable();
                $table->string('serial_number')->nullable();
                $table->string('onu_name')->nullable();
                $table->string('state')->nullable();
                $table->timestamp('state_collected_at')->nullable();
                $table->timestamp('inventory_collected_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_feature_is_off_and_queue_contract_cannot_disturb_psb(): void
    {
        Queue::fake();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '0');
        Cache::put('psb_wa:webhook_status', 'OK');
        $service = app(GuidedMigrationLookupService::class);

        $this->assertFalse($service->enabled());
        $this->assertFalse($service->dispatch([
            'name' => 'Pelanggan',
            'new_sn' => 'FHTTNEW12345',
            'phone' => '081200000039',
        ]));
        $this->assertContains('feature_disabled', $service->dispatchGuard(0, true)['reasons']);
        Queue::assertNothingPushed();
        Queue::assertNotPushed(ProcessCustomerMigrationWaJob::class);

        $job = new ResolveGuidedMigrationLookupJob(['name' => 'Pelanggan']);
        $this->assertSame(GuidedMigrationLookupService::QUEUE, $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(45, $job->timeout);
    }

    public function test_exact_name_and_phone_returns_high_confidence_snapshot_without_writes(): void
    {
        $customer = $this->customer([
            'name' => 'BUDI USERAT',
            'phone' => '081200000039',
            'pppoe_user' => 'USERAT_RT03_RW04_L3.1',
            'onu_serial' => 'ZTEOLD12345',
            'olt_port_label' => '1/3/2:4',
            'odp_name' => 'ODP GONDANG L3.1',
        ]);
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'excel_import',
            'message_id' => 'guided-excel-1',
            'pppoe_user' => $customer->pppoe_user,
            'full_name' => $customer->name,
            'old_sn' => 'ZTEOLD12345',
            'new_sn' => 'FHTTNEW12345',
            'old_onu_ref' => '1/3/2:4',
            'meta' => ['project' => 'starlite-akuisisi'],
        ]);
        OltOnu::create([
            'olt_id' => $customer->olt_id,
            'onu_ref' => '1/3/2:4',
            'serial_number' => 'ZTEOLD12345',
            'state' => 'offline',
            'inventory_collected_at' => now(),
        ]);
        GenieAcsDevice::create([
            'device_id' => 'guided-device-1',
            'customer_id' => $customer->id,
            'serial_number' => 'ZTEOLD12345',
            'last_inform_at' => now(),
        ]);
        $before = $this->writeCounts();

        $service = app(GuidedMigrationLookupService::class);
        $first = $service->preview([
            'name' => 'Budi Userat',
            'new_sn' => 'FHTTNEW12345',
            'phone' => '0812-0000-0039',
        ]);
        $second = $service->preview([
            'name' => 'Budi Userat',
            'new_sn' => 'FHTTNEW12345',
            'phone' => '0812-0000-0039',
        ]);

        $this->assertSame('ready_for_selection', $first['status']);
        $this->assertSame('high', $first['candidates'][0]['confidence']);
        $this->assertSame(1, $first['candidates'][0]['number']);
        $this->assertSame('USERAT_RT03_RW04_L3.1', $first['candidates'][0]['pppoe_user']);
        $this->assertSame('ZTEOLD12345', $first['candidates'][0]['old_sn']);
        $this->assertSame('1/3/2:4', $first['candidates'][0]['old_onu_ref']);
        $this->assertContains('starlite_akuisisi_confirmed', $first['candidates'][0]['sources']);
        $this->assertContains('olt_snapshot', $first['candidates'][0]['sources']);
        $this->assertContains('genieacs_snapshot', $first['candidates'][0]['sources']);
        $this->assertFalse($first['live_device_query']);
        $this->assertFalse($first['device_write']);
        $this->assertSame($first, $second, 'Preview yang sama harus idempotent.');
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_area_odp_and_rt_rw_work_without_pppoe_old_sn_or_old_onu(): void
    {
        $customer = $this->customer([
            'name' => 'TEKNISI TEST',
            'phone' => '080000000001',
            'pppoe_user' => 'TEKNISITEST_RT03_RW10_L4',
            'address' => 'RT 03 RW 10',
            'odp_name' => 'ODP GONDANG L4',
            'onu_serial' => null,
            'olt_port_label' => null,
        ]);
        $area = $customer->area->name;
        $service = app(GuidedMigrationLookupService::class);

        $byArea = $service->preview([
            'name' => 'Teknisi Test',
            'new_sn' => 'FHTTAREA1234',
            'area' => $area,
            'odp' => 'ODP GONDANG L4',
        ]);
        $byRtRw = $service->preview([
            'name' => 'Teknisi Test',
            'new_sn' => 'FHTTRTRW1234',
            'rt' => '03',
            'rw' => '10',
        ]);

        $this->assertSame('ready_for_selection', $byArea['status']);
        $this->assertSame('ready_for_selection', $byRtRw['status']);
        $this->assertSame('skipped_unknown_old_onu', $byArea['candidates'][0]['old_onu_resolution']);
        $this->assertNull($byArea['candidates'][0]['old_onu_ref']);
        $this->assertSame($customer->id, $byRtRw['candidates'][0]['customer_id']);
    }

    public function test_explicit_pppoe_and_old_sn_are_scored_and_mismatches_are_blocked(): void
    {
        $customer = $this->customer([
            'name' => 'USER BI',
            'pppoe_user' => 'USERBI_POS_PM',
            'onu_serial' => 'ZXIC00000A0E8',
            'address' => 'RT 03 RW 04',
        ]);
        $service = app(GuidedMigrationLookupService::class);

        $matched = $service->preview([
            'name' => 'USER BI',
            'pppoe_user' => 'USERBI_POS_PM',
            'old_sn' => 'ZXIC00000A0E8',
            'new_sn' => 'FHTT0000A04E',
        ]);
        $mismatch = $service->preview([
            'name' => 'USER BI',
            'pppoe_user' => 'USERBI_POS_PM',
            'old_sn' => 'ZXICDIFFERENT',
            'new_sn' => 'FHTT0000A04E',
        ]);

        $this->assertSame('ready_for_selection', $matched['status']);
        $this->assertSame($customer->id, $matched['candidates'][0]['customer_id']);
        $this->assertContains('pppoe_exact', $matched['candidates'][0]['match_reasons']);
        $this->assertContains('old_sn_exact', $matched['candidates'][0]['match_reasons']);
        $this->assertSame('needs_review', $mismatch['status']);
        $this->assertContains('old_sn_mismatch', $mismatch['candidates'][0]['conflicts']);
    }

    public function test_historical_old_sn_finds_typo_name_but_blocks_stale_current_sn(): void
    {
        $customer = $this->customer([
            'name' => 'USER BL ASTUTIK',
            'pppoe_user' => 'USERBL_RT04_RW04_03',
            'onu_serial' => 'FHTT0000A04C',
            'address' => 'RT 04 RW 04',
        ]);
        CustomerMigration::create([
            'customer_id' => $customer->id,
            'status' => CustomerMigration::STATUS_RECEIVED,
            'source' => 'excel_master',
            'message_id' => 'yuni-old-sn-evidence',
            'full_name' => 'USER BL ASTUTIK',
            'pppoe_user' => $customer->pppoe_user,
            'old_sn' => 'HWTC0000A002',
        ]);

        $result = app(GuidedMigrationLookupService::class)->preview([
            'name' => 'USER BL ASTUTI',
            'new_sn' => 'FHTT0000A039',
            'old_sn' => 'HWTC0000A002',
            'area' => 'Gondang',
            'rt' => '02',
            'rw' => '04',
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame($customer->id, $result['candidates'][0]['customer_id']);
        $this->assertContains('old_sn_historical_exact', $result['candidates'][0]['match_reasons']);
        $this->assertContains('old_sn_not_current_customer_sn', $result['candidates'][0]['conflicts']);
    }

    public function test_name_typos_return_manual_candidates_without_auto_linking(): void
    {
        $rahmad = $this->customer([
            'code' => 'TYPO-RAHMAD',
            'name' => 'USER BI',
            'pppoe_user' => 'USERBI_RT05_RW04_01',
            'address' => 'RT 05 RW 04',
            'onu_serial' => 'ZXICTYPO0001',
        ]);
        $this->customer([
            'code' => 'EXACT-BUT-WRONG-RAHMAT',
            'name' => 'USER BJ',
            'pppoe_user' => 'USERBJ_RT01_RW01_01',
            'address' => 'RT 01 RW 01',
            'onu_serial' => 'ZXICOTHER001',
        ]);
        $widya = $this->customer([
            'code' => 'TYPO-WIDYA',
            'name' => 'USER BK LESTARI',
            'pppoe_user' => 'USERBK_RT02_RW03_01',
            'address' => 'RT 02 RW 03',
        ]);
        $service = app(GuidedMigrationLookupService::class);

        $rahmat = $service->preview([
            'name' => 'USER BJ',
            'new_sn' => 'FHTTTYPO0001',
            'old_sn' => 'ZXICTYPO0001',
            'rt' => '05',
            'rw' => '04',
        ]);
        $windya = $service->preview([
            'name' => 'USER BKN LESTARI',
            'new_sn' => 'FHTTTYPO0002',
            'rt' => '02',
            'rw' => '03',
        ]);

        $this->assertSame('needs_review', $rahmat['status']);
        $this->assertSame($rahmad->id, $rahmat['candidates'][0]['customer_id']);
        $this->assertContains('name_typo_candidate', $rahmat['candidates'][0]['match_reasons']);
        $this->assertTrue($rahmat['candidates'][0]['requires_manual_selection']);
        $this->assertSame('needs_review', $windya['status']);
        $this->assertSame($widya->id, $windya['candidates'][0]['customer_id']);
    }

    public function test_onu_name_and_olt_can_preview_unknown_customer_read_only(): void
    {
        $olt = Olt::firstOrCreate(
            ['code' => 'RA'],
            ['name' => 'OLT RA', 'vendor' => 'zte_c300']
        );
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/3/8:9',
            'pon_port' => '1/3/8',
            'serial_number' => 'FHTTOLDKANTOR',
            'onu_name' => 'KANTOR_DAPUR',
            'state' => 'online',
            'inventory_collected_at' => now(),
        ]);
        $before = $this->writeCounts();

        $result = app(GuidedMigrationLookupService::class)->preview([
            'onu_name' => 'KANTOR_DAPUR',
            'new_sn' => 'FHTT0000A032',
            'olt' => 'RA',
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertContains('onu_snapshot_found_without_customer', $result['reasons']);
        $this->assertSame('KANTOR_DAPUR', $result['candidates'][0]['pppoe_user']);
        $this->assertSame('FHTTOLDKANTOR', $result['candidates'][0]['old_sn']);
        $this->assertSame('1/3/8:9', $result['candidates'][0]['old_onu_ref']);
        $this->assertContains('customer_not_linked', $result['candidates'][0]['warnings']);
        $this->assertFalse($result['live_device_query']);
        $this->assertFalse($result['device_write']);
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_duplicate_names_are_limited_and_require_operator_selection(): void
    {
        foreach (range(1, 6) as $index) {
            $this->customer([
                'code' => 'DUP-'.$index,
                'name' => 'NAMA SAMA',
                'phone' => '0800000000'.$index,
                'pppoe_user' => 'NAMASAMA'.$index.'_RT02_RW07_01',
                'address' => 'RT 02 RW 07',
            ]);
        }

        $result = app(GuidedMigrationLookupService::class)->preview([
            'name' => 'NAMA SAMA',
            'new_sn' => 'FHTTDUPL1234',
            'rt' => '2',
            'rw' => '7',
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertContains('candidate_ambiguous', $result['reasons']);
        $this->assertCount(5, $result['candidates']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($result['candidates'], 'number'));
        $this->assertSame('#migrasi pilih #ID <nomor kandidat>', $result['next_action']);
    }

    public function test_stale_snapshots_and_conflicting_new_sn_are_review_only(): void
    {
        $target = $this->customer([
            'name' => 'TARGET MIGRASI',
            'phone' => '081111111111',
            'pppoe_user' => 'TARGET_RT01_RW02_01',
            'onu_serial' => 'ZTEOLDTARGET',
            'olt_port_label' => '1/1/1:1',
        ]);
        OltOnu::create([
            'olt_id' => $target->olt_id,
            'onu_ref' => '1/1/1:1',
            'serial_number' => 'ZTEOLDTARGET',
            'state' => 'offline',
            'inventory_collected_at' => now()->subHours(4),
        ]);
        $other = $this->customer([
            'code' => 'OTHER-1',
            'name' => 'PELANGGAN LAIN',
            'phone' => '081200000047',
            'pppoe_user' => 'OTHER_RT09_RW09_01',
            'onu_serial' => 'FHTTCONFLICT1',
        ]);

        $result = app(GuidedMigrationLookupService::class)->preview([
            'name' => 'TARGET MIGRASI',
            'new_sn' => 'FHTTCONFLICT1',
            'phone' => '081111111111',
        ]);

        $this->assertSame('needs_review', $result['status']);
        $this->assertContains('source_conflict', $result['reasons']);
        $this->assertContains('new_sn_already_linked_to_other_customer', $result['candidates'][0]['conflicts']);
        $this->assertTrue($result['candidates'][0]['freshness']['olt_snapshot']['stale']);
        $this->assertSame($other->id, Customer::where('onu_serial', 'FHTTCONFLICT1')->value('id'));
        $this->assertFalse($result['live_device_query']);
    }

    public function test_guided_mode_does_not_implicitly_reuse_recent_sender_migration(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, 'same-technician');
        $recent = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => 'activation-test',
            'sender_jid' => 'same-technician',
            'message_id' => 'recent-other-customer',
            'full_name' => 'CUSTOMER A',
            'new_sn' => 'FHTT0000A007',
        ]);
        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod($service, 'findOrCreateMigration');
        $method->setAccessible(true);

        $resolved = $method->invoke($service, [
            'group_jid' => 'activation-test',
            'sender_jid' => 'same-technician',
        ], [
            'migration_id' => null,
            'pppoe_user' => null,
            'full_name' => 'CUSTOMER B',
            'new_sn' => 'FHTT0000A008',
        ]);

        $this->assertFalse($resolved->exists);
        $this->assertNotSame($recent->id, $resolved->id);
        $this->assertSame(1, CustomerMigration::count());
    }

    public function test_operator_preview_endpoint_is_global_admin_read_only_and_masks_serial(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '0');
        $customer = $this->customer([
            'name' => 'OPERATOR PREVIEW',
            'phone' => '081234567899',
            'onu_serial' => 'ZTESECRET1234',
        ]);
        $before = $this->writeCounts();
        $noc = User::factory()->create(['role' => 'noc']);

        $response = $this->actingAs($noc)->postJson(route('customer-migrations.guided-lookup.preview'), [
            'name' => 'Operator Preview',
            'new_sn' => 'FHTTNEW99999',
            'phone' => '081234567899',
        ]);

        $response->assertOk()
            ->assertJsonPath('readonly', true)
            ->assertJsonPath('feature_enabled', false)
            ->assertJsonPath('queue.consumer_enabled', false)
            ->assertJsonPath('candidates.0.customer_id', $customer->id)
            ->assertJsonPath('candidates.0.old_sn', 'ZTES***1234')
            ->assertJsonPath('device_write', false)
            ->assertJsonPath('customer_write', false);
        $this->assertStringNotContainsString('ZTESECRET1234', $response->getContent());
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_operator_preview_scoped_allowlist_defaults_off_and_blocks_unlisted_global_admin(): void
    {
        $customer = $this->customer([
            'name' => 'ALLOWLIST PREVIEW',
            'phone' => '081200000029',
        ]);
        $noc = User::factory()->create(['role' => 'noc']);
        $admin = User::factory()->create(['role' => 'admin']);
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $payload = [
            'name' => 'Allowlist Preview',
            'new_sn' => 'FHTTALLOW001',
            'phone' => '081200000029',
        ];
        $before = $this->writeCounts();

        $this->actingAs($noc)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertOk()
            ->assertJsonPath('candidates.0.customer_id', $customer->id);

        Setting::set(GuidedMigrationLookupService::ALLOWED_ROLES, 'noc');
        $this->actingAs($admin)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertForbidden();
        $this->actingAs($noc)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertOk();

        Setting::set(GuidedMigrationLookupService::ALLOWED_ROLES, '');
        Setting::set(GuidedMigrationLookupService::ALLOWED_USER_IDS, (string) $superadmin->id);
        $this->actingAs($noc)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertForbidden();
        $this->actingAs($superadmin)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertOk();

        $this->assertSame($before, $this->writeCounts());
    }

    public function test_wa_guided_lookup_requires_sender_or_group_allowlist_even_when_global_flag_is_on(): void
    {
        Queue::fake();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set('migration_guided_lookup_host_safe', '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, '');
        Cache::put('psb_wa:webhook_status', 'OK');
        $service = app(GuidedMigrationLookupService::class);
        $payload = [
            'name' => 'WA Allowlist',
            'new_sn' => 'FHTTWAALLOW1',
            'phone' => '081200000030',
            'group_jid' => 'aktivasi@g.us',
            'sender_jid' => '628111111111@s.whatsapp.net',
        ];

        $this->assertFalse($service->canUseWa($payload));
        $this->assertContains('wa_sender_or_group_not_allowlisted', $service->waAllowlistGuard($payload)['reasons']);
        $this->assertFalse($service->dispatch($payload));
        Queue::assertNothingPushed();

        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '628111111111');
        $this->assertTrue($service->canUseWa($payload));

        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'aktivasi@g.us');
        $this->assertTrue($service->canUseWa($payload));
    }

    public function test_operator_preview_rejects_technician_and_keeps_two_operator_requests_isolated(): void
    {
        $firstCustomer = $this->customer([
            'name' => 'OPERATOR SATU',
            'phone' => '081111111101',
        ]);
        $secondCustomer = $this->customer([
            'name' => 'OPERATOR DUA',
            'phone' => '081111111102',
        ]);
        $before = $this->writeCounts();
        $firstNoc = User::factory()->create(['role' => 'noc']);
        $secondNoc = User::factory()->create(['role' => 'superadmin']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $payload = ['name' => 'OPERATOR SATU', 'new_sn' => 'FHTTPARALLEL1', 'phone' => '081111111101'];

        $this->actingAs($technician)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertForbidden();
        $first = $this->actingAs($firstNoc)
            ->postJson(route('customer-migrations.guided-lookup.preview'), $payload)
            ->assertOk();
        $second = $this->actingAs($secondNoc)
            ->postJson(route('customer-migrations.guided-lookup.preview'), [
                'name' => 'OPERATOR DUA',
                'new_sn' => 'FHTTPARALLEL2',
                'phone' => '081111111102',
            ])
            ->assertOk();

        $this->assertSame($firstCustomer->id, $first->json('candidates.0.customer_id'));
        $this->assertSame($secondCustomer->id, $second->json('candidates.0.customer_id'));
        $this->assertNotSame($first->json('idempotency_key'), $second->json('idempotency_key'));
        $this->assertSame($before, $this->writeCounts());
    }

    public function test_operator_can_approve_guided_candidate_db_only_with_audit(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_ROLES, 'noc,superadmin');
        $noc = User::factory()->create(['role' => 'noc']);
        $customer = $this->customer([
            'name' => 'APPROVAL DB ONLY',
            'phone' => '081200000034',
            'pppoe_user' => 'APPROVAL_RT03_RW04_L1',
            'onu_serial' => 'ZTEAPPROVAL1',
            'olt_port_label' => '1/3/1:7',
            'odp_name' => 'ODP GONDANG L1',
        ]);
        $before = $this->writeCounts();

        $this->actingAs($noc)
            ->post(route('customer-migrations.guided-lookup.approve'), [
                'name' => 'Approval DB Only',
                'new_sn' => 'FHTTAPPROVAL1',
                'phone' => '081200000034',
                'candidate_number' => 1,
                'reason' => 'Operator memilih kandidat hasil preview',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $migration = CustomerMigration::where('source', 'guided_lookup_operator')->firstOrFail();
        $approval = $migration->meta['guided_operator_approval'];
        $this->assertSame($before['customers'], Customer::count());
        $this->assertSame($before['psb_drafts'], Schema::hasTable('psb_drafts') ? DB::table('psb_drafts')->count() : 0);
        $this->assertSame($before['device_actions'], Schema::hasTable('device_action_logs') ? DB::table('device_action_logs')->count() : 0);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
        $this->assertSame($customer->id, $migration->customer_id);
        $this->assertSame('APPROVAL_RT03_RW04_L1', $migration->pppoe_user);
        $this->assertSame('ZTEAPPROVAL1', $migration->old_sn);
        $this->assertSame('FHTTAPPROVAL1', $migration->new_sn);
        $this->assertSame('confirmed_pending_approval', $migration->meta['migration_review']['match_status']);
        $this->assertSame('confirmed_db_only', $approval['status']);
        $this->assertSame($noc->id, $approval['actor_id']);
        $this->assertSame('Operator memilih kandidat hasil preview', $approval['reason']);
        $this->assertStringStartsWith('guided-approval:', $approval['idempotency_key']);
        $this->assertStringContainsString('tidak ada customer/perangkat yang diubah', $approval['rollback_hint']);
        $this->assertFalse($approval['device_write']);
        $this->assertFalse($approval['customer_write']);
        $this->assertSame('ZTEAPPROVAL1', $customer->fresh()->onu_serial);
    }

    public function test_operator_guided_approval_is_idempotent(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        $noc = User::factory()->create(['role' => 'noc']);
        $this->customer([
            'name' => 'APPROVAL IDEMPOTENT',
            'phone' => '081200000035',
        ]);
        $payload = [
            'name' => 'Approval Idempotent',
            'new_sn' => 'FHTTIDEMPOT1',
            'phone' => '081200000035',
            'candidate_number' => 1,
            'reason' => 'Operator approve ulang aman',
        ];

        $this->actingAs($noc)->post(route('customer-migrations.guided-lookup.approve'), $payload)
            ->assertSessionHasNoErrors();
        $first = CustomerMigration::where('source', 'guided_lookup_operator')->firstOrFail();
        $this->actingAs($noc)->post(route('customer-migrations.guided-lookup.approve'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertSame(1, CustomerMigration::where('source', 'guided_lookup_operator')->count());
        $this->assertSame($first->message_id, CustomerMigration::where('source', 'guided_lookup_operator')->first()->message_id);
    }

    public function test_operator_guided_approval_requires_flag_reason_and_rbac(): void
    {
        $customer = $this->customer([
            'name' => 'APPROVAL GUARDED',
            'phone' => '081200000036',
        ]);
        $noc = User::factory()->create(['role' => 'noc']);
        $technician = User::factory()->create(['role' => 'teknisi']);
        $payload = [
            'name' => 'Approval Guarded',
            'new_sn' => 'FHTTGUARDED1',
            'phone' => '081200000036',
            'candidate_number' => 1,
            'reason' => 'Operator approve aman',
        ];

        $this->actingAs($noc)
            ->post(route('customer-migrations.guided-lookup.approve'), $payload)
            ->assertSessionHasErrors('guided_lookup');

        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        $this->actingAs($technician)
            ->post(route('customer-migrations.guided-lookup.approve'), $payload)
            ->assertForbidden();
        $this->actingAs($noc)
            ->post(route('customer-migrations.guided-lookup.approve'), array_merge($payload, ['reason' => 'pendek']))
            ->assertSessionHasErrors('reason');

        $this->assertSame($customer->id, Customer::where('phone', '081200000036')->value('id'));
        $this->assertSame(0, CustomerMigration::where('source', 'guided_lookup_operator')->count());
    }

    public function test_operator_guided_approval_rejects_conflicting_candidate(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        $noc = User::factory()->create(['role' => 'noc']);
        $target = $this->customer([
            'name' => 'APPROVAL CONFLICT',
            'phone' => '081200000037',
        ]);
        $this->customer([
            'code' => 'CONFLICT-OWNER',
            'name' => 'OWNER SN LAIN',
            'phone' => '081200000038',
            'onu_serial' => 'FHTTCONFLICTX',
        ]);

        $this->actingAs($noc)
            ->post(route('customer-migrations.guided-lookup.approve'), [
                'name' => 'Approval Conflict',
                'new_sn' => 'FHTTCONFLICTX',
                'phone' => '081200000037',
                'candidate_number' => 1,
                'reason' => 'Operator mencoba kandidat conflict',
            ])
            ->assertSessionHasErrors('guided_lookup');

        $this->assertSame($target->id, Customer::where('phone', '081200000037')->value('id'));
        $this->assertSame(0, CustomerMigration::where('source', 'guided_lookup_operator')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function customer(array $overrides = []): Customer
    {
        $index = Customer::withTrashed()->count() + 1;
        $area = Area::firstOrCreate(['code' => 'AREA-TEST'], ['name' => 'Gondang']);
        $package = Package::firstOrCreate(
            ['code' => 'MIG-GUIDED'],
            ['name' => 'Migrasi Guided', 'price' => 150000]
        );
        $olt = Olt::firstOrCreate(
            ['code' => 'OLT-GUIDED'],
            ['name' => 'OLT Guided', 'vendor' => 'zte_c300', 'area_id' => $area->id]
        );

        return Customer::create(array_merge([
            'code' => 'GUIDED-'.$index,
            'name' => 'PELANGGAN '.$index,
            'phone' => '08000000000'.$index,
            'address' => 'RT 01 RW 01',
            'pppoe_user' => 'GUIDED'.$index.'_RT01_RW01_01',
            'package_id' => $package->id,
            'area_id' => $area->id,
            'olt_id' => $olt->id,
            'status' => 'active',
        ], $overrides));
    }

    /**
     * @return array<string, int>
     */
    private function writeCounts(): array
    {
        return [
            'customers' => Customer::count(),
            'migrations' => CustomerMigration::count(),
            'psb_drafts' => Schema::hasTable('psb_drafts') ? DB::table('psb_drafts')->count() : 0,
            'device_actions' => Schema::hasTable('device_action_logs') ? DB::table('device_action_logs')->count() : 0,
        ];
    }
}
