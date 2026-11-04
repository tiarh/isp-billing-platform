<?php

namespace App\Console\Commands;

use App\Models\Package;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Services\MikrotikService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Auto-sync PPP profile MikroTik -> Package DRAFT harian (ACK owner 2026-07-27).
 * - Profile BARU  -> Package draft (is_active=0, price=0) — operator isi harga lalu aktifkan.
 * - Profile LAMA  -> hanya update rate_limit; name/price/is_active TIDAK PERNAH disentuh.
 * Read-only ke MikroTik (getProfiles saja). Router gagal konek di-skip dengan log, lanjut router berikutnya.
 */
class SyncRouterProfilesDraft extends Command
{
    protected $signature = 'routers:sync-profiles-draft {--router= : ID router tertentu (default: semua router aktif)}';

    protected $description = 'Sync PPP profiles semua MikroTik ke Package sebagai draft (tanpa menimpa data operator)';

    private const SKIP_NAMES = ['default', 'default-encryption', 'isolation'];

    public function handle(MikrotikService $mikrotik): int
    {
        $query = Router::query()->where('is_active', true)->orderBy('id');
        if ($this->option('router')) {
            $query->whereKey((int) $this->option('router'));
        }
        $routers = $query->get();
        if ($routers->isEmpty()) {
            $this->warn('Tidak ada router aktif yang cocok.');

            return self::SUCCESS;
        }

        $totalNew = 0;
        $totalUpdated = 0;
        foreach ($routers as $router) {
            try {
                $mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
                try {
                    $profiles = $mikrotik->getProfiles();
                } finally {
                    $mikrotik->disconnect();
                }
            } catch (\Throwable $e) {
                Log::warning('[profiles-draft] router skip - konek gagal', ['router' => $router->id, 'name' => $router->name, 'err' => $e->getMessage()]);
                $this->warn("skip {$router->name}: {$e->getMessage()}");

                continue;
            }

            $new = 0;
            $updated = 0;
            foreach ($profiles as $profile) {
                $name = trim((string) ($profile['name'] ?? ''));
                if ($name === '' || in_array(strtolower($name), self::SKIP_NAMES, true)
                    || stripos($name, 'isolir') !== false || stripos($name, 'speedtest') !== false) {
                    continue;
                }
                $rateLimit = ($profile['rate-limit'] ?? '') ?: null;

                $pkg = Package::query()
                    ->where('router_id', $router->id)
                    ->where('mikrotik_profile', $name)
                    ->first();
                if ($pkg) {
                    if ((string) $pkg->rate_limit !== (string) $rateLimit) {
                        $pkg->rate_limit = $rateLimit;
                        $pkg->save();
                        $updated++;
                    }
                } else {
                    Package::create([
                        'router_id' => $router->id,
                        'mikrotik_profile' => $name,
                        'name' => 'Paket '.$name,
                        'rate_limit' => $rateLimit,
                        'code' => strtoupper(preg_replace('/[^a-z0-9]+/i', '_', $name)).'_'.$router->id,
                        'price' => 0,
                        'is_active' => false, // draft — operator isi harga lalu aktifkan
                    ]);
                    $new++;
                }

                RouterProfile::updateOrCreate(
                    ['router_id' => $router->id, 'name' => $name],
                    [
                        'rate_limit' => $rateLimit,
                        'bandwidth' => $rateLimit,
                        'local_address' => $profile['local-address'] ?? null,
                        'remote_address' => $profile['remote-address'] ?? null,
                        'only_one' => $profile['only-one'] ?? 'yes',
                    ]
                );
            }

            $totalNew += $new;
            $totalUpdated += $updated;
            $this->info("{$router->name}: draft_baru={$new} rate_limit_update={$updated}");
            Log::info('[profiles-draft] router synced', ['router' => $router->id, 'name' => $router->name, 'new_draft' => $new, 'rate_limit_updated' => $updated]);
        }

        $this->info("Selesai. total_draft_baru={$totalNew} total_rate_limit_update={$totalUpdated} (draft perlu diisi harga + diaktifkan operator)");

        return self::SUCCESS;
    }
}
