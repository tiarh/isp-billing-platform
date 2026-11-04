<?php

namespace Tests\Feature;

use App\Http\Controllers\OltC300Controller;
use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\GenieAcsService;
use App\Services\GuidedMigrationLookupService;
use App\Services\MikrotikService;
use App\Services\PsbOrchestratorService;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Incident #1102 (Krian router -> Skynet-Krian-OLT, typo SN FHTT0000A00B vs
 * real FHTT0000A00D): the first execute moved the MikroTik secret and disabled
 * the source, the OLT step missed the ONU, and every later command either
 * downgraded the state or no-op'ed silently. All devices here are fakes.
 */
#[Group('blocking-psb')]
class MigrationPartialExecutionRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP = 'activation@g.us';

    private const TECH = '6281200000003@s.whatsapp.net';

    private const NOC = '6281200000002@s.whatsapp.net';

    private const TYPO_SN = 'FHTT0000A00B';

    private const REAL_SN = 'FHTT0000A00D';

    private const PPPOE = 'USERAB27_RT1_RW2';

    /** @var list<string> */
    private array $replies = [];

    private object $mikrotik;

    /** @var list<array<string, mixed>> */
    private array $uncfgOnus = [];

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set(GuidedMigrationLookupService::FEATURE_FLAG, '1');
        Setting::set(GuidedMigrationLookupService::ALLOWED_GROUP_JIDS, self::GROUP);
        Setting::set(GuidedMigrationLookupService::ALLOWED_SENDER_JIDS, self::NOC);
        Setting::set('migration_wa_only_live_enabled', '1');
        Setting::set('control_plane.device_read_only_mode', '0');

        $psb = Mockery::mock(PsbOrchestratorService::class)->shouldIgnoreMissing();
        $psb->shouldReceive('reply')->andReturnUsing(function (string $group, string $text): bool {
            $this->replies[] = $text;

            return true;
        });
        $psb->shouldReceive('isAllowedGroup')->andReturnFalse();
        $this->app->instance(PsbOrchestratorService::class, $psb);

        $genie = Mockery::mock(GenieAcsService::class)->shouldIgnoreMissing();
        $genie->shouldReceive('resolvePreferredTag')->andReturnUsing(fn (string $pppoe): string => $pppoe);
        $genie->shouldReceive('ensureTagAfterProvision')->andReturn(['success' => true, 'pushed' => true]);
        $this->app->instance(GenieAcsService::class, $genie);

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
                $this->secrets[$this->current][$username] = [
                    '.id' => '*new',
                    'name' => $username,
                    'password' => $password,
                    'profile' => $profile,
                    'local-address' => $localAddress ?? '198.19.1.1',
                    'remote-address' => $remoteAddress ?? '198.19.1.20',
                ];

                return ['success' => true, 'pushed' => true, 'message' => 'PPPoE secret added', 'secret_id' => '*new'];
            }

            public function disablePPPSecret(string $username): array
            {
                $this->writes[] = "disable:{$this->current}:{$username}";
                $this->secrets[$this->current][$username]['disabled'] = 'true';

                return ['success' => true, 'message' => 'disabled'];
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
            public function __construct(private readonly MigrationPartialExecutionRecoveryTest $test) {}

            public function listUncfg(Request $request)
            {
                return response()->json(['onus' => $this->test->uncfgOnusForFake()]);
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function uncfgOnusForFake(): array
    {
        return $this->uncfgOnus;
    }

    public function test_incident_1102_partial_execution_is_recovered_without_repeating_mikrotik(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration, $source, $target, $olt, $customer] = $this->incidentFixture();

        // 03:22 first execute: MikroTik moved, OLT lookup misses the typo SN.
        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        Queue::assertPushed(ExecuteCustomerMigrationWaJob::class, 1);
        $this->assertStringContainsString('masuk antrean manual', $this->lastReply());
        $this->runQueuedJob($migration->id);

        $migration->refresh();
        $firstLedger = $this->ledgers($migration)->orderBy('id')->firstOrFail();
        $this->assertSame('executed', $firstLedger->status);
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $this->assertSame('executed', data_get($migration->meta, 'device_action_approval.status'));
        $this->assertTrue((bool) data_get($migration->meta, 'partial_execution.mikrotik.source_disabled'));
        $this->assertStringContainsString('MikroTik sudah dipindah ke Skynet-Krian-OLT', (string) $migration->last_error);
        $this->assertStringContainsString('menunggu ONU/SN '.self::TYPO_SN, (string) $migration->last_error);
        $this->assertSame(["add:{$target->id}:".self::PPPOE, "disable:{$source->id}:".self::PPPOE], $this->mikrotik->writes);
        $partialReply = $this->lastReply();
        $this->assertStringContainsString('SEBAGIAN DIEKSEKUSI', $partialReply);
        $this->assertStringContainsString('sudah DISABLE', $partialReply);
        $this->assertStringContainsString(
            'SN '.self::TYPO_SN.' tidak ditemukan di OLT OLT KRIAN. Maksud Anda '.self::REAL_SN.' (port 1/2/2:3)? Kirim #migrasi sn #'.$migration->id.' '.self::REAL_SN,
            $partialReply
        );
        $this->assertSame(self::TYPO_SN, $migration->new_sn, 'Typo hint must never be auto-applied.');

        // 03:26 web Retry must not downgrade to a "Tidak ada action perangkat" draft.
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('customer-migrations.retry', $migration))->assertSessionHas('error');
        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $this->assertStringNotContainsString('Tidak ada action perangkat', (string) $migration->last_error);
        $this->assertNotSame('needs_review', data_get($migration->meta, 'migration_review.match_status'));

        // `#migrasi cek` explains the partial state.
        $this->wa('#migrasi cek #'.$migration->id, self::TECH);
        $this->assertStringContainsString('sebagian dieksekusi', $this->lastReply());
        $this->assertStringContainsString('Belum selesai:', $this->lastReply());

        // 03:29 batal is refused while the customer is half-migrated.
        $this->wa('#migrasi batal #'.$migration->id, self::TECH);
        $this->assertStringContainsString('DITOLAK', $this->lastReply());
        $this->assertStringContainsString('#migrasi batal paksa #'.$migration->id, $this->lastReply());
        $this->assertStringContainsString('enable lagi secret '.self::PPPOE.' di Krian', $this->lastReply());
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);

        // 03:31 the full #migrasi re-sent with the corrected SN updates #ID.
        $migrationsBefore = CustomerMigration::query()->count();
        $this->wa("#migrasi\nPPPoE: ".self::PPPOE."\nSN Baru: ".self::REAL_SN."\nRouter lama: Krian\nRouter baru: Skynet-Krian-OLT\nOLT: KRIAN", self::TECH);
        $migration->refresh();
        $this->assertSame(self::REAL_SN, $migration->new_sn);
        $this->assertSame($migrationsBefore, CustomerMigration::query()->count(), 'A re-sent #migrasi must not create a parallel draft.');
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->status);
        $correctionReply = $this->lastReply();
        $this->assertStringContainsString('SN diperbarui', $correctionReply);
        $this->assertStringContainsString('ONU ditemukan di OLT OLT KRIAN port 1/2/2:3', $correctionReply);
        $this->assertStringContainsString("Nama ONU di OLT 'USERAB7'", $correctionReply);
        $this->assertStringContainsString('Dry-run lanjutan siap', $correctionReply);
        $this->assertSame('resend', data_get($migration->meta, 'sn_corrections.0.origin'));
        $continuation = DeviceActionLog::query()->findOrFail((int) data_get($migration->meta, 'device_action_approval.ledger_id'));
        $this->assertSame('dry_run', $continuation->status);
        $this->assertTrue((bool) data_get($continuation->payload, 'continuation'));
        $this->assertContains('olt:provision_or_link_new_onu', (array) data_get($continuation->payload, 'remaining_steps'));
        $this->assertSame('skip_already_verified', data_get($continuation->payload, 'steps.1.mode'));

        // 03:35 execute again: only the remaining steps run.
        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        Queue::assertPushed(ExecuteCustomerMigrationWaJob::class, 2);
        $this->runQueuedJob($migration->id);

        $migration->refresh();
        $customer->refresh();
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->status);
        $this->assertSame('verified', $continuation->fresh()->status);
        $this->assertTrue((bool) data_get($continuation->fresh()->result, 'continuation'));
        $this->assertSame(
            ["add:{$target->id}:".self::PPPOE, "disable:{$source->id}:".self::PPPOE],
            $this->mikrotik->writes,
            'Continuation must not repeat addPPPSecret/disablePPPSecret.'
        );
        $this->assertSame(self::REAL_SN, $customer->onu_serial);
        $this->assertSame('1/2/2:3', $customer->olt_port_label);
        $this->assertSame($target->id, (int) $customer->router_id);
        $this->assertSame($olt->id, (int) $customer->olt_id);
        $this->assertSame(self::PPPOE, OltOnu::query()->where('olt_id', $olt->id)->where('onu_ref', '1/2/2:3')->value('onu_name'));
        $this->assertTrue((bool) data_get($migration->meta, 'olt_existing_onu.name_mismatch'));
        $this->assertStringContainsString('selesai', $this->lastReply());
        $this->assertStringContainsString("nama ONU di OLT 'USERAB7'", $this->lastReply());
        $this->assertContains('mikrotik_step_skipped_already_verified', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
    }

    public function test_incident_1102_recovery_also_works_between_midnight_and_7am_wib(): void
    {
        // 01:30 WIB = 18:30 UTC of the previous day: the "today" lookup must use the WIB day window.
        $this->travelTo(Carbon::parse('2026-10-08 01:30:00', 'Asia/Jakarta'));

        $this->test_incident_1102_partial_execution_is_recovered_without_repeating_mikrotik();
    }

    public function test_execute_on_executed_ledger_replies_and_prepares_continuation_instead_of_silent_noop(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration] = $this->incidentFixture();
        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->runQueuedJob($migration->id);
        $firstLedger = $this->ledgers($migration)->orderBy('id')->firstOrFail();

        // Legacy state seen in production: approval still says dry_run_ready
        // although its ledger already executed. The queued job must not no-op.
        $migration->refresh();
        $meta = $migration->meta;
        $meta['device_action_approval']['status'] = 'dry_run_ready';
        $migration->meta = $meta;
        $migration->save();
        $before = count($this->replies);

        $this->runQueuedJob($migration->id);

        $this->assertGreaterThan($before, count($this->replies), 'Executed ledger must never be a silent no-op.');
        $reply = $this->lastReply();
        $this->assertStringContainsString('ledger #'.$firstLedger->id.') sudah berjalan, belum selesai', $reply);
        $this->assertStringContainsString('Sudah dilakukan:', $reply);
        $this->assertStringContainsString('Dry-run lanjutan siap', $reply);
        $this->assertStringContainsString('Maksud Anda '.self::REAL_SN, $reply);
        $this->assertStringContainsString('#migrasi eksekusi #'.$migration->id, $reply);
        $migration->refresh();
        $continuation = DeviceActionLog::query()->findOrFail((int) data_get($migration->meta, 'device_action_approval.ledger_id'));
        $this->assertSame($firstLedger->idempotency_key.':retry:'.$firstLedger->id, $continuation->idempotency_key);
        $this->assertSame('dry_run', $continuation->status);
        $this->assertContains('execute_skipped_ledger_already_executed', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertCount(2, $this->mikrotik->writes, 'No device write happens while preparing the continuation.');

        // Preparing again is idempotent: same continuation ledger.
        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->assertSame(2, $this->ledgers($migration)->count());
    }

    public function test_sn_command_validates_format_ownership_and_never_auto_applies_hint(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration, , , $olt, $customer] = $this->incidentFixture();
        Customer::create([
            'code' => 'OTHER-1',
            'name' => 'Pelanggan Lain',
            'pppoe_user' => 'LAINNYA_RT2_RW3',
            'onu_serial' => 'FHTT0000A00A',
            'address' => 'Krian',
            'package_id' => $customer->package_id,
            'status' => 'active',
        ]);

        $this->wa('#migrasi sn #'.$migration->id.' FHTT-XYZ', self::TECH);
        $this->assertStringContainsString('Format SN tidak valid', $this->lastReply());

        $this->wa('#migrasi sn #'.$migration->id.' FHTT0000A00A', self::TECH);
        $this->assertStringContainsString('ditolak', $this->lastReply());
        $this->assertStringContainsString('LAINNYA_RT2_RW3', $this->lastReply());
        $this->assertSame(self::TYPO_SN, $migration->fresh()->new_sn);

        $this->wa('#migrasi sn #'.$migration->id.' '.self::REAL_SN, '6289999999999@s.whatsapp.net');
        $this->assertStringContainsString('ditolak', $this->lastReply());
        $this->assertSame(self::TYPO_SN, $migration->fresh()->new_sn);

        // Unknown SN: not found, typo hint offered, SN stored but nothing applied.
        $this->wa('#migrasi sn #'.$migration->id.' FHTT0000A00C', self::TECH);
        $reply = $this->lastReply();
        $this->assertStringContainsString('belum ditemukan di OLT OLT KRIAN', $reply);
        $this->assertStringContainsString('Maksud Anda '.self::REAL_SN.' (port 1/2/2:3)?', $reply);
        $this->assertSame('FHTT0000A00C', $migration->fresh()->new_sn);
        $this->assertSame(0, $this->ledgers($migration)->whereIn('status', ['executing', 'executed', 'verified'])->count());

        // Valid owned-by-nobody SN found in the snapshot.
        $this->wa('#migrasi sn #'.$migration->id.' '.self::REAL_SN, self::TECH);
        $migration->refresh();
        $this->assertSame(self::REAL_SN, $migration->new_sn);
        $this->assertStringContainsString('ONU ditemukan di OLT OLT KRIAN port 1/2/2:3', $this->lastReply());
        $this->assertSame('sn_command', data_get($migration->meta, 'sn_corrections.1.origin'));
        $this->assertContains('sn_corrected', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertSame([], $this->mikrotik->writes);
        $this->assertSame($olt->id, (int) $migration->new_olt_id);
    }

    public function test_typo_hint_skips_onu_owned_by_another_customer(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration, , , $olt, $customer] = $this->incidentFixture();
        OltOnu::query()->where('olt_id', $olt->id)->update(['onu_name' => 'BUDI_RT9_RW9']);
        Customer::create([
            'code' => 'BUDI-1',
            'name' => 'Budi',
            'pppoe_user' => 'BUDI_RT9_RW9',
            'onu_serial' => 'ZTEG0000A003',
            'address' => 'Krian',
            'package_id' => $customer->package_id,
            'status' => 'active',
        ]);

        $this->wa('#migrasi sn #'.$migration->id.' FHTT0000A00C', self::TECH);

        $this->assertStringNotContainsString('Maksud Anda', $this->lastReply());
        $this->assertStringContainsString('belum ditemukan', $this->lastReply());
    }

    public function test_force_cancel_of_partial_migration_keeps_devices_and_explains_rollback(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration] = $this->incidentFixture();
        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->runQueuedJob($migration->id);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('customer-migrations.cancel', $migration))->assertSessionHas('error');
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);

        $this->wa('#migrasi batal paksa #'.$migration->id, '6289999999999@s.whatsapp.net');
        $this->assertStringContainsString('ditolak', $this->lastReply());
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $migration->fresh()->status);

        $this->wa('#migrasi batal paksa #'.$migration->id, self::TECH);
        $migration->refresh();
        $this->assertSame(CustomerMigration::STATUS_CANCELLED, $migration->status);
        $this->assertNotNull(data_get($migration->meta, 'partial_execution.force_cancelled.at'));
        $this->assertStringContainsString('dibatalkan PAKSA', $this->lastReply());
        $this->assertStringContainsString('TIDAK dikembalikan otomatis', $this->lastReply());
        $this->assertStringContainsString('enable lagi secret', $this->lastReply());
        $this->assertCount(2, $this->mikrotik->writes, 'Force cancel never writes a device.');

        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->assertStringContainsString('sudah dibatalkan', $this->lastReply());
    }

    public function test_execution_job_guard_stops_reply_instead_of_silent_return(): void
    {
        [$migration] = $this->incidentFixture();
        Setting::set('migration_wa_only_live_enabled', '0');

        $result = app(CustomerMigrationService::class)->executeWaMigration(
            $migration->id,
            $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH)
        );

        $this->assertSame($migration->id, $result?->id);
        $this->assertStringContainsString('dihentikan aman sebelum device action: WA-only live masih OFF', $this->lastReply());
        $this->assertContains('wa_execution_stopped', array_column((array) data_get($migration->fresh()->meta, 'timeline'), 'step'));
        $this->assertSame([], $this->mikrotik->writes);

        Setting::set('migration_wa_only_live_enabled', '1');
        $meta = $migration->fresh()->meta;
        unset($meta['device_action_approval']);
        $migration->forceFill(['meta' => $meta])->save();
        app(CustomerMigrationService::class)->executeWaMigration(
            $migration->id,
            $this->payload('#migrasi eksekusi #'.$migration->id, self::TECH)
        );
        $this->assertStringContainsString('dry-run belum siap', $this->lastReply());
    }

    public function test_manual_fiberhome_psb_name_that_truncates_a_pppoe_gets_a_warning(): void
    {
        $this->incidentFixture();
        $warning = new \ReflectionMethod(OltC300Controller::class, 'fiberhomeNameWarning');
        $controller = app(OltC300Controller::class);

        $this->assertStringContainsString("mirip PPPoE pelanggan '".self::PPPOE."'", (string) $warning->invoke($controller, 'USERAB7', 'psb_new'));
        $this->assertNull($warning->invoke($controller, self::PPPOE, 'psb_new'));
        $this->assertNull($warning->invoke($controller, 'USERBARU_RT1_RW1', 'psb_new'));
        $this->assertNull($warning->invoke($controller, 'USERAB7', 'migration'), 'Migration purposes already require the exact PPPoE.');
    }

    public function test_parser_recognises_sn_correction_and_force_cancel(): void
    {
        $parser = app(CustomerMigrationMessageParser::class);

        $sn = $parser->parse('#migrasi sn #1102 FHTT0000A00D');
        $this->assertTrue($sn['is_sn_update']);
        $this->assertSame(1102, $sn['migration_id']);
        $this->assertSame('FHTT0000A00D', $sn['sn_update_value']);

        $draft = $parser->parse("#migrasi\nPPPoE: X_RT1_RW1\nSN Baru: FHTT0000A00D");
        $this->assertFalse($draft['is_sn_update']);
        $this->assertFalse($parser->parse('#migrasi sn baru: FHTT0000A00D')['is_sn_update']);

        $force = $parser->parse('#migrasi batal paksa #1102');
        $this->assertTrue($force['is_batal']);
        $this->assertTrue($force['is_force']);
        $this->assertSame(1102, $force['migration_id']);
        $this->assertFalse($parser->parse('#migrasi batal #1102')['is_force']);
    }

    /**
     * Incident #1106: #1105 (typo SN) copied the secret to Skynet-Krian-OLT and
     * was then cancelled; a fresh migration with the right SN must re-use that
     * secret instead of failing with "Secret target ... sudah ada".
     */
    public function test_new_migration_adopts_target_secret_created_by_cancelled_migration_of_same_customer(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration, $source, $target, $olt, $customer] = $this->incidentFixture(self::REAL_SN);
        $prior = $this->priorCancelledMigration($customer, $source, $target);
        $this->mikrotik->secrets[$target->id][self::PPPOE] = [
            '.id' => '*232', 'name' => self::PPPOE, 'password' => Canary::of('source'), 'profile' => '10MB',
            'local-address' => '198.19.1.1', 'remote-address' => '198.19.1.9',
        ];
        $this->mikrotik->secrets[$source->id][self::PPPOE]['disabled'] = 'true';

        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->runQueuedJob($migration->id);

        $migration->refresh();
        $this->assertStringNotContainsString('sudah ada di router', (string) $migration->last_error);
        $this->assertSame(CustomerMigration::STATUS_SYNCED, $migration->status);
        $this->assertSame($prior->id, (int) data_get($migration->meta, 'mikrotik_migration.adopted_from_migration_id'));
        $this->assertContains('mikrotik_target_secret_adopted', array_column((array) data_get($migration->meta, 'timeline'), 'step'));
        $this->assertSame('198.19.1.9', $this->mikrotik->secrets[$target->id][self::PPPOE]['remote-address'], 'Existing target IP must be kept.');
        $this->assertSame(self::REAL_SN, $customer->fresh()->onu_serial);
    }

    public function test_existing_target_secret_without_prior_migration_of_same_customer_still_blocks(): void
    {
        Queue::fake([ExecuteCustomerMigrationWaJob::class]);
        [$migration, $source, $target, , $customer] = $this->incidentFixture(self::REAL_SN);
        $other = Customer::create(['package_id' => $customer->package_id, 'code' => 'KRN-OTHER', 'name' => 'LAIN', 'phone' => '081200000000', 'address' => 'Krian', 'pppoe_user' => 'LAIN_X', 'router_id' => $target->id, 'status' => 'active']);
        $this->priorCancelledMigration($other, $source, $target);
        $this->mikrotik->secrets[$target->id][self::PPPOE] = ['.id' => '*9', 'name' => self::PPPOE, 'password' => Canary::of('source'), 'profile' => '10MB'];

        $this->wa('#migrasi eksekusi #'.$migration->id, self::TECH);
        $this->runQueuedJob($migration->id);

        $this->assertStringContainsString('sudah ada di router', (string) $migration->fresh()->last_error);
        $this->assertNotContains("disable:{$source->id}:".self::PPPOE, $this->mikrotik->writes);
    }

    private function priorCancelledMigration(Customer $customer, Router $source, Router $target): CustomerMigration
    {
        return CustomerMigration::create([
            'status' => CustomerMigration::STATUS_CANCELLED,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'message_id' => 'guided-wa:prior-'.$customer->id,
            'raw_text' => '#migrasi',
            'customer_id' => $customer->id,
            'pppoe_user' => self::PPPOE,
            'old_router_id' => $source->id,
            'router_id' => $target->id,
            'new_sn' => self::TYPO_SN,
            'last_error' => 'Dibatalkan dari menu Migrasi',
            'meta' => ['mikrotik_migration' => [
                'cross_router' => true,
                'old_username' => self::PPPOE,
                'new_username' => self::PPPOE,
                'source_router' => ['id' => $source->id, 'name' => $source->name],
                'target_router' => ['id' => $target->id, 'name' => $target->name],
                'profile' => '10MB',
                'target_prepared' => true,
                'verified_at' => now()->subMinutes(20)->toIso8601String(),
            ]],
        ]);
    }

    /** @return array{0: CustomerMigration, 1: Router, 2: Router, 3: Olt, 4: Customer} */
    private function incidentFixture(string $newSn = self::TYPO_SN): array
    {
        $source = Router::create(['name' => 'Krian', 'ip_address' => '127.0.0.15', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $target = Router::create(['name' => 'Skynet-Krian-OLT', 'ip_address' => '127.0.0.14', 'username' => 'admin', 'password' => Canary::of('pw'), 'is_active' => true]);
        $oldOlt = Olt::create(['name' => 'OLT KRIAN HTB', 'code' => 'OLT-KRIAN-HTB', 'vendor' => 'zte_c300', 'router_id' => $source->id]);
        $olt = Olt::create(['name' => 'OLT KRIAN', 'code' => 'OLT-KRIAN', 'vendor' => 'zte_c300', 'router_id' => $target->id]);
        $package = Package::create(['code' => 'KRIAN-10', 'name' => 'Krian 10MB', 'price' => 150000, 'mikrotik_profile' => '10MB', 'router_id' => $source->id]);
        $customer = Customer::create([
            'code' => 'KRN-1102',
            'name' => 'SENO',
            'phone' => '081200000032',
            'address' => 'RT 1 RW 2 Krian',
            'package_id' => $package->id,
            'pppoe_user' => self::PPPOE,
            'mikrotik_profile' => '10MB',
            'onu_serial' => 'ZTEGOLD01102',
            'olt_port_label' => '1/1/1:5',
            'odp_name' => 'ODP-KRN-01',
            'ktp_photo_url' => 'customers/ktp/seno.jpg',
            'router_id' => $source->id,
            'olt_id' => $oldOlt->id,
            'status' => 'active',
        ]);
        // The real ONU was registered earlier with a truncated name.
        OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => '1/2/2:3',
            'pon_port' => '1/2/2',
            'serial_number' => self::REAL_SN,
            'onu_name' => 'USERAB7',
            'state' => 'online',
            'phase_state' => 'working',
        ]);
        $this->mikrotik->secrets[$source->id][self::PPPOE] = [
            '.id' => '*old',
            'name' => self::PPPOE,
            'password' => Canary::of('source'),
            'profile' => '10MB',
            'disabled' => 'false',
        ];
        $this->uncfgOnus = [];

        $raw = "#migrasi\nNama: SENO\nPPPoE: ".self::PPPOE."\nSN Baru: ".self::TYPO_SN
            ."\nRouter lama: Krian\nRouter baru: Skynet-Krian-OLT\nOLT: KRIAN\nODP: ODP-KRN-01\nKeterangan: ganti perangkat";
        $senderKeys = [preg_replace('/\D/', '', self::TECH)];
        $migration = CustomerMigration::create([
            'status' => CustomerMigration::STATUS_NEEDS_REVIEW,
            'source' => 'guided_lookup_wa',
            'group_jid' => self::GROUP,
            'sender_jid' => self::TECH,
            'sender_name' => 'Teknisi Krian',
            'message_id' => 'guided-wa:incident-1102',
            'raw_text' => $raw,
            'customer_id' => $customer->id,
            'pppoe_user' => self::PPPOE,
            'full_name' => 'SENO',
            'phone' => '081200000032',
            'odp' => 'ODP-KRN-01',
            'old_router_id' => $source->id,
            'old_olt_id' => $oldOlt->id,
            'old_onu_ref' => '1/1/1:5',
            'old_sn' => 'ZTEGOLD01102',
            'router_id' => $target->id,
            'new_olt_id' => $olt->id,
            'new_sn' => $newSn,
            'meta' => [
                'wa_origin_sender_keys' => $senderKeys,
                'input_identity' => ['pppoe_user' => self::PPPOE],
                'router_mapping' => [
                    'source_input' => 'Krian',
                    'target_input' => 'Skynet-Krian-OLT',
                    'source_router_id' => $source->id,
                    'target_router_id' => $target->id,
                    'cross_router' => true,
                    'status' => 'resolved',
                ],
                'guided_wa_selection' => ['candidate_number' => 1, 'customer_id' => $customer->id, 'selected_by_sender_keys' => $senderKeys],
                'guided_wa_confirmation' => ['status' => 'confirmed', 'selected_by_sender_keys' => $senderKeys],
                'migration_review' => ['match_status' => 'confirmed_pending_approval', 'reasons' => ['guided_wa_candidate_confirmed']],
            ],
        ]);

        $prepared = app(CustomerMigrationService::class)->prepareDeviceActionApproval($migration, null, 'Teknisi konfirmasi migrasi Krian');
        $this->assertSame('dry_run_ready', data_get($prepared->meta, 'device_action_approval.status'));
        $this->replies = [];

        return [$prepared->fresh(), $source, $target, $olt, $customer];
    }

    /** @return array<string, mixed> */
    private function payload(string $text, string $sender): array
    {
        return [
            'group_jid' => self::GROUP,
            'sender_jid' => $sender,
            'sender_name' => 'Teknisi',
            'message_id' => 'wa-'.md5($text.$sender.microtime()),
            'text' => $text,
        ];
    }

    private function wa(string $text, string $sender): CustomerMigration
    {
        return app(CustomerMigrationService::class)->handleInbound($this->payload($text, $sender));
    }

    /** Emulates the migration-live worker: handle, then free the unique lock. */
    private function runQueuedJob(int $migrationId): void
    {
        app(CustomerMigrationService::class)->executeWaMigration($migrationId, $this->payload('#migrasi eksekusi #'.$migrationId, self::TECH));
        (new UniqueLock(app(CacheRepository::class)))->release(new ExecuteCustomerMigrationWaJob($migrationId, []));
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
