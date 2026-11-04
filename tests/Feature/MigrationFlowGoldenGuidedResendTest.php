<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use App\Services\CustomerMigrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * A full guided message re-sent on a migration that is already partly executed (safety fix).
 *
 * A re-send must never re-derive the OLT or the router of such a migration: the same SN gets the
 * "resend blocked" partial summary, another SN goes through the SN correction, and the next
 * "eksekusi" continues on the ORIGINAL target OLT. Also pins the two defences: a guided re-fill is
 * refused when device writes exist, and a continuation on another OLT than the earlier run is refused.
 *
 * Golden: tests/Fixtures/migration-flow/m12-*.golden.json, written ONLY with MIGRATION_FLOW_GOLDEN_WRITE=1.
 */
#[Group('blocking-psb')]
class MigrationFlowGoldenGuidedResendTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    private const PON_KEY = 'device-olt-pon-9-1/2/2';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->seedWorld();
        $this->olt->uncfg = [
            ['sn' => 'FHTTC0FFEEB1', 'port' => '1/2/2', 'onu_id' => 3],
            ['sn' => 'FHTTC0FFEEB2', 'port' => '1/2/2', 'onu_id' => 4],
            ['sn' => 'FHTTC0FFEEB3', 'port' => '1/2/2', 'onu_id' => 5],
            ['sn' => 'FHTTC0FFEEB4', 'port' => '1/2/2', 'onu_id' => 6],
        ];
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    /** Guided intake, then "eksekusi" while the PON port is busy: PPPoE moved, OLT step not run, partial state. */
    private function partialByBusyPon(string $label, string $person, string $sn, array $extra = []): int
    {
        $id = $this->guidedIntake($label.': guided intake, automatic dry-run', $person, $sn, $extra);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->hold(self::PON_KEY);
        $this->drainJobs('job with the PON port busy: PPPoE moved, OLT step not started, partial state', null, ExecuteCustomerMigrationWaJob::class);
        $this->release(self::PON_KEY);

        return $id;
    }

    /** @return array<string, mixed> the fields a re-send must never change */
    private function routing(int $id): array
    {
        $row = CustomerMigration::query()->findOrFail($id);

        return [
            'old_router_id' => (int) $row->old_router_id,
            'router_id' => (int) $row->router_id,
            'new_olt_id' => (int) $row->new_olt_id,
            'new_pon_port' => (string) $row->new_pon_port,
            'status' => $row->status,
        ];
    }

    /** @return list<int> OLT id of every provision call recorded so far */
    private function provisionedOltIds(): array
    {
        $ids = [];
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === 'olt.provisionFiberhome') {
                    $ids[] = (int) ($event['input']['olt_id'] ?? 0);
                }
            }
        }

        return $ids;
    }

    /** @return list<string> every WA text sent so far */
    private function waTexts(): array
    {
        $texts = [];
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === 'wa.send') {
                    $texts[] = (string) $event['text'];
                }
            }
        }

        return $texts;
    }

    public function test_scenario_matches_golden(): void
    {
        $name = 'm12-guided-resend-on-partial-migration';

        // Draft A: no OLT line (the target OLT comes from the target router), same full message re-sent.
        $a = $this->partialByBusyPon('draft A without an OLT line', 'budi', 'FHTTC0FFEEB1', ['OLT' => null]);
        $before = $this->routing($a);
        $this->assertSame(self::OLT_KRIAN, $before['new_olt_id']);
        $this->assertSame(CustomerMigration::STATUS_PARTIALLY_EXECUTED, $before['status']);
        $budi = $this->person('budi');
        $this->say('the full guided message re-sent with the same SN and no OLT line: resend blocked, partial state shown', $this->intakeText((string) $budi->name, (string) $budi->pppoe_user, 'FHTTC0FFEEB1', ['OLT' => null]));
        $this->assertSame($before, $this->routing($a), 'a re-send must not change OLT, routers, port or status');
        $this->say('eksekusi: continuation dry-run on the original target OLT, no job', '#migrasi eksekusi #'.$a);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$a);
        $this->drainJobs('continuation job: OLT provisioned on the target OLT, synced', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame([self::OLT_KRIAN], $this->provisionedOltIds(), 'the ONU must be provisioned on the target OLT only');
        $this->assertSame(CustomerMigration::STATUS_SYNCED, CustomerMigration::query()->findOrFail($a)->status);

        // Draft B: no OLT line, the full message re-sent with another SN: the validated SN correction.
        $b = $this->partialByBusyPon('draft B without an OLT line', 'siti', 'FHTTC0FFEEB2', ['OLT' => null]);
        $beforeB = $this->routing($b);
        $siti = $this->person('siti');
        $this->say('the full guided message re-sent with another SN and no OLT line: SN correction, OLT and routers unchanged', $this->intakeText((string) $siti->name, (string) $siti->pppoe_user, 'FHTTC0FFEEB3', ['OLT' => null]));
        $afterB = $this->routing($b);
        $this->assertSame($beforeB['new_olt_id'], $afterB['new_olt_id']);
        $this->assertSame($beforeB['old_router_id'], $afterB['old_router_id']);
        $this->assertSame('FHTTC0FFEEB3', (string) CustomerMigration::query()->findOrFail($b)->new_sn);
        $this->say('cek after the SN correction', '#migrasi cek #'.$b);

        // Draft C: with an OLT line the OLT stays and the migration continues on it.
        $c = $this->partialByBusyPon('draft C with an OLT line', 'dewi', 'FHTTC0FFEEB4');
        $beforeC = $this->routing($c);
        $dewi = $this->person('dewi');
        $this->say('the full guided message with an OLT line re-sent with the same SN: resend blocked', $this->intakeText((string) $dewi->name, (string) $dewi->pppoe_user, 'FHTTC0FFEEB4'));
        $this->assertSame($beforeC, $this->routing($c));
        $this->say('eksekusi: continuation dry-run, no job', '#migrasi eksekusi #'.$c);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$c);
        $this->drainJobs('continuation job: OLT provisioned on the target OLT, synced', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame([self::OLT_KRIAN, self::OLT_KRIAN], $this->provisionedOltIds());

        // Draft D: the add-secret step fails, so the ledger ran but no step marker exists; the re-send gets a short reply.
        $agus = $this->person('agus');
        $this->mt->addSecretError = 'router menolak';
        $d = $this->guidedIntake('draft D without an OLT line: guided intake, automatic dry-run', 'agus', 'FHTTC0FFEEB5', ['OLT' => null]);
        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$d);
        $this->drainJobs('job: the add-secret step fails, ledger ran, no step marker', null, ExecuteCustomerMigrationWaJob::class);
        $this->mt->addSecretError = null;
        $beforeD = $this->routing($d);
        $this->say('the full guided message re-sent after a failed run without a step marker: short reply, nothing changed', $this->intakeText((string) $agus->name, (string) $agus->pppoe_user, 'FHTTC0FFEEB5', ['OLT' => null]));
        $this->assertSame($beforeD, $this->routing($d));
        $last = $this->waTexts();
        $this->assertMatchesRegularExpression('/^Migrasi #\S+ sudah pernah dijalankan; pesan ulang tidak dipakai\.\nLanjutkan: #migrasi eksekusi #\S+\nCek: #migrasi cek #\S+$/', (string) end($last));

        $this->probeLocksFree();
        $this->compareToGolden($name, $this->encode($this->recordedScenario($name)));
    }

    public function test_guided_refill_is_refused_when_device_writes_exist(): void
    {
        $id = $this->partialByBusyPon('draft', 'budi', 'FHTTC0FFEEB1', ['OLT' => null]);
        $before = $this->routing($id);
        $migration = CustomerMigration::query()->findOrFail($id);
        $service = app(CustomerMigrationService::class);
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'confirmGuidedWaCandidate');
        $method->setAccessible(true);

        $result = $method->invoke($service, $migration, ['customer_id' => (int) $migration->customer_id, 'conflicts' => []], $this->commandPayload('#migrasi'), 1);

        $this->assertSame($id, (int) $result->id);
        $this->assertSame($before, $this->routing($id), 'a refused re-fill must not touch the migration');
    }

    public function test_choosing_another_customer_over_a_migration_with_device_writes_is_refused(): void
    {
        $id = $this->partialByBusyPon('draft', 'budi', 'FHTTC0FFEEB1', ['OLT' => null]);
        $before = $this->routing($id);
        $migration = CustomerMigration::query()->findOrFail($id);
        $method = new \ReflectionMethod(CustomerMigrationService::class, 'confirmGuidedWaCandidate');
        $method->setAccessible(true);

        try {
            $method->invoke(app(CustomerMigrationService::class), $migration, ['customer_id' => (int) $this->person('siti')->id, 'conflicts' => []], $this->commandPayload('#migrasi'), 1);
            $this->fail('another customer must be refused');
        } catch (\RuntimeException $e) {
            $this->assertSame('Migrasi ini sudah pernah dijalankan; pelanggan tidak bisa diganti.', $e->getMessage());
        }
        $this->assertSame($before, $this->routing($id));
        $this->assertSame((int) $this->person('budi')->id, (int) CustomerMigration::query()->findOrFail($id)->customer_id);
    }

    public function test_continuation_on_another_olt_than_the_earlier_run_is_refused(): void
    {
        $id = $this->partialByBusyPon('draft', 'budi', 'FHTTC0FFEEB1', ['OLT' => null]);
        // The earlier run is recorded as having used another OLT.
        $executed = DeviceActionLog::query()->where('target_id', (string) $id)->where('status', 'executed')->firstOrFail();
        $result = (array) $executed->result;
        $result['before']['olt']['id'] = self::OLT_KRIAN_OLD;
        $executed->forceFill(['result' => $result])->save();

        $this->say('eksekusi: continuation dry-run', '#migrasi eksekusi #'.$id);
        $this->say('eksekusi: continuation job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('continuation job: refused before any device write', null, ExecuteCustomerMigrationWaJob::class);

        $this->assertSame([], $this->provisionedOltIds(), 'no ONU may be provisioned');
        $latest = DeviceActionLog::query()->where('target_id', (string) $id)->orderByDesc('id')->firstOrFail();
        $this->assertSame('dry_run', $latest->status, 'the ledger stays dry_run');
        $texts = [];
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === 'wa.send') {
                    $texts[] = (string) $event['text'];
                }
            }
        }
        $this->assertStringContainsString('OLT tujuan berbeda', implode("\n", $texts));
    }
}
