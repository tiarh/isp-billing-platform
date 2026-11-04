<?php

namespace App\Console\Commands;

use App\Services\PsbOrchestratorService;
use Illuminate\Console\Command;

class PsbHourlyReportCommand extends Command
{
    protected $signature = 'psb:hourly-report';

    protected $description = 'PSB rekap otomatis s/d jam 9 WIB; hanya jika data berubah (setelah 10:00 pakai /psb)';

    public function handle(PsbOrchestratorService $psb): int
    {
        $n = $psb->hourlyReport();
        $this->info("groups_notified={$n} (0 = skip: jam>=10 / 0 draft / no change)");

        return self::SUCCESS;
    }
}
