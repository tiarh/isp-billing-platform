<?php

namespace App\Console\Commands\Concerns;

use App\Models\Customer;
use App\Models\User;

/**
 * Shared by the one-off list commands (customers:stop-and-archive,
 * customers:isolate-list). Resolves customer codes / PPPoE usernames to exactly
 * one live (not archived) customer of the admin list (Customer::ebilling()).
 * Unknown, ambiguous or already archived identifiers are reported and never guessed.
 */
trait ResolvesCustomerIdentifiers
{
    /**
     * @return list<string>
     */
    protected function parseList(mixed $raw): array
    {
        // trim() keeps NBSP / zero-width spaces from spreadsheet copy-paste.
        $items = array_map(
            fn (string $v): string => (string) preg_replace('/^[\s\x{00A0}\x{200B}\x{FEFF}]+|[\s\x{00A0}\x{200B}\x{FEFF}]+$/u', '', $v),
            explode(',', (string) $raw),
        );

        return array_values(array_unique(array_filter($items, fn (string $v): bool => $v !== '')));
    }

    /**
     * Admin/superadmin actor from --actor (id or email), or null when invalid.
     */
    protected function resolveActor(string $raw): ?User
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $actor = ctype_digit($raw) ? User::find((int) $raw) : User::where('email', $raw)->first();
        if (! $actor || ! ($actor->isSuperAdmin() || $actor->isAdmin())) {
            return null;
        }

        return $actor;
    }

    /**
     * @param  list<string>  $codes
     * @param  list<string>  $pppoe
     * @return list<array{label: string, customer: ?Customer, state: string}> state: ok|not_found|ambiguous|already_archived|duplicate
     */
    protected function resolveIdentifiers(array $codes, array $pppoe): array
    {
        $out = [];
        $seen = [];
        foreach ([['code', $codes], ['pppoe_user', $pppoe]] as [$column, $values]) {
            foreach ($values as $value) {
                $label = ($column === 'code' ? 'code=' : 'pppoe=').$value;
                $live = Customer::ebilling()->where($column, $value)->with(['router', 'olt'])->get();
                if ($live->count() > 1) {
                    $out[] = ['label' => $label, 'customer' => null, 'state' => 'ambiguous'];

                    continue;
                }
                if ($live->count() === 1) {
                    $customer = $live->first();
                    if (isset($seen[$customer->id])) {
                        $out[] = ['label' => $label, 'customer' => null, 'state' => 'duplicate'];

                        continue;
                    }
                    $seen[$customer->id] = true;
                    $out[] = ['label' => $label, 'customer' => $customer, 'state' => 'ok'];

                    continue;
                }
                $archived = Customer::ebilling()->onlyTrashed()->where($column, $value)->exists();
                $out[] = ['label' => $label, 'customer' => null, 'state' => $archived ? 'already_archived' : 'not_found'];
            }
        }

        return $out;
    }
}
