<?php

namespace App\Console\Commands;

use App\Services\PsbAreaRecapService;
use App\Services\Recap\MigrationRecapService;
use App\Services\Recap\OnuHealthService;
use App\Services\Recap\RecapRequest;
use App\Services\Recap\RecapTopicMessages;
use App\Services\Recap\RecapTopics;
use App\Support\OpsTime;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Prints the reply of a "#rekap <topic> ..." command to the terminal (read-only acceptance tool).
 * Sends no WA message, writes no file, writes no row; the flags are NOT consulted.
 */
class RecapPreviewCommand extends Command
{
    protected $signature = 'recap:preview
        {text : the command text, e.g. "#rekap onu klampok"}
        {--now= : pretend "now" is this WIB time (Y-m-d H:i)}
        {--stats : also print counts and timing}';

    protected $description = 'Preview a #rekap <topic> reply (read-only: no WA, no files, no writes)';

    public function handle(PsbAreaRecapService $areas, OnuHealthService $onu, RecapTopicMessages $messages): int
    {
        try {
            return $this->preview($areas, $onu, $messages);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    private function preview(PsbAreaRecapService $areas, OnuHealthService $onu, RecapTopicMessages $messages): int
    {
        $now = OpsTime::now();
        if (trim((string) $this->option('now')) !== '') {
            try {
                $now = CarbonImmutable::parse((string) $this->option('now'), OpsTime::TZ);
            } catch (\Throwable) {
                $this->error('Invalid --now, expected "Y-m-d H:i" (WIB).');

                return self::FAILURE;
            }
            Carbon::setTestNow($now);
            CarbonImmutable::setTestNow($now);
        }

        $text = (string) $this->argument('text');
        $topic = RecapTopics::matchTopic($text);
        if ($topic === null) {
            $this->error('Not a supported "#rekap <topic>" command (supported: '.implode(', ', RecapTopics::topics()).'; psb has its own command).');

            return self::FAILURE;
        }

        $request = RecapTopics::parser($topic)?->parse($text, $now);
        if ($request === null) {
            $this->error('Could not parse the command.');

            return self::FAILURE;
        }

        $this->line('DRY: nothing is sent, no file is written.');
        if ($request->error !== null) {
            $this->line(match ($request->error) {
                RecapRequest::ERROR_INVALID_DATE => $messages->invalidDate($topic),
                RecapRequest::ERROR_RANGE_TOO_LONG => $messages->rangeTooLong($request->maxDays),
                default => $messages->usage($topic),
            });

            return self::SUCCESS;
        }

        $area = $areas->resolveArea($request);
        if ($area['status'] === 'ambiguous') {
            $this->line($messages->areaAmbiguous($request->areaQuery, $area['candidates']));

            return self::SUCCESS;
        }
        if ($area['status'] === 'unmatched') {
            $this->line($messages->areaUnmatched($request->areaQuery, $area['candidates']));

            return self::SUCCESS;
        }

        if ($topic === RecapTopics::MIGRASI) {
            return $this->previewMigrasi($request, $area, $now, $messages);
        }

        $t0 = microtime(true);
        $data = $onu->collect($area['area_id'], $area['area_name'], $now, $request->dateIgnored);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $this->line('---');
        $this->line($messages->onuRecap($data));
        $this->line('---');

        if ($this->option('stats')) {
            $this->line('collect: '.$ms.' ms | peak memory: '.round(memory_get_peak_usage(true) / 1048576, 1).' MB | problem rows (Excel): '.$data['problem_total']);
            $c = $data['counts'];
            unset($c['weak_worst']);
            $this->line('counts: '.json_encode($c));
            foreach ($data['by_olt'] as $o) {
                $this->line(sprintf('olt %-14s terpantau %d offline %d weak %d stale %d last %s', $o['name'], $o['terpantau'], $o['offline'], $o['weak'], $o['stale'], $o['last'] !== null ? OpsTime::format($o['last']) : '-'));
            }
            $this->line('usage_stale: '.($data['usage_stale'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{status: string, area_id: ?int, area_name: string, candidates: list<string>}  $area
     */
    private function previewMigrasi(RecapRequest $request, array $area, CarbonImmutable $now, RecapTopicMessages $messages): int
    {
        $t0 = microtime(true);
        $service = app(MigrationRecapService::class);
        // The evidence flag is NOT consulted here (like every other flag): the preview always shows it.
        $data = $service->collect($request, $area['area_id'], $area['area_name'], $now, true);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $this->line('---');
        $this->line($messages->migrasiRecap($data));
        $this->line('---');

        if ($this->option('stats')) {
            $this->line('collect: '.$ms.' ms | peak memory: '.round(memory_get_peak_usage(true) / 1048576, 1).' MB | rows (Excel): '.$data['total']);
            $this->line('selesai: '.json_encode(['lapangan' => $data['selesai']['lapangan'], 'impor' => $data['selesai']['impor'], 'db' => $data['selesai']['db'], 'auto' => $data['selesai']['auto']]));
            $this->line('masuk: '.$data['masuk'].' | belum: '.json_encode($data['belum']));
            $this->line('online_pending: '.$data['online_pending'].' | perlu_dicek: '.$data['perlu_dicek'].' | evidence_failed: '.($data['evidence_failed'] ? 'yes' : 'no'));
        }

        return self::SUCCESS;
    }
}
