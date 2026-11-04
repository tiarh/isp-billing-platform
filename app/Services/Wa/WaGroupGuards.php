<?php

namespace App\Services\Wa;

use App\Support\WaGroupJid;

/**
 * Pure (no I/O) role guards for WhatsApp group targets. The runtime guards
 * (OutageShadowNotifier::refusalReason, ReportDailyWaCommand::alertJid) and the
 * Grup WhatsApp settings page all delegate here, so a save-time decision can never
 * differ from a send-time decision (pinned by WaGroupSettingsCliParityTest).
 *
 * Group identity is the bare local part of the JID (the part before "@"), the same
 * rule ReportGroupRegistry::isReportGroup / isPsbGroup use.
 */
final class WaGroupGuards
{
    public const REASON_EMPTY = 'empty';

    public const REASON_PSB = 'psb_group';

    public const REASON_REPORT_OR_OPS = 'report_or_ops_group';

    public const REASON_REPORT = 'report_group';

    public const REASON_OPS = 'ops_group';

    public const REASON_TICKET = 'ticket_group';

    public static function bare(string $jid): string
    {
        return explode('@', WaGroupJid::normalize($jid), 2)[0];
    }

    public static function isGroupJid(string $jid): bool
    {
        // A personal JID is never a group (WaGroupJid::normalize would rewrite it to a fake @g.us).
        if (self::isPersonalJid($jid)) {
            return false;
        }

        return str_ends_with(WaGroupJid::normalize($jid), '@g.us');
    }

    /** A personal chat JID (the technician Input target may be one). */
    public static function isPersonalJid(string $jid): bool
    {
        return (bool) preg_match('/^[^@\s]+@s\.whatsapp\.net$/i', trim($jid));
    }

    /**
     * Normalise a target that may be a group OR a personal chat. WaGroupJid::normalize() would rewrite
     * "<digits>@s.whatsapp.net" to "<digits>@g.us", so a personal JID is kept as-is (domain lowercased).
     */
    public static function normalizeTarget(string $jid): string
    {
        $jid = (string) preg_replace('/\s+/', '', trim($jid));
        if (self::isPersonalJid($jid)) {
            return explode('@', $jid, 2)[0].'@s.whatsapp.net';
        }

        return WaGroupJid::normalize($jid);
    }

    /**
     * Technician Input target: like normalizeTarget(), but a bare phone number (10-15 digits, optional "+",
     * leading 0 = Indonesia 62) becomes a personal JID instead of a fake @g.us group. This matches the
     * runtime (TechnicianProgressService::normalizeInputJid treats >= 10 digits as a phone). Real group ids
     * are longer (18 digits) and keep the group path. Shorter digit-only values are refused by the settings
     * writer (see isTooShortInputNumber).
     */
    public static function normalizeInputTarget(string $jid): string
    {
        $compact = (string) preg_replace('/\s+/', '', trim($jid));
        if (preg_match('/^\+?(\d{10,15})$/', $compact, $m)) {
            return self::phoneToPersonalJid($m[1]);
        }

        return self::normalizeTarget($jid);
    }

    /**
     * A digit-only (optional "+") Input value of 1-9 digits: too short for a phone number the runtime would
     * recognise (>= 10 digits) and not a group id, so it is a typo and must be refused on save.
     */
    public static function isTooShortInputNumber(string $jid): bool
    {
        $compact = (string) preg_replace('/\s+/', '', trim($jid));

        return (bool) preg_match('/^\+?\d{1,9}$/', $compact);
    }

    /**
     * Digits of a phone number -> personal JID with the Indonesian prefix rules shared with the runtime
     * (TechnicianProgressService::normalizeInputJid): 0xxx -> 62xxx, 8xxx -> 628xxx, 62xxx unchanged.
     */
    public static function phoneToPersonalJid(string $digits): string
    {
        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return $digits.'@s.whatsapp.net';
    }

    /**
     * Ticket / connectivity / attendance / technician-share target: never a PSB/migration group.
     * Empty is not a refusal (it means "unset").
     *
     * @param  list<string>  $psbJids
     */
    public static function notPsbRefusal(string $jid, array $psbJids): ?string
    {
        return self::inList($jid, $psbJids) ? self::REASON_PSB : null;
    }

    /** @param  list<string>  $list */
    public static function inList(string $jid, array $list): bool
    {
        $jid = WaGroupJid::normalize($jid);
        if ($jid === '' || $list === []) {
            return false;
        }
        $bare = self::bare($jid);
        foreach ($list as $candidate) {
            if (self::bare((string) $candidate) === $bare) {
                return true;
            }
        }

        return false;
    }

    /** Is $jid the same group as $other (both non-empty)? */
    public static function same(string $jid, string $other): bool
    {
        $jid = WaGroupJid::normalize($jid);
        $other = WaGroupJid::normalize($other);

        return $jid !== '' && $other !== '' && self::bare($jid) === self::bare($other);
    }

    /** Skynet Report group: never a PSB/migration group. */
    public static function reportRefusal(string $jid, array $psbJids): ?string
    {
        return self::inList($jid, $psbJids) ? self::REASON_PSB : null;
    }

    /**
     * Ops alert group (wa_report_group_jid): never a PSB/migration group, and never the ticket group
     * (EvolutionApiService::sendTicketClosedReportToGroup refuses to send when both are the same group;
     * pinned by WaGroupFollowupsTest). $ticketJid is the effective ticket group (setting, else env default).
     */
    public static function opsAlertRefusal(string $jid, array $psbJids, string $ticketJid = ''): ?string
    {
        if (self::inList($jid, $psbJids)) {
            return self::REASON_PSB;
        }

        return self::same($jid, $ticketJid) ? self::REASON_TICKET : null;
    }

    /**
     * Technical (shadow) log group: PSB never; Report / ops-alert group only with the explicit allow flag.
     *
     * @param  list<string>  $psbJids
     * @param  list<string>  $reportJids
     */
    public static function shadowRefusal(string $jid, bool $allowReportGroup, array $psbJids, array $reportJids, string $opsJid): ?string
    {
        $jid = WaGroupJid::normalize($jid);
        if ($jid === '') {
            return self::REASON_EMPTY;
        }
        if (self::inList($jid, $psbJids)) {
            return self::REASON_PSB;
        }
        $isReport = self::inList($jid, $reportJids) || self::same($opsJid, $jid);
        if ($isReport && ! $allowReportGroup) {
            return self::REASON_REPORT_OR_OPS;
        }

        return null;
    }

    /**
     * "Daily report not delivered" alert target: never a Report, ops-alert or PSB group (no allow flag).
     * An empty JID is not a refusal (it means: no WA alert).
     *
     * @param  list<string>  $psbJids
     * @param  list<string>  $reportJids
     */
    public static function undeliveredRefusal(string $jid, array $psbJids, array $reportJids, string $opsJid): ?string
    {
        $jid = WaGroupJid::normalize($jid);
        if ($jid === '') {
            return null;
        }
        if (self::inList($jid, $psbJids)) {
            return self::REASON_PSB;
        }
        if (self::inList($jid, $reportJids)) {
            return self::REASON_REPORT;
        }
        if (self::same($opsJid, $jid)) {
            return self::REASON_OPS;
        }

        return null;
    }
}
