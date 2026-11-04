<?php

namespace App\Support;

use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Reason a Dismantle ticket was filed. Codes are stable (stored in
 * support_tickets.dismantle_reason); labels are Indonesian UI text.
 */
final class DismantleReason
{
    public const OTHER = 'other';

    public const NOTE_MAX = 500;

    public const OPTIONS = [
        'not_continuing' => 'Tidak lanjut berlangganan',
        'moving_house' => 'Pindah rumah',
        'switch_provider' => 'Pindah ke provider lain',
        'arrears' => 'Tunggakan / tidak bayar',
        'unresolved_issue' => 'Gangguan tidak terselesaikan',
        'duplicate_device' => 'Perangkat ganda (salah satu dicabut)',
        self::OTHER => 'Lainnya',
    ];

    /**
     * Subject keyword → reason, checked in order (first match wins).
     *
     * @var array<string, list<string>>
     */
    private const SUBJECT_KEYWORDS = [
        'not_continuing' => ['tidak lanjut', 'tdk lanjut'],
        'moving_house' => ['pindah rumah'],
        'switch_provider' => ['pindah provider', 'provider lain'],
        'arrears' => ['tunggakan', 'tidak bayar'],
        'unresolved_issue' => ['gangguan'],
        'duplicate_device' => ['terpasang 2', 'salah satu'],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function label(?string $code): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        return self::OPTIONS[$code] ?? $code;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (string $code): array => ['value' => $code, 'label' => self::OPTIONS[$code]],
            self::codes(),
        );
    }

    /**
     * Validation rules: the reason is required only when type = dismantle
     * (in the same request); the note is required only for "other".
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'dismantle_reason' => ['nullable', 'required_if:type,dismantle', 'string', Rule::in(self::codes())],
            'dismantle_reason_note' => ['nullable', 'required_if:dismantle_reason,'.self::OTHER, 'string', 'max:'.self::NOTE_MAX],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'dismantle_reason.required_if' => 'Alasan dismantle wajib dipilih untuk tiket Dismantle.',
            'dismantle_reason.in' => 'Alasan dismantle tidak valid.',
            'dismantle_reason_note.required_if' => 'Keterangan wajib diisi bila alasan "Lainnya".',
            'dismantle_reason_note.max' => 'Keterangan maksimal '.self::NOTE_MAX.' karakter.',
        ];
    }

    /**
     * Normalise validated input against the ticket type: non-dismantle tickets
     * carry no reason; blank notes are stored as null.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public static function normalize(array $validated, ?string $type): array
    {
        if ($type !== 'dismantle') {
            unset($validated['dismantle_reason'], $validated['dismantle_reason_note']);

            return $validated;
        }

        if (array_key_exists('dismantle_reason_note', $validated)) {
            $note = trim((string) $validated['dismantle_reason_note']);
            $validated['dismantle_reason_note'] = $note === '' ? null : $note;
        }

        return $validated;
    }

    /**
     * Map a legacy ticket subject to a reason (backfill).
     *
     * @return array{reason: string, note: ?string}
     */
    public static function classifySubject(?string $subject): array
    {
        $haystack = mb_strtolower(preg_replace('/\s+/u', ' ', (string) $subject) ?? '');

        foreach (self::SUBJECT_KEYWORDS as $reason => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return ['reason' => $reason, 'note' => null];
                }
            }
        }

        $note = trim((string) $subject);

        return ['reason' => self::OTHER, 'note' => $note === '' ? null : mb_substr($note, 0, self::NOTE_MAX)];
    }

    /**
     * Record a set/changed reason in the ticket timeline and in the
     * customer's activity log. No-op when nothing changed.
     */
    public static function recordChange(SupportTicket $ticket, ?string $oldReason, ?string $oldNote, ?User $actor, string $source = 'ticket_form'): void
    {
        $newReason = $ticket->dismantle_reason;
        $newNote = $ticket->dismantle_reason_note;
        if ($newReason === $oldReason && $newNote === $oldNote) {
            return;
        }
        if ($newReason === null && $oldReason === null) {
            return;
        }

        $isSet = $oldReason === null;
        $label = self::label($newReason) ?? '-';
        $description = $newReason === null
            ? 'Alasan dismantle dihapus'
            : ($isSet ? 'Alasan dismantle: ' : 'Alasan dismantle diubah: ').$label.($newNote ? ' — '.$newNote : '');

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor?->id,
            'action' => $isSet ? 'dismantle_reason_set' : 'dismantle_reason_changed',
            'old_value' => $isSet ? null : ['dismantle_reason' => $oldReason, 'dismantle_reason_note' => $oldNote],
            'new_value' => ['dismantle_reason' => $newReason, 'dismantle_reason_note' => $newNote],
            'description' => mb_substr($description, 0, 255),
        ]);

        if ($ticket->customer_id && $ticket->customer) {
            $log = activity()
                ->performedOn($ticket->customer)
                ->withProperties([
                    'ticket_id' => $ticket->id,
                    'ticket_code' => $ticket->code,
                    'source' => $source,
                    'old' => ['dismantle_reason' => $oldReason, 'dismantle_reason_note' => $oldNote],
                    'new' => ['dismantle_reason' => $newReason, 'dismantle_reason_note' => $newNote],
                ]);
            if ($actor) {
                $log->causedBy($actor);
            }
            $log->log($isSet ? 'ticket_dismantle_reason_set' : 'ticket_dismantle_reason_changed');
        }
    }
}
