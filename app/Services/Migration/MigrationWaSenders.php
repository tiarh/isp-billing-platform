<?php

namespace App\Services\Migration;

use App\Models\CustomerMigration;
use App\Models\User;

/**
 * Who sent a WA command and who may change a migration, moved out of CustomerMigrationService
 * without a change of behaviour.
 *
 * Sender keys, the technician who created/selected a draft, the operation-bound sender, the
 * static allowlist check and the lookup of a migration by command. No lock, no host call.
 * The allowlist guard comes through the seams at call time.
 */
final class MigrationWaSenders
{
    public function __construct(
        private MigrationSeams $seams,
    ) {}

    /** @param array<string, mixed> $payload */
    public function sameGuidedSelectionSender(CustomerMigration $migration, array $payload): bool
    {
        $selected = (array) data_get($migration->meta, 'guided_wa_selection.selected_by_sender_keys', []);
        if ($selected === []) {
            $selected = array_filter([(string) data_get($migration->meta, 'guided_wa_selection.selected_by_sender', '')]);
        }
        if ($selected === [] && filled($migration->sender_jid)) {
            $selected = [(string) $migration->sender_jid];
        }

        return $this->sameWaSenderKeys($selected, $this->waSenderKeys($payload));
    }

    /** @param array<string, mixed> $payload @return list<string> */
    public function waSenderKeys(array $payload): array
    {
        return array_values(array_unique(array_filter(array_map(
            function ($value): string {
                $jid = strtolower(trim((string) $value));
                $digits = preg_replace('/\D/', '', $jid) ?? '';

                return $digits !== '' ? $digits : $jid;
            },
            [
                $payload['sender_jid'] ?? '',
                $payload['sender_jid_pn'] ?? '',
                $payload['sender_jid_lid'] ?? '',
                $payload['participant'] ?? '',
            ]
        ))));
    }

    /** @param list<string> $left @param list<string> $right */
    public function sameWaSenderKeys(array $left, array $right): bool
    {
        $normalize = fn ($value): string => (($digits = preg_replace('/\D/', '', (string) $value) ?? '') !== '')
            ? $digits
            : strtolower(trim((string) $value));

        return array_intersect(array_map($normalize, $left), array_map($normalize, $right)) !== [];
    }

    /** @param array<string, mixed> $payload */
    public function waActor(array $payload): ?User
    {
        $phones = array_values(array_unique(array_filter(array_map(
            fn ($value) => preg_replace('/\D/', '', (string) $value) ?: '',
            [$payload['sender_jid'] ?? '', $payload['sender_jid_pn'] ?? '', $payload['participant'] ?? '']
        ))));
        if ($phones === []) {
            return null;
        }

        return User::query()->whereNotNull('phone')->get(['id', 'phone', 'role'])->first(
            fn (User $user) => in_array(preg_replace('/\D/', '', (string) $user->phone) ?: '', $phones, true)
        );
    }

    /**
     * The original sender is an operation-bound authorization fallback. The
     * activation group allowlist remains mandatory; this only avoids forcing
     * every technician into a second static sender list.
     *
     * @return list<string>
     */
    public function migrationBoundSenderKeys(?CustomerMigration $migration): array
    {
        if (! $migration || ! in_array((string) $migration->source, ['wa', 'guided_lookup_wa'], true)) {
            return [];
        }

        $keys = array_values(array_filter((array) data_get($migration->meta, 'wa_origin_sender_keys', [])));
        if ($keys === [] && filled($migration->sender_jid)) {
            $keys = [(string) $migration->sender_jid];
        }

        return array_values(array_unique(array_map('strval', $keys)));
    }

    /** @param array<string, mixed> $payload */
    public function canModifyMigrationFromWa(CustomerMigration $migration, array $payload): bool
    {
        if ((string) $migration->group_jid !== (string) ($payload['group_jid'] ?? '')) {
            return false;
        }
        if ($this->sameGuidedSelectionSender($migration, $payload)) {
            return true;
        }
        $bound = $this->migrationBoundSenderKeys($migration);
        if ($bound !== [] && $this->sameWaSenderKeys($bound, $this->waSenderKeys($payload))) {
            return true;
        }

        return ($this->seams->guidedLookup)()->waExecutionAllowlistGuard($payload, $bound)['allowed'];
    }

    public function findMigrationForCommand(string $groupJid, array $parsed): ?CustomerMigration
    {
        $id = $parsed['migration_id'] ?? null;
        if ($id) {
            return CustomerMigration::query()->whereKey((int) $id)->first();
        }

        $pppoe = MigrationNormalizer::normalizeUsername((string) ($parsed['pppoe_user'] ?? ''));
        if ($pppoe !== '') {
            return CustomerMigration::query()
                ->where('pppoe_user', $pppoe)
                ->orderByDesc('id')
                ->first();
        }

        return CustomerMigration::query()
            ->where('group_jid', $groupJid)
            ->orderByDesc('id')
            ->first();
    }
}
