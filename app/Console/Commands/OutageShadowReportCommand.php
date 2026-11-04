<?php

namespace App\Console\Commands;

use App\Services\Outage\OutageShadowReporter;
use App\Services\Outage\OutageTicketMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Roadmap 2.4 PR-4: shadow accuracy report (precision / recall / lead time / coverage) with optional
 * threshold replay. Reads tickets and customers; the only write is the matched_* columns on
 * outage_signals (skip with --no-match). Sends nothing.
 */
class OutageShadowReportCommand extends Command
{
    protected $signature = 'outage:shadow-report
        {--from= : Start (default 7 days ago)}
        {--to= : End (default now)}
        {--replay= : e.g. "mass_min:3,4,5;single_los:20,30,45;weak_rx:-26,-27,-28"}
        {--no-match : Do not refresh ticket matches first}
        {--json : JSON output}';

    protected $description = 'Shadow outage accuracy report with threshold replay (read-only, no WA, no tickets)';

    public function handle(): int
    {
        try {
            // Dates are WIB wall clock (NOC runs this by date); the app timezone is UTC.
            $to = $this->option('to') ? Carbon::parse((string) $this->option('to'), 'Asia/Jakarta') : now();
            $from = $this->option('from') ? Carbon::parse((string) $this->option('from'), 'Asia/Jakarta') : $to->copy()->subDays(7);
        } catch (\Throwable) {
            $this->error('Invalid --from/--to date.');

            return self::FAILURE;
        }
        if ($from->gte($to)) {
            $this->error('--from must be before --to.');

            return self::FAILURE;
        }

        $matcher = $this->option('no-match') ? null : (new OutageTicketMatcher)->run($from, $to);
        $report = (new OutageShadowReporter)->build($from, $to, OutageShadowReporter::parseReplay((string) $this->option('replay')));
        $report['matcher'] = $matcher;

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('Shadow report '.$report['from'].' .. '.$report['to'].' ('.$report['days'].' day(s)); no tickets, no WA were created');
        $rows = [];
        foreach ($report['signals'] as $type => $s) {
            $rows[] = [
                $type, $s['opened'], $s['confirmed'], $s['would_ticket'], $s['would_ticket_per_day'],
                $s['precision_matched'] ?? '-', $s['precision_label'] ?? '-', $s['label_sample'].($s['label_sample_ok'] ? '' : ' (<'.OutageShadowReporter::LABEL_MIN_SAMPLE.')'),
                $s['lead_time_median_min'] ?? '-', json_encode($s['suppressed']),
            ];
        }
        $this->table(['type', 'opened', 'confirmed', 'would', '/day', 'prec(match)', 'prec(label)', 'labels', 'lead min', 'suppressed'], $rows);

        $r = $report['recall'];
        $this->line('Recall: '.($r['recall'] ?? '-').' ('.$r['covered'].'/'.$r['monitorable_tickets'].' monitorable of '.$r['real_tickets'].' real incident tickets); missed: '.json_encode($r['missed_reasons']));
        $c = $report['coverage'];
        $this->line('Coverage: '.$c['monitorable'].'/'.$c['active_customers'].' active customers monitorable ('.json_encode($c['per_olt']).')');
        $s = $report['suppression'];
        $this->line('Suppression: PSB '.$s['psb_customers_suppressing'].'/'.$s['psb_customers_open'].' customers suppress (<='.$s['psb_days'].'d, '.$s['psb_customers_aged_out'].' aged out); migration '
            .$s['migration_customers_suppressing'].'/'.$s['migration_customers_open'].' (<='.$s['migration_days'].'d, '.$s['migration_customers_aged_out'].' aged out); episodes in window suppressed by psb '
            .$s['episodes_suppressed_by_psb'].', migration '.$s['episodes_suppressed_by_migration']);
        foreach ($report['data_blind']['open'] as $b) {
            $this->warn('Data tidak terbaca: '.$b['label'].' - '.$b['reason'].' since '.$b['since']);
        }
        $ic = $report['incident_closes'];
        $this->line('Incident tickets closed: '.$ic['with_cause'].' with a cause, '.$ic['auto_closed'].' Ditutup otomatis / tanpa penyebab');
        if ($report['manual_tickets'] !== []) {
            $this->line('Manual tickets (button "Buat tiket"; not counted as match or recall):');
            $mrows = [];
            foreach ($report['manual_tickets'] as $type => $m) {
                $mrows[] = [
                    $type, $m['shown'], $m['clicked'], $m['click_rate'] ?? '-', json_encode($m['state_at_click']),
                    $m['median_open_to_click_min'] ?? '-', $m['median_confirm_to_click_min'] ?? '-',
                    $m['outcome']['real_fault'].'/'.$m['outcome']['not_fault'].'/'.$m['outcome']['unknown'].'/'.$m['outcome']['still_open'],
                    $m['as_guessed'], $m['proxy_precision'] ?? '-'.($m['proxy_sample_ok'] ? '' : ' (<'.OutageShadowReporter::LABEL_MIN_SAMPLE.')'),
                ];
            }
            $this->table(['type', 'shown', 'clicked', 'rate', 'state at click', 'open>click min', 'confirm>click min', 'real/not/unknown/open', 'as guessed', 'proxy prec'], $mrows);
        }
        $w = $report['weak_rx_weekly'];
        $this->line('Weak RX weekly list: '.$w['count'].' customers beyond the cap of '.$w['max_per_day'].' maintenance tickets per day');
        foreach ($report['stale_olts'] as $o) {
            $this->warn('Stale collector data: '.$o['olt'].' ('.($o['minutes_since_success'] ?? 'no success run').' min)');
        }
        foreach ($report['replay'] as $key => $rowsFor) {
            $this->line('Replay '.$key.':');
            $this->table(['threshold', 'would', '/day', 'matched', 'precision', 'no data'], array_map(
                fn ($x) => [$x['threshold'], $x['would_ticket'], $x['per_day'], $x['matched'], $x['precision_matched'] ?? '-', $x['no_data']],
                $rowsFor,
            ));
        }

        return self::SUCCESS;
    }
}
