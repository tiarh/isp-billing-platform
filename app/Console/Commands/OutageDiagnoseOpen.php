<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use App\Services\Outage\Diagnosis\OutageCorrelator;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4: every 15 minutes, a fresh stored "auto_refresh" diagnosis for each OPEN incident ticket of a customer
 * (cap per run). A ticket that has no diagnosis at all yet (created through another path than the web form) gets
 * its first one as "ticket_open" here, so it still has a prediction for the weekly precision report.
 * DB only: no router / OLT / ACS I/O, nothing is queued. Flag diagnosis_enabled = 0 turns it off.
 */
class OutageDiagnoseOpen extends Command
{
    protected $signature = 'outage:diagnose-open {--cap=200 : Max tickets per run}';

    protected $description = 'Refresh the stored diagnosis of open incident tickets (RCA-4, DB only)';

    /**
     * A "Cek sekarang" whose browser never called finish keeps raw probe answers in its row: after 10 minutes the
     * check is closed as failed and only the status / reason / text of each probe stays.
     */
    private function purgeAbandonedChecks(): void
    {
        OutageDiagnosis::query()->where('state', OutageDiagnosis::STATE_RUNNING)->where('created_at', '<', now()->subMinutes(10))->get()
            ->each(function (OutageDiagnosis $d): void {
                $d->state = OutageDiagnosis::STATE_FAILED;
                $d->probes = OutageDiagnoser::probeSummary((array) $d->probes);
                $d->save();
            });
    }

    public function handle(OutageDiagnoser $diagnoser, OutageCorrelator $correlator): int
    {
        try {
            if (! OutageSettings::diagnosisEnabled() || ! Schema::hasTable('outage_diagnoses')) {
                $this->line('diagnosis disabled');

                return self::SUCCESS;
            }
            $this->purgeAbandonedChecks();
            $cap = max(1, min(1000, (int) $this->option('cap')));
            // newest tickets first: with more than the cap open, the oldest ones wait, not the fresh complaints
            $tickets = SupportTicket::query()->where('type', 'incident')->whereNotNull('customer_id')
                ->whereNotIn('status', ['resolved', 'closed', 'cancelled'])
                ->orderByDesc('id')->limit($cap)->get(['id', 'customer_id']);
            $done = 0;
            foreach ($tickets as $ticket) {
                $customer = Customer::query()->find($ticket->customer_id);
                if ($customer === null) {
                    continue;
                }
                try {
                    $last = OutageDiagnosis::query()->where('ticket_id', $ticket->id)->where('state', OutageDiagnosis::STATE_DONE)->orderByDesc('id')->first(['id', 'cause_code', 'confidence', 'created_at']);
                    $correlator->reset();
                    if ($last !== null) {
                        // an unchanged answer is not stored again every 15 minutes (at most one row per 3 hours)
                        $now = $diagnoser->preview($customer);
                        if ($now['cause'] === $last->cause_code && $now['confidence'] === $last->confidence && $last->created_at !== null && $last->created_at->gt(now()->subHours(3))) {
                            continue;
                        }
                    }
                    $diagnoser->diagnose($customer, $last !== null ? OutageDiagnosis::TRIGGER_AUTO_REFRESH : OutageDiagnosis::TRIGGER_TICKET_OPEN, ['ticket_id' => $ticket->id]);
                    $done++;
                } catch (\Throwable $e) {
                    Log::warning('[Outage] diagnose-open: one ticket failed', ['ticket_id' => $ticket->id, 'exception_class' => $e::class]);
                }
            }
            $this->line('diagnosed '.$done.' open incident ticket(s)');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] outage:diagnose-open failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }
            $this->warn('outage:diagnose-open failed: '.$e::class);

            return self::SUCCESS;
        }
    }
}
