<?php

namespace App\Services\Outage\Diagnosis;

use App\Enums\OutageRootCause;
use App\Models\OutageDiagnosis;
use App\Models\User;
use App\Services\Outage\OutageCauseText;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;

/**
 * RCA-4: the one place that turns a stored diagnosis into the props of the "Diagnosa otomatis" panel (ticket create
 * form, ticket detail, "Cek sekarang" responses) and decides who may see / use it.
 */
final class DiagnosisPresenter
{
    /** Whether the panel (and the stored diagnosis) is shown to this user at all. */
    public static function visibleTo(?User $user): bool
    {
        return $user !== null && OutageSettings::diagnosisPanelVisibleTo((string) $user->role);
    }

    /**
     * Props of the panel for one customer / ticket: the stored diagnosis plus the "Cek sekarang" switches.
     *
     * @return array<string,mixed>|null null = the panel is not shown to this user
     */
    public static function panelProps(?User $user, ?OutageDiagnosis $diagnosis): ?array
    {
        if (! self::visibleTo($user)) {
            return null;
        }

        return [
            'diagnosis' => self::payload($diagnosis),
            'checkNowEnabled' => OutageSettings::diagnosisCheckNowEnabled() && OutageSettings::diagnosisCheckNowAllowedTo((string) $user->role),
            'cooldownMinutes' => OutageSettings::diagnoseNowCustomerCooldownMinutes(),
            'timeouts' => (array) config('diagnosis.timeouts'),
        ];
    }

    /** @return array<string,mixed>|null */
    public static function payload(?OutageDiagnosis $d): ?array
    {
        if ($d === null) {
            return null;
        }
        $action = $d->suggested_action ?: OutageCauseText::actionFor($d->cause_code);

        return [
            'id' => (int) $d->id,
            'state' => (string) $d->state,
            'trigger' => (string) $d->trigger,
            'cause' => $d->cause_code,
            'label' => OutageRootCause::labelFor($d->cause_code),
            'confidence' => $d->confidence,
            'confidence_word' => self::calibratedWord((string) $d->cause_code, $d->confidence),
            'action' => OutageCauseText::actionText($action),
            'evidence' => array_values((array) $d->evidence),
            'secondary' => array_values(array_filter(array_map(fn ($c) => OutageRootCause::labelFor((string) $c), (array) $d->secondary))),
            'probes' => (array) $d->probes,
            'created_at' => ($d->updated_at ?? $d->created_at)?->copy()->timezone('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * Panel payload of a classification result that is not stored (preview).
     *
     * @param  array<string,mixed>  $r  result of OutageDiagnoser::classify()
     * @return array<string,mixed>
     */
    public static function fromResult(array $r, string $trigger, Carbon $at): array
    {
        return [
            'id' => null,
            'state' => 'preview',
            'trigger' => $trigger,
            'cause' => $r['cause_code'],
            'label' => OutageRootCause::labelFor($r['cause_code']),
            'confidence' => $r['confidence'],
            'confidence_word' => self::calibratedWord((string) $r['cause_code'], $r['confidence']),
            'action' => OutageCauseText::actionText($r['suggested_action']),
            'evidence' => array_values($r['evidence']),
            'secondary' => array_values(array_filter(array_map(fn ($c) => OutageRootCause::labelFor((string) $c), (array) $r['secondary']))),
            'probes' => [],
            'created_at' => $at->copy()->timezone('Asia/Jakarta')->toIso8601String(),
        ];
    }

    /**
     * "Pasti / Kemungkinan / Belum tahu" instead of tinggi / sedang / rendah, only while the setting
     * diagnosis_calibrated_labels_enabled is on (default off; null = show the old word). "Pasti" needs the class to be
     * "pasti" in the newest calibration week AND this diagnosis to be "tinggi"; a "tinggi" of any other class shows as
     * "Kemungkinan". An unknown / data_blind answer is "Belum tahu".
     */
    public static function calibratedWord(string $cause, ?string $confidence): ?string
    {
        if (! OutageSettings::diagnosisCalibratedLabelsEnabled()) {
            return null;
        }
        if (in_array($cause, PrecisionReport::ABSTAIN, true)) {
            return 'Belum tahu';
        }

        return match (PrecisionReport::latestCalibration()[$cause] ?? null) {
            'pasti' => $confidence === Rules::HIGH ? 'Pasti' : 'Kemungkinan',
            'belum_tahu' => 'Belum tahu',
            default => 'Kemungkinan',
        };
    }

    /** The diagnosis shown on a ticket: the newest finished one linked to it. */
    public static function latestForTicket(int $ticketId): ?OutageDiagnosis
    {
        return OutageDiagnosis::query()->where('ticket_id', $ticketId)->where('state', OutageDiagnosis::STATE_DONE)->orderByDesc('id')->first();
    }

    /** The newest finished diagnosis of a customer (create form: shown right after a customer is picked). */
    public static function latestForCustomer(int $customerId): ?OutageDiagnosis
    {
        return OutageDiagnosis::query()->where('customer_id', $customerId)->where('state', OutageDiagnosis::STATE_DONE)->orderByDesc('id')->first();
    }
}
