<?php

namespace App\Console\Commands;

use App\Enums\OutageRootCause;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\User;
use App\Services\Outage\OutageShadowReporter;
use Illuminate\Console\Command;

/**
 * Roadmap 2.4 PR-4: NOC label for a shadow "would have ticketed" episode (tp|fp|unsure). Writes only
 * outage_signals.label / labelled_by. --pending lists episodes still waiting for a label (no PII).
 */
class OutageShadowLabel extends Command
{
    protected $signature = 'outage:shadow-label
        {id? : outage_signals id}
        {label? : tp|fp|unsure|clear}
        {--user= : User id of the NOC person (optional)}
        {--cause= : Optional root-cause code (App\Enums\OutageRootCause, e.g. drop_los, pon_cut, unknown)}
        {--pending : List unlabelled would-have-ticketed episodes instead}
        {--limit=30 : Max rows for --pending}';

    protected $description = 'Label a shadow outage episode as true/false positive (writes only the label)';

    public function handle(): int
    {
        if ($this->option('pending')) {
            return $this->pending();
        }

        $id = (int) $this->argument('id');
        $label = strtolower(trim((string) $this->argument('label')));
        if ($id <= 0 || ! in_array($label, ['tp', 'fp', 'unsure', 'clear'], true)) {
            $this->error('Usage: outage:shadow-label {id} {tp|fp|unsure|clear} [--user=ID]   or   --pending');

            return self::FAILURE;
        }

        $signal = OutageSignal::query()->find($id);
        if ($signal === null) {
            $this->error('No such episode: '.$id);

            return self::FAILURE;
        }

        $userId = null;
        if ($this->option('user') !== null && $this->option('user') !== '') {
            $userId = (int) $this->option('user');
            if ($userId <= 0 || ! User::query()->whereKey($userId)->exists()) {
                $this->error('Unknown --user.');

                return self::FAILURE;
            }
        }

        $cause = null;
        if ($this->option('cause') !== null && $this->option('cause') !== '') {
            $cause = strtolower(trim((string) $this->option('cause')));
            if (! in_array($cause, OutageRootCause::codes(), true)) {
                $this->error('Unknown --cause. Allowed: '.implode(', ', OutageRootCause::codes()));

                return self::FAILURE;
            }
        }

        $signal->label = $label === 'clear' ? null : $label;
        $signal->labelled_by = $label === 'clear' ? null : $userId;
        if ($label === 'clear') {
            $signal->cause_code = null;
        } elseif ($cause !== null) {
            $signal->cause_code = $cause;
        }
        $signal->save();

        $this->line('Episode '.$id.' ('.$signal->signal_type.') label = '.($signal->label ?? 'none').($signal->cause_code ? ', cause = '.$signal->cause_code : ''));

        return self::SUCCESS;
    }

    private function pending(): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $names = Olt::query()->pluck('name', 'id');
        $rows = OutageSignal::query()
            ->whereIn('would_action', OutageShadowReporter::WOULD_TICKET)
            ->whereNull('label')
            ->orderByDesc('confirmed_at')
            ->limit($limit)
            ->get()
            ->map(fn (OutageSignal $s) => [
                $s->id, $s->signal_type, (string) ($names[$s->olt_id] ?? $s->olt_id), (string) $s->pon_port,
                (int) $s->affected_count, optional($s->confirmed_at)->toDateTimeString(),
                $s->matched_ticket_id ? 'matched' : 'no ticket',
            ])->all();

        $this->table(['id', 'type', 'olt', 'pon', 'n', 'confirmed', 'ticket'], $rows);

        return self::SUCCESS;
    }
}
