<?php

namespace App\Services;

use App\Models\DeviceRecoveryCase;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * NotificationService — Unified notification dispatcher for ticket assignments.
 *
 * All automated ticket notifications use the Evolution API gateway.
 * The only supported opt-out is the explicit `disabled` channel.
 */
class NotificationService
{
    /**
     * Get the active notification channel from config.
     */
    public function getActiveChannel(): string
    {
        $configured = (string) config('services.notification.channel', 'evolution_api');

        return $configured === 'disabled' ? 'disabled' : 'evolution_api';
    }

    /**
     * Send a ticket assignment notification through the active channel.
     */
    public function sendTicketAssignment(SupportTicket $ticket): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            Log::info('[Notification] Channel disabled. Would have sent ticket assignment.', [
                'ticket_code' => $ticket->code,
                'teknisi' => $ticket->assignee?->name,
            ]);

            return false;
        }

        Log::info('[Notification] Sending ticket assignment', [
            'ticket_code' => $ticket->code,
            'channel' => $channel,
            'teknisi' => $ticket->assignee?->name,
        ]);

        try {
            return app(EvolutionApiService::class)
                ->forChannel('ticket')
                ->sendTicketAssignment($ticket)['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send', [
                'ticket_code' => $ticket->code,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a pickup (device recovery) assignment to the case technician.
     */
    public function sendRecoveryAssignment(DeviceRecoveryCase $case): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            Log::info('[Notification] Channel disabled. Would have sent recovery assignment.', [
                'recovery_case_id' => $case->id,
                'teknisi' => $case->assignee?->name,
            ]);

            return false;
        }

        try {
            return app(EvolutionApiService::class)
                ->forChannel('ticket')
                ->sendRecoveryAssignment($case)['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send recovery assignment', [
                'recovery_case_id' => $case->id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a ticket reassignment notification to the old technician.
     */
    public function sendTicketReassigned(SupportTicket $ticket, User $oldTechnician): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            return false;
        }

        try {
            $phone = $oldTechnician->phone;
            if (! empty($phone)) {
                $message = "🔄 Tiket Dipindahkan

ID: {$ticket->code}
Gangguan: {$ticket->gangguan}

Tiket ini telah dipindahkan ke teknisi lain. Terima kasih!";

                return app(EvolutionApiService::class)
                    ->forChannel('ticket')
                    ->send($phone, $message)['success'] ?? false;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send reassign', [
                'ticket_code' => $ticket->code,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a ticket closed/resolved notification to WA group.
     */
    public function sendTicketClosedToGroup(SupportTicket $ticket): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            Log::info('[Notification] Channel disabled. Would have sent ticket closed notification.', [
                'ticket_code' => $ticket->code,
            ]);

            return false;
        }

        Log::info('[Notification] Sending ticket closed notification to group', [
            'ticket_code' => $ticket->code,
            'channel' => $channel,
        ]);

        try {
            $evolution = app(EvolutionApiService::class)->forChannel('ticket');
            $ticketResult = $evolution->sendTicketClosedToGroup($ticket);
            $evolution->sendTicketClosedReportToGroup($ticket);

            return $ticketResult['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send closed notification', [
                'ticket_code' => $ticket->code,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a new-ticket notification to WA group (always, even if unassigned).
     */
    public function sendTicketCreatedToGroup(SupportTicket $ticket): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            Log::info('[Notification] Channel disabled. Would have sent new-ticket group notification.', [
                'ticket_code' => $ticket->code,
            ]);

            return false;
        }

        Log::info('[Notification] Sending new-ticket notification to group', [
            'ticket_code' => $ticket->code,
            'channel' => $channel,
        ]);

        try {
            return app(EvolutionApiService::class)
                ->forChannel('ticket')
                ->sendTicketCreatedToGroup($ticket)['success'] ?? false;
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send new-ticket notification', [
                'ticket_code' => $ticket->code,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a ticket status change notification to WA group.
     */
    public function sendTicketStatusChangeToGroup(SupportTicket $ticket, string $oldStatus, string $newStatus): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            Log::info('[Notification] Channel disabled. Would have sent status change notification.', [
                'ticket_code' => $ticket->code,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return false;
        }

        Log::info('[Notification] Sending status change notification to group', [
            'ticket_code' => $ticket->code,
            'channel' => $channel,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
        ]);

        try {
            $groupResult = app(EvolutionApiService::class)
                ->forChannel('ticket')
                ->sendTicketStatusChangeToGroup($ticket, $oldStatus, $newStatus);

            // Also notify the assigned teknisi directly (individual DM).
            // Best-effort: do not fail the group notification if individual fails.
            $techResult = app(EvolutionApiService::class)
                ->forChannel('ticket')
                ->sendTicketStatusChangeToTeknisi($ticket, $oldStatus, $newStatus);

            Log::info('[Notification] Status change sent', [
                'ticket_code' => $ticket->code,
                'group_ok' => $groupResult['success'] ?? false,
                'teknisi_ok' => $techResult['success'] ?? false,
            ]);

            return ($groupResult['success'] ?? false) || ($techResult['success'] ?? false);
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed to send status change notification', [
                'ticket_code' => $ticket->code,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generate a wa.me link with pre-filled ticket message for manual WhatsApp send.
     *
     * This is 100% safe — no API call, just a link the admin clicks to open WhatsApp Web.
     */
    public function generateWaMeLink(SupportTicket $ticket): ?string
    {
        $phone = $ticket->assignee?->phone;

        if (empty($phone)) {
            return null;
        }

        // Format phone for wa.me: strip non-numeric, convert 08xxx to 628xxx
        $formatted = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($formatted, '0')) {
            $formatted = '62'.substr($formatted, 1);
        } elseif (str_starts_with($formatted, '8')) {
            $formatted = '62'.$formatted;
        }

        // Build pre-filled message
        $message = "🎫 TICKET BARU DIASSIGN\n\n"
            ."ID Tiket: {$ticket->code}\n"
            ."Gangguan: {$ticket->gangguan}\n"
            .'Pelanggan: '.($ticket->customer_name ?? '-')."\n"
            .'No HP: '.($ticket->customer_phone ?? '-')."\n\n"
            .'Silakan kerjakan segera. Terima kasih! 🙏';

        return "https://wa.me/{$formatted}?".http_build_query(['text' => $message]);
    }

    /**
     * Check if the active notification channel is configured.
     */
    public function isConfigured(): bool
    {
        $channel = $this->getActiveChannel();

        if ($channel === 'disabled') {
            return true; // "configured" to do nothing
        }

        return app(EvolutionApiService::class)
            ->forChannel('ticket')
            ->isConfigured();
    }
}
