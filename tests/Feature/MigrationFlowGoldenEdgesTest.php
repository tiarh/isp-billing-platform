<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Jobs\ProcessPsbWaJob;
use App\Models\CustomerMigration;
use App\Models\Setting;
use App\Services\CustomerMigrationMessageParser;
use App\Services\CustomerMigrationService;
use App\Services\ManualProvisionMigrationLinker;
use App\Services\Migration\MigrationManualCompleteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\SyntheticOcrFixtures;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Characterization goldens of the migration WA flow, part 2 (migration refactor M0, tests only).
 *
 * m06-m08: auto-reconcile and manual complete (DB only), the manual FiberHome link, and the
 * commands plus every guard before a device call. Part 1 (m01-m05) is MigrationFlowGoldenTest.
 *
 * Fixtures: tests/Fixtures/migration-flow/<scenario>.golden.json, written ONLY with the env
 * MIGRATION_FLOW_GOLDEN_WRITE=1 (never in CI). A golden change needs owner OK.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenEdgesTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    /** @return array<string, array{0: string}> */
    public static function scenarios(): array
    {
        $names = [
            'm06-auto-reconcile-and-manual-complete',
            'm07-manual-fiberhome-link',
            'm08-commands-and-guards',
        ];

        return array_combine($names, array_map(fn (string $n): array => [$n], $names));
    }

    #[DataProvider('scenarios')]
    public function test_scenario_matches_golden(string $name): void
    {
        $this->seedWorld();
        $this->{'scenario_'.str_replace('-', '_', $name)}();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));
    }

    private function service(): CustomerMigrationService
    {
        return app(CustomerMigrationService::class);
    }

    /** m06: reconcile writes, the eksekusi that follows, revert, a stale expectation, and the manual close. */
    private function scenario_m06_auto_reconcile_and_manual_complete(): void
    {
        $this->olt->uncfg = [];
        $id = $this->guidedIntake('dry-run ready draft', 'siti', 'FHTTC0FFEE61');
        $customerId = (int) $this->person('siti')->id;

        $this->callPublic('auto reconcile: synced, dry-run ledgers superseded', 'public:markSyncedByAutoReconcile', fn () => $this->service()->markSyncedByAutoReconcile($id, ['customer_id' => $customerId, 'sn_source' => 'new_sn'], 'run-1', ['status' => 'needs_review']));
        $this->say('eksekusi on the reconciled row: already done, no job, no device', '#migrasi eksekusi #'.$id);
        $this->callPublic('revert the auto reconcile', 'public:revertAutoReconcile', fn () => $this->service()->revertAutoReconcile($id));
        $this->callPublic('a stale expectation is refused', 'public:markSyncedByAutoReconcile', fn () => $this->service()->markSyncedByAutoReconcile($id, [], 'run-2', ['status' => CustomerMigration::STATUS_CANCELLED]));

        $second = $this->guidedIntake('second dry-run ready draft', 'dewi', 'FHTTC0FFEE62');
        $this->callPublic('manual complete by an admin', 'public:MigrationManualCompleteService::complete', fn () => app(MigrationManualCompleteService::class)->complete(CustomerMigration::query()->findOrFail($second), $this->admin(), 'Dicek manual: ONU dan PPPoE sudah online.'));
        $this->callPublic('manual complete again: already done', 'public:markSyncedManually', fn () => $this->service()->markSyncedManually($second, [], 'Dicek manual sekali lagi oleh admin', $this->admin()));
        $this->callPublic('revert on a manual row is refused', 'public:revertAutoReconcile', fn () => $this->service()->revertAutoReconcile($second));
    }

    /** m07: the manual FiberHome provision recorder: exact link, ownership refusal, new record, confirmed near match. */
    private function scenario_m07_manual_fiberhome_link(): void
    {
        $this->olt->uncfg = [];
        $linker = fn (): ManualProvisionMigrationLinker => app(ManualProvisionMigrationLinker::class);
        $payload = fn (string $person, string $sn, int $onuId): array => [
            'olt_id' => self::OLT_KRIAN, 'router_id' => self::KRIAN_NEW, 'sn' => $sn, 'port' => '1/2/2', 'onu_id' => $onuId,
            'name' => (string) $this->person($person)->pppoe_user,
        ];

        $this->guidedIntake('open migration for an exact SN match', 'budi', 'FHTTC0FFEE71');
        $this->callPublic('provision recorded: exact SN match links the open migration', 'public:recordFiberhomeProvision', fn () => $linker()->recordFiberhomeProvision($payload('budi', 'FHTTC0FFEE71', 5), 'migration', 'admin'));

        $conflict = $this->guidedIntake('open migration of a customer whose provisioned SN belongs to another customer', 'siti', 'FHTTC0FFEE72');
        $this->callPublic('provision recorded: SN owned by another customer is a conflict, no link', 'public:recordAfterProvision', fn () => $linker()->recordAfterProvision($payload('siti', 'ZTEGA0000014', 6), null, true, 'admin'));
        $this->callPublic('confirm on the conflicting migration is refused', 'public:confirm', fn () => $linker()->confirm(CustomerMigration::query()->findOrFail($conflict), $payload('siti', 'ZTEGA0000014', 6), null, 'admin'));

        $this->callPublic('provision recorded with no open migration: manual record, ACS tag, old ONU delete', 'public:recordFiberhomeProvision', fn () => $linker()->recordFiberhomeProvision($payload('dewi', 'FHTTC0FFEE73', 7), 'migration', 'admin'));

        $open = $this->guidedIntake('open migration for a confirmed link', 'andi', 'FHTTC0FFEE74');
        $this->callPublic('confirm links the open migration', 'public:confirm', fn () => $linker()->confirm(CustomerMigration::query()->findOrFail($open), $payload('andi', 'FHTTC0FFEE74', 8), null, 'admin'));
    }

    /** m08: help, maintenance, hp, every refusal before a device call, held locks, and the queue guard. */
    private function scenario_m08_commands_and_guards(): void
    {
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE81', 'port' => '1/2/2', 'onu_id' => 3]];
        $budi = $this->person('budi');

        $this->say('help', '#migrasi bantu');
        Setting::set('migration_maintenance_mode', '1');
        $this->say('intake during maintenance is refused', $this->intakeText('DRAFTE SANTOSO', (string) $budi->pppoe_user, 'FHTTC0FFEE81'));
        $this->say('status still answers during maintenance', '#migrasi');
        Setting::set('migration_maintenance_mode', '0');

        $id = $this->guidedIntake('dry-run ready draft', 'budi', 'FHTTC0FFEE81');
        $this->say('hp command saves the number', '#migrasi hp #'.$id.' 081200000077');
        $this->say('hp command with a bad number', '#migrasi hp #'.$id.' 123');

        Setting::set('migration_wa_only_live_enabled', '0');
        $this->say('eksekusi with WA-only live OFF', '#migrasi eksekusi #'.$id);
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->say('eksekusi by a sender who is not allowlisted and not bound', '#migrasi eksekusi #'.$id, self::STRANGER);

        $conflict = $this->guidedIntake('draft with an SN conflict: no dry-run', 'dewi', 'ZTEGA0000014');
        $this->say('eksekusi when the draft is not dry_run_ready', '#migrasi eksekusi #'.$conflict);
        $this->say('pilih in a group that is not guided: candidate preview text', '#migrasi pilih #'.$conflict.' 1', self::STRANGER, self::OTHER_GROUP);
        CustomerMigration::query()->whereKey($conflict)->update(['status' => CustomerMigration::STATUS_FAILED]);
        $photo = $this->storeImage('ktp-a', 856, 540, 1, SyntheticOcrFixtures::ktpColumnWiseText());
        $this->callPublic('follow-up photo on a failed guided draft', 'public:attachFollowUpImages', fn () => $this->service()->attachFollowUpImages(CustomerMigration::query()->findOrFail($conflict), [$this->imageBytes($photo)], '#migrasi ktp #'.$conflict));

        unset($this->mt->tables[self::KRIAN_OLD][(string) $this->person('siti')->pppoe_user]);
        $unverified = $this->guidedIntake('draft whose source PPP row is missing', 'siti', 'FHTTC0FFEE82');
        $this->say('eksekusi when the source PPP row is not verified', '#migrasi eksekusi #'.$unverified);

        Setting::set('control_plane.device_read_only_mode', '1');
        $this->adminPost('admin web execute with the kill switch ON: blocked_kill_switch', 'customer-migrations.device-action.execute', $id, ['reason' => 'Admin jalankan migrasi']);
        Setting::set('control_plane.device_read_only_mode', '0');

        $this->callPublic('queue job failed hook', 'public:ExecuteCustomerMigrationWaJob::failed', function () use ($unverified): void {
            (new ExecuteCustomerMigrationWaJob($unverified, $this->commandPayload('#migrasi eksekusi #'.$unverified)))->failed(new \RuntimeException('worker stopped'));
        });

        $this->scenarioLockGuards();
    }

    /** The held-lock and queue guards of m08, on a fresh dry-run ready draft. */
    private function scenarioLockGuards(): void
    {
        $this->olt->uncfg = [['sn' => 'FHTTC0FFEE84', 'port' => '1/2/2', 'onu_id' => 4]];
        $text = $this->intakeText('DRAFTC WIJAYA', (string) $this->person('andi')->pppoe_user, 'FHTTC0FFEE83');
        $parsed = (new CustomerMigrationMessageParser)->parse($text);
        $payload = $this->commandPayload($text);
        $key = (new \ReflectionMethod($this->service(), 'migrationOperationLockKey'))->invoke($this->service(), $payload, $parsed);

        // The full intake from the guided group never reaches the operation lock: the guided draft path answers first.
        $this->hold($key);
        $this->inbound('guided-group intake while the operation lock of the same operation is held: the guided path does not use that lock', $payload);
        $this->release($key);

        // The Excel short form (PPPoE + new SN) from a sender in a group that is not guided skips the guided draft
        // path and reaches the operation lock of handleInbound. The message id is set by hand so the ids of the later
        // steps do not move.
        $shortText = '#migrasi '.(string) $this->person('andi')->pppoe_user.' FHTTC0FFEE83';
        $shortPayload = $this->commandPayload($shortText, self::STRANGER, self::OTHER_GROUP);
        $this->messageSeq--;
        $shortPayload['message_id'] = 'FAKE-MIG-LOCK-1';
        $shortKey = (new \ReflectionMethod($this->service(), 'migrationOperationLockKey'))->invoke($this->service(), $shortPayload, (new CustomerMigrationMessageParser)->parse($shortText));

        $this->hold($shortKey);
        $this->inbound('Excel short form from a stranger in another group while the same operation holds its lock: busy reply', $shortPayload);
        $this->release($shortKey);

        $id = $this->guidedIntake('fresh dry-run ready draft', 'toni', 'FHTTC0FFEE84');
        $this->hold('customer-migration-device-'.$id);
        $this->adminPost('admin web prepare while the device lock is held', 'customer-migrations.device-action.prepare', $id, ['reason' => 'Admin cek dry-run migrasi']);
        $this->release('customer-migration-device-'.$id);

        $this->hold('customer-migration-wa-live-active');
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job runs while another live migration is active: busy reply', null, ExecuteCustomerMigrationWaJob::class);
        $this->release('customer-migration-wa-live-active');

        $this->say('eksekusi again: job queued', '#migrasi eksekusi #'.$id);
        Queue::pushOn('psb', new ProcessPsbWaJob(['group_jid' => self::GROUP, 'text' => 'x']));
        $this->drainJobs('a PSB job is queued first: the migration job is released, no device call', null, ExecuteCustomerMigrationWaJob::class);

        $this->hold('customer-migration-device-'.$id);
        $this->say('SN correction while the device lock is held: busy reply, SN unchanged', '#migrasi sn #'.$id.' FHTTC0FFEE85');
        $this->release('customer-migration-device-'.$id);
        $this->probeLocksFree();
    }
}
