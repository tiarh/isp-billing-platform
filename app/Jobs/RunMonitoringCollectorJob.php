<?php

namespace App\Jobs;

use App\Services\MonitoringAdmissionGate;
use App\Services\MonitoringQueueContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Runs one fixed read collector on its isolated monitoring lane.
 * The dispatcher owns the allowlist; request input never selects a command.
 */
class RunMonitoringCollectorJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // The PON correlation scan is bounded by the ops worker's 660s timeout;
    // leave headroom for graceful queue acknowledgement instead of failing
    // a single-attempt job at 180s while the collector is still read-only.
    public int $timeout = 600;

    public int $uniqueFor = 900;

    /** @param array<string, mixed> $parameters */
    public function __construct(
        public string $lane,
        public string $command,
        public array $parameters = [],
        public string $target = 'fleet',
        ?int $timeoutSeconds = null,
    ) {
        // Per-target worker timeout (serialized into the queue payload) so a
        // slow collector frees the shared ops worker early instead of holding
        // it for the full 600s default.
        if ($timeoutSeconds !== null && $timeoutSeconds > 0) {
            $this->timeout = max(30, min(600, $timeoutSeconds));
        }

        $this->onQueue(app(MonitoringQueueContract::class)->queueFor($lane));
    }

    public function uniqueId(): string
    {
        return 'monitoring-collector:'.$this->lane.':'.$this->target;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                // Collector jobs are single-attempt and periodic. Dropping a
                // duplicate is safer than releasing it into MaxAttemptsExceeded.
                ->dontRelease()
                // A worker killed on timeout cannot release this lock; let it
                // lapse shortly after the job's own timeout (720s at 600s).
                ->expireAfter(max(240, $this->timeout + 120)),
        ];
    }

    public function isRouterSessionLane(): bool
    {
        return $this->lane === 'router' && $this->command === 'routers:mt-session-sync';
    }

    public function handle(MonitoringAdmissionGate $gate): void
    {
        // A command such as genieacs:sync may change PHP's process-wide
        // execution timer. Queue workers are long-lived, so clear that state
        // before and after each monitoring command to protect the next job.
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);
        try {
            // The OLT lane carries core NOC truth (RX + state + inventory) and
            // must not be held by unrelated host-load/queue pressure. It still
            // honors an explicit operator hold and refuses without DB.
            if ($this->lane === 'olt') {
                $allowed = $gate->allowsOltCollector();
                $reasonCodes = ['olt_collector_lane'];
            } elseif ($this->isRouterSessionLane()) {
                // PPPoE freshness lane: host-load exempt, still yields to PSB/migration.
                $allowed = $gate->allowsRouterSessionCollector();
                $reasonCodes = ['router_session_lane'];
            } else {
                $decision = $gate->evaluate('collector:'.$this->lane);
                $allowed = (bool) ($decision['allowed'] ?? false);
                $reasonCodes = $decision['reason_codes'] ?? ['gate_denied'];
            }

            if (! $allowed) {
                Log::notice('Monitoring collector skipped by admission gate.', [
                    'lane' => $this->lane,
                    'target' => $this->target,
                    'reason_codes' => $reasonCodes,
                ]);

                return;
            }

            try {
                $exitCode = Artisan::call($this->command, $this->parameters);
            } catch (\Throwable $e) {
                // Monitoring is a snapshot lane: retain the last good snapshot and
                // keep the failure visible without creating a retry storm/failed
                // job that can compete with PSB and migration queues.
                Log::error('Monitoring collector threw; keeping the last snapshot.', [
                    'command' => $this->command,
                    'target' => $this->target,
                    'exception' => get_class($e),
                    'message' => mb_substr($e->getMessage(), 0, 500),
                ]);

                return;
            }

            if ($exitCode !== 0) {
                Log::warning('Monitoring collector returned a non-zero exit code; keeping the last snapshot.', [
                    'command' => $this->command,
                    'exit_code' => $exitCode,
                    'target' => $this->target,
                ]);
            }
        } finally {
            @ini_set('max_execution_time', '0');
            @set_time_limit(0);
        }
    }
}
