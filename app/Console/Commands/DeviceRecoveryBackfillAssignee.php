<?php

namespace App\Console\Commands;

use App\Models\DeviceRecoveryCase;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Owner 2026-10-09: one-off backfill for pickup cases created before
 * auto-assign. Dry run by default; --execute assigns `waiting_assignment`
 * cases to their dismantle ticket's technician (same audit path as the live
 * flow). Cases without a ticket technician are reported and left alone.
 */
class DeviceRecoveryBackfillAssignee extends Command
{
    protected $signature = 'device-recovery:backfill-assignee
        {--cases= : Comma-separated case ids (default: all waiting_assignment cases)}
        {--execute : Write the assignments (default is a dry run)}
        {--dry-run : Report only, never write (wins over --execute)}
        {--actor=1 : User id recorded as actor in the case history}
        {--notify : Send the pickup WA to each newly assigned technician}';

    protected $description = 'Assign waiting pickup cases to their dismantle ticket technician (dry run by default)';

    public function handle(DeviceRecoveryService $service): int
    {
        $execute = (bool) $this->option('execute') && ! $this->option('dry-run');

        $actor = User::query()->find((int) $this->option('actor'));
        if (! $actor) {
            $this->error('Actor user #'.$this->option('actor').' tidak ditemukan.');

            return self::FAILURE;
        }
        if ($execute && config('device-recovery.mode') !== 'write') {
            $this->error('Pengambilan Perangkat sedang read-only; backfill tidak dijalankan.');

            return self::FAILURE;
        }

        $query = DeviceRecoveryCase::query()
            ->with(['ticket.assignee:id,name,role', 'customer:id,code'])
            ->orderBy('id');
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('cases')))));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('status', DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT);
        }

        $rows = [];
        foreach ($query->get() as $case) {
            $assignee = $case->ticket?->assignee;
            $action = $this->plannedAction($case, $assignee);

            if ($execute && $action === 'assign') {
                $result = $service->assignFromTicket($case, $actor);
                $action = $result === 'assigned' ? 'assigned' : $this->label($result);
                if ($result === 'assigned' && $this->option('notify')) {
                    $action .= $service->notifyAssignee($case) ? ' + WA' : ' (WA gagal)';
                }
            }

            $rows[] = [
                'case_id' => $case->id,
                'ticket_id' => $case->ticket_id ?? '-',
                'customer_code' => $case->customer?->code ?? '-',
                'ticket_assignee' => $assignee ? $assignee->name.' (#'.$assignee->id.')' : '-',
                'case_status' => $case->status,
                'action' => $action,
            ];
        }

        if ($rows !== []) {
            $this->table(array_keys($rows[0]), $rows);
        }
        $assigned = count(array_filter($rows, fn (array $row): bool => str_starts_with($row['action'], $execute ? 'assigned' : 'assign')));
        $this->info(sprintf(
            'mode=%s cases=%d %s=%d skipped=%d',
            $execute ? 'execute' : 'dry-run',
            count($rows),
            $execute ? 'assigned' : 'would_assign',
            $assigned,
            count($rows) - $assigned,
        ));
        if (! $execute) {
            $this->line('Dry run: tidak ada yang diubah. Jalankan dengan --execute untuk menulis.');
        } else {
            Log::info('[device-recovery] backfill-assignee', [
                'actor_id' => $actor->id,
                'assigned_case_ids' => array_column(array_filter($rows, fn (array $row): bool => str_starts_with($row['action'], 'assigned')), 'case_id'),
            ]);
        }

        return self::SUCCESS;
    }

    private function plannedAction(DeviceRecoveryCase $case, ?User $assignee): string
    {
        if ($case->status !== DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT) {
            return $this->label('skipped_status');
        }
        if (! $assignee) {
            return $this->label('skipped_no_ticket_assignee');
        }
        if (! $assignee->isTeknisi()) {
            return $this->label('skipped_not_technician');
        }

        return 'assign';
    }

    private function label(string $result): string
    {
        return match ($result) {
            'skipped_status' => 'skip: status bukan waiting_assignment',
            'skipped_no_ticket_assignee' => 'skip: tiket belum ada teknisi',
            'skipped_not_technician' => 'skip: assignee tiket bukan teknisi',
            default => $result,
        };
    }
}
