<?php

namespace App\Enums;

use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Outage\Diagnosis\DiagnosisPresenter;
use App\Services\Outage\OutageSettings;
use App\Services\Outage\Outcome\OutcomeLabeler;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Root-cause taxonomy of an outage (RCA plan section 1). One list shared by the outage detector, the
 * incident ticket close form ("Penyebab sebenarnya"), the technician update and the weekly reports.
 *
 * Codes are stable (stored in support_tickets.root_cause and outage_signals.cause_code); labels are
 * Indonesian UI text. "unknown" is the explicit "Tidak tahu" answer: a technician is never forced to
 * guess, but must always answer.
 *
 * Plan ticket-root-cause-required adds four codes: not_fault, self_recovered, other (needs a note) and
 * auto_closed (system only, never offered to a person). The detector and Diagnosis\Rules never emit them.
 */
enum OutageRootCause: string
{
    case Upstream = 'upstream';
    case CoreRouter = 'core_router';
    case OltDown = 'olt_down';
    case DataBlind = 'data_blind';
    case PonCut = 'pon_cut';
    case OdpCut = 'odp_cut';
    case DropLos = 'drop_los';
    case PowerOff = 'power_off';
    case CpeDead = 'cpe_dead';
    case CpeRebootLoop = 'cpe_reboot_loop';
    case OpticalDegrade = 'optical_degrade';
    case PppoeAuth = 'pppoe_auth';
    case PppoeFlap = 'pppoe_flap';
    // RCA-4: stale PPPoE session ("user X is already active") blocks the reconnect while the ONU is online.
    case PppoeStuck = 'pppoe_stuck';
    case IsolirAdmin = 'isolir_admin';
    case WlanDown = 'wlan_down';
    case WlanFlap = 'wlan_flap';
    case WifiClient = 'wifi_client';
    case Unknown = 'unknown';
    // Not a network fault (PSB, survey, pindah, dismantle, wrongly filed).
    case NotFault = 'not_fault';
    case SelfRecovered = 'self_recovered';
    // A cause that is not in the list: a short note is required.
    case Other = 'other';
    // Closed by the system (pickup receipt closed the ticket); never in a picker.
    case AutoClosed = 'auto_closed';

    /** Ticket statuses that count as "closing" an incident. */
    public const CLOSING_STATUSES = ['resolved', 'closed'];

    /** Codes a person can never choose (system writes them directly; data_blind is a data state, not a field cause). */
    public const SYSTEM_ONLY = ['auto_closed', 'data_blind'];

    /** Closed, but nothing was broken. */
    public const NO_FAULT = ['not_fault', 'self_recovered'];

    /** Answers that say nothing about the cause: left out of the precision of the diagnosis. */
    public const NOT_SCORED = ['unknown', 'other', 'auto_closed'];

    public const NOTE_MIN = 5;

    public const NOTE_MAX = 255;

    public const SOURCES = ['technician', 'admin', 'api', 'system'];

    /** suggestion = the "Pakai saran" chip (diagnosis), signature = the "Saran dari data" chip (repair signature). */
    public const VIA = ['suggestion', 'manual', 'signature'];

    /**
     * One plain line under each main choice (owner-approved text, no flag): what the technician sees at the door.
     * The first two answer the common mix-up between a cut cable and a bad signal.
     */
    public const HINTS = [
        'drop_los' => 'ONU merah / tidak ada sinyal',
        'optical_degrade' => 'ONU nyala tapi redaman jelek atau putus-sambung',
        'power_off' => 'Listrik atau adaptor ONU mati',
        'cpe_dead' => 'Modem diganti',
        'pppoe_auth' => 'Modem disetting ulang',
        'wifi_client' => 'Internet jalan, masalah di HP / jarak',
        'isolir_admin' => 'Belum bayar / diisolir',
        'self_recovered' => 'Normal lagi sebelum dicek',
        'not_fault' => 'PSB, pindah atau dismantle',
        'odp_cut' => 'Beberapa rumah satu ODP mati',
        'pon_cut' => 'Satu jalur PON mati',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Upstream => 'Jalur internet pusat putus',
            self::CoreRouter => 'Router mati',
            self::OltDown => 'OLT mati',
            self::DataBlind => 'Data tidak terbaca',
            self::PonCut => 'Kabel PON putus',
            self::OdpCut => 'Kabel ODP putus',
            self::DropLos => 'Kabel ke rumah putus',
            self::PowerOff => 'Listrik / adaptor mati',
            self::CpeDead => 'Modem rusak',
            self::CpeRebootLoop => 'Modem restart terus',
            self::OpticalDegrade => 'Sinyal kabel jelek',
            self::PppoeAuth => 'Modem gagal login',
            self::PppoeFlap => 'Internet putus-sambung',
            self::PppoeStuck => 'Internet nyangkut',
            self::IsolirAdmin => 'Diisolir / cuti',
            self::WlanDown => 'WiFi mati',
            self::WlanFlap => 'WiFi putus-putus',
            self::WifiClient => 'HP / laptop pelanggan',
            self::Unknown => 'Belum tahu',
            self::NotFault => 'Bukan gangguan',
            self::SelfRecovered => 'Pulih sendiri',
            self::Other => 'Lainnya',
            self::AutoClosed => 'Ditutup otomatis',
        };
    }

    /**
     * False between a code deploy and `migrate`: the close form then behaves as before instead of
     * writing / requiring a column that does not exist yet.
     */
    public static function columnReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasColumn('support_tickets', 'root_cause');
    }

    /** Same for the note / suggested / source columns (migration 2026_10_22_000001). */
    public static function detailReady(): bool
    {
        return self::detailMemo() ?? self::detailMemo(self::columnReady() && Schema::hasColumn('support_tickets', 'root_cause_source'));
    }

    /** Test hook: force (true / false) or forget (null) the memoised answer of detailReady(). */
    public static function forceDetailReady(?bool $ready): void
    {
        self::detailMemo($ready, true);
    }

    /** Enums cannot hold properties, so the memo lives in a function-level static. */
    private static function detailMemo(?bool $set = null, bool $overwrite = false): ?bool
    {
        static $memo = null;
        if ($overwrite || $set !== null) {
            $memo = $set;
        }

        return $memo;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (self $c): string => $c->value, self::cases());
    }

    /** Codes a person may send. @return list<string> */
    public static function humanCodes(): array
    {
        return array_values(array_diff(self::codes(), self::SYSTEM_ONLY));
    }

    public static function labelFor(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::tryFrom($code)?->label() ?? $code;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $code): array => ['value' => $code, 'label' => (string) self::labelFor($code)],
            self::humanCodes(),
        );
    }

    /**
     * Button texts of the picker (technician friendly). "main" = shown at once, "more" = under "Penyebab lain".
     * A saved cause is shown everywhere else with label().
     *
     * @return list<array{value: string, label: string, group: string, hint: string|null}>
     */
    public static function pickOptions(): array
    {
        $main = [
            'drop_los' => 'Kabel drop putus',
            'optical_degrade' => 'Redaman / konektor',
            'power_off' => 'Listrik / adaptor',
            'cpe_dead' => 'Modem rusak',
            'pppoe_auth' => 'Setting PPPoE / modem',
            'wifi_client' => 'WiFi / HP pelanggan',
            'isolir_admin' => 'Diisolir / belum bayar',
            'self_recovered' => 'Pulih sendiri',
            'not_fault' => 'Bukan gangguan (PSB / pindah / dismantle)',
            'other' => 'Lainnya (tulis singkat)',
        ];
        $more = [
            'odp_cut' => 'Kabel ODP putus',
            'pon_cut' => 'Kabel PON / backbone putus',
            'cpe_reboot_loop' => 'Modem restart terus',
            'wlan_down' => 'WiFi modem mati',
            'wlan_flap' => 'WiFi putus-putus',
            'pppoe_stuck' => 'Internet nyangkut',
            'pppoe_flap' => 'PPPoE putus-sambung',
            'core_router' => 'Router mati',
            'olt_down' => 'OLT mati',
            'upstream' => 'Jalur upstream putus',
            'unknown' => 'Tidak tahu',
        ];

        $out = [];
        foreach ($main as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label, 'group' => 'main', 'hint' => self::HINTS[$value] ?? null];
        }
        foreach ($more as $value => $label) {
            $out[] = ['value' => $value, 'label' => $label, 'group' => 'more', 'hint' => self::HINTS[$value] ?? null];
        }

        return $out;
    }

    /**
     * The words a technician saw on the button, also used where the saved cause of a ticket is shown (ticket page,
     * timeline, weekly WA), so the word does not change after saving. The two hints in brackets are dropped.
     */
    public static function ticketLabel(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }
        if (in_array($code, [self::NotFault->value, self::Other->value], true)) {
            return self::labelFor($code);
        }

        return self::pickLabel($code) ?? self::labelFor($code);
    }

    public static function pickLabel(string $code): ?string
    {
        foreach (self::pickOptions() as $option) {
            if ($option['value'] === $code) {
                return $option['label'];
            }
        }

        return null;
    }

    /**
     * Validation rule for an incoming root_cause value (only checked when present). People cannot send
     * the system-only codes.
     *
     * @return array<int, mixed>
     */
    public static function rule(): array
    {
        return ['nullable', 'string', Rule::in(self::humanCodes())];
    }

    /** @return array<int, mixed> */
    public static function noteRule(): array
    {
        return ['nullable', 'string', 'min:'.self::NOTE_MIN, 'max:'.self::NOTE_MAX];
    }

    /** @return array<int, mixed> */
    public static function viaRule(): array
    {
        return ['nullable', 'string', Rule::in(self::VIA)];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'root_cause.in' => 'Penyebab tidak dikenal. Pilih dari daftar.',
            'root_cause_note.min' => 'Tulis penyebabnya minimal '.self::NOTE_MIN.' huruf.',
            'root_cause_note.max' => 'Catatan penyebab maksimal '.self::NOTE_MAX.' huruf.',
        ];
    }

    /**
     * True when this update closes a ticket that is or becomes an incident, and no cause is known yet:
     * an incident that moves from an open status into resolved/closed needs "Penyebab sebenarnya".
     * Changing the type away from incident in the same request does not skip it. Tickets that are already
     * resolved/closed (legacy or answered) and tickets that are not incidents are unaffected.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function isRequiredFor(SupportTicket $ticket, array $validated, ?string $effectiveType = null): bool
    {
        if (! self::columnReady()) {
            return false;
        }
        $newStatus = $validated['status'] ?? null;
        if (! is_string($newStatus) || ! in_array($newStatus, self::CLOSING_STATUSES, true)) {
            return false;
        }
        if (in_array((string) $ticket->status, self::CLOSING_STATUSES, true)) {
            return false;
        }
        if ((string) $ticket->type !== 'incident' && ($effectiveType ?? (string) $ticket->type) !== 'incident') {
            return false;
        }

        $incoming = trim((string) ($validated['root_cause'] ?? ''));

        return $incoming === '' && trim((string) $ticket->root_cause) === '';
    }

    /**
     * Throws a 422 (field "root_cause") when the close needs a cause and none was sent, and a 422 (field
     * "root_cause_note") when "Lainnya" is chosen without a note.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function assertProvidedOnClose(SupportTicket $ticket, array $validated, ?string $effectiveType = null): void
    {
        if (self::isRequiredFor($ticket, $validated, $effectiveType)) {
            throw ValidationException::withMessages([
                'root_cause' => 'Pilih penyebab sebenarnya dulu (boleh "Tidak tahu").',
            ]);
        }

        if (self::columnReady() && ($validated['root_cause'] ?? null) === self::Other->value) {
            $note = trim((string) ($validated['root_cause_note'] ?? ''));
            if ($note === '' && $ticket->root_cause === self::Other->value && ! array_key_exists('root_cause_note', $validated)) {
                $note = trim((string) ($ticket->root_cause_note ?? ''));
            }
            if ($note === '') {
                throw ValidationException::withMessages([
                    'root_cause_note' => 'Tulis singkat penyebabnya.',
                ]);
            }
        }
    }

    /** Remove an empty cause so it is neither stored nor logged as a change. */
    public static function normalize(array $validated): array
    {
        if (! self::columnReady() || (array_key_exists('root_cause', $validated) && trim((string) $validated['root_cause']) === '')) {
            unset($validated['root_cause']);
        }
        if (! self::detailReady()) {
            unset($validated['root_cause_note'], $validated['root_cause_suggested'], $validated['root_cause_source']);
        } elseif (array_key_exists('root_cause_note', $validated)) {
            $note = trim((string) $validated['root_cause_note']);
            if ($note === '') {
                unset($validated['root_cause_note']);
            } else {
                $validated['root_cause_note'] = $note;
            }
        }

        return $validated;
    }

    /**
     * One call for every human path (web update, technician update, API): validates the cause rules,
     * stamps source / suggestion on the data to save and returns how the cause was chosen.
     *
     * The suggestion is recomputed here from the stored diagnosis; the client only says whether it used the
     * "Pakai saran" chip (root_cause_via). `$source` = technician | admin | api.
     *
     * @param  array<string, mixed>  $validated
     * @return array{0: array<string, mixed>, 1: string|null} the data to save and via (suggestion|manual|null)
     */
    public static function prepareClose(SupportTicket $ticket, array $validated, ?string $effectiveType, string $source, ?User $viewer): array
    {
        $viaInput = $validated['root_cause_via'] ?? null;
        unset($validated['root_cause_via']);

        $sentNote = trim((string) ($validated['root_cause_note'] ?? ''));
        $validated = self::normalize($validated);
        // Before migrate the note column is missing and the note is dropped; "Lainnya" must still count as answered.
        self::assertProvidedOnClose($ticket, self::detailReady() || $sentNote === '' ? $validated : $validated + ['root_cause_note' => $sentNote], $effectiveType);

        $code = trim((string) ($validated['root_cause'] ?? ''));
        if ($code === '' || ! self::columnReady()) {
            return [$validated, null];
        }
        $changed = $code !== (string) $ticket->root_cause;
        // The screen only offers a suggestion while the ticket has no cause yet; mirror that here.
        $suggested = $changed && trim((string) $ticket->root_cause) === ''
            ? (self::suggestionFor($ticket, $viewer)['code'] ?? null)
            : null;

        if (self::detailReady() && $changed) {
            $validated['root_cause_source'] = in_array($source, self::SOURCES, true) ? $source : 'admin';
            $validated['root_cause_suggested'] = $suggested;
            if ($code !== self::Other->value && ! array_key_exists('root_cause_note', $validated)) {
                $validated['root_cause_note'] = null;
            }
        }

        $via = null;
        if ($changed) {
            $via = $viaInput === 'suggestion' && $suggested !== null && $suggested === $code ? 'suggestion' : 'manual';
            // "Saran dari data": the server recomputes the repair signature and keeps `signature` only when it names this cause
            if ($viaInput === 'signature' && trim((string) $ticket->root_cause) === '' && (self::signatureFor($ticket, $viewer)['code'] ?? null) === $code) {
                $via = 'signature';
            }
        }

        return [$validated, $via];
    }

    /** Source of a person: technician (role teknisi) or admin (everybody else). */
    public static function sourceFor(?User $user): string
    {
        return $user !== null && $user->isTeknisi() ? 'technician' : 'admin';
    }

    /**
     * The diagnosis cause offered as a suggestion at close: the newest finished diagnosis of the ticket, unless it
     * says unknown / data_blind or this user may not see diagnoses. Never pre-selected in the UI.
     *
     * @return array{code: string, label: string, confidence: string|null}|null
     */
    public static function suggestionFor(SupportTicket $ticket, ?User $viewer): ?array
    {
        if (! $ticket->exists || (string) $ticket->type !== 'incident' || ! DiagnosisPresenter::visibleTo($viewer)) {
            return null;
        }
        if (! Schema::hasTable('outage_diagnoses')) {
            return null;
        }

        $diagnosis = DiagnosisPresenter::latestForTicket((int) $ticket->id);
        $code = (string) ($diagnosis?->cause_code ?? '');
        if ($code === '' || in_array($code, ['unknown', 'data_blind'], true)) {
            return null;
        }
        $label = self::pickLabel($code);
        if ($label === null) {
            return null;
        }

        return ['code' => $code, 'label' => $label, 'confidence' => $diagnosis?->confidence];
    }

    /**
     * The "Saran dari data" chip: what the stored data says fixed this open ticket (RX jump, serial swap, unisolate),
     * from OutcomeLabeler::preview(). Only while the setting diagnosis_signature_chip_enabled is on (default off), the
     * ticket has no cause yet and this user may see diagnoses. DB only; never throws. Never pre-selected in the UI.
     *
     * @return array{code: string, label: string, text: string, signature: string}|null
     */
    public static function signatureFor(SupportTicket $ticket, ?User $viewer): ?array
    {
        try {
            if (! $ticket->exists || (string) $ticket->type !== 'incident' || trim((string) $ticket->root_cause) !== ''
                || ! OutageSettings::diagnosisSignatureChipEnabled() || ! DiagnosisPresenter::visibleTo($viewer)) {
                return null;
            }

            return app(OutcomeLabeler::class)->preview($ticket);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fill the cause on a close the system does (pickup receipt, monitoring triage) when nobody gave one.
     * Incident tickets only; an existing cause is never replaced. Returns true when a cause was written.
     */
    public static function recordSystemCause(SupportTicket $ticket, self $cause, ?User $actor): bool
    {
        if (! self::columnReady() || (string) $ticket->type !== 'incident' || trim((string) $ticket->root_cause) !== '') {
            return false;
        }

        $values = ['root_cause' => $cause->value];
        if (self::detailReady()) {
            $values['root_cause_source'] = 'system';
        }
        $ticket->forceFill($values)->save();
        self::recordChange($ticket, null, $actor, 'system');

        return true;
    }

    /** Timeline row + customer activity for a set / changed cause. No-op when nothing changed. */
    public static function recordChange(SupportTicket $ticket, ?string $oldCause, ?User $actor, string $source = 'ticket_form', ?string $via = null): void
    {
        $new = $ticket->root_cause;
        if ($new === $oldCause || $new === null || $new === '') {
            return;
        }

        $isSet = $oldCause === null || $oldCause === '';
        $newValue = ['root_cause' => $new];
        if ($via !== null) {
            $newValue['via'] = $via;
        }
        if (self::detailReady() && $ticket->root_cause_source) {
            $newValue['source'] = $ticket->root_cause_source;
        }
        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor?->id,
            'action' => $isSet ? 'root_cause_set' : 'root_cause_changed',
            'old_value' => $isSet ? null : ['root_cause' => $oldCause],
            'new_value' => $newValue,
            'description' => mb_substr(($isSet ? 'Penyebab: ' : 'Penyebab diubah: ').(self::ticketLabel($new) ?? '-'), 0, 255),
        ]);

        if ($ticket->customer_id && $ticket->customer) {
            $log = activity()
                ->performedOn($ticket->customer)
                ->withProperties([
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'source' => $source,
                    'old' => ['root_cause' => $oldCause],
                    'new' => ['root_cause' => $new],
                ]);
            if ($actor) {
                $log->causedBy($actor);
            }
            $log->log($isSet ? 'ticket_root_cause_set' : 'ticket_root_cause_changed');
        }
    }
}
