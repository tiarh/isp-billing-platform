<?php

namespace Tests\Unit;

use App\Jobs\ProcessPsbWaJob;
use App\Models\PsbDraft;
use App\Services\PsbOrchestratorService;
use Tests\TestCase;

class ProcessPsbWaJobDeliveryTest extends TestCase
{
    public function test_job_retries_when_orchestrator_reports_reply_delivery_failure(): void
    {
        $orchestrator = \Mockery::mock(PsbOrchestratorService::class);
        $orchestrator->shouldReceive('handleInbound')
            ->once()
            ->andReturn(new PsbDraft(['status' => 'report']));
        $orchestrator->shouldReceive('hasReplyDeliveryFailure')
            ->once()
            ->andReturnTrue();
        $orchestrator->shouldReceive('reply')
            ->once()
            ->andReturnTrue();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PSB reply delivery failed; retryable');

        (new ProcessPsbWaJob([
            'message_id' => 'psb-delivery-failure-1',
            'group_jid' => '120363-activation@g.us',
            'text' => '/psb',
        ]))->handle($orchestrator);
    }
}
