<?php

namespace Tests\Unit;

use App\Jobs\ExecuteCustomerMigrationWaJob;
use App\Jobs\ProcessCustomerMigrationWaJob;
use App\Jobs\ProcessPsbRecapCommandJob;
use App\Jobs\ProcessPsbWaJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Tests\TestCase;

class ProtectedQueueContractTest extends TestCase
{
    public function test_psb_wa_job_contract_remains_independent(): void
    {
        $job = new ProcessPsbWaJob([
            'message_id' => 'psb-message-001',
            'group_jid' => 'activation-test',
            'text' => '#psb test',
        ]);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(10, $job->tries);
        $this->assertSame(2, $job->maxExceptions);
        $this->assertSame(300, $job->timeout);
        $this->assertSame(600, $job->uniqueFor);
        $this->assertSame('psb', $job->queue);
        $this->assertSame('psb-wa-msg:psb-message-001', $job->uniqueId());
    }

    public function test_customer_migration_command_job_contract_remains_independent(): void
    {
        $job = new ProcessCustomerMigrationWaJob([
            'message_id' => 'migration-message-001',
            'group_jid' => 'activation-test',
            'text' => '#migrasi test',
        ]);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(2, $job->tries);
        $this->assertSame(420, $job->timeout);
        $this->assertSame(900, $job->uniqueFor);
        $this->assertSame('migration-command', $job->queue);
        $this->assertSame('migration-wa-msg:migration-message-001', $job->uniqueId());
    }

    public function test_psb_recap_command_job_contract_is_read_only_wa_lane(): void
    {
        $job = new ProcessPsbRecapCommandJob([
            'message_id' => 'recap-message-001',
            'group_jid' => 'activation-test',
            'text' => '#rekap psb klampok 1-14 okt',
        ]);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(2, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame('wa', $job->queue);
        $this->assertSame('psb-recap-msg:recap-message-001', $job->uniqueId());
        $this->assertSame(
            'psb-recap-fb:activation-test:'.substr(sha1('#rekap psb klampok 1-14 okt'), 0, 16),
            (new ProcessPsbRecapCommandJob(['group_jid' => 'activation-test', 'text' => '#rekap psb klampok 1-14 okt']))->uniqueId(),
        );
    }

    public function test_customer_migration_live_job_contract_remains_separate(): void
    {
        $job = new ExecuteCustomerMigrationWaJob(42, ['source' => 'test']);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame(120, $job->tries);
        $this->assertSame(420, $job->timeout);
        $this->assertSame(15, $job->backoff);
        $this->assertSame(3600, $job->uniqueFor);
        $this->assertSame('migration-live', $job->queue);
        $this->assertSame('migration-wa-live:42', $job->uniqueId());
    }

    public function test_missing_message_id_uses_each_job_specific_fallback_key(): void
    {
        $payload = [
            'group_jid' => 'activation-test',
            'text' => '#same-content',
        ];

        $this->assertSame(
            'psb-wa-fb:activation-test:'.substr(sha1('#same-content'), 0, 16),
            (new ProcessPsbWaJob($payload))->uniqueId(),
        );
        $this->assertSame(
            'migration-wa-fallback:activation-test:'.substr(sha1('#same-content'), 0, 16),
            (new ProcessCustomerMigrationWaJob($payload))->uniqueId(),
        );
    }
}
