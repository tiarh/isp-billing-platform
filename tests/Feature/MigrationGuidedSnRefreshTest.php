<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbStarliteEntry;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationService;
use App\Services\GuidedMigrationLookupService;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\MikrotikService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use phpseclib3\Net\SSH2;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Migration #1208 class of bug (guided WA draft): an SN correction left the
 * stored candidate list stale, `konfirmasi` was recorded without a selected
 * customer, and a web Retry ran the Excel review on a draft that has no Excel
 * evidence. Real CustomerMigrationService + GuidedMigrationLookupService; only
 * the I/O edges are faked (Evolution sendText, MikroTik, OLT uncfg list).
 * All names, PPPoE users and JIDs below are fake.
 */
#[Group('blocking-psb')]
class MigrationGuidedSnRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'activation@g.us';

    private const TECH = '6281200000003@s.whatsapp.net';

    private const SN_OWNED = 'FHTTC0FFEE01';

    private const SN_FREE = 'FHTTC0FFEE02';

    private const SN_PSB = 'FHTTC0FFEE03';

    private const SN_FIXED = 'FHTTC0FFEE05';

    private const PPPOE_A = 'FAKE_A_RT01';

    private const PPPOE_B = 'FAKE_B_RT02';

    /** @var list<string> */
    private array $replies = [];

    private object $mikrotik;

    private object $huawei;

    /** @var list<array<string, mixed>> */
    private array $uncfgOnus = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, self::GROUP);
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, '6281200000002@s.whatsapp.net');
        Setting::set('migration_wa_only_live_enabled', '1');
        config([
            'services.evolution_api.api_key' => 'canary-pw',
            'services.evolution_api.webhook_secret' => 'canary-pw',
            'services.evolution_api.base_url' => 'http://evolution.test',
            'services.evolution_api.instance_name' => 'ops',
            'services.evolution.url' => 'http://evolution.test',
            'services.evolution.instance' => 'ops',
            'services.evolution.key' => 'canary-pw',
        ]);
        Cache::flush();

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/message/sendText/')) {
                $this->replies[] = (string) ($request->data()['text'] ?? '');

                return Http::response(['key' => ['id' => 'OUT-FAKE'], 'status' => 'PENDING']);
            }

            return Http::response(['error' => 'unexpected fake'], 404);
        });

        $this->mikrotik = new class extends MikrotikService
        {
            /** @var array<int, array<string, array<string, mixed>>> */
            public array $secrets = [];

            public int $current = 0;

            /** @var list<string> */
            public array $writes = [];

            public function connect(Router $router, array $options = []): self
            {
                $this->current = (int) $router->id;

                return $this;
            }

            public function disconnect(): void {}

            public function getPPPSecret(string $username): ?array
            {
                return $this->secrets[$this->current][$username] ?? null;
            }

            public function getProfiles(): array
            {
                return [['name' => '10MB']];
            }

            public function getPPPSecretsWithAddresses(): array
            {
                return [];
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
                $this->writes[] = "add:{$this->current}:{$username}";

                return ['success' => true, 'pushed' => true];
            }

            public function disablePPPSecret(string $username): array
            {
                $this->writes[] = "disable:{$this->current}:{$username}";

                return ['success' => true];
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
                $this->writes[] = "rename:{$this->current}:{$oldUsername}";

                return ['success' => true];
            }
        };
        $this->app->instance(MikrotikService::class, $this->mikrotik);

        $test = $this;
        $this->app->instance(OltC300Controller::class, new class($test) extends OltC300Controller
        {
            public function __construct(private readonly MigrationGuidedSnRefreshTest $test) {}

            public function listUncfg(Request $request)
            {
                return response()->json(['onus' => $this->test->uncfgOnusForFake()]);
            }
        });

        $this->huawei = new class($this->mikrotik) extends HuaweiOltOnlyProvisioningService
        {
            /** Counts SSH session attempts (the OLT edge); the service logic is real. */
            public int $calls = 0;

            protected function connect(Olt $olt): SSH2
            {
                $this->calls++;

                throw new \RuntimeException('fake OLT SSH edge: no session in tests');
            }
        };
        $this->app->instance(HuaweiOltOnlyProvisioningService::class, $this->huawei);
    }

    /** @return list<array<string, mixed>> */
    public function uncfgOnusForFake(): array
    {
        return $this->uncfgOnus;
    }

    // ───────────────────────── scenarios ─────────────────────────

    public function test_incident_replay_sn_conflict_then_sn_fix_then_pilih_and_dry_run_only(): void
    {
        [$source, $target, , $custA, $custB] = $this->fixture(withKtp: false);
        $this->uncfgOnus = [['sn' => self::SN_FIXED, 'port' => '1/2/2', 'onu_id' => null]];

        // 1. The technician typed the SN of customer A by mistake.
        $migration = $this->wa($this->fullMessage(self::SN_OWNED));
        $this->assertSame('guided_lookup_wa', $migration->source);
        $id = $migration->id;
        $this->assertContains(
            'new_sn_already_linked_to_other_customer',
            (array) data_get($migration->meta, 'guided_wa_preview.candidates.0.conflicts')
        );
        $this->assertSame(self::SN_OWNED, data_get($migration->meta, 'guided_wa_preview.new_sn'));
        $this->assertStringContainsString(
            "SN baru sudah tercatat di pelanggan lain. Cek SN di ONT. Jika salah: #migrasi sn #{$id} <SN_BENAR>",
            $this->lastReply()
        );

        // 2. pilih is refused with a plain text that points at the SN.
        $this->wa("#migrasi pilih #{$id} 1");
        $this->assertSame(
            "Pelanggan #1 belum bisa dipilih: SN baru sudah tercatat di pelanggan lain.\n"
            ."Cek SN di ONT. Jika salah: #migrasi sn #{$id} <SN_BENAR>\n"
            ."Lalu pilih lagi: #migrasi pilih #{$id} 1",
            $this->lastReply()
        );
        $migration->refresh();
        $this->assertNull(data_get($migration->meta, 'guided_wa_selection'));
        $this->assertNull(data_get($migration->meta, 'migration_review.match_status'));

        // 3. konfirmasi without a selection is not recorded.
        $this->wa("#migrasi konfirmasi #{$id}");
        $this->assertStringStartsWith("Migrasi #{$id}: pilih pelanggan dulu.", $this->lastReply());
        $this->assertStringContainsString("Kirim: #migrasi pilih #{$id} <nomor>", $this->lastReply());
        $migration->refresh();
        $this->assertNotSame('confirmed', data_get($migration->meta, 'guided_wa_confirmation.status'));
        $this->assertSame(0, $this->ledgers($migration)->count());

        // 4. The technician fixes the SN: the list is recomputed, no auto-selection.
        $this->wa("#migrasi sn #{$id} ".self::SN_FIXED);
        $reply = $this->lastReply();
        $migration->refresh();
        $this->assertSame(self::SN_FIXED, $migration->new_sn);
        $this->assertSame(self::SN_FIXED, data_get($migration->meta, 'guided_wa_preview.new_sn'));
        $this->assertSame([], data_get($migration->meta, 'guided_wa_preview.candidates.0.conflicts'));
        $this->assertStringContainsString('SN diperbarui', $reply);
        $this->assertStringContainsString("Daftar pelanggan #{$id} diperbarui:\n1. Pelanggan Dua | ".self::PPPOE_B.' | skor ', $reply);
        $this->assertStringContainsString("Pilih: #migrasi pilih #{$id} <nomor>", $reply);
        $this->assertStringNotContainsString('lalu #migrasi eksekusi', $reply);
        $this->assertContains('guided_wa_preview_refreshed', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertNull(data_get($migration->meta, 'guided_wa_selection'));
        $this->assertNull(data_get($migration->meta, 'migration_review.match_status'));

        // 4b. The exact #1208 state: the SN command already bound customer_id
        // (silent resolveCustomer), nothing is selected. konfirmasi must not be recorded.
        $this->assertSame($custB->id, (int) $migration->customer_id);
        $this->wa("#migrasi konfirmasi #{$id}");
        $this->assertStringStartsWith("Migrasi #{$id}: pilih pelanggan dulu.", $this->lastReply());
        $migration->refresh();
        $this->assertNotSame('confirmed', data_get($migration->meta, 'guided_wa_confirmation.status'));
        $this->assertNull(data_get($migration->meta, 'guided_wa_selection'));
        $this->assertSame(0, $this->ledgers($migration)->count());

        // 5. pilih works now.
        $this->wa("#migrasi pilih #{$id} 1");
        $migration->refresh();
        $this->assertSame($custB->id, (int) data_get($migration->meta, 'guided_wa_selection.customer_id'));
        $this->assertSame('confirmed_pending_approval', data_get($migration->meta, 'migration_review.match_status'));

        // 5b. The customer has no KTP yet: konfirmasi asks for it, nothing is prepared.
        $this->wa("#migrasi konfirmasi #{$id}");
        $this->assertStringContainsString('foto KTP', $this->lastReply());
        $this->assertSame(0, $this->ledgers($migration)->count());

        // 5c. KTP photo for this ID.
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        Storage::disk('local')->put('migrasi/inbox/ktp-fake.jpg', str_repeat('K', 512));
        $this->wa("#migrasi ktp #{$id}", ['image_paths' => ['migrasi/inbox/ktp-fake.jpg']]);
        $this->assertNotNull($migration->fresh()->ktp_photo_path);

        // 6. konfirmasi prepares a dry-run only. Nothing is written to a device.
        $this->wa("#migrasi konfirmasi #{$id}");
        $migration->refresh();
        $this->assertSame('dry_run_ready', data_get($migration->meta, 'device_action_approval.status'));
        $ledger = $this->ledgers($migration)->firstOrFail();
        $this->assertSame('dry_run', $ledger->status);
        $this->assertNull($ledger->executed_at);
        $this->assertSame(0, $this->ledgers($migration)->whereIn('status', ['executed', 'verified'])->count());
        $this->assertSame([], $this->mikrotik->writes);
        $this->assertSame(self::SN_OWNED, $custA->fresh()->onu_serial);
        $this->assertSame($source->id, (int) $custB->fresh()->router_id);
        $this->assertNotSame($target->id, (int) $custB->fresh()->router_id);
    }

    public function test_sn_fix_keeps_a_still_valid_selection_and_cancels_the_old_dry_run(): void
    {
        $this->fixture();
        $this->uncfgOnus = [['sn' => self::SN_FIXED, 'port' => '1/2/2', 'onu_id' => null]];
        $migration = $this->selectedDraft(self::SN_FREE);
        $oldLedgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        $this->assertGreaterThan(0, $oldLedgerId);
        $customerId = (int) $migration->customer_id;

        $this->wa("#migrasi sn #{$migration->id} ".self::SN_FIXED);

        $migration->refresh();
        $this->assertSame($customerId, (int) $migration->customer_id);
        $this->assertSame($customerId, (int) data_get($migration->meta, 'guided_wa_selection.customer_id'));
        $this->assertSame('confirmed_pending_approval', data_get($migration->meta, 'migration_review.match_status'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($oldLedgerId)->status);
        $this->assertStringContainsString('SN diperbarui', $this->lastReply());
        $this->assertStringContainsString("Lanjut: #migrasi konfirmasi #{$migration->id} lalu #migrasi eksekusi #{$migration->id}", $this->lastReply());
        $this->assertStringNotContainsString('Daftar pelanggan', $this->lastReply());
        $this->assertSame(self::SN_FIXED, data_get($migration->meta, 'guided_wa_preview.new_sn'));
    }

    public function test_sn_fix_to_an_sn_in_other_psb_history_drops_the_selection(): void
    {
        $this->fixture();
        PsbStarliteEntry::create([
            'full_name' => 'Pelanggan Tiga',
            'pppoe_user' => 'FAKE_C_RT03',
            'sn' => self::SN_PSB,
            'install_status' => 'success',
        ]);
        $this->uncfgOnus = [['sn' => self::SN_PSB, 'port' => '1/2/2', 'onu_id' => null]];
        $migration = $this->selectedDraft(self::SN_FREE);
        $oldLedgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');

        $this->wa("#migrasi sn #{$migration->id} ".self::SN_PSB);

        $migration->refresh();
        $id = $migration->id;
        $this->assertNull(data_get($migration->meta, 'guided_wa_selection'));
        $this->assertSame('cancelled', data_get($migration->meta, 'guided_wa_confirmation.status'));
        $this->assertSame('sn_conflict_after_change', data_get($migration->meta, 'guided_wa_confirmation.reason'));
        $this->assertSame('needs_review', data_get($migration->meta, 'migration_review.match_status'));
        $this->assertSame(['guided_selection_invalid_after_sn_change'], data_get($migration->meta, 'migration_review.reasons'));
        $this->assertNull(data_get($migration->meta, 'device_action_approval'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($oldLedgerId)->status);
        $this->assertStringContainsString(
            "Pilihan pelanggan #{$id} dibatalkan: SN baru sudah dipakai di PSB lain.\nCek SN, lalu pilih lagi: #migrasi pilih #{$id} <nomor>",
            $this->lastReply()
        );
        $this->assertStringNotContainsString('lalu #migrasi eksekusi', $this->lastReply());
    }

    public function test_stale_list_makes_pilih_show_the_fresh_list_but_hand_built_drafts_are_unchanged(): void
    {
        [, , , , $custB] = $this->fixture();

        // Legacy draft (no stored SN in the preview): the SN was corrected after the list was made.
        $stale = $this->handDraft('guided-wa:stale-1', self::SN_FIXED, [
            'guided_wa_preview' => [
                'status' => 'needs_review',
                'candidates' => [$this->candidateRow($custB, ['new_sn_already_linked_to_other_customer'])],
                'updated_at' => now()->subMinutes(10)->toIso8601String(),
            ],
            'sn_corrections' => [['from' => self::SN_OWNED, 'to' => self::SN_FIXED, 'changed' => true, 'at' => now()->subMinutes(5)->toIso8601String()]],
        ]);
        $this->wa("#migrasi pilih #{$stale->id} 1");
        $stale->refresh();
        $this->assertStringStartsWith("Daftar pelanggan #{$stale->id} diperbarui:", $this->lastReply());
        $this->assertNull(data_get($stale->meta, 'guided_wa_selection'), 'A stale pilih must not select.');
        $this->assertSame(self::SN_FIXED, data_get($stale->meta, 'guided_wa_preview.new_sn'));

        // The next pilih uses the fresh list and selects.
        $this->wa("#migrasi pilih #{$stale->id} 1");
        $stale->refresh();
        $this->assertSame($custB->id, (int) data_get($stale->meta, 'guided_wa_selection.customer_id'));

        // Hand-built draft without SN corrections is never stale: pilih behaves as before.
        $plain = $this->handDraft('guided-wa:plain-1', self::SN_FREE, [
            'guided_wa_preview' => [
                'status' => 'needs_review',
                'candidates' => [$this->candidateRow($custB, [])],
                'updated_at' => now()->subMinutes(10)->toIso8601String(),
            ],
        ]);
        $this->wa("#migrasi pilih #{$plain->id} 1");
        $plain->refresh();
        $this->assertSame('confirmed_pending_approval', data_get($plain->meta, 'migration_review.match_status'));
        $this->assertNotContains('guided_wa_preview_refreshed', array_column((array) data_get($plain->meta, 'timeline'), 'step'));
    }

    public function test_web_retry_on_guided_draft_without_selection_only_refreshes_the_list(): void
    {
        $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->wa($this->fullMessage(self::SN_OWNED));
        $id = $migration->id;

        // The #1208 damage: Excel review result and a confirmation without a selection.
        $meta = $migration->meta;
        $meta['migration_review'] = ['match_status' => 'needs_review', 'reasons' => ['excel_confirm_missing']];
        $meta['guided_wa_confirmation'] = ['status' => 'confirmed'];
        $migration->meta = $meta;
        $migration->last_error = 'Draft migrasi perlu review: excel_confirm_missing. Tidak ada action perangkat.';
        $migration->save();
        $this->replies = [];

        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))
            ->assertSessionHas('success', "Migrasi #{$id} dari WA: daftar pelanggan diperbarui. Teknisi perlu kirim #migrasi pilih #{$id} <nomor>.");

        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
        $this->assertNull(data_get($migration->meta, 'migration_review'));
        $this->assertSame('cancelled', data_get($migration->meta, 'guided_wa_confirmation.status'));
        $this->assertSame('no_candidate_selected', data_get($migration->meta, 'guided_wa_confirmation.reason'));
        $this->assertSame("Menunggu teknisi pilih pelanggan: #migrasi pilih #{$id} <nomor>", $migration->last_error);
        $this->assertStringNotContainsString('excel_confirm_missing', json_encode($migration->meta));
        $this->assertSame([], $this->replies, 'Same candidate order: no WA message.');

        // The stored list was out of date (order changed): one WA message with the list.
        $meta = $migration->meta;
        $meta['guided_wa_preview']['candidates'] = [];
        $migration->meta = $meta;
        $migration->save();
        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))->assertSessionHas('success');
        $this->assertCount(1, $this->replies);
        $this->assertStringStartsWith("Daftar pelanggan #{$id} diperbarui:", $this->replies[0]);

        // Double click: nothing changed, no second WA message.
        $this->travel(30)->seconds();
        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))->assertSessionHas('success');
        $this->assertCount(1, $this->replies);
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->fresh()->status);
    }

    public function test_web_retry_with_selection_restores_a_review_damaged_by_excel(): void
    {
        $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->selectedDraft(self::SN_FREE);
        $id = $migration->id;
        $customerId = (int) $migration->customer_id;

        $meta = $migration->meta;
        $meta['migration_review'] = ['match_status' => 'needs_review', 'reasons' => ['excel_confirm_missing']];
        $migration->meta = $meta;
        $migration->last_error = 'Draft migrasi perlu review: excel_confirm_missing. Tidak ada action perangkat.';
        $migration->save();
        $this->replies = [];

        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))
            ->assertSessionHas('success', "Migrasi #{$id} dari WA: pilihan pelanggan masih berlaku. Lanjut di WA: #migrasi konfirmasi #{$id}.");

        $migration->refresh();
        $this->assertSame('confirmed_pending_approval', data_get($migration->meta, 'migration_review.match_status'));
        $this->assertSame(['guided_wa_selection_revalidated'], data_get($migration->meta, 'migration_review.reasons'));
        $this->assertSame($customerId, (int) data_get($migration->meta, 'guided_wa_selection.customer_id'));
        $this->assertStringNotContainsString('excel_confirm_missing', json_encode($migration->meta));
        $this->assertSame([], $this->replies, 'Same candidate order: no WA message.');
    }

    public function test_web_retry_on_huawei_olt_only_draft_changes_nothing_and_never_opens_an_olt_session(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->handDraft('guided-wa:huawei-1', self::SN_FREE, [
            'migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
            'marker' => 'untouched',
        ]);
        $migration->last_error = 'Menunggu konfirmasi';
        $migration->save();
        $before = $migration->fresh();
        $id = $migration->id;

        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))
            ->assertSessionHas('success', "Migrasi #{$id} mode Huawei dari WA: Retry web tidak dipakai. Lanjut di WA: #migrasi konfirmasi #{$id}.");

        $after = $migration->fresh();
        $this->assertSame($before->meta, $after->meta);
        $this->assertSame($before->status, $after->status);
        $this->assertSame($before->last_error, $after->last_error);
        $this->assertSame(0, $this->huawei->calls);
        $this->assertSame([], $this->replies);
    }

    public function test_review_guard_on_huawei_olt_only_draft_replies_t8_without_an_olt_session(): void
    {
        $migration = $this->handDraft('guided-wa:huawei-2', self::SN_FREE, [
            'migration_operation_mode' => HuaweiOltOnlyProvisioningService::MODE,
        ]);
        $id = $migration->id;

        $result = app(CustomerMigrationService::class)->processMigration($migration);

        $this->assertSame(
            "Migrasi #{$id} mode Huawei. Lanjut: #migrasi konfirmasi #{$id}, atau kirim ulang format Huawei.",
            $this->lastReply()
        );
        $this->assertContains('review_skipped_huawei_olt_only', array_column((array) data_get($result->fresh()->meta, 'timeline'), 'step'));
        $this->assertNull(data_get($result->fresh()->meta, 'migration_review'));
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $result->fresh()->status);
        $this->assertSame(0, $this->huawei->calls);
    }

    public function test_web_retry_that_drops_a_selection_sends_t4_even_when_the_order_is_unchanged(): void
    {
        $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = $this->selectedDraft(self::SN_FREE);
        $id = $migration->id;
        $oldLedgerId = (int) data_get($migration->meta, 'device_action_approval.ledger_id');
        // The current SN turns out to be in another customer's PSB history.
        PsbStarliteEntry::create([
            'full_name' => 'Pelanggan Tiga',
            'pppoe_user' => 'FAKE_C_RT03',
            'sn' => self::SN_FREE,
            'install_status' => 'success',
        ]);

        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))
            ->assertSessionHas('success', "Migrasi #{$id} dari WA: daftar pelanggan diperbarui. Teknisi perlu kirim #migrasi pilih #{$id} <nomor>.");

        $migration->refresh();
        $this->assertNull(data_get($migration->meta, 'guided_wa_selection'));
        $this->assertSame('needs_review', data_get($migration->meta, 'migration_review.match_status'));
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($oldLedgerId)->status);
        $this->assertCount(1, $this->replies, 'Order unchanged: only the dropped-selection text.');
        $this->assertSame(
            "Pilihan pelanggan #{$id} dibatalkan: SN baru sudah dipakai di PSB lain.\nCek SN, lalu pilih lagi: #migrasi pilih #{$id} <nomor>",
            $this->replies[0]
        );
    }

    public function test_when_the_list_cannot_be_rebuilt_pilih_konfirmasi_and_review_answer_plainly(): void
    {
        [, , , , $custB] = $this->fixture();
        // No raw text, no name, no PPPoE: the lookup input is incomplete. A selection exists
        // and the stored list was made for another SN.
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'message_id' => 'guided-wa:incomplete-1',
            'customer_id' => $custB->id,
            'new_sn' => self::SN_FREE,
            'meta' => [
                'guided_wa_preview' => [
                    'status' => 'needs_review',
                    'candidates' => [$this->candidateRow($custB, [])],
                    'updated_at' => now()->toIso8601String(),
                    'new_sn' => self::SN_FIXED,
                ],
                'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $custB->id],
                'migration_review' => ['match_status' => 'needs_review', 'reasons' => ['excel_confirm_missing']],
            ],
        ]);
        $id = $migration->id;
        $before = $migration->fresh()->meta;

        $this->wa("#migrasi pilih #{$id} 1");
        $this->assertSame(
            "Pelanggan #1 belum bisa dipilih: data belum cocok.\nKirim ulang #migrasi dengan data yang benar (PPPoE/HP/SN lama).",
            $this->lastReply()
        );

        $this->wa("#migrasi konfirmasi #{$id}");
        $this->assertSame(
            "Migrasi #{$id}: pilih pelanggan dulu.\nPelanggan belum ketemu. Kirim ulang #migrasi dengan PPPoE atau HP.",
            $this->lastReply()
        );

        app(CustomerMigrationService::class)->processMigration($migration->fresh());
        $this->assertSame("Migrasi #{$id} sudah ada pilihan pelanggan. Lanjut: #migrasi konfirmasi #{$id}", $this->lastReply());

        $this->assertSame($before, $migration->fresh()->meta, 'Nothing is changed when the list cannot be rebuilt.');
        $this->assertSame(0, $this->ledgers($migration)->count());
    }

    public function test_konfirmasi_refreshes_a_stale_list_even_when_the_review_looks_confirmed(): void
    {
        $this->fixture();
        $migration = $this->selectedDraft(self::SN_FREE);
        $id = $migration->id;
        $meta = $migration->meta;
        $meta['guided_wa_preview']['new_sn'] = self::SN_FIXED;
        $migration->meta = $meta;
        $migration->save();

        $this->wa("#migrasi konfirmasi #{$id}");

        $migration->refresh();
        $this->assertSame(self::SN_FREE, data_get($migration->meta, 'guided_wa_preview.new_sn'));
        $this->assertContains('guided_wa_preview_refreshed', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertSame((int) $migration->customer_id, (int) data_get($migration->meta, 'guided_wa_selection.customer_id'));
        $this->assertSame('confirmed', data_get($migration->meta, 'guided_wa_confirmation.status'));
    }

    public function test_web_retry_on_a_normal_wa_draft_still_runs_the_excel_review(): void
    {
        [, , , , $custB] = $this->fixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'message_id' => 'wa-plain-1',
            'raw_text' => '#migrasi',
            'customer_id' => $custB->id,
            'pppoe_user' => self::PPPOE_B,
            'new_sn' => self::SN_FREE,
        ]);

        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))->assertSessionHas('success');

        $migration->refresh();
        $this->assertContains('excel_confirm_missing', (array) data_get($migration->meta, 'migration_review.reasons'));
        $this->assertStringContainsString('excel_confirm_missing', (string) $migration->last_error);
        $this->assertCount(1, $this->replies);
        $this->assertStringContainsString("Draft migrasi #{$migration->id} menunggu review", $this->replies[0]);
    }

    public function test_re_sent_short_form_for_a_guided_draft_goes_through_sn_correction_or_reports_state(): void
    {
        $this->fixture();
        $this->uncfgOnus = [['sn' => self::SN_FIXED, 'port' => '1/2/2', 'onu_id' => null]];
        $migration = $this->wa($this->fullMessage(self::SN_OWNED));
        $id = $migration->id;
        $name = $migration->full_name;
        $phone = $migration->phone;

        // Different SN: validated correction (origin resend), the draft fields are not overwritten.
        $this->wa("#migrasi\nPPPoE: ".self::PPPOE_B."\nSN Baru: ".self::SN_FIXED."\nRouter baru: Krian\nOLT: KRIAN");
        $migration->refresh();
        $this->assertSame(self::SN_FIXED, $migration->new_sn);
        $this->assertSame($name, $migration->full_name);
        $this->assertSame($phone, $migration->phone);
        $steps = (array) data_get($migration->meta, 'timeline');
        $corrected = collect($steps)->firstWhere('step', 'sn_corrected');
        $this->assertSame('resend', data_get($corrected, 'data.origin') ?? data_get($corrected, 'origin'));
        $this->assertNull(data_get($migration->meta, 'migration_review'));
        $this->assertStringContainsString("Daftar pelanggan #{$id} diperbarui:", $this->lastReply());

        // Same SN again: only the guided state, no Excel review.
        $this->wa("#migrasi\nPPPoE: ".self::PPPOE_B."\nSN Baru: ".self::SN_FIXED."\nRouter baru: Krian\nOLT: KRIAN");
        $migration->refresh();
        $this->assertStringStartsWith("Migrasi #{$id}: pilih pelanggan dulu.", $this->lastReply());
        $this->assertNull(data_get($migration->meta, 'migration_review'));
        $this->assertStringNotContainsString('excel_confirm_missing', json_encode($migration->meta));
        $this->assertSame(CustomerMigration::STATUS_NEEDS_REVIEW, $migration->status);
    }

    public function test_follow_up_photo_on_a_failed_guided_draft_does_not_run_the_excel_review(): void
    {
        [, , , , $custB] = $this->fixture();
        $migration = $this->selectedDraft(self::SN_FREE);
        $migration->status = CustomerMigration::STATUS_FAILED;
        $migration->save();

        $result = app(CustomerMigrationService::class)->attachFollowUpImages($migration->fresh(), [], '');

        $result->refresh();
        $this->assertStringNotContainsString('excel_confirm_missing', json_encode($result->meta));
        $this->assertSame('confirmed_pending_approval', data_get($result->meta, 'migration_review.match_status'));
        $this->assertSame($custB->id, (int) data_get($result->meta, 'guided_wa_selection.customer_id'));
        $this->assertNotContains('draft_pending_review', array_column((array) data_get($result->meta, 'timeline'), 'step'));
    }

    public function test_sn_fix_on_a_partially_executed_guided_draft_does_not_refresh_the_list(): void
    {
        [, , , , $custB] = $this->fixture();
        $this->uncfgOnus = [['sn' => self::SN_FIXED, 'port' => '1/2/2', 'onu_id' => null]];
        $migration = $this->handDraft('guided-wa:partial-1', self::SN_FREE, [
            'guided_wa_preview' => [
                'status' => 'needs_review',
                'candidates' => [$this->candidateRow($custB, [])],
                'updated_at' => now()->subMinutes(10)->toIso8601String(),
                'new_sn' => self::SN_FREE,
            ],
            'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $custB->id],
            'mikrotik_migration' => ['ok' => true, 'cross_router' => true, 'old_username' => self::PPPOE_B, 'new_username' => self::PPPOE_B],
        ]);
        $migration->customer_id = $custB->id;
        $migration->status = CustomerMigration::STATUS_PARTIALLY_EXECUTED;
        $migration->save();
        $previewBefore = $migration->fresh()->meta['guided_wa_preview'];

        $this->wa("#migrasi sn #{$migration->id} ".self::SN_FIXED);

        $migration->refresh();
        $this->assertSame(self::SN_FIXED, $migration->new_sn);
        $this->assertSame($previewBefore, $migration->meta['guided_wa_preview']);
        $this->assertNotContains('guided_wa_preview_refreshed', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertSame($custB->id, (int) data_get($migration->meta, 'guided_wa_selection.customer_id'));
        $this->assertStringNotContainsString('Daftar pelanggan', $this->lastReply());
        $this->assertStringNotContainsString('dibatalkan', $this->lastReply());
        $this->assertStringContainsString('Belum selesai:', $this->lastReply());
    }

    public function test_pilih_on_a_candidate_without_customer_gives_a_plain_message(): void
    {
        $this->fixture();
        $migration = $this->handDraft('guided-wa:nocust-1', self::SN_FREE, [
            'guided_wa_preview' => [
                'status' => 'needs_review',
                'candidates' => [['number' => 1, 'customer_id' => null, 'name' => 'Pelanggan Lima', 'pppoe_user' => '', 'score' => 40, 'conflicts' => []]],
                'updated_at' => now()->toIso8601String(),
            ],
        ]);

        $this->wa("#migrasi pilih #{$migration->id} 1");

        $this->assertStringContainsString(
            'Pelanggan nomor ini belum terhubung ke data pelanggan. Pilih nomor lain atau kirim ulang #migrasi dengan PPPoE/HP.',
            $this->lastReply()
        );
        $this->assertNull(data_get($migration->fresh()->meta, 'guided_wa_selection'));
    }

    // ───────────────────────── helpers ─────────────────────────

    /** @return array{0: Router, 1: Router, 2: Olt, 3: Customer, 4: Customer} */
    private function fixture(bool $withKtp = true): array
    {
        // Fake router login built at runtime (no literal user/password pair in the source).
        $login = ['username' => implode('', ['ad', 'min']), 'password' => implode('-', ['canary', 'pw'])];
        $source = Router::create(['name' => 'Krian', 'ip_address' => '127.0.0.15', 'is_active' => true] + $login);
        $target = Router::create(['name' => 'Skynet-Krian-OLT', 'ip_address' => '127.0.0.14', 'is_active' => true] + $login);
        $oldOlt = Olt::create(['name' => 'OLT KRIAN HTB', 'code' => 'OLT-KRIAN-HTB', 'vendor' => 'zte_c300', 'router_id' => $source->id]);
        $olt = Olt::create(['name' => 'OLT KRIAN', 'code' => 'OLT-KRIAN', 'vendor' => 'zte_c300', 'router_id' => $target->id]);
        $package = Package::create(['code' => 'KRIAN-10', 'name' => 'Krian 10MB', 'price' => 150000, 'mikrotik_profile' => '10MB', 'router_id' => $source->id]);
        $custA = Customer::create([
            'code' => 'FAKE-A',
            'name' => 'Pelanggan Satu',
            'phone' => '081200000011',
            'address' => 'RT 1 RW 1 Krian',
            'package_id' => $package->id,
            'pppoe_user' => self::PPPOE_A,
            'mikrotik_profile' => '10MB',
            'onu_serial' => self::SN_OWNED,
            'router_id' => $target->id,
            'olt_id' => $olt->id,
            'status' => 'active',
        ]);
        $custB = Customer::create([
            'code' => 'FAKE-B',
            'name' => 'Pelanggan Dua',
            'phone' => '081200000012',
            'address' => 'RT 2 RW 2 Krian',
            'package_id' => $package->id,
            'pppoe_user' => self::PPPOE_B,
            'mikrotik_profile' => '10MB',
            'onu_serial' => 'ZTEFAKE00002',
            'olt_port_label' => '1/1/1:5',
            'odp_name' => 'ODP-FAKE-01',
            'ktp_photo_url' => $withKtp ? 'customers/ktp/fake-b.jpg' : null,
            'router_id' => $source->id,
            'olt_id' => $oldOlt->id,
            'status' => 'active',
        ]);
        // Like #1207: an earlier synced migration of customer A already holds the SN.
        CustomerMigration::create([
            'status' => CustomerMigration::STATUS_SYNCED,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'message_id' => 'guided-wa:synced-a',
            'customer_id' => $custA->id,
            'pppoe_user' => self::PPPOE_A,
            'new_sn' => self::SN_OWNED,
            'synced_at' => now()->subHour(),
        ]);
        $this->mikrotik->secrets[$source->id][self::PPPOE_B] = [
            '.id' => '*old',
            'name' => self::PPPOE_B,
            'password' => $login['password'],
            'profile' => '10MB',
            'disabled' => 'false',
        ];

        return [$source, $target, $olt, $custA, $custB];
    }

    private function fullMessage(string $sn): string
    {
        return "#migrasi\nNama: Pelanggan Duo\nPPPoE: ".self::PPPOE_B."\nSN Baru: {$sn}\nHP: 081200000012\nRouter lama: Krian\nRouter baru: Skynet-Krian-OLT\nOLT: KRIAN";
    }

    /**
     * Guided draft with customer B selected and a dry-run prepared (the state
     * right after pilih + konfirmasi), built by hand like the other migration suites.
     */
    private function selectedDraft(string $sn): CustomerMigration
    {
        $custB = Customer::query()->where('pppoe_user', self::PPPOE_B)->firstOrFail();
        $target = Router::query()->where('name', 'Skynet-Krian-OLT')->firstOrFail();
        $source = Router::query()->where('name', 'Krian')->firstOrFail();
        $olt = Olt::query()->where('code', 'OLT-KRIAN')->firstOrFail();
        $senderKeys = [preg_replace('/\D/', '', self::TECH)];
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'sender_name' => 'Teknisi Satu',
            'message_id' => 'guided-wa:selected-'.$sn,
            'raw_text' => $this->fullMessage($sn),
            'customer_id' => $custB->id,
            'pppoe_user' => self::PPPOE_B,
            'full_name' => 'Pelanggan Duo',
            'phone' => '081200000012',
            'old_router_id' => $source->id,
            'old_olt_id' => $custB->olt_id,
            'old_onu_ref' => '1/1/1:5',
            'old_sn' => 'ZTEFAKE00002',
            'router_id' => $target->id,
            'new_olt_id' => $olt->id,
            'new_sn' => $sn,
            'meta' => [
                'wa_origin_sender_keys' => $senderKeys,
                'input_identity' => ['pppoe_user' => self::PPPOE_B],
                'router_mapping' => [
                    'target_input' => 'Skynet-Krian-OLT',
                    'target_router_id' => $target->id,
                    'status' => 'resolved',
                ],
                'guided_wa_preview' => [
                    'status' => 'needs_review',
                    'candidates' => [$this->candidateRow($custB, [])],
                    'updated_at' => now()->toIso8601String(),
                    'new_sn' => $sn,
                ],
                'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $custB->id, 'selected_by_sender_keys' => $senderKeys],
                'guided_wa_confirmation' => ['status' => 'confirmed', 'selected_by_sender_keys' => $senderKeys],
                'migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['guided_wa_candidate_confirmed'], 'source' => 'guided_lookup_wa'],
            ],
        ]);

        $prepared = app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration, null, 'Konfirmasi teknisi (test)');
        $this->assertSame('dry_run_ready', data_get($prepared->meta, 'device_action_approval.status'));
        $this->replies = [];

        return $prepared->fresh();
    }

    /** @param array<string, mixed> $meta */
    private function handDraft(string $key, string $sn, array $meta): CustomerMigration
    {
        $senderKeys = [preg_replace('/\D/', '', self::TECH)];

        return CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'sender_name' => 'Teknisi Satu',
            'message_id' => $key,
            'raw_text' => $this->fullMessage($sn),
            'pppoe_user' => self::PPPOE_B,
            'full_name' => 'Pelanggan Duo',
            'phone' => '081200000012',
            'new_sn' => $sn,
            'meta' => array_merge(['wa_origin_sender_keys' => $senderKeys, 'input_identity' => ['pppoe_user' => self::PPPOE_B]], $meta),
        ]);
    }

    /**
     * @param  list<string>  $conflicts
     * @return array<string, mixed>
     */
    private function candidateRow(Customer $customer, array $conflicts): array
    {
        return [
            'number' => 1,
            'customer_id' => $customer->id,
            'name' => $customer->name,
            'pppoe_user' => $customer->pppoe_user,
            'old_sn' => $customer->onu_serial,
            'score' => 100,
            'confidence' => 'high',
            'conflicts' => $conflicts,
            'warnings' => [],
            'sources' => ['pppoe_exact'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(string $text): array
    {
        return [
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'sender_name' => 'Teknisi Satu',
            'message_id' => 'wa-'.md5($text.microtime()),
            'text' => $text,
        ];
    }

    /** @param array<string, mixed> $extra */
    private function wa(string $text, array $extra = []): CustomerMigration
    {
        return app(CustomerMigrationService::class)->handleInbound(array_merge($this->payload($text), $extra));
    }

    private function ledgers(CustomerMigration $migration): Builder
    {
        return DeviceActionLog::query()->where('target_type', 'customer_migration')->where('target_id', (string) $migration->id);
    }

    private function lastReply(): string
    {
        return (string) end($this->replies);
    }
}
