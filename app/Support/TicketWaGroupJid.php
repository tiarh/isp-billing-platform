<?php

namespace App\Support;

use App\Models\Setting;

/**
 * WA group JID for the ticket report/summary commands.
 *
 * Order (unchanged): --group option, Setting wa_ticket_progress_share_group_jid,
 * Setting wa_ticket_group_jid, config services.evolution_api.ticket_group_id
 * (env EVOLUTION_API_TICKET_GROUP_ID, default ''). The literal JID that used to
 * be passed as config()'s default was unreachable because that config key is
 * always defined, so dropping it changes nothing.
 */
final class TicketWaGroupJid
{
    public static function resolve(?string $override = null): string
    {
        return (string) ($override
            ?: Setting::where('key', 'wa_ticket_progress_share_group_jid')->value('value')
            ?: Setting::where('key', 'wa_ticket_group_jid')->value('value')
            ?: config('services.evolution_api.ticket_group_id', ''));
    }
}
