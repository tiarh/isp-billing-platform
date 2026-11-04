<?php

namespace App\Console\Commands;

use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Support\OpsTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CleanupCustomerMigrations extends Command
{
    protected $signature = 'migrations:cleanup-stale
        {--apply : Apply changes; default is dry-run}
        {--days=1 : Minimum age in days}
        {--limit=200 : Max live migration rows per run}
        {--cleanup-staging : Remove resolved/applied staging rows too}';

    protected $description = 'Archive stale migration rows and optionally cleanup resolved import staging rows';

    public function handle(): int
    {
        if (! Schema::hasTable('customer_migrations')) {
            $this->warn('customer_migrations table missing.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $cutoff = OpsTime::now()->subDays($days);
        $actor = $apply ? 'migrations:cleanup-stale --apply' : 'migrations:cleanup-stale --dry-run';

        $query = CustomerMigration::query()
            ->whereIn('status', [
                CustomerMigration::STATUS_RECEIVED,
                CustomerMigration::STATUS_WAITING_SN,
                CustomerMigration::STATUS_NEEDS_REVIEW,
                CustomerMigration::STATUS_FAILED,
            ])
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit($limit);

        $rows = $query->get();
        $archive = [];
        $review = [];

        foreach ($rows as $migration) {
            $reason = $this->archiveReason($migration);
            if ($reason === null) {
                $review[] = [$migration->id, $migration->status, $migration->full_name, 'review'];

                continue;
            }

            $archive[] = [$migration->id, $migration->status, $migration->full_name, $reason];

            if ($apply) {
                $meta = is_array($migration->meta) ? $migration->meta : [];
                $meta['cleanup_archive'] = [
                    'status' => 'archived_as_cancelled',
                    'reason' => $reason,
                    'archived_by' => $actor,
                    'archived_at' => OpsTime::now()->toIso8601String(),
                    'previous_status' => $migration->status,
                ];

                $migration->forceFill([
                    'status' => CustomerMigration::STATUS_CANCELLED,
                    'last_error' => 'Diarsip otomatis: '.$reason,
                    'meta' => $meta,
                ])->save();
            }
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' live migrations');
        $this->table(['ID', 'Status', 'Nama', 'Reason'], array_slice($archive, 0, 30));
        $this->line('archive_candidates='.count($archive).' review_skipped='.count($review));

        if ($this->option('cleanup-staging')) {
            $this->cleanupStaging($apply);
        }

        return self::SUCCESS;
    }

    private function archiveReason(CustomerMigration $migration): ?string
    {
        if ($this->hasSyncedSibling($migration)) {
            return 'sudah ada migrasi synced untuk identitas yang sama';
        }

        return match ($migration->status) {
            CustomerMigration::STATUS_FAILED => 'gagal lebih dari batas hari dan belum aktif lagi',
            CustomerMigration::STATUS_RECEIVED,
            CustomerMigration::STATUS_WAITING_SN,
            CustomerMigration::STATUS_NEEDS_REVIEW => 'pending/review lebih dari batas hari',
            default => null,
        };
    }

    private function hasSyncedSibling(CustomerMigration $migration): bool
    {
        $customerId = $migration->customer_id;
        $newSn = trim((string) $migration->new_sn);
        $pppoeUser = trim((string) $migration->pppoe_user);

        if (! $customerId && $newSn === '' && $pppoeUser === '') {
            return false;
        }

        return CustomerMigration::query()
            ->whereKeyNot($migration->id)
            ->where('status', CustomerMigration::STATUS_SYNCED)
            ->where(function ($query) use ($customerId, $newSn, $pppoeUser) {
                if ($customerId) {
                    $query->orWhere('customer_id', $customerId);
                }
                if ($newSn !== '') {
                    $query->orWhere('new_sn', $newSn);
                }
                if ($pppoeUser !== '') {
                    $query->orWhere('pppoe_user', $pppoeUser);
                }
            })
            ->exists();
    }

    private function cleanupStaging(bool $apply): void
    {
        if (! Schema::hasTable('customer_migration_import_rows')) {
            $this->warn('customer_migration_import_rows table missing.');

            return;
        }

        $query = CustomerMigrationImportRow::query()
            ->where(function ($query) {
                $query->whereIn('status', ['applied', 'resolved'])
                    ->orWhere(function ($query) {
                        $query->whereNotNull('applied_at')
                            ->whereNull('applied_error');
                    });
            });

        $count = (clone $query)->count();
        if ($apply) {
            $query->delete();
        }

        $this->info(($apply ? 'APPLY' : 'DRY-RUN').' staging cleanup resolved_rows='.$count);
    }
}
