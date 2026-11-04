<?php

namespace App\Enums;

/**
 * Single source of truth for customers.status.
 *
 * DB column: enum('active','suspended','inactive','isolated','terminated','pending_installation').
 * Values such as 'isolir', 'dismantled', 'offboarding', 'cuti' can never be stored,
 * so they are intentionally NOT cases here.
 *
 * 'inactive' is legacy (kept because the DB enum and legacy sync/import can still
 * write it); it is not an official status.
 */
enum CustomerStatus: string
{
    case PendingInstallation = 'pending_installation';
    case Active = 'active';
    case Isolated = 'isolated';
    case Suspended = 'suspended';
    case Terminated = 'terminated';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::PendingInstallation => 'Menunggu Pasang',
            self::Active => 'Aktif',
            self::Isolated => 'Isolir',
            self::Suspended => 'Cuti',
            self::Terminated => 'Berhenti',
            self::Inactive => 'Berhenti (lama)',
        };
    }

    public function isLegacy(): bool
    {
        return $this === self::Inactive;
    }

    /** Label for a raw DB value; unknown values are returned unchanged. */
    public static function labelFor(?string $status): string
    {
        $case = $status === null ? null : self::tryFrom($status);

        return $case?->label() ?? (string) $status;
    }

    /**
     * Statuses that receive monthly invoices / overdue checks / live-customer logic.
     *
     * @return list<string>
     */
    public static function billable(): array
    {
        return [self::Active->value, self::Isolated->value];
    }

    /**
     * Statuses of an already-provisioned customer (PSB must not reset these to pending_installation).
     *
     * @return list<string>
     */
    public static function provisionedLive(): array
    {
        return [self::Active->value, self::Isolated->value, self::Suspended->value];
    }

    /**
     * Official statuses that may be written (everything except legacy 'inactive').
     *
     * @return list<string>
     */
    public static function writable(): array
    {
        return [
            self::PendingInstallation->value,
            self::Active->value,
            self::Isolated->value,
            self::Suspended->value,
            self::Terminated->value,
        ];
    }

    /**
     * Statuses the customer create/edit form allows today (suspended joins after the Cuti feature).
     *
     * @return list<string>
     */
    public static function uiEditable(): array
    {
        return [
            self::PendingInstallation->value,
            self::Active->value,
            self::Isolated->value,
            self::Terminated->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function isolatedValues(): array
    {
        return [self::Isolated->value];
    }

    /**
     * Closed (service ended) statuses.
     *
     * @return list<string>
     */
    public static function closed(): array
    {
        return [self::Terminated->value];
    }

    /**
     * Statuses shown in network monitoring lists (everything except pending/suspended/legacy).
     *
     * @return list<string>
     */
    public static function monitored(): array
    {
        return [self::Active->value, self::Isolated->value, self::Terminated->value];
    }

    /**
     * Monitoring "not up by design" bucket: isolated or terminated.
     *
     * @return list<string>
     */
    public static function isolatedOrClosed(): array
    {
        return [self::Isolated->value, self::Terminated->value];
    }
}
