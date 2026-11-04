<?php

namespace App\Services\Outage;

/**
 * Settings of the manual "Buat tiket" button in Deteksi Gangguan. Own file (trait) so OutageSettings only gains a
 * `use` line. Relies on OutageSettings::flag() / read() (private static, same class).
 * Defaults: button ON, roles admin + noc (superadmin always).
 */
trait OutageManualTicketSettings
{
    /** Roles that may be listed (superadmin always may and is not listed). */
    public const MANUAL_TICKET_ROLES = ['admin', 'noc'];

    public static function manualTicketEnabled(): bool
    {
        return self::flag('outage_manual_ticket_enabled', true);
    }

    /** @return list<string> */
    public static function manualTicketRoles(): array
    {
        $raw = self::read('outage_manual_ticket_roles');
        if (self::$lastReadFailed || $raw === null || trim((string) $raw) === '') {
            return self::MANUAL_TICKET_ROLES;
        }
        $roles = [];
        foreach (preg_split('/[\s,;]+/', strtolower((string) $raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $role) {
            if (in_array($role, self::MANUAL_TICKET_ROLES, true)) {
                $roles[$role] = $role;
            }
        }

        return $roles === [] ? self::MANUAL_TICKET_ROLES : array_values($roles);
    }

    /** Flag on AND (superadmin OR a listed role). */
    public static function manualTicketAllowedTo(?string $role): bool
    {
        return $role !== null
            && self::manualTicketEnabled()
            && ($role === 'superadmin' || in_array($role, self::manualTicketRoles(), true));
    }

    /** @return array<string,mixed> */
    public static function manualTicketSnapshot(): array
    {
        return [
            'outage_manual_ticket_enabled' => self::manualTicketEnabled(),
            'outage_manual_ticket_roles' => implode(',', self::manualTicketRoles()),
        ];
    }
}
