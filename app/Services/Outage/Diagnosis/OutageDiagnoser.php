<?php

namespace App\Services\Outage\Diagnosis;

use App\Enums\OutageRootCause as Cause;
use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\OutageSignal;
use App\Services\Outage\OutageCauseText;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4 classifier: turns the layered context of OutageCorrelator into ONE diagnosis (cause, confidence, evidence
 * chain, secondary causes, suggested action) by walking the rule table of Rules top-down. Rule based, no ML.
 *
 *  - classify() is pure (context in, result out): used by the tests and by "Cek sekarang".
 *  - diagnose() reads the context (DB only), classifies and stores an outage_diagnoses row.
 *
 * It never opens a device session; live probe answers arrive through `$opts['probes']`.
 */
class OutageDiagnoser
{
    /** Causes that never count as a conflict or a secondary cause. */
    private const NEUTRAL = ['unknown', 'data_blind', 'optical_degrade'];

    /** Rules whose answer explains every layer below it. */
    private const EXPLAINING_RULES = ['isolir_admin', 'core_router', 'olt_down', 'pon_odp_cut'];

    public function __construct(private readonly OutageCorrelator $correlator) {}

    /**
     * @param  array<string,mixed>  $ctx  from OutageCorrelator::contextFor()
     * @return array{cause_code:string,confidence:string,layer:string,rule_id:string,secondary:list<string>,evidence:list<array<string,mixed>>,suggested_action:string}
     */
    public function classify(array $ctx): array
    {
        $primary = null;
        $primaryId = null;
        $secondary = [];
        $conflict = false;
        $extraEvidence = [];

        foreach (Rules::table() as $id => $rule) {
            if ($id === 'blind_or_unknown' || ($rule['needs_data'] ?? false) || $rule['run'] === null) {
                continue; // needs_data rules (UPSTREAM) never run; the fallback is applied last
            }
            $hit = ($rule['run'])($ctx);
            if ($hit === null) {
                continue;
            }
            if ($primary === null && $hit['decisive']) {
                $primary = $hit;
                $primaryId = $id;
                foreach ($hit['secondary'] as $s) {
                    $secondary[$s] = $s;
                }

                continue;
            }
            // A higher layer (billing, router, OLT, PON) EXPLAINS the layers below it: that is not a conflict.
            if ($primary !== null && ! in_array($primaryId, self::EXPLAINING_RULES, true) && $hit['decisive'] && $hit['cause'] !== $primary['cause'] && ! in_array($hit['cause'], self::NEUTRAL, true) && ! in_array($hit['cause'], $primary['secondary'], true)) {
                $conflict = true;
            }
            if ($hit['cause'] !== ($primary['cause'] ?? null) && $hit['cause'] !== Cause::Unknown->value && $hit['cause'] !== Cause::DataBlind->value) {
                $secondary[$hit['cause']] = $hit['cause'];
                // a non-decisive isolir note (RCA-5) shows in the first lines, before the optical line
                if ($hit['cause'] === Cause::OpticalDegrade->value || $hit['cause'] === Cause::IsolirAdmin->value) {
                    $extraEvidence = array_merge($extraEvidence, $hit['evidence']);
                }
            }
        }

        if ($primary === null) {
            $primary = Rules::blindOrUnknown($ctx);
            $primaryId = 'blind_or_unknown';
        }

        // 2026-10-26.1 abstain gate: every class except billing needs at least one fresh hardware item (or an authoritative
        // event of the last 24 h); otherwise the honest answer is "unknown" (RCA-5 did this for the PON classes only).
        if (($ctx['rca5'] ?? true) && ! in_array($primary['cause'], Rules::GATE_EXEMPT, true) && ! Rules::hardwareSupport($ctx, $primary)) {
            $gated = $primary['cause'];
            $primary = Rules::abstainGate($ctx);
            $primaryId = 'abstain_gate';
            $secondary[$gated] = $gated; // kept as "Bisa juga": CS still sees the hint
        }

        // PR-D traffic guard (flag diagnosis_traffic_guard_enabled, default off): fresh traffic taken after the ONU went down
        // contradicts a hard fibre / power / modem answer. "unknown" with the old cause kept as "Bisa juga", never "not a fault".
        if (Rules::trafficConflict($ctx, $primary)) {
            $blocked = $primary['cause'];
            $primary = Rules::trafficGuard($ctx);
            $primaryId = 'traffic_guard';
            $secondary[$blocked] = $blocked;
        }
        // PR-D lines + "Bisa juga" (each behind its own flag): evidence only, the primary cause stays
        $more = Rules::moreEvidence($ctx, $primary, (string) $primaryId);
        foreach ($more['secondary'] as $s) {
            $secondary[$s] = $s;
        }

        // OPTICAL_DEGRADE as primary may also have been matched earlier as "secondary": drop it from the list then.
        unset($secondary[$primary['cause']]);
        $confidence = $primary['confidence'];
        if ($confidence === Rules::HIGH && $conflict) {
            $confidence = Rules::MID; // tinggi needs "no conflict"
        }

        // rule lines -> history lines -> current RX line -> what is fine around it
        $evidence = DiagnosisEvidence::cap(array_merge($primary['evidence'], $extraEvidence, $more['evidence'], Rules::historyEvidence($ctx, $primaryId, $primary['cause']), Rules::supporting($ctx)));

        return [
            'cause_code' => $primary['cause'],
            'confidence' => $confidence,
            'layer' => $primary['layer'],
            'rule_id' => $primaryId,
            'secondary' => array_slice(array_values($secondary), 0, 3),
            'evidence' => $evidence,
            'suggested_action' => (string) ($primary['action'] ?? OutageCauseText::actionFor($primary['cause'])),
        ];
    }

    /**
     * DB-only classification that is NOT stored (ticket create form, right after a customer is picked). Same payload
     * shape as DiagnosisPresenter::payload(); `id` is null and `trigger` is "preview".
     *
     * @return array<string,mixed>
     */
    public function preview(Customer $customer, ?Carbon $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $this->correlator->reset();
        $result = $this->classify($this->correlator->contextFor($customer, $now));

        return DiagnosisPresenter::fromResult($result, 'preview', $now);
    }

    /**
     * Classify and store. $opts: ticket_id, signal_id, created_by, probes (live answers), now, started_at (microtime),
     * diagnosis (an existing "running" row to complete instead of inserting a new one).
     *
     * @param  array<string,mixed>  $opts
     */
    public function diagnose(Customer $customer, string $trigger, array $opts = []): OutageDiagnosis
    {
        $now = isset($opts['now']) ? Carbon::instance($opts['now']) : now();
        $started = (float) ($opts['started_at'] ?? microtime(true));
        $probes = (array) ($opts['probes'] ?? []);
        $this->correlator->reset();
        $ctx = $this->correlator->contextFor($customer, $now, $probes, isset($opts['ticket_id']) ? (int) $opts['ticket_id'] : null);
        $result = $this->classify($ctx);

        $attrs = [
            'customer_id' => (int) $customer->id,
            'ticket_id' => $opts['ticket_id'] ?? null,
            'signal_id' => $opts['signal_id'] ?? null,
            'created_by' => $opts['created_by'] ?? null,
            'trigger' => $trigger,
            'state' => OutageDiagnosis::STATE_DONE,
            'cause_code' => $result['cause_code'],
            'confidence' => $result['confidence'],
            'layer' => $result['layer'],
            'secondary' => $result['secondary'],
            'evidence' => $result['evidence'],
            'probes' => self::probeSummary($probes),
            'rule_id' => $result['rule_id'],
            'rules_version' => ($ctx['rca5'] ?? true) ? Rules::RULES_VERSION : Rules::RULES_VERSION_LEGACY,
            'suggested_action' => $result['suggested_action'],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
        // Context snapshot (bounded JSON) for the rows the precision report scores (a few per ticket) and, since the
        // learn-and-measure PR, for detector episodes (setting diagnosis_episode_snapshot_enabled, pruned after 180 days
        // by outage:diagnosis-context-prune). Never the 15-minute refreshes. Skipped while the column is not migrated yet.
        $snapshot = in_array($trigger, [OutageDiagnosis::TRIGGER_TICKET_OPEN, OutageDiagnosis::TRIGGER_MANUAL], true)
            || ($trigger === OutageDiagnosis::TRIGGER_AUTO_EPISODE && OutageSettings::diagnosisEpisodeSnapshotEnabled());
        if ($snapshot && self::contextColumnReady()) {
            try {
                $attrs['context'] = DiagnosisContextSnapshot::of($ctx);
            } catch (\Throwable) {
                // a snapshot problem must never lose the diagnosis
            }
        }
        $row = $opts['diagnosis'] ?? null;
        try {
            return $this->store($row, $attrs);
        } catch (\Throwable $e) {
            if (! isset($attrs['context'])) {
                throw $e;
            }
            // the snapshot must never cost the diagnosis: store it without
            unset($attrs['context']);
            if ($row instanceof OutageDiagnosis) {
                $row->setAttribute('context', null);
            }
            try {
                Log::warning('[Outage] diagnosis context snapshot dropped', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return $this->store($row, $attrs);
        }
    }

    /** @param  array<string,mixed>  $attrs */
    private function store(mixed $row, array $attrs): OutageDiagnosis
    {
        if ($row instanceof OutageDiagnosis) {
            $row->fill($attrs)->save();

            return $row;
        }

        return OutageDiagnosis::query()->create($attrs);
    }

    private static ?bool $contextReady = null;

    private static function contextColumnReady(): bool
    {
        return self::$contextReady ??= Schema::hasColumn('outage_diagnoses', 'context');
    }

    /**
     * Auto diagnosis of a detector episode (customer episodes only). Writes outage_signals.diagnosed_cause +
     * diagnosis_id and NEVER cause_code (that stays the human label). Never throws.
     */
    public function forEpisode(OutageSignal $ep, string $trigger = OutageDiagnosis::TRIGGER_AUTO_EPISODE): ?OutageDiagnosis
    {
        try {
            if ($ep->customer_id === null || ! OutageSettings::diagnosisEnabled() || ! Schema::hasTable('outage_diagnoses')) {
                return null;
            }
            $customer = Customer::query()->find($ep->customer_id);
            if ($customer === null) {
                return null;
            }
            $diagnosis = $this->diagnose($customer, $trigger, ['signal_id' => $ep->id]);
            if (Schema::hasColumn('outage_signals', 'diagnosed_cause')) {
                // query update: bypasses the model saving hook and leaves the in-memory episode untouched
                OutageSignal::query()->whereKey($ep->id)->update(['diagnosed_cause' => $diagnosis->cause_code, 'diagnosis_id' => $diagnosis->id]);
            }

            return $diagnosis;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] episode diagnosis failed', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /**
     * Probe answers reduced to what is worth storing: status, reason, time. No raw device output.
     *
     * @param  array<string,mixed>  $probes
     * @return array<string,array<string,mixed>>
     */
    public static function probeSummary(array $probes): array
    {
        $out = [];
        foreach (['router', 'olt', 'cpe'] as $name) {
            if (isset($probes[$name]) && is_array($probes[$name])) {
                $out[$name] = array_filter([
                    'status' => $probes[$name]['status'] ?? null,
                    'reason' => $probes[$name]['reason'] ?? null,
                    'text' => $probes[$name]['text'] ?? null,
                    'at' => $probes[$name]['at'] ?? null,
                ], fn ($v) => $v !== null);
            }
        }

        return $out;
    }
}
