<?php

namespace Tests\Feature;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Models\CustomerMigration;
use App\Models\DeviceActionLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\MigrationGolden\MigrationGoldenHarness;
use Tests\Support\MigrationGolden\MigrationGoldenWorld;
use Tests\TestCase;

/**
 * Guided WA flow: pilih 1, dry-run, then pilih 2 must clear the old approval, and the executor must never run
 * a cancelled ledger or a ledger of another migration. Real service, same fakes as the migration goldens.
 */
#[Group('blocking-psb')]
class MigrationRepickStaleApprovalTest extends TestCase
{
    use MigrationGoldenHarness;
    use MigrationGoldenWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMigrationGolden();
        $this->seedWorld();
        $this->olt->uncfg = [];
    }

    protected function tearDown(): void
    {
        $this->shutdownMigrationGolden();
        parent::tearDown();
    }

    private function row(int $id): CustomerMigration
    {
        return CustomerMigration::query()->findOrFail($id);
    }

    private function ledgerOf(int $id): int
    {
        return (int) data_get($this->row($id)->meta, 'device_action_approval.ledger_id');
    }

    /** @return int count of recorded device calls (reads and writes) */
    private function deviceCalls(): int
    {
        $count = 0;
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (preg_match('/^(mt|olt|huawei|acs)\./', (string) ($event['type'] ?? '')) === 1) {
                    $count++;
                }
            }
        }

        return $count;
    }

    /** A list of three, pilih 1, konfirmasi: dry-run ready on the first customer. */
    private function draftWithDryRun(string $sn): int
    {
        $this->seedRinaCandidates();
        $this->olt->uncfg = [['sn' => $sn, 'port' => '1/2/2', 'onu_id' => 3]];
        $this->say('name-only intake: list of three', $this->rinaIntakeText($sn));
        $id = $this->lastMigrationId();
        $this->say('pilih 1', '#migrasi pilih #'.$id.' 1');
        $this->say('konfirmasi: dry-run ready', '#migrasi konfirmasi #'.$id);
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));

        return $id;
    }

    public function test_pilih_after_a_dry_run_clears_the_old_approval_and_konfirmasi_makes_a_new_dry_run(): void
    {
        $id = $this->draftWithDryRun('FHTTC0FFEE81');
        $first = $this->ledgerOf($id);
        $firstCustomer = (int) $this->row($id)->customer_id;

        $this->say('pilih 2', '#migrasi pilih #'.$id.' 2');
        $this->assertSame((int) $this->person('rina2')->id, (int) $this->row($id)->customer_id);
        $this->assertNotSame($firstCustomer, (int) $this->row($id)->customer_id);
        $this->assertNull(data_get($this->row($id)->meta, 'device_action_approval'), 'the old approval must not be saved back');
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($first)->status);

        $this->say('konfirmasi: a new dry-run', '#migrasi konfirmasi #'.$id);
        $second = $this->ledgerOf($id);
        $this->assertGreaterThan(0, $second);
        $this->assertNotSame($first, $second, 'konfirmasi must prepare a new dry-run');
        $this->assertSame('dry_run_ready', data_get($this->row($id)->meta, 'device_action_approval.status'));
        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($second)->status);
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($first)->status, 'the first ledger stays cancelled');
        $this->probeLocksFree();
    }

    public function test_pilih_of_the_same_customer_keeps_the_dry_run(): void
    {
        $id = $this->draftWithDryRun('FHTTC0FFEE82');
        $first = $this->ledgerOf($id);

        $this->say('pilih 1 again', '#migrasi pilih #'.$id.' 1');
        $this->assertSame($first, $this->ledgerOf($id), 'the same customer keeps its dry-run');
        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($first)->status);
        $this->probeLocksFree();
    }

    public function test_executor_refuses_a_cancelled_ledger_without_a_device_call(): void
    {
        $id = $this->draftWithDryRun('FHTTC0FFEE83');
        $ledger = $this->ledgerOf($id);
        // The state the old bug left behind: approval still ready, ledger cancelled.
        DeviceActionLog::query()->whereKey($ledger)->update(['status' => 'cancelled']);
        $before = $this->deviceCalls();
        $resultBefore = DeviceActionLog::query()->findOrFail($ledger)->result;

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: refused, no device call', null, ExecuteCustomerMigrationWaJob::class);

        $after = DeviceActionLog::query()->findOrFail($ledger);
        $this->assertSame('cancelled', $after->status, 'the ledger is left as it was');
        $this->assertNull($after->executed_at);
        $this->assertEquals($resultBefore, $after->result);
        $this->assertSame($before, $this->deviceCalls(), 'no device call at all');
        $this->assertNotSame(CustomerMigration::STATUS_SYNCED, $this->row($id)->status);
        $this->assertStringContainsString('Dry-run sudah dibatalkan', $this->lastWaTexts());
        $this->probeLocksFree();
    }

    public function test_konfirmasi_after_a_refused_cancelled_ledger_makes_a_new_dry_run_and_eksekusi_runs_it(): void
    {
        $id = $this->draftWithDryRun('FHTTC0FFEE85');
        $first = $this->ledgerOf($id);
        // Legacy state: approval still ready, but its ledger was cancelled.
        DeviceActionLog::query()->whereKey($first)->update(['status' => 'cancelled']);
        $before = $this->deviceCalls();

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: refused, no device call', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame($before, $this->deviceCalls(), 'the refused step makes no device call');
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($first)->status);
        $this->assertStringContainsString('Dry-run sudah dibatalkan', $this->lastWaTexts());

        $this->say('konfirmasi: a new dry-run', '#migrasi konfirmasi #'.$id);
        $second = $this->ledgerOf($id);
        $this->assertNotSame($first, $second, 'konfirmasi must not skip the dry-run');
        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($second)->status);
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($first)->status);

        $this->say('eksekusi again: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: runs the new ledger', null, ExecuteCustomerMigrationWaJob::class);
        $this->assertSame('verified', DeviceActionLog::query()->findOrFail($second)->status);
        $this->assertSame('cancelled', DeviceActionLog::query()->findOrFail($first)->status);
        $this->assertSame($second, $this->ledgerOf($id));
        $this->probeLocksFree();
    }

    public function test_executor_refuses_a_ledger_of_another_migration(): void
    {
        $id = $this->draftWithDryRun('FHTTC0FFEE84');
        $ledger = $this->ledgerOf($id);
        DeviceActionLog::query()->whereKey($ledger)->update(['target_id' => (string) ($id + 900)]);
        $before = $this->deviceCalls();

        $this->say('eksekusi: job queued', '#migrasi eksekusi #'.$id);
        $this->drainJobs('job: refused, ledger of another migration', null, ExecuteCustomerMigrationWaJob::class);

        $this->assertSame('dry_run', DeviceActionLog::query()->findOrFail($ledger)->status);
        $this->assertSame($before, $this->deviceCalls(), 'no device call at all');
        $this->assertNotSame(CustomerMigration::STATUS_SYNCED, $this->row($id)->status);
        $this->assertStringContainsString('bukan untuk migrasi ini', $this->lastWaTexts());
        $this->probeLocksFree();
    }

    private function lastWaTexts(): string
    {
        $texts = [];
        foreach ($this->steps as $step) {
            foreach ($step['events'] as $event) {
                if (($event['type'] ?? '') === 'wa.send') {
                    $texts[] = (string) $event['text'];
                }
            }
        }

        return implode("\n", $texts);
    }
}
