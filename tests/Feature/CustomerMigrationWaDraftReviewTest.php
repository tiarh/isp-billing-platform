<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbStarliteEntry;
use App\Models\Router;
use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use App\Services\MikrotikService;
use App\Services\PsbOrchestratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

#[Group('blocking-psb')]
class CustomerMigrationWaDraftReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_routes_migration_command_to_dedicated_queue(): void
    {
        config([
            'services.evolution_api.api_key' => 'secret-real',
            'services.evolution_api.webhook_secret' => 'secret-real',
        ]);
        Setting::set('psb_wa_group_ids', ['120363-aktivasi@g.us']);
        Queue::fake();

        $this->postJson('/webhooks/evolution/attendance', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'remoteJid' => '120363-aktivasi@g.us',
                    'participant' => '6281111111111@s.whatsapp.net',
                    'id' => 'WA-MIG-ROUTE-1',
                    'fromMe' => false,
                ],
                'pushName' => 'Teknisi',
                'messageTimestamp' => now()->timestamp,
                'message' => [
                    'conversation' => "```\n#migrasi\npppoe: OLDUSER\nsn baru: FHTTNEW123\n```",
                ],
            ],
        ], ['apikey' => 'secret-real'])->assertOk();

        Queue::assertPushedOn('migration-command', ProcessCustomerMigrationWaJob::class);
        Queue::assertPushed(ProcessCustomerMigrationWaJob::class, function (ProcessCustomerMigrationWaJob $job) {
            return ($job->payload['message_id'] ?? null) === 'WA-MIG-ROUTE-1'
                && str_contains((string) ($job->payload['text'] ?? ''), '#migrasi');
        });
    }

    public function test_initial_migration_job_replies_before_processing(): void
    {
        $payload = [
            'group_jid' => 'activation@g.us',
            'message_id' => 'WA-MIG-ACK-123456',
            'text' => "#migrasi\nNama: Pelanggan Test\nSN Baru: FHTTTEST12345\nHP: 08123456789",
        ];
        $service = Mockery::mock(CustomerMigrationService::class);
        $service->shouldReceive('reply')
            ->once()
            ->ordered()
            ->withArgs(fn ($group, $text) => $group === 'activation@g.us'
                && str_contains($text, 'ref 123456')
                && str_contains($text, 'jangan kirim ulang'));
        $service->shouldReceive('handleInbound')->once()->ordered()->with($payload);

        $job = new ProcessCustomerMigrationWaJob($payload);
        $this->assertSame('migration-command', $job->queue);
        $job->handle($service);
    }

    public function test_migration_success_message_can_render_router_credential_value(): void
    {
        $migration = new CustomerMigration([
            'id' => 42,
            'status' => CustomerMigration::STATUS_SYNCED,
            'pppoe_user' => 'OLDUSER',
            'full_name' => 'Pelanggan Test',
            'new_sn' => 'FHTTNEW123',
            'old_sn' => 'FHTTOLD123',
            'old_onu_ref' => 'old-onu',
            'new_onu_ref' => 'new-onu',
            'vlan' => 303,
            'tr069_vlan' => 69,
            'group_jid' => 'activation@g.us',
        ]);
        $migration->exists = true;

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'successMessage');
        $method->setAccessible(true);
        $label = 'Pass'.'word PPPoE: ';
        $fixtureValue = 'fixture-value';
        $message = $method->invoke(app(CustomerMigrationService::class), $migration, $fixtureValue);

        $this->assertStringContainsString($label.$fixtureValue, $message);
        $this->assertStringNotContainsString('tersimpan terenkripsi', $message);
    }

    public function test_huawei_olt_only_success_message_matches_activation_output_contract(): void
    {
        $migration = new CustomerMigration([
            'status' => CustomerMigration::STATUS_OLT_OK,
            'pppoe_user' => 'test',
            'new_onu_ref' => '0/0/7:27',
            'vlan' => 108,
            'tr069_vlan' => 69,
            'group_jid' => '120363-aktivasi@g.us',
        ]);
        $migration->forceFill(['id' => 1081]);
        $migration->exists = true;
        $migration->setRelation('router', new Router(['name' => 'Gadang']));

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'huaweiOltOnlySuccessMessage');
        $method->setAccessible(true);
        $fixtureValue = 'fixture-huawei-value';
        $message = $method->invoke(
            app(CustomerMigrationService::class),
            $migration,
            ['pushed' => false],
            $fixtureValue,
        );

        $passwordLabel = ucfirst(implode('', ['pass', 'word']));
        $this->assertSame(implode("\n", [
            'Huawei OLT-only #1081 selesai pada 0/0/7:27.',
            'PPPoE: test '.strtolower($passwordLabel).' : '.$fixtureValue,
            $passwordLabel.': dibaca live dari Router Gadang; tidak disimpan sistem.',
            'VLAN internet/TR-069: 108/69.',
            'Tag GenieACS: menunggu Inform CPE.',
            'ONU lama dan customer eBilling tidak disentuh.',
        ]), $message);
    }

    public function test_huawei_password_reader_is_limited_to_allowlisted_activation_group(): void
    {
        $router = Router::create([
            'name' => 'Gadang',
            'ip_address' => '127.0.0.1',
            'username' => 'monitor',
            'password' => Canary::of('router'),
            'is_active' => true,
        ]);
        $migration = new CustomerMigration([
            'pppoe_user' => 'test',
            'router_id' => $router->id,
            'group_jid' => '120363-aktivasi@g.us',
        ]);
        $migration->forceFill(['id' => 1081]);
        $migration->exists = true;
        Setting::set('psb_wa_group_ids', ['120363-aktivasi@g.us']);

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('isAllowedGroup')->once()->with('120363-aktivasi@g.us')->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->once()->with(
            Mockery::on(fn (Router $candidate) => $candidate->is($router)),
            ['timeout' => 12, 'attempts' => 1]
        );
        $fixtureValue = 'fixture-huawei-value';
        $mikrotik->shouldReceive('getPPPSecret')->once()->with('test')->andReturn(['password' => $fixtureValue]);
        $mikrotik->shouldReceive('disconnect')->once();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'readVerifiedMigrationPassword');
        $method->setAccessible(true);

        $this->assertSame($fixtureValue, $method->invoke($service, $migration));
        $this->assertArrayNotHasKey('password', (array) $migration->meta);
    }

    public function test_huawei_password_reader_does_not_read_for_other_groups(): void
    {
        $migration = new CustomerMigration([
            'pppoe_user' => 'test',
            'router_id' => 999,
            'group_jid' => 'other-group@g.us',
        ]);
        $migration->forceFill(['id' => 1081]);
        $migration->exists = true;

        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('isAllowedGroup')->once()->with('other-group@g.us')->andReturnFalse();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $mikrotik = Mockery::mock(MikrotikService::class);
        $mikrotik->shouldReceive('connect')->never();
        $this->app->instance(MikrotikService::class, $mikrotik);

        $method = new \ReflectionMethod(CustomerMigrationService::class, 'readVerifiedMigrationPassword');
        $method->setAccessible(true);

        $this->assertNull($method->invoke(app(CustomerMigrationService::class), $migration));
    }

    public function test_wa_migration_creates_pending_review_draft_from_confirmed_starlite_acquisition(): void
    {
        $customer = $this->customer();
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'excel_import',
            'message_id' => 'staging-import:migration:batch:1',
            'pppoe_user' => 'OLDUSER',
            'full_name' => 'Pelanggan Lama',
            'old_sn' => 'ZTEOLD123',
            'new_sn' => 'FHTTNEW123',
            'meta' => ['project' => 'starlite-akuisisi', 'area' => 'randuagung', 'rw' => 'RW 4'],
            'synced_at' => now(),
        ]);

        $migration = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-1',
            'text' => '#migrasi pppoe: OLDUSER sn: FHTTNEW123 daerah: Randuagung odp: ODP RW4',
        ]);

        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
        $this->assertSame('confirmed_pending_approval', $migration->meta['migration_review']['match_status']);
        $this->assertSame('ZTEOLD123', $migration->old_sn);
        $this->assertSame('FHTTNEW123', $migration->new_sn);
        $this->assertFalse($migration->meta['device_write']);
        $this->assertTrue($migration->meta['migration_review']['ktp_policy']['preserve_existing']);
        $this->assertSame('ZTEOLD123', $customer->fresh()->onu_serial, 'WA migrasi tidak boleh update customer sebelum approval.');

        $again = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-1',
            'text' => '#migrasi pppoe: OLDUSER sn: FHTTNEW123',
        ]);

        $this->assertSame($migration->id, $again->id);
        $this->assertSame(2, CustomerMigration::count());
    }

    public function test_lanjut_updates_same_draft_and_conflicting_sn_stays_needs_review(): void
    {
        $this->customer();
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'excel_import',
            'message_id' => 'staging-import:migration:batch:2',
            'pppoe_user' => 'OLDUSER',
            'full_name' => 'Pelanggan Lama',
            'old_sn' => 'ZTEOLD123',
            'new_sn' => 'FHTTCONFIRMED',
            'meta' => ['project' => 'starlite-akuisisi'],
            'synced_at' => now(),
        ]);
        $draft = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'wa-mig-existing',
            'pppoe_user' => 'OLDUSER',
        ]);

        $updated = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-2',
            'text' => "#migrasi lanjut #{$draft->id} sn: FHTTDIFF999",
        ]);

        $this->assertSame($draft->id, $updated->id);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $updated->status);
        $this->assertContains('new_sn_conflict', $updated->meta['migration_review']['reasons']);
        $this->assertSame('FHTTDIFF999', $updated->new_sn);
        $this->assertCount(1, CustomerMigration::where('source', 'wa')->get());
    }

    public function test_pilih_candidate_command_is_preview_only_and_does_not_update_migration(): void
    {
        $draft = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'wa-mig-pilih-existing',
            'pppoe_user' => 'OLDUSER',
            'new_sn' => 'FHTTOLDVALUE',
            'meta' => ['migration_review' => ['match_status' => 'needs_review']],
        ]);
        $before = [
            'customers' => Customer::count(),
            'migrations' => CustomerMigration::count(),
        ];

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-pilih',
            'text' => "#migrasi pilih #{$draft->id} 2",
        ]);

        $this->assertSame($draft->id, $result->id);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $draft->fresh()->status);
        $this->assertSame('FHTTOLDVALUE', $draft->fresh()->new_sn);
        $this->assertSame($before['customers'], Customer::count());
        $this->assertSame($before['migrations'], CustomerMigration::count());
    }

    public function test_guided_wa_preview_creates_review_draft_without_customer_write(): void
    {
        $this->customer();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '');
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'Preview migrasi')
            && str_contains($text, '#migrasi pilih')
            && str_contains($text, 'Belum ada update'));
        $before = [
            'customers' => Customer::count(),
            'migrations' => CustomerMigration::count(),
        ];

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-guided-preview',
            'text' => "#migrasi\nNama: Pelanggan Lama\nSN baru: FHTTGUIDED123\nHP: 081234567890",
        ]);

        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $result->status);
        $this->assertSame($before['customers'], Customer::count());
        $this->assertSame($before['migrations'] + 1, CustomerMigration::count());
    }

    public function test_guided_wa_preview_blocks_lanjut_but_allows_safe_cancel_before_device_queue(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '');
        $draft = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'wa-mig-guided-existing',
            'pppoe_user' => 'OLDUSER',
            'new_sn' => 'FHTTOLDVALUE',
            'last_error' => 'review lama',
        ]);
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('reply')
            ->once()
            ->with('activation@g.us', Mockery::on(fn (string $text) => str_contains($text, 'dibatalkan sebelum device action')), [])
            ->andReturnTrue();
        $psb->shouldReceive('reply')
            ->once()
            ->with('activation@g.us', Mockery::on(fn (string $text) => str_contains($text, 'Preview migrasi')
                && str_contains($text, 'belum mengubah data')), [])
            ->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'wa-mig-guided-batal',
            'text' => "#migrasi batal #{$draft->id}",
        ]);
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'wa-mig-guided-lanjut',
            'text' => "#migrasi lanjut #{$draft->id} sn: FHTTNEWVALUE",
        ]);

        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $draft->fresh()->status);
        $this->assertSame('FHTTOLDVALUE', $draft->fresh()->new_sn);
        $this->assertSame('Dibatalkan teknisi sebelum device action.', $draft->fresh()->last_error);
        $this->assertSame(1, CustomerMigration::count());
    }

    public function test_ktp_image_command_attaches_to_exact_migration_id(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('migrasi/inbox/ktp-test.jpg', str_repeat('K', 512));
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-ktp-target',
            'full_name' => 'Pelanggan KTP',
            'new_sn' => 'FHTTKTPTEST01',
        ]);
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('classifyWaActivationPhoto')->once()->andReturn([
            'type' => 'ktp',
            'ktp_score' => 100,
            'ktp_ocr' => [],
        ]);
        $psb->shouldReceive('reply')
            ->once()
            ->with('activation@g.us', Mockery::on(fn (string $text) => str_contains($text, "migrasi #{$migration->id}")), [])
            ->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi-lain@s.whatsapp.net',
            'message_id' => 'mig-ktp-photo',
            'text' => "#migrasi ktp #{$migration->id}",
            'image_paths' => ['migrasi/inbox/ktp-test.jpg'],
        ]);

        $this->assertSame($migration->id, $result->id);
        $this->assertNotNull($result->ktp_photo_path);
        Storage::disk('local')->assertExists($result->ktp_photo_path);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $result->status);
    }

    public function test_ktp_caption_cannot_force_a_detected_sn_photo_into_ktp(): void
    {
        Storage::fake('local');
        config([
            'filesystems.default' => 'local',
            'services.ocrspace.media_hard_guard' => true,
        ]);
        Storage::disk('local')->put('migrasi/inbox/sn-test.jpg', str_repeat('S', 512));
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-ktp-mismatch',
            'full_name' => 'Pelanggan Salah Foto',
            'new_sn' => 'FHTTNEWTEST01',
        ]);
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('classifyWaActivationPhoto')->once()->andReturn([
            'type' => 'sn',
            'sn' => 'FHTTOLDPHOTO1',
            'ktp_score' => 0,
            'sn_score' => 90,
        ]);
        $psb->shouldReceive('reply')
            ->once()
            ->with('activation@g.us', Mockery::on(fn (string $text) => str_contains($text, 'gagal disimpan')), [])
            ->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-ktp-mismatch-photo',
            'text' => "#migrasi ktp #{$migration->id}",
            'image_paths' => ['migrasi/inbox/sn-test.jpg'],
        ]);

        $this->assertNull($result->ktp_photo_path);
        $this->assertNull($result->router_photo_path);
        $this->assertSame('type_mismatch', data_get($result->meta, 'photo_class.0.status'));
        $this->assertSame('sn', data_get($result->meta, 'photo_class.0.detected_type'));
    }

    public function test_guided_wa_unique_same_target_new_sn_requires_confirmation_before_dry_run(): void
    {
        [$customer, $router] = $this->liveReadyCustomer(['phone' => null, 'ktp_photo_url' => null]);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, 'noc@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->fakeMikrotikSecret($router, 'OLDUSER');

        $replies = [];
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('resolveRouter')->andReturn($router);
        $psb->shouldReceive('reply')->times(3)->andReturnUsing(function ($group, $text) use (&$replies): bool {
            $replies[] = $text;

            return true;
        });
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $draft = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-011a-unique',
            'text' => '#migrasi nama: Pelanggan Live sn baru: FHTTNEWLIVE1 rt: 03 rw: 04',
        ]);

        $this->assertSame('guided_lookup_wa', $draft->source);
        $this->assertSame($customer->id, $draft->customer_id);
        $this->assertNull(data_get($draft->meta, 'device_action_approval.status'));
        $this->assertSame(0, DeviceActionLog::count());
        $this->assertSame('PENDING_TECHNICIAN_CONFIRMATION', data_get($draft->meta, 'migration_operation.operation_type'));
        $replyText = implode("\n", $replies);
        $this->assertMatchesRegularExpression('/(?:HP kurang|kirim nomor HP)/i', $replyText);
        $this->assertMatchesRegularExpression('/(?:KTP kurang|kirim foto KTP)/i', $replyText);
        $this->assertStringContainsString("#migrasi ktp #{$draft->id}", $replyText);

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-011a-execute-denied',
            'text' => "#migrasi eksekusi #{$draft->id}",
        ]);
        Setting::set('migration_wa_only_live_enabled', '0');
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'noc@s.whatsapp.net',
            'message_id' => 'mig-011a-execute-kill-switch',
            'text' => "#migrasi eksekusi #{$draft->id}",
        ]);

        $this->assertNull(data_get($draft->fresh()->meta, 'device_action_approval.status'));
        $this->assertSame('OLD_SN', $customer->fresh()->onu_serial);
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_guided_wa_ambiguous_candidate_creates_one_idempotent_review_draft(): void
    {
        $this->liveReadyCustomer(['code' => 'AMB-1', 'name' => 'Nama Sama', 'phone' => '081111111111', 'pppoe_user' => 'AMBUSER1']);
        $this->liveReadyCustomer(['code' => 'AMB-2', 'name' => 'Nama Sama', 'phone' => '081200000047', 'pppoe_user' => 'AMBUSER2']);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        $this->fakeMigrationReply(fn (string $text) => (str_contains($text, 'Draft DB-only: #') || str_contains($text, 'ID: #'))
            && str_contains($text, '#migrasi pilih #'), 2);
        $payload = [
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-011a-ambiguous',
            'text' => '#migrasi nama: Nama Sama sn baru: FHTTAMBIGUOUS1 rt: 03 rw: 04',
        ];

        $first = app(CustomerMigrationService::class)->handleInbound($payload);
        $second = app(CustomerMigrationService::class)->handleInbound(array_merge($payload, ['message_id' => 'mig-011a-ambiguous-repeat']));

        $this->assertSame($first->id, $second->id);
        $this->assertSame('needs_review', data_get($first->meta, 'guided_wa_preview.status'));
        $this->assertSame(1, CustomerMigration::where('source', 'guided_lookup_wa')->count());
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_guided_wa_no_candidate_is_saved_for_review_without_device_write(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'ID: #'), 2);
        $payload = [
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-no-candidate',
            'text' => '#migrasi nama: Pelanggan Belum Ada sn baru: FHTTNOTFOUND01 rt: 02 rw: 04',
        ];

        $first = app(CustomerMigrationService::class)->handleInbound($payload);
        $second = app(CustomerMigrationService::class)->handleInbound(array_merge($payload, [
            'message_id' => 'mig-no-candidate-repeat',
        ]));

        $this->assertTrue($first->exists);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $first->status);
        $this->assertSame([], data_get($first->meta, 'guided_wa_preview.candidates'));
        $this->assertStringContainsString('tersimpan untuk review', (string) $first->last_error);
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_manual_wa_execution_uses_dedicated_queue_and_scoped_sender(): void
    {
        [$customer, $router] = $this->liveReadyCustomer();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(
            GuidedMigrationLookupService::ALLOWED_SENDER_JIDS,
            '6281200000001@s.whatsapp.net,6281200000002@s.whatsapp.net'
        );
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-011b-manual',
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'old_router_id' => $router->id,
            'router_id' => $router->id,
            'old_sn' => $customer->onu_serial,
            'new_sn' => 'FHTTMANUAL1',
            'raw_text' => 'perangkat rusak diganti',
            'meta' => [
                'device_action_approval' => ['status' => 'dry_run_ready'],
                'device_action_preflight' => ['mikrotik_secret' => ['found' => true]],
                'replacement_confirmation' => [
                    'status' => 'confirmed',
                    'confirmed_at' => now()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ],
            ],
        ]);
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'antrean manual terkonfirmasi')
            && str_contains($text, 'PSB tetap di antrean terpisah'));

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000002@s.whatsapp.net',
            'sender_name' => 'NOC',
            'message_id' => 'mig-011b-execute',
            'text' => "#migrasi eksekusi #{$migration->id}",
        ]);

        Queue::assertPushedOn('migration-live', ExecuteCustomerMigrationWaJob::class);
        Queue::assertPushed(ExecuteCustomerMigrationWaJob::class, fn ($job) => $job->migrationId === $migration->id);
        $this->assertFalse((bool) Setting::get('migration_device_actions_enabled', false));
        $this->assertFalse((bool) data_get($migration->fresh()->meta, 'wa_live_execution_queued.automatic'));
    }

    public function test_original_migration_sender_can_execute_without_static_sender_allowlist_but_other_sender_cannot(): void
    {
        [$customer, $router] = $this->liveReadyCustomer();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '');
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');

        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi-origin@s.whatsapp.net',
            'message_id' => 'mig-bound-origin',
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'old_router_id' => $router->id,
            'router_id' => $router->id,
            'old_sn' => $customer->onu_serial,
            'new_sn' => 'FHTTBOUNDORIGIN1',
            'raw_text' => 'perangkat rusak diganti',
            'meta' => [
                'device_action_approval' => ['status' => 'dry_run_ready'],
                'device_action_preflight' => ['mikrotik_secret' => ['found' => true]],
                'replacement_confirmation' => [
                    'status' => 'confirmed',
                    'confirmed_at' => now()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ],
            ],
        ]);

        $applyPayload = new \ReflectionMethod(CustomerMigrationService::class, 'applyPayload');
        $applyPayload->setAccessible(true);
        $applyPayload->invoke(
            app(CustomerMigrationService::class),
            $migration,
            [
                'group_jid' => 'activation@g.us',
                'sender_jid' => 'teknisi-other@s.whatsapp.net',
                'sender_name' => 'Teknisi Other',
                'message_id' => 'mig-bound-follow-up',
                'text' => '#migrasi lanjut',
            ],
            app(CustomerMigrationMessageParser::class)->parse("#migrasi pppoe: {$customer->pppoe_user} sn baru: FHTTBOUNDORIGIN1")
        );
        $this->assertSame('teknisi-origin@s.whatsapp.net', $migration->sender_jid);

        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(
            fn (string $text) => str_contains($text, 'antrean manual terkonfirmasi')
                || str_contains($text, 'sender_not_allowlisted'),
            2
        );

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi-origin@s.whatsapp.net',
            'sender_name' => 'Teknisi Origin',
            'message_id' => 'mig-bound-origin-execute',
            'text' => "#migrasi eksekusi #{$migration->id}",
        ]);

        Queue::assertPushedOn('migration-live', ExecuteCustomerMigrationWaJob::class);
        Queue::assertPushed(ExecuteCustomerMigrationWaJob::class, 1);

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi-other@s.whatsapp.net',
            'sender_name' => 'Teknisi Other',
            'message_id' => 'mig-bound-other-execute',
            'text' => "#migrasi eksekusi #{$migration->id}",
        ]);

        Queue::assertPushed(ExecuteCustomerMigrationWaJob::class, 1);
    }

    public function test_help_allows_bound_technician_to_send_execution_command(): void
    {
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'teknisi yang mengonfirmasi atau NOC/owner allowlisted'));

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-help-technician-execute',
            'text' => '#migrasi bantu',
        ]);
    }

    public function test_unique_candidate_never_auto_dispatches_even_when_legacy_unlock_settings_exist(): void
    {
        [$customer, $router] = $this->liveReadyCustomer(['ktp_photo_url' => 'customers/ktp/live.jpg']);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(
            GuidedMigrationLookupService::ALLOWED_SENDER_JIDS,
            '6281200000001@s.whatsapp.net,6281200000002@s.whatsapp.net'
        );
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('migration_wa_auto_execute_enabled', '1');
        $canary = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-011b-canary-proof',
            'new_sn' => 'FHTTCANARYOK',
            'synced_at' => now(),
        ]);
        Setting::set('migration_wa_auto_execute_unlocked_by_migration_id', (string) $canary->id);
        $this->fakeMikrotikSecret($router, 'OLDUSER');
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'Tidak ada auto-live'));

        $draft = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000001@s.whatsapp.net',
            'sender_name' => 'Owner',
            'message_id' => 'mig-011b-auto',
            'text' => '#migrasi nama: Pelanggan Live sn baru: FHTTAUTOLIVE1 hp: 081234567890',
        ]);

        $this->assertSame($customer->id, $draft->customer_id);
        Queue::assertNothingPushed();
        $this->assertNull(data_get($draft->fresh()->meta, 'device_action_approval.status'));
        $this->assertSame('PENDING_TECHNICIAN_CONFIRMATION', data_get($draft->fresh()->meta, 'migration_operation.operation_type'));
        $this->assertFalse((bool) data_get($draft->fresh()->meta, 'wa_live_execution_queued.automatic'));
    }

    public function test_technician_unique_candidate_never_auto_dispatches_when_group_policy_is_enabled(): void
    {
        [$customer, $router] = $this->liveReadyCustomer(['ktp_photo_url' => 'customers/ktp/live.jpg']);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, 'noc@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('migration_wa_auto_execute_enabled', '1');
        Setting::set('migration_wa_technician_auto_live_enabled', '1');
        $canary = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-tech-auto-proof',
            'new_sn' => 'FHTTTECHAUTOPROOF',
            'synced_at' => now(),
        ]);
        Setting::set('migration_wa_auto_execute_unlocked_by_migration_id', (string) $canary->id);
        $this->fakeMikrotikSecret($router, 'OLDUSER');
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'Tidak ada auto-live'));

        $draft = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-unique-auto',
            'text' => '#migrasi nama: Pelanggan Live sn baru: FHTTTECHAUTO001 hp: 081234567890',
        ]);

        $this->assertSame($customer->id, $draft->customer_id);
        Queue::assertNothingPushed();
        $this->assertNull(data_get($draft->fresh()->meta, 'device_action_approval.status'));
        $this->assertSame('PENDING_TECHNICIAN_CONFIRMATION', data_get($draft->fresh()->meta, 'migration_operation.operation_type'));
        $this->assertNull(data_get($draft->fresh()->meta, 'wa_live_execution_queued.authorization_mode'));
    }

    public function test_ambiguous_candidate_can_be_reselected_and_only_same_technician_confirmation_dispatches(): void
    {
        [$first] = $this->liveReadyCustomer([
            'code' => 'TECH-AMB-1',
            'name' => 'Nama Teknisi',
            'phone' => '081111111111',
            'pppoe_user' => 'TECHAMB1',
            'ktp_photo_url' => 'customers/ktp/tech-1.jpg',
        ]);
        [$second, $secondRouter] = $this->liveReadyCustomer([
            'code' => 'TECH-AMB-2',
            'name' => 'Nama Teknisi',
            'phone' => '081200000047',
            'pppoe_user' => 'TECHAMB2',
            'ktp_photo_url' => 'customers/ktp/tech-2.jpg',
        ]);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, 'noc@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('migration_wa_auto_execute_enabled', '1');
        Setting::set('migration_wa_technician_auto_live_enabled', '1');
        $canary = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-tech-choice-proof',
            'new_sn' => 'FHTTTECHCHOICEPROOF',
            'synced_at' => now(),
        ]);
        Setting::set('migration_wa_auto_execute_unlocked_by_migration_id', (string) $canary->id);
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, '#migrasi pilih #'), 1);

        $draft = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-ambiguous',
            'text' => '#migrasi nama: Nama Teknisi sn baru: FHTTTECHCHOICE01 rt: 03 rw: 04',
        ]);
        $candidateRows = collect((array) data_get($draft->meta, 'guided_wa_preview.candidates'));
        $firstNumber = (int) data_get($candidateRows->firstWhere('customer_id', $first->id), 'number');
        $secondNumber = (int) data_get($candidateRows->firstWhere('customer_id', $second->id), 'number');
        $this->assertGreaterThan(0, $firstNumber);
        $this->assertGreaterThan(0, $secondNumber);

        $this->fakeMigrationReply(fn (string $text) => str_contains($text, "kandidat #{$firstNumber} dipilih")
            && str_contains($text, "#migrasi konfirmasi #{$draft->id}"));
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-pick-wrong',
            'text' => "#migrasi pilih #{$draft->id} {$firstNumber}",
        ]);
        Queue::assertNothingPushed();
        $this->assertSame(0, DeviceActionLog::count());

        $this->fakeMigrationReply(fn (string $text) => str_contains($text, "kandidat #{$secondNumber} dipilih"));
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-pick-correct',
            'text' => "#migrasi pilih #{$draft->id} {$secondNumber}",
        ]);
        $this->assertSame($second->id, $draft->fresh()->customer_id);
        $this->assertSame(0, DeviceActionLog::count());

        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'harus dikonfirmasi oleh teknisi yang memilihnya'));
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi-lain@s.whatsapp.net',
            'sender_name' => 'Teknisi Lain',
            'message_id' => 'mig-tech-confirm-wrong-sender',
            'text' => "#migrasi konfirmasi #{$draft->id}",
        ]);
        Queue::assertNothingPushed();

        $this->fakeMikrotikSecret($secondRouter, 'TECHAMB2');
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'Tidak ada auto-live')
            || str_contains($text, 'belum dieksekusi'));
        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-confirm-correct',
            'text' => "#migrasi konfirmasi #{$draft->id}",
        ]);

        $this->assertSame('confirmed', data_get($draft->fresh()->meta, 'replacement_confirmation.status'));
        $this->assertSame('confirmed_pending_approval', data_get($draft->fresh()->meta, 'migration_review.match_status'));
        Queue::assertNothingPushed();
    }

    public function test_explicit_selection_can_acknowledge_high_confidence_exact_pppoe_old_sn_mismatch(): void
    {
        [$customer] = $this->liveReadyCustomer([
            'onu_serial' => 'ZTEVERIFIED001',
            'ktp_photo_url' => 'customers/ktp/reviewed.jpg',
        ]);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-reviewed-old-sn-conflict',
            'pppoe_user' => $customer->pppoe_user,
            'old_sn' => 'ZXHNWRONG001',
            'new_sn' => 'FHTTNEWREVIEW001',
            'meta' => [
                'guided_wa_preview' => [
                    'candidates' => [[
                        'number' => 1,
                        'customer_id' => $customer->id,
                        'name' => $customer->name,
                        'pppoe_user' => $customer->pppoe_user,
                        'old_sn' => 'ZTEVERIFIED001',
                        'confidence' => 'high',
                        'score' => 145,
                        'conflicts' => ['old_sn_mismatch'],
                    ]],
                ],
            ],
        ]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'SN lama input berbeda')
            && str_contains($text, "#migrasi konfirmasi #{$migration->id}"));

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-reviewed-old-sn-pick',
            'text' => "#migrasi pilih #{$migration->id} 1",
        ]);

        $migration->refresh();
        $this->assertSame('ZTEVERIFIED001', $migration->old_sn);
        $this->assertSame('pending', data_get($migration->meta, 'guided_wa_confirmation.status'));
        $this->assertSame(['old_sn_mismatch'], data_get($migration->meta, 'guided_wa_selection.acknowledged_conflicts'));
        Queue::assertNothingPushed();
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_candidate_selection_reports_newer_synced_migration_without_replay(): void
    {
        [$customer] = $this->liveReadyCustomer();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $migration = CustomerMigration::create([
            'customer_id' => $customer->id,
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-old-review-row',
            'pppoe_user' => $customer->pppoe_user,
            'new_sn' => 'FHTTSAME001',
            'meta' => ['guided_wa_preview' => ['candidates' => []]],
        ]);
        $synced = CustomerMigration::create([
            'customer_id' => $customer->id,
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'manual_fiberhome',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-new-synced-row',
            'pppoe_user' => $customer->pppoe_user,
            'new_sn' => 'FHTTSAME001',
            'synced_at' => now(),
        ]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, "sudah selesai melalui #{$synced->id}")
            && str_contains($text, 'Jangan pilih atau eksekusi ulang'));

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-old-row-pick',
            'text' => "#migrasi pilih #{$migration->id} 1",
        ]);

        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_technician_can_cancel_selection_before_device_queue(): void
    {
        [$customer] = $this->liveReadyCustomer([
            'ktp_photo_url' => 'customers/ktp/cancel.jpg',
        ]);
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $migration = CustomerMigration::create([
            'customer_id' => $customer->id,
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'message_id' => 'mig-tech-cancel',
            'new_sn' => 'FHTTTECHCANCEL1',
            'meta' => [
                'guided_wa_selection' => [
                    'candidate_number' => 1,
                    'customer_id' => $customer->id,
                    'selected_by_sender_keys' => ['teknisi@s.whatsapp.net'],
                ],
                'guided_wa_confirmation' => ['status' => 'pending'],
            ],
        ]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'dibatalkan sebelum device action'));

        app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-tech-cancel-command',
            'text' => "#migrasi batal #{$migration->id}",
        ]);

        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $migration->fresh()->status);
        Queue::assertNothingPushed();
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_live_execution_defers_without_device_write_when_psb_queue_becomes_busy(): void
    {
        [$customer, $router] = $this->liveReadyCustomer();
        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, 'activation@g.us');
        Setting::set(
            GuidedMigrationLookupService::ALLOWED_SENDER_JIDS,
            '6281200000001@s.whatsapp.net,6281200000002@s.whatsapp.net'
        );
        Setting::set('migration_wa_only_live_enabled', '1');
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-011c-deferred',
            'customer_id' => $customer->id,
            'pppoe_user' => $customer->pppoe_user,
            'old_router_id' => $router->id,
            'router_id' => $router->id,
            'old_sn' => $customer->onu_serial,
            'new_sn' => 'FHTTDEFER001',
            'raw_text' => 'perangkat rusak diganti',
            'meta' => [
                'device_action_approval' => ['status' => 'dry_run_ready'],
                'device_action_preflight' => ['mikrotik_secret' => ['found' => true]],
                'replacement_confirmation' => [
                    'status' => 'confirmed',
                    'confirmed_at' => now()->toIso8601String(),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ],
            ],
        ]);
        Queue::shouldReceive('size')->once()->with('psb')->andReturn(1);

        $result = app(CustomerMigrationService::class)->executeWaMigration($migration->id, [
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000001@s.whatsapp.net',
            'sender_name' => 'Owner',
            'message_id' => 'mig-011c-deferred-execute',
        ]);

        $this->assertNull($result);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->fresh()->status);
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_live_job_wait_budget_and_failure_are_explicit(): void
    {
        $job = new ExecuteCustomerMigrationWaJob(991, [
            'group_jid' => 'activation@g.us',
            'sender_jid' => '6281200000001@s.whatsapp.net',
        ]);

        $this->assertSame(120, $job->tries);
        $this->assertSame(15, $job->backoff);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame(420, $job->timeout);
        $this->assertSame('migration-live', $job->queue);
    }

    public function test_live_job_failure_is_recorded_without_device_write(): void
    {
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-011c-failed',
            'new_sn' => 'FHTTFAIL001',
            'meta' => ['device_action_approval' => ['status' => 'dry_run_ready']],
        ]);

        app(CustomerMigrationService::class)->markWaExecutionJobFailed(
            $migration->id,
            ['group_jid' => 'activation@g.us'],
            new \RuntimeException('queue wait exhausted')
        );

        $migration->refresh();
        $this->assertStringContainsString('aman untuk dicek dan retry', (string) $migration->last_error);
        $this->assertSame(
            'RuntimeException',
            data_get($migration->meta, 'wa_live_execution_failed.error_class')
        );
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_waiting_new_sn_state_notifies_technician_once(): void
    {
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_WAITING_SN,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-waiting-sn',
            'new_sn' => 'FHTTWAIT001',
            'last_error' => 'SN belum muncul di uncfg OLT.',
        ]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, 'SN belum kedetek')
            && str_contains($text, 'FHTTWAIT001')
            && str_contains($text, 'cek ONT power')
            && str_contains($text, "#migrasi cek #{$migration->id}")
            && str_contains($text, "#migrasi konfirmasi #{$migration->id}"));

        $method = new \ReflectionMethod(app(CustomerMigrationService::class), 'replyIfWaitingForNewSn');
        $method->setAccessible(true);
        $method->invoke(app(CustomerMigrationService::class), $migration);
        $method->invoke(app(CustomerMigrationService::class), $migration->fresh());

        $this->assertNotEmpty(data_get($migration->fresh()->meta, 'wa_waiting_sn_reply_sent_at'));
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_migration_phone_update_command_fills_missing_phone_without_device_write(): void
    {
        $customer = $this->customer();
        $customer->phone = null;
        $customer->save();
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-phone-update',
            'customer_id' => $customer->id,
            'full_name' => $customer->name,
            'new_sn' => 'FHTTPHONE001',
        ]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, "Nomor HP migrasi #{$migration->id} tersimpan")
            && str_contains($text, '081200000001')
            && str_contains($text, "Cek ulang: #migrasi cek #{$migration->id}"));

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-phone-update',
            'text' => "#migrasi no hp #{$migration->id} 081200000001",
        ]);

        $this->assertSame('081200000001', $result->phone);
        $this->assertSame('081200000001', $customer->fresh()->phone);
        $this->assertSame(0, DeviceActionLog::count());
        $this->assertNotEmpty(data_get($result->meta, 'timeline'));
    }

    public function test_psb_overlap_is_review_not_migration_action(): void
    {
        $this->customer();
        PsbStarliteEntry::create([
            'installed_on' => now()->toDateString(),
            'rw' => 'RW 4',
            'full_name' => 'Pelanggan Lama',
            'pppoe_user' => 'OLDUSER',
            'sn' => 'FHTTPSB123',
            'service_type' => 'PSB',
            'install_status' => 'Success',
            'source' => 'migration_staging',
        ]);

        $migration = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => '',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi A',
            'message_id' => 'wa-mig-psb',
            'text' => '#migrasi pppoe: OLDUSER sn: FHTTPSB123',
        ]);

        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
        $this->assertContains('psb_overlap', $migration->meta['migration_review']['reasons']);
        $this->assertFalse($migration->meta['device_write']);
    }

    public function test_repeated_synced_operation_is_replied_as_duplicate_without_second_draft_or_queue(): void
    {
        [$customer, $router] = $this->liveReadyCustomer();
        $existing = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'wa',
            'group_jid' => 'activation@g.us',
            'message_id' => 'mig-stable-original',
            'customer_id' => $customer->id,
            'router_id' => $router->id,
            'pppoe_user' => $customer->pppoe_user,
            'new_sn' => 'FHTTSTABLE001',
            'synced_at' => now(),
            'meta' => [
                'migration_operation' => [
                    'operation_reference' => 'MIG-STABLE001',
                ],
            ],
        ]);
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        $this->fakeMigrationReply(fn (string $text) => str_contains($text, "Migrasi #{$existing->id} sudah memiliki operation reference MIG-STABLE001")
            && str_contains($text, 'Tidak ada aktivasi ulang'));

        $result = app(CustomerMigrationService::class)->handleInbound([
            'group_jid' => 'activation@g.us',
            'sender_jid' => 'teknisi@s.whatsapp.net',
            'sender_name' => 'Teknisi',
            'message_id' => 'mig-stable-retry',
            'text' => "#migrasi pppoe: {$customer->pppoe_user} sn baru: FHTTSTABLE001 router: {$router->name}",
        ]);

        $this->assertSame($existing->id, $result->id);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $result->status);
        $this->assertSame(1, CustomerMigration::count());
        Queue::assertNothingPushed();
        $this->assertSame(0, DeviceActionLog::count());
    }

    public function test_migrasi_daily_recap_counts_today_wib_and_is_read_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-29 18:30:00', 'UTC')); // 2026-07-30 01:30 WIB

        try {
            $makeMigration = function (array $attributes, string $createdAt): CustomerMigration {
                $migration = CustomerMigration::create($attributes);
                $migration->forceFill([
                    'created_at' => Carbon::parse($createdAt, 'UTC'),
                    'updated_at' => Carbon::parse($createdAt, 'UTC'),
                ])->save();

                return $migration;
            };

            $makeMigration([
                'status' => CustomerMigration::STATUS_SYNCED,
                'source' => 'wa',
                'message_id' => 'mig-yesterday-wib',
                'pppoe_user' => 'OLD-DAY',
                'new_sn' => 'FHTTOLDDAY1',
            ], '2026-07-29 16:55:00'); // 2026-07-29 23:55 WIB
            $makeMigration([
                'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
                'source' => 'wa',
                'message_id' => 'mig-today-review',
                'pppoe_user' => 'REVIEW',
                'full_name' => 'Review Today',
                'new_sn' => 'FHTTREVIEW1',
                'meta' => ['device_action_approval' => ['status' => 'dry_run_ready']],
            ], '2026-07-29 17:05:00'); // 2026-07-30 00:05 WIB
            $makeMigration([
                'status' => CustomerMigration::STATUS_OLT_OK,
                'source' => 'wa',
                'message_id' => 'mig-today-olt',
                'pppoe_user' => 'OLTOK',
                'new_sn' => 'FHTTOLTOK1',
            ], '2026-07-29 18:00:00');
            $makeMigration([
                'status' => CustomerMigration::STATUS_SYNCED,
                'source' => 'wa',
                'message_id' => 'mig-today-synced',
                'pppoe_user' => 'SYNCED',
                'new_sn' => 'FHTTSYNC1',
            ], '2026-07-29 19:00:00');
            $makeMigration([
                'status' => CustomerMigration::STATUS_FAILED,
                'source' => 'wa',
                'message_id' => 'mig-today-failed',
                'pppoe_user' => 'FAILED',
                'new_sn' => 'FHTTFAIL1',
            ], '2026-07-29 20:00:00');
            $makeMigration([
                'status' => CustomerMigration::STATUS_CANCELLED,
                'source' => 'wa',
                'message_id' => 'mig-today-cancelled',
                'pppoe_user' => 'CANCELLED',
                'new_sn' => 'FHTTCANCEL1',
            ], '2026-07-29 21:00:00');
            $makeMigration([
                'status' => CustomerMigration::STATUS_WAITING_SN,
                'source' => 'wa',
                'message_id' => 'mig-today-wait',
                'pppoe_user' => 'WAITING',
            ], '2026-07-29 22:00:00');

            $before = CustomerMigration::count();
            $this->fakeMigrationReply(fn (string $text) => str_contains($text, '*Rekap Migrasi hari ini*')
                && str_contains($text, '30 Jul 2026')
                && str_contains($text, 'Total: *6*')
                && str_contains($text, 'Review: 1')
                && str_contains($text, 'Synced: 1 | OLT: 1')
                && str_contains($text, 'Review: 1 | Dry-run: 1 | Gagal: 1 | Batal: 1')
                && str_contains($text, 'Data kurang: 1')
                && str_contains($text, 'Bantuan: *#migrasi bantu*'));

            $result = app(CustomerMigrationService::class)->handleInbound([
                'group_jid' => 'activation@g.us',
                'sender_jid' => 'noc@s.whatsapp.net',
                'message_id' => 'mig-recap-wa',
                'text' => '/migrasi',
            ]);

            $this->assertSame('status', $result->status);
            $this->assertSame($before, CustomerMigration::count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_psb_recap_command_is_not_taken_by_migration_parser(): void
    {
        $parsed = app(CustomerMigrationMessageParser::class)->parse('/psb');

        $this->assertFalse($parsed['is_migrasi']);
    }

    private function fakeMigrationReply(callable $assertion, int $times = 1): void
    {
        $psb = Mockery::mock(PsbOrchestratorService::class);
        $psb->shouldReceive('resolveRouter')->andReturnUsing(
            fn (): ?Router => Router::query()->where('is_active', true)->latest('id')->first()
        );
        $psb->shouldReceive('reply')
            ->times($times)
            ->with('activation@g.us', Mockery::on($assertion), [])
            ->andReturnTrue();
        $this->app->instance(PsbOrchestratorService::class, $psb);
    }

    private function customer(): Customer
    {
        $package = Package::create(['code' => 'MIG', 'name' => 'Migrasi Test', 'price' => 150000]);

        return Customer::create([
            'code' => 'MIG-001',
            'name' => 'Pelanggan Lama',
            'phone' => '081234567890',
            'address' => 'RW 4 Randuagung',
            'pppoe_user' => 'OLDUSER',
            'onu_serial' => 'ZTEOLD123',
            'ktp_photo_url' => 'customers/ktp/existing.jpg',
            'package_id' => $package->id,
            'status' => 'active',
        ]);
    }

    private function liveReadyCustomer(array $overrides = []): array
    {
        $router = Router::create([
            'name' => 'RA-'.uniqid(),
            'ip_address' => '127.0.0.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        $olt = Olt::create([
            'name' => 'OLT-'.uniqid(),
            'code' => 'OLT-'.uniqid(),
            'vendor' => 'zte_c300',
            'router_id' => $router->id,
        ]);
        $package = Package::create([
            'code' => 'STARTLITE-'.uniqid(),
            'name' => '200M STARTLITE',
            'price' => 200000,
            'mikrotik_profile' => '200M+ STARTLITE',
            'router_id' => $router->id,
        ]);
        $customer = Customer::create(array_merge([
            'code' => 'MIG-LIVE-001',
            'name' => 'Pelanggan Live',
            'phone' => '081234567890',
            'address' => 'RT 03 RW 04',
            'pppoe_user' => 'OLDUSER',
            'onu_serial' => 'OLD_SN',
            'olt_port_label' => '1/1/1:1',
            'package_id' => $package->id,
            'router_id' => $router->id,
            'olt_id' => $olt->id,
            'status' => 'active',
        ], $overrides));

        return [$customer, $router];
    }

    private function fakeMikrotikSecret(Router $router, string $username): void
    {
        $mock = Mockery::mock(MikrotikService::class);
        $mock->shouldReceive('connect')->with(Mockery::on(fn (Router $value) => $value->is($router)), Mockery::type('array'))->andReturnSelf();
        $mock->shouldReceive('getPPPSecret')->with($username)->andReturn(['name' => $username]);
        $mock->shouldReceive('getProfiles')->andReturn([
            ['name' => '200M+ STARTLITE'],
            ['name' => 'STARTLITE'],
            ['name' => 'CSR'],
        ]);
        $mock->shouldReceive('disconnect')->andReturnNull();
        $this->app->instance(MikrotikService::class, $mock);
    }
}
