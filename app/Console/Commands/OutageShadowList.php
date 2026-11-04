<?php

namespace App\Console\Commands;

use App\Models\Olt;
use App\Models\OutageSignal;
use Illuminate\Console\Command;

/**
 * Read-only list of shadow detections for owner review (roadmap 2.4). Writes nothing, sends nothing.
 */
class OutageShadowList extends Command
{
    protected $signature = 'outage:shadow-list
        {--days=3 : Look back this many days}
        {--type= : mass_los|single_los|dying_gasp|olt_wide}
        {--state= : open|confirmed|resolved|expired}
        {--confirmed : Only episodes that reached "would have ticketed"}
        {--limit=50 : Max rows}
        {--json : JSON output}';

    protected $description = 'List shadow outage detections (read-only)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, min(500, (int) $this->option('limit')));

        $q = OutageSignal::query()->where('opened_at', '>=', now()->subDays($days))->orderByDesc('opened_at');
        if ($type = (string) $this->option('type')) {
            $q->where('signal_type', $type);
        }
        if ($state = (string) $this->option('state')) {
            $q->where('state', $state);
        }
        if ($this->option('confirmed')) {
            $q->whereNotNull('confirmed_at');
        }

        $oltNames = Olt::query()->pluck('name', 'id');
        $rows = $q->limit($limit)->get()->map(fn (OutageSignal $s) => [
            'id' => $s->id,
            'type' => $s->signal_type,
            'state' => $s->state,
            'olt' => (string) ($oltNames[$s->olt_id] ?? $s->olt_id),
            'pon' => (string) $s->pon_port,
            'affected' => (int) $s->affected_count,
            'opened_at' => optional($s->opened_at)->toDateTimeString(),
            'confirmed_at' => optional($s->confirmed_at)->toDateTimeString(),
            'resolved_at' => optional($s->resolved_at)->toDateTimeString(),
            'would_action' => (string) $s->would_action,
            'suppressed' => (string) $s->suppressed_reason,
            'flaps' => (int) $s->flap_count,
            'cause_unknown' => (bool) (($s->payload['cause_unknown'] ?? false)),
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->values(), JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Shadow detections, last '.$days.' day(s): '.$rows->count().' shown (no tickets, no WA were created)');
        $this->table(
            ['id', 'type', 'state', 'olt', 'pon', 'n', 'opened', 'confirmed', 'resolved', 'would', 'suppressed', 'flaps'],
            $rows->map(fn ($r) => [$r['id'], $r['type'].($r['cause_unknown'] ? '*' : ''), $r['state'], $r['olt'], $r['pon'], $r['affected'], $r['opened_at'], $r['confirmed_at'], $r['resolved_at'], $r['would_action'], $r['suppressed'], $r['flaps']])->all(),
        );
        $this->line('* = cause unknown (Huawei: online/offline only)');

        $summary = OutageSignal::query()->where('opened_at', '>=', now()->subDays($days))->whereNotNull('confirmed_at')
            ->selectRaw('signal_type, count(*) as c')->groupBy('signal_type')->pluck('c', 'signal_type');
        $this->line('Would-have-ticketed (confirmed) by type: '.json_encode($summary));

        return self::SUCCESS;
    }
}
