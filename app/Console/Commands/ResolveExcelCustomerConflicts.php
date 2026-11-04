<?php

namespace App\Console\Commands;

use App\Services\ExcelCustomerConflictResolver;
use Illuminate\Console\Command;

class ResolveExcelCustomerConflicts extends Command
{
    protected $signature = 'customers:resolve-excel-conflicts
                            {--apply : Apply guarded DB-only resolution}
                            {--actor-id= : Required operator user ID for apply}
                            {--reason= : Required audit reason for apply}';

    protected $description = 'Resolve the acknowledged 2026-07-24 Excel customer conflicts without device writes';

    public function handle(ExcelCustomerConflictResolver $resolver): int
    {
        if (! $this->option('apply')) {
            $this->warn('DRY RUN - no customer/staging row changed.');
            $this->line(json_encode($resolver->preview(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $actorId = (int) $this->option('actor-id');
        $reason = trim((string) $this->option('reason'));
        if ($actorId < 1 || $reason === '') {
            $this->error('--actor-id and --reason are required for apply.');

            return self::FAILURE;
        }

        $this->line(json_encode(
            $resolver->apply($actorId, $reason),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));

        return self::SUCCESS;
    }
}
