<?php

namespace App\Console\Commands;

use App\Services\EvolutionApiService;
use App\Support\OpsTime;
use App\Support\TicketWaGroupJid;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TicketReportWA extends Command
{
    protected $signature = 'ticket:report-wa {--group= : Override WA group JID}';

    protected $description = 'Send ticket status report to WhatsApp group via Evolution API';

    public function handle(): int
    {
        $evoService = app(EvolutionApiService::class)->forChannel('ticket');
        $groupJid = TicketWaGroupJid::resolve($this->option('group'));

        if (! $evoService->isConfigured()) {
            $this->error('Evolution API key not configured');

            return 1;
        }

        // Get ticket counts by status
        [$today] = OpsTime::dayBounds();
        $stats = DB::table('support_tickets')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        // Tickets closed today
        $closedToday = DB::table('support_tickets')
            ->where('status', 'closed')
            ->where('updated_at', '>=', $today)
            ->count();

        // Tickets opened today
        $openedToday = DB::table('support_tickets')
            ->where('created_at', '>=', $today)
            ->count();

        // Recently closed tickets detail (last 2 hours)
        $recentlyClosed = DB::table('support_tickets')
            ->where('status', 'closed')
            ->where('updated_at', '>=', now()->subHours(2))
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get(['code', 'customer_name', 'gangguan', 'updated_at']);

        $open = ($stats['open'] ?? 0) + ($stats['assigned'] ?? 0);
        $pending = $stats['pending'] ?? 0;
        $inProgress = $stats['in_progress'] ?? 0;
        $closed = $stats['closed'] ?? 0;
        $total = array_sum($stats);

        $now = OpsTime::now()->format('H:i');
        $date = OpsTime::now()->format('d/m/Y');

        $message = "📊 *LAPORAN TIKET*\n";
        $message .= "🕐 {$date} Jam {$now} WIB\n";
        $message .= "━━━━━━━━━━━━━━━\n\n";
        $message .= "📋 *Ringkasan:*\n";
        $message .= "🟢 Open/Assigned: *{$open}*\n";
        $message .= "🟡 Pending: *{$pending}*\n";
        $message .= "🔵 In Progress: *{$inProgress}*\n";
        $message .= "✅ Closed: *{$closed}*\n";
        $message .= "📌 Total: *{$total}*\n\n";
        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= "📈 *Hari Ini:*\n";
        $message .= "📥 Tiket Baru: *{$openedToday}*\n";
        $message .= "✅ Tiket Closed: *{$closedToday}*\n\n";

        if ($recentlyClosed->count() > 0) {
            $message .= "━━━━━━━━━━━━━━━\n";
            $message .= "✅ *Tiket Closed (2 jam terakhir):*\n";
            foreach ($recentlyClosed as $t) {
                $time = Carbon::parse($t->updated_at, 'UTC')->timezone(OpsTime::TZ)->format('H:i');
                $gangguan = $t->gangguan ? substr($t->gangguan, 0, 30) : '-';
                $message .= "• [{$t->code}] {$t->customer_name} — {$gangguan} ({$time})\n";
            }
            $message .= "\n";
        }

        $message .= "━━━━━━━━━━━━━━━\n";
        $message .= '_Auto-report by Skynet eBilling_';

        // Send via Evolution API
        try {
            $result = $evoService->sendToGroup($groupJid, $message);

            if ($result['success'] ?? false) {
                Log::info('[Ticket Report WA] Sent successfully', ['group' => $groupJid]);
                $this->info("Report sent to WA group: {$groupJid}");

                return 0;
            }

            Log::error('[Ticket Report WA] Send failed', ['result' => $result]);
            $this->error('Failed to send: '.($result['error'] ?? 'Unknown error'));

            return 1;
        } catch (\Exception $e) {
            Log::error('[Ticket Report WA] Exception', ['error' => $e->getMessage()]);
            $this->error('Exception: '.$e->getMessage());

            return 1;
        }
    }
}
