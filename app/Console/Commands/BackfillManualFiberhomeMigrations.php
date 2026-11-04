<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Services\CustomerMigrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillManualFiberhomeMigrations extends Command
{
    protected $signature = 'customer-migrations:backfill-manual-fiberhome
        {--psb-id=* : Specific psb_drafts IDs to move}
        {--since= : Created-at lower bound, default today Asia/Jakarta}
        {--activity-log : Also import old non-FHTT to new FHTT customer activity logs}
        {--days=30 : Activity-log lookback days}
        {--limit=1000 : Activity-log scan limit}
        {--dry-run : Preview only}';

    protected $description = 'Move manual FiberHome PSB draft rows into the customer migrations table';

    public function handle(CustomerMigrationService $service): int
    {
        if (! Schema::hasTable('customer_migrations') || ! Schema::hasTable('psb_drafts')) {
            $this->error('Required tables are not ready.');

            return self::FAILURE;
        }

        $ids = array_values(array_filter(array_map('intval', (array) $this->option('psb-id'))));
        $query = PsbDraft::query()
            ->where(function ($q) {
                $q->where('group_jid', 'manual@ebilling')
                    ->orWhere('sender_name', 'like', 'manual%');
            })
            ->whereNotNull('pppoe_user')
            ->whereNotNull('sn')
            ->orderBy('id');

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $since = trim((string) $this->option('since'));
            $query->where('created_at', '>=', $since !== '' ? $since : now('Asia/Jakarta')->startOfDay()->timezone('UTC'));
        }

        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;

        foreach ($query->get() as $draft) {
            $existing = CustomerMigration::query()
                ->where('source', 'manual_fiberhome')
                ->where('pppoe_user', $draft->pppoe_user)
                ->where('new_sn', strtoupper((string) $draft->sn))
                ->first();

            if ($existing) {
                $this->line("skip psb#{$draft->id}: already migration#{$existing->id}");

                continue;
            }

            if ($dryRun) {
                $this->line("would move psb#{$draft->id}: {$draft->pppoe_user} / {$draft->sn}");

                continue;
            }

            $migration = $service->recordManualFiberhomeProvision([
                'name' => $draft->pppoe_user,
                'pppoe_user' => $draft->pppoe_user,
                'full_name' => $draft->full_name,
                'sn' => $draft->sn,
                'port' => $draft->pon_port,
                'onu_id' => $draft->onu_id,
                'olt_id' => $draft->olt_id,
                'router_id' => $draft->router_id,
                'vlan' => $draft->vlan,
                'tr069_vlan' => $draft->tr069_vlan,
                'psb_draft_id' => $draft->id,
                'psb_created_at' => optional($draft->created_at)->toIso8601String(),
            ]);

            if (! $migration) {
                $this->warn("failed psb#{$draft->id}: recorder returned empty");

                continue;
            }

            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['moved_to_customer_migration_id'] = $migration->id;
            $meta['moved_to_customer_migration_at'] = now()->toIso8601String();
            $draft->forceFill([
                'status' => PsbDraft::STATUS_CANCELLED,
                'last_error' => "Dipindahkan ke menu Migrasi #{$migration->id}",
                'meta' => $meta,
            ])->save();

            $moved++;
            $this->info("moved psb#{$draft->id} -> migration#{$migration->id}");
        }

        if ((bool) $this->option('activity-log')) {
            $moved += $this->backfillActivityLogs($dryRun);
        }

        $this->info("moved={$moved}");

        return self::SUCCESS;
    }

    protected function backfillActivityLogs(bool $dryRun): int
    {
        if (! Schema::hasTable('activity_log')) {
            $this->warn('activity_log table is not available.');

            return 0;
        }

        $days = max(1, (int) $this->option('days'));
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $rows = DB::table('activity_log')
            ->where('subject_type', Customer::class)
            ->where('created_at', '>=', now()->subDays($days))
            ->where('properties', 'like', '%onu_serial%')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'subject_id', 'properties', 'created_at']);

        $moved = 0;
        foreach ($rows as $row) {
            $props = json_decode((string) $row->properties, true);
            if (! is_array($props)) {
                continue;
            }

            $old = is_array($props['old'] ?? null) ? $props['old'] : [];
            $attr = is_array($props['attributes'] ?? null) ? $props['attributes'] : [];
            $oldSn = $this->serial((string) ($old['onu_serial'] ?? ''));
            $newSn = $this->serial((string) ($attr['onu_serial'] ?? ''));
            if ($oldSn === '' || $newSn === '' || ! str_starts_with($newSn, 'FHTT') || str_starts_with($oldSn, 'FHTT')) {
                continue;
            }

            $customer = Customer::with(['area', 'router', 'olt'])->find((int) $row->subject_id);
            if (! $customer) {
                continue;
            }

            $existing = CustomerMigration::query()
                ->where('customer_id', $customer->id)
                ->where('new_sn', $newSn)
                ->first();
            if ($existing) {
                continue;
            }

            if ($dryRun) {
                $this->line("would import activity#{$row->id}: {$customer->pppoe_user} {$oldSn} -> {$newSn}");

                continue;
            }

            $migration = CustomerMigration::create([
                'status' => CustomerMigration::STATUS_SYNCED,
                'source' => 'activity_log_fhtt',
                'group_jid' => 'backfill@ebilling',
                'sender_name' => 'activity-log-backfill',
                'message_id' => 'activity-migrasi-fhtt-'.$row->id,
                'raw_text' => '[activity log migration] '.$customer->pppoe_user,
                'customer_id' => $customer->id,
                'pppoe_user' => $customer->pppoe_user,
                'full_name' => $customer->name,
                'phone' => $customer->phone,
                'area_id' => $customer->area_id,
                'old_router_id' => $customer->router_id,
                'old_olt_id' => $customer->olt_id,
                'old_onu_ref' => $old['olt_port_label'] ?? null,
                'old_sn' => $oldSn ?: null,
                'router_id' => $customer->router_id,
                'new_olt_id' => $customer->olt_id,
                'new_onu_ref' => $attr['olt_port_label'] ?? $customer->olt_port_label,
                'new_sn' => $newSn,
                'last_error' => 'Backfill dari activity_log; ONU lama sudah/harus dicek manual.',
                'olt_at' => $row->created_at,
                'synced_at' => $row->created_at,
                'meta' => [
                    'activity_log_id' => $row->id,
                    'activity_log_created_at' => (string) $row->created_at,
                    'old_onu_delete' => 'not_executed_backfill',
                ],
            ]);

            $moved++;
            $this->info("imported activity#{$row->id} -> migration#{$migration->id}");
        }

        return $moved;
    }

    protected function serial(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $value) ?? '');
    }
}
