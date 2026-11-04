<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\TechnicianDailySchedule;
use App\Models\TechnicianDailyTask;
use App\Models\TechnicianMember;
use App\Models\TechnicianTeam;
use App\Models\TechnicianWaCommandLog;
use Illuminate\Support\Facades\Cache;

class KerjaanKnowledgeBase
{
    /**
     * Context pengetahuan statis (arsitektur, aturan operasional, dsb).
     * Di-cache selama 1 jam agar hemat I/O.
     */
    public function staticKnowledge(): string
    {
        return Cache::remember('kerjaan_knowledge_static', 3600, function () {
            $files = [
                'arsitektur.md' => base_path('docs/arsitektur.md'),
                'NOTES_PRIORITY_NEXT.md' => base_path('docs/NOTES_PRIORITY_NEXT.md'),
                'PLAN_PROGRESS_HARIAN_TEKNISI.md' => base_path('users/tiar/tiket/PLAN_PROGRESS_HARIAN_TEKNISI.md'),
                'arsitektur_progress_teknisi.md' => base_path('users/tiar/tiket/arsitektur_progress_teknisi.md'),
                'flowchart_progress_teknisi.md' => base_path('users/tiar/tiket/flowchart_progress_teknisi.md'),
                'PSB_AREA_ROUTER_MATRIX.md' => base_path('docs/PSB_AREA_ROUTER_MATRIX.md'),
                'PSB_WA_SAFETY.md' => base_path('docs/PSB_WA_SAFETY.md'),
            ];

            $out = [];
            foreach ($files as $name => $path) {
                if (file_exists($path)) {
                    $content = file_get_contents($path);
                    if ($content) {
                        $out[] = "=== KNOWLEDGE DOC: {$name} ===\n".trim($content);
                    }
                }
            }

            return implode("\n\n---\n\n", $out);
        });
    }

    /**
     * Context pengetahuan dinamis (realtime data dari DB hari ini).
     */
    public function dynamicContext(string $workDate): string
    {
        $teams = TechnicianTeam::with('members')
            ->whereDate('work_date', $workDate)
            ->get();

        $tasks = TechnicianDailyTask::whereDate('work_date', $workDate)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->get();

        $schedule = TechnicianDailySchedule::whereDate('schedule_date', $workDate)->first();
        $members = TechnicianMember::where('is_active', true)->get();

        $teamLines = [];
        foreach ($teams as $t) {
            $memberNames = $t->members->pluck('name')->join(' & ') ?: 'Belum ada teknisi';
            $taskCount = $tasks->where('team_no', $t->team_no)->count();
            $teamLines[] = "- Team {$t->team_no}: {$memberNames} | Site: ".($t->site ?: '-')." | {$taskCount} task aktif";
        }

        $memberLines = $members->map(fn ($m) => "- {$m->name} (".($m->role ?: 'teknisi').')')->join("\n");

        $memoryItems = Setting::get('ticket_progress_language_memory', []);
        if (is_string($memoryItems)) {
            $memoryItems = json_decode($memoryItems, true) ?: [];
        }
        $memoryLines = [];
        if (is_array($memoryItems)) {
            foreach ($memoryItems as $item) {
                if (is_array($item) && ! empty($item['alias']) && ! empty($item['meaning'])) {
                    $memoryLines[] = '- '.$item['alias'].' = '.$item['meaning'];
                }
            }
        }

        return "=== REALTIME DYNAMIC CONTEXT ({$workDate}) ===\n"
            .'Status Schedule: '.($schedule?->status ?: 'draft')."\n"
            .'Total Task Aktif: '.$tasks->count()."\n\n"
            ."TEAM TERBENTUK HARI INI:\n".($teamLines !== [] ? implode("\n", $teamLines) : '- Belum ada team khusus di-assign')."\n\n"
            ."DAFTAR TEKNISI REFRESH:\n".($memberLines ?: '- Belum ada data teknisi')."\n\n"
            ."KAMUS BAHASA / MEMORY:\n".($memoryLines !== [] ? implode("\n", $memoryLines) : '- Belum ada kamus khusus')."\n\n"
            .'SETTINGS: dry_run='.(Setting::get('ticket_progress_dry_run_enabled', true) ? 'true' : 'false')
            .', reply_enabled='.(Setting::get('ticket_progress_reply_enabled', false) ? 'true' : 'false');
    }

    /**
     * Ambil 10 riwayat pesan chat di grup KERJAAN hari ini sebagai context percakapan.
     */
    public function recentChatHistory(string $workDate): array
    {
        return TechnicianWaCommandLog::query()
            ->whereDate('work_date', $workDate)
            ->latest('id')
            ->take(10)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($log) {
                $sender = $log->sender_name ?: 'Pengguna';

                return [
                    'role' => 'user',
                    'parts' => [['text' => "[{$sender}]: {$log->raw_text}"]],
                ];
            })
            ->all();
    }
}
