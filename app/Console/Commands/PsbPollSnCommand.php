<?php

namespace App\Console\Commands;

use App\Services\PsbOrchestratorService;
use Illuminate\Console\Command;

class PsbPollSnCommand extends Command
{
    protected $signature = 'psb:poll-sn';

    protected $description = 'Poll OLT uncfg for PSB drafts waiting for SN (FO plug-in)';

    public function handle(PsbOrchestratorService $psb): int
    {
        $n = $psb->pollWaitingSn();
        $this->info("polled={$n}");

        return self::SUCCESS;
    }
}
