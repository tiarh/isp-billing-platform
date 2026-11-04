<?php

namespace App\Services;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * WhatsAppService — Notification channel for ticket assignments.
 *
 * All automated messages use the self-hosted Evolution API gateway.
 * The only supported opt-out is the explicit `disabled` provider.
 *
 * @deprecated Prefer NotificationService for ticket notifications.
 * Kept for backward compatibility and as a direct WA provider dispatcher.
 */
class WhatsAppService
{
    /**
     * Get the active provider name from config.
     */
    public function getActiveProvider(): string
    {
        $configured = (string) config('services.whatsapp.provider', 'evolution_api');

        return $configured === 'disabled' ? 'disabled' : 'evolution_api';
    }

    /**
     * Send a ticket assignment notification via the configured provider.
     *
     * This is intentionally non-blocking: failures are logged but not thrown.
     */
    public function sendTicketAssignment(SupportTicket $ticket): bool
    {
        /** @var User|null $technician */
        $technician = $ticket->assignee;

        if (! $technician) {
            Log::warning('[WhatsApp] No assignee found for ticket', ['ticket_code' => $ticket->code]);

            return false;
        }

        $phone = $technician->phone ?? $technician->getAttribute('phone');

        if (empty($phone)) {
            Log::info('[WhatsApp] Teknisi has no phone number, skipping WA notification', [
                'ticket_code' => $ticket->code,
                'teknisi' => $technician->name,
                'teknisi_id' => $technician->id,
            ]);

            return false;
        }

        $provider = $this->getActiveProvider();

        if ($provider === 'disabled') {
            Log::info('[WhatsApp] Provider disabled. Would have sent ticket assignment.', [
                'ticket_code' => $ticket->code,
                'teknisi' => $technician->name,
                'phone' => $phone,
            ]);

            return false;
        }

        $message = $this->buildMessage($ticket);

        Log::info('[WhatsApp] Attempting to send ticket assignment notification', [
            'ticket_code' => $ticket->code,
            'teknisi' => $technician->name,
            'phone' => $phone,
            'provider' => $provider,
        ]);

        try {
            $result = $this->sendViaProvider($provider, $phone, $message);

            if ($result !== null) {
                Log::info('[WhatsApp] Notification sent successfully', [
                    'ticket_code' => $ticket->code,
                    'teknisi' => $technician->name,
                    'phone' => $phone,
                    'provider' => $provider,
                ]);

                return $result['success'] ?? true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('[WhatsApp] Failed to send notification', [
                'ticket_code' => $ticket->code,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a reassignment notification to the old technician.
     */
    public function sendTicketReassigned(SupportTicket $ticket, User $oldTechnician): bool
    {
        $phone = $oldTechnician->phone ?? $oldTechnician->getAttribute('phone');

        if (empty($phone)) {
            Log::info('[WhatsApp] Old technician has no phone number, skipping reassign WA notification', [
                'ticket_code' => $ticket->code,
                'old_technician' => $oldTechnician->name,
            ]);

            return false;
        }

        $provider = $this->getActiveProvider();

        if ($provider === 'disabled') {
            Log::info('[WhatsApp] Provider disabled. Would have sent reassign notification.', [
                'ticket_code' => $ticket->code,
                'old_technician' => $oldTechnician->name,
                'phone' => $phone,
            ]);

            return false;
        }

        $message = "\xF0\x9F\x94\x84 *Tiket Dipindahkan*\n\n"
            ."ID: `{$ticket->code}`\n"
            ."Gangguan: {$ticket->gangguan}\n\n"
            ."Tiket ini telah dipindahkan ke teknisi lain. Terima kasih atas kontribusinya! \xF0\x9F\x99\x8F";

        Log::info('[WhatsApp] Attempting to send reassign notification', [
            'ticket_code' => $ticket->code,
            'old_technician' => $oldTechnician->name,
            'phone' => $phone,
            'provider' => $provider,
        ]);

        try {
            $result = $this->sendViaProvider($provider, $phone, $message);

            if ($result !== null) {
                Log::info('[WhatsApp] Reassign notification sent successfully', [
                    'ticket_code' => $ticket->code,
                    'old_technician' => $oldTechnician->name,
                    'phone' => $phone,
                ]);

                return $result['success'] ?? true;
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('[WhatsApp] Failed to send reassign notification', [
                'ticket_code' => $ticket->code,
                'old_technician' => $oldTechnician->name,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Generic send — dispatch to the configured provider.
     *
     * @return array|null Provider result array
     */
    public function sendTicketNotification(string $phone, string $message): ?array
    {
        $provider = $this->getActiveProvider();

        if ($provider === 'disabled') {
            Log::info('[Notification] Notifications disabled');

            return ['success' => false, 'error' => 'Notifications disabled'];
        }

        try {
            return $this->sendViaProvider($provider, $phone, $message);
        } catch (\Throwable $e) {
            Log::error('[Notification] Failed', ['provider' => $provider, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate a wa.me link for manual WhatsApp send (static convenience method).
     */
    public static function generateWaLink(string $phone, string $message): string
    {
        return app(WhatsAppLinkService::class)->generateLink($phone, $message);
    }

    /**
     * Quick check if any WhatsApp provider is configured.
     */
    public function isConfigured(): bool
    {
        $provider = $this->getActiveProvider();

        if ($provider === 'disabled') {
            return false;
        }

        if ($provider === 'evolution_api') {
            return app(EvolutionApiService::class)->forChannel('ops')->isConfigured();
        }

        return true;
    }

    /**
     * Dispatch message to the appropriate provider.
     */
    private function sendViaProvider(string $provider, string $phone, string $message): ?array
    {
        return match ($provider) {
            'evolution_api' => app(EvolutionApiService::class)->forChannel('ops')->send($phone, $message),
            'disabled' => ['success' => false, 'error' => 'Notifications disabled'],
            default => ['success' => false, 'error' => 'Only Evolution API is supported'],
        };
    }

    /**
     * Build the ticket assignment notification message.
     */
    private function buildMessage(SupportTicket $ticket): string
    {
        $priorityLabels = [
            'low' => 'Rendah',
            'normal' => 'Normal',
            'high' => 'Tinggi',
            'urgent' => 'URGENT',
        ];
        $priorityLabel = $priorityLabels[$ticket->priority] ?? ucfirst($ticket->priority ?? 'Normal');

        $statusLabels = [
            'open' => 'Open',
            'assigned' => 'Assigned',
            'in_progress' => 'Proses',
            'pending' => 'Pending',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ];
        $statusLabel = $statusLabels[$ticket->status] ?? ucfirst($ticket->status ?? 'Assigned');

        return "🎫 *TICKET BARU DIASSIGN*\n\n"
            ."ID Tiket: `{$ticket->code}`\n"
            ."Laporan: `{$ticket->report_id}`\n"
            ."Gangguan: {$ticket->gangguan}\n"
            .'Keterangan: '.($ticket->keterangan ?: '-')."\n\n"
            ."Pelanggan: {$ticket->customer_name}\n"
            ."No HP: {$ticket->customer_phone}\n"
            .'Koordinat: '.($ticket->customer_coords ?: '-')."\n\n"
            ."Prioritas: {$priorityLabel}\n"
            ."Status: {$statusLabel}\n\n"
            .'Silakan kerjakan segera. Terima kasih! 🙏';
    }
}
