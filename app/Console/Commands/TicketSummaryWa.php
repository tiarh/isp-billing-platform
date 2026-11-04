<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\EvolutionApiService;
use App\Support\TicketWaGroupJid;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketSummaryWa extends Command
{
    protected $signature = 'ticket:summary-wa {--group= : Override WA group JID}';

    protected $description = 'Send ticket summary to WhatsApp group via Evolution API';

    public function handle(): int
    {
        $evoService = app(EvolutionApiService::class)->forChannel('ticket');

        if (! $evoService->isConfigured()) {
            $this->error('Evolution API not configured');

            return 1;
        }

        $groupJid = TicketWaGroupJid::resolve($this->option('group'));

        if (empty($groupJid)) {
            $this->error('No WA group ID configured');

            return 1;
        }

        // Ensure @g.us suffix for group JIDs
        if (! str_ends_with($groupJid, '@g.us')) {
            $groupJid = $groupJid.'@g.us';
        }

        // --- Build the summary ---

        $now = now()->setTimezone('Asia/Jakarta');
        $dateStr = $now->format('d/m/Y H:i');

        // Ticket counts by status
        $statusCounts = DB::table('support_tickets')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $open = ($statusCounts['open'] ?? 0) + ($statusCounts['assigned'] ?? 0);
        $inProgress = $statusCounts['in_progress'] ?? 0;
        $resolved = $statusCounts['resolved'] ?? 0;
        $closed = $statusCounts['closed'] ?? 0;
        $pending = $statusCounts['pending'] ?? 0;
        $totalActive = $open + $inProgress + $pending;

        // Teknisi breakdown: open/in_progress tickets per teknisi
        $teknisiStats = User::where('role', 'teknisi')
            ->select('id', 'name')
            ->withCount([
                'assignedTickets as active_count' => function ($q) {
                    $q->whereIn('status', ['open', 'assigned', 'in_progress', 'pending']);
                },
            ])
            ->having('active_count', '>', 0)
            ->orderByDesc('active_count')
            ->get();

        // --- Format message ---
        $message = "📊 RINGKASAN TIKET - {$dateStr} WIB\n\n";
        $message .= "Status:\n";
        $message .= "🔴 Open: {$open}\n";
        $message .= "🟡 Proses: {$inProgress}\n";
        if ($pending > 0) {
            $message .= "⏳ Pending: {$pending}\n";
        }
        $message .= "🟢 Selesai: {$resolved}\n";
        $message .= "⚪ Closed: {$closed}\n";

        if ($teknisiStats->isNotEmpty()) {
            $message .= "\nTeknisi:\n";
            foreach ($teknisiStats as $tech) {
                $message .= "🔧 {$tech->name}: {$tech->active_count} tiket proses\n";
            }
        }

        $message .= "\nTotal aktif: {$totalActive} tiket\n";
        $message .= "\n_Skynet eBilling_";

        // --- Send via Evolution API ---
        try {
            $result = $evoService->sendToGroup($groupJid, $message);

            if ($result['success'] ?? false) {
                Log::info('[Ticket Summary WA] Sent successfully', ['group' => $groupJid]);
                $this->info("Summary sent to WA group: {$groupJid}");

                return 0;
            }

            Log::error('[Ticket Summary WA] Send failed', ['result' => $result]);
            $this->error('Failed to send: '.($result['error'] ?? 'Unknown error'));

            return 1;
        } catch (\Exception $e) {
            Log::error('[Ticket Summary WA] Exception', ['error' => $e->getMessage()]);
            $this->error('Exception: '.$e->getMessage());

            return 1;
        }
    }
}
