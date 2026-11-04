<?php

namespace App\Console\Commands;

use App\Models\Attendance;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\OpsAlertService;
use App\Support\OpsAlertSource;
use App\Support\OpsTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Keep WA gateway healthy so absensi + broadcast keep working.
 * - Clears sticky status cache
 * - Soft-heals close/connecting sessions
 * - Alerts ops when QR re-pair is required (deduped)
 * - After recovery to open, kicks attendance catch-up once
 */
class HealWaGateway extends Command
{
    protected $signature = 'wa:heal
                            {--no-sync : Do not trigger attendance:sync-today after recovery}
                            {--quiet-ok : Exit 0 even when needs QR (for scheduler)}';

    protected $description = 'Heal Evolution WA session + nudge attendance after reconnect';

    public function handle(EvolutionApiService $evo, OpsAlertService $ops): int
    {
        if (! $evo->isConfigured()) {
            $this->error('Evolution API not configured');

            return self::FAILURE;
        }

        $result = $evo->healSession();
        $state = (string) ($result['state'] ?? 'unknown');
        $action = (string) ($result['action'] ?? 'none');
        $msg = (string) ($result['message'] ?? '');

        $this->line("state={$state} action={$action} msg={$msg}");
        Log::info('[wa:heal]', $result);

        Cache::put('wa:last_heal_state', $state, now()->addDay());
        Cache::put('wa:last_heal_at', now()->toIso8601String(), now()->addDay());

        $failStreakKey = 'wa:heal_fail_streak';
        if ($state === 'open') {
            $prevStreak = (int) Cache::get($failStreakKey, 0);
            Cache::forget($failStreakKey);
            Cache::forget('wa:heal_alerted');

            // After recovery from outage, pull absensi (covers missed webhooks)
            if (! $this->option('no-sync') && $prevStreak > 0) {
                $this->info('Recovered after outage — running attendance:sync-today');
                try {
                    Artisan::call('attendance:sync-today', [
                        '--days' => 3,
                        '--limit' => 300,
                    ]);
                    $this->line(trim(Artisan::output()));
                } catch (\Throwable $e) {
                    $this->warn('attendance sync after heal failed: '.$e->getMessage());
                }
            } elseif (! $this->option('no-sync')) {
                // Light check: if no attendance today and group set, still catch-up once per hour
                $groupId = (string) Setting::get('attendance_group_id', '');
                if ($groupId !== '' && $this->shouldCatchUpToday()) {
                    $this->info('No attendance today yet — catch-up sync');
                    try {
                        Artisan::call('attendance:sync-today', [
                            '--days' => 1,
                            '--limit' => 200,
                        ]);
                        $this->line(trim(Artisan::output()));
                        Cache::put('wa:attendance_catchup_hour', now('Asia/Jakarta')->format('Y-m-d-H'), now()->addHours(2));
                    } catch (\Throwable $e) {
                        $this->warn($e->getMessage());
                    }
                }
            }

            return self::SUCCESS;
        }

        $streak = (int) Cache::get($failStreakKey, 0) + 1;
        Cache::put($failStreakKey, $streak, now()->addHours(6));

        // Alert after 2 consecutive failed heals (~10 min if every 5 min) to avoid noise on brief blips
        if ($streak >= 2 && ! Cache::get('wa:heal_alerted')) {
            $ops->send(
                "[Skynet WA] Gateway tidak open (state={$state}). Absensi/broadcast bisa macet. Buka Settings → WhatsApp Gateway → scan QR. Instance={$evo->getInstanceName()}",
                'wa-gateway-down',
                1800,
                '',
                OpsAlertSource::WA_GATEWAY_DOWN
            );
            Cache::put('wa:heal_alerted', 1, now()->addMinutes(30));
            $this->warn('ops alert sent');
        }

        if ($this->option('quiet-ok')) {
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function shouldCatchUpToday(): bool
    {
        $hourKey = now('Asia/Jakarta')->format('Y-m-d-H');
        if (Cache::get('wa:attendance_catchup_hour') === $hourKey) {
            return false;
        }

        try {
            if (! class_exists(Attendance::class)) {
                return true;
            }
            // Any row created today (WIB day, stored as UTC) counts as activity
            [$from, $to] = OpsTime::dayBounds();
            $has = Attendance::query()
                ->whereBetween('created_at', [$from, $to])
                ->exists();

            return ! $has;
        } catch (\Throwable) {
            return true;
        }
    }
}
