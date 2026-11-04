<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PsbDraft;
use App\Models\RouterMtSecretState;
use App\Models\RouterStagedCustomer;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Read-only "#pppoe cari <nama/user/telp>" search + reply text. Single source of truth for both the
 * PSB/Aktivasi group (PsbOrchestratorService::handlePppoeQuery) and the Skynet Report group
 * (ProcessReportPppoeCariJob). Never sends anything, never writes (the live MikroTik read is
 * read-only: connect, getPPPSecret, getActiveConnections), never exposes the PPPoE password.
 */
class PppoeSearchService
{
    public function __construct(protected MikrotikService $mikrotik) {}

    /**
     * @return array{status: string, text: string}
     */
    public function search(?string $query): array
    {
        $q = trim((string) $query);
        if ($q === '') {
            return ['status' => 'pppoe_query', 'text' => "⚠️ *Format cari PPPoE*\nGunakan: `#pppoe cari <nama/user/telp>`\n\n_Contoh:_\n`#pppoe cari djoko comboran`\n`#pppoe cari 0812...`"];
        }

        $terms = array_filter(explode(' ', $q));
        $queryBuilder = Customer::with(['router', 'package']);
        foreach ($terms as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $queryBuilder->where(function ($sub) use ($term) {
                $sub->where('name', 'like', "%{$term}%")
                    ->orWhere('pppoe_user', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('address', 'like', "%{$term}%");
            });
        }

        $matches = $queryBuilder->limit(6)->get();

        if ($matches->isEmpty()) {
            $routerMatches = $this->pppoeRouterSnapshotMatches($q);
            if ($routerMatches !== []) {
                return ['status' => 'pppoe_router_snapshot_found', 'text' => $this->pppoeRouterSnapshotReply($q, $routerMatches)];
            }

            return ['status' => 'pppoe_not_found', 'text' => "❌ *Pencarian PPPoE*\nTidak ditemukan pelanggan dengan kata kunci: *{$q}*"];
        }

        if ($matches->count() === 1) {
            $c = $matches->first();
            $routerName = $c->router ? $c->router->name : 'Unassigned';
            $statusStr = strtoupper((string) ($c->status ?? 'ACTIVE'));
            $profile = $c->package?->mikrotik_profile ?: $c->package?->name ?: '-';
            $local = $c->pppoe_local_address ?: '-';
            $remote = $c->pppoe_remote_address ?: '-';
            $customerUrl = url('/customers/'.$c->id);
            $live = $this->pppoeLiveStatus($c);
            $msg = "🔍 *Detail PPPoE Pelanggan*\n\n".
                   "🆔 *Customer:* #{$c->id}\n".
                   "👤 *Nama:* {$c->name}\n".
                   "🔑 *User PPPoE:* `{$c->pppoe_user}`\n".
                   "🔐 *Pass PPPoE:* tersedia di MikroTik (tidak ditampilkan)\n".
                   "📦 *Profile/Paket:* {$profile}\n".
                   "🌐 *Local/Remote:* `{$local}` / `{$remote}`\n".
                   "📍 *Alamat/Area:* {$c->address}\n".
                   "🌐 *Router:* {$routerName}\n".
                   "⚡ *Status:* {$statusStr}\n".
                   "🔗 *Link:* {$customerUrl}\n".
                   "➡️ *Lanjut:* jika pelanggan ini benar, gunakan data router/secret di atas. Jika session tidak aktif, cek MikroTik {$routerName} > PPP Active.";
            $msg .= "\n\n*Live Read MikroTik*\n"
                ."Secret Live: {$live['secret']}\n"
                ."Active Connection: {$live['active']}\n"
                ."Last Seen/Snapshot: {$live['last_seen']}\n"
                ."Action: {$live['next_action']}";

            return ['status' => 'pppoe_found', 'text' => $msg];
        }

        // Multiple matches: display up to 5 matches with area hint
        $listStr = '';
        foreach ($matches->take(5) as $idx => $c) {
            $num = $idx + 1;
            $routerName = $c->router ? $c->router->name : 'Unassigned';
            $profile = $c->package?->mikrotik_profile ?: $c->package?->name ?: '-';
            $local = $c->pppoe_local_address ?: '-';
            $remote = $c->pppoe_remote_address ?: '-';
            $live = $this->pppoeLiveStatus($c);
            $routerName .= "\n   - Live: secret {$live['secret']}, active {$live['active']}, seen {$live['last_seen']}";
            $listStr .= "{$num}. *{$c->name}* (#{$c->id})\n   • User: `{$c->pppoe_user}` | Pass: tersedia di MikroTik (tidak ditampilkan)\n   • Profile: {$profile} | IP: `{$local}` / `{$remote}`\n   • Area/Alamat: {$c->address}\n   • Router: {$routerName}\n\n";
        }

        $totalHits = $matches->count();
        $moreHint = $totalHits > 5 ? "_Ditemukan {$totalHits} hasil. Tulis kata kunci lebih spesifik, contoh nama + area atau user PPPoE._" : '_Ketik kata kunci lengkap jika ingin detail tunggal._';

        return ['status' => 'pppoe_multi_match', 'text' => "🔍 *Ditemukan beberapa pelanggan:* (max 5)\n\n{$listStr}{$moreHint}"];
    }

    /**
     * @return list<RouterStagedCustomer>
     */
    protected function pppoeRouterSnapshotMatches(string $query): array
    {
        $q = trim($query);
        if ($q === '' || ! Schema::hasTable('router_staged_customers')) {
            return [];
        }

        return RouterStagedCustomer::query()
            ->with('router:id,name')
            ->where('pppoe_user', 'like', '%'.$q.'%')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhere('status', '!=', 'gone');
            })
            ->orderByDesc('last_seen_at')
            ->limit(5)
            ->get()
            ->all();
    }

    /**
     * @param  list<RouterStagedCustomer>  $matches
     */
    protected function pppoeRouterSnapshotReply(string $query, array $matches): string
    {
        $lines = [
            '🔍 *Pencarian PPPoE*',
            'Pelanggan eBilling belum ketemu, tapi secret/router snapshot ditemukan untuk: *'.$query.'*',
            '',
        ];

        foreach ($matches as $index => $row) {
            $raw = is_array($row->raw_payload) ? $row->raw_payload : [];
            $local = data_get($raw, 'local-address')
                ?: data_get($raw, 'local_address')
                ?: data_get($raw, 'local')
                ?: '-';
            $remote = data_get($raw, 'remote-address')
                ?: data_get($raw, 'remote_address')
                ?: data_get($raw, 'address')
                ?: '-';
            $profile = $row->profile ?: data_get($raw, 'profile', '-');
            $status = $row->disabled ? 'disabled' : ($row->status ?: 'snapshot');
            $seen = $row->last_seen_at?->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';

            $lines[] = ($index + 1).'. `'.$row->pppoe_user.'`';
            $lines[] = '   Router: '.($row->router?->name ?: '-').' | Profile: '.$profile;
            $lines[] = '   IP: `'.$local.'` / `'.$remote.'` | Status: '.$status;
            $lines[] = '   Last sync: '.($seen ?: '-');
        }

        $lines[] = '';
        $lines[] = 'Catatan: ini read-only dari snapshot MikroTik. Belum ada link customer eBilling.';
        $lines[] = 'Next: cek/link customer manual dulu, atau pakai data ini untuk preview migrasi.';

        return implode("\n", $lines);
    }

    /** @return array{secret:string,active:string,last_seen:string,next_action:string} */
    protected function pppoeLiveStatus(Customer $customer): array
    {
        $username = trim((string) $customer->pppoe_user);
        if (! $customer->router || $username === '') {
            return [
                'secret' => 'tidak dicek (router/user kosong)',
                'active' => 'tidak dicek',
                'last_seen' => $this->pppoeSnapshotSeen($customer),
                'next_action' => 'lengkapi router dan username PPPoE di data pelanggan.',
            ];
        }

        $router = $customer->router;
        $candidateNewUser = $this->pendingCorrectionUsernameForCustomer($customer, $username);
        $cacheKey = 'psb-wa:pppoe-live:'.$router->id.':'.md5(strtolower($username.'|'.$candidateNewUser));

        return Cache::remember($cacheKey, now()->addSeconds(45), function () use ($customer, $router, $username, $candidateNewUser) {
            $timeout = max(2, min(8, (int) Setting::get('psb_pppoe_search_timeout_seconds', 4)));

            try {
                $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
                try {
                    $secret = $this->mikrotik->getPPPSecret($username);
                    $newSecret = $candidateNewUser !== '' ? $this->mikrotik->getPPPSecret($candidateNewUser) : null;
                    $active = collect($this->mikrotik->getActiveConnections(true))
                        ->first(fn ($row) => strcasecmp((string) ($row['name'] ?? ''), $username) === 0
                            || ($candidateNewUser !== '' && strcasecmp((string) ($row['name'] ?? ''), $candidateNewUser) === 0));
                } finally {
                    $this->mikrotik->disconnect();
                }

                $secretProfile = $secret['profile'] ?? null;
                $newSecretProfile = $newSecret['profile'] ?? null;
                $activeAddress = $active['address'] ?? null;
                $activeUptime = $active['uptime'] ?? null;
                $activeName = (string) ($active['name'] ?? '');
                if (! $secret && $newSecret && $candidateNewUser !== '') {
                    return [
                        'secret' => 'DB stale; secret baru live `'.$candidateNewUser.'`'.($newSecretProfile ? " ({$newSecretProfile})" : ''),
                        'active' => $active ? 'online'.($activeAddress ? " ({$activeAddress})" : '') : 'offline/tidak aktif',
                        'last_seen' => $activeUptime ? "active uptime {$activeUptime}" : $this->pppoeSnapshotSeen($customer),
                        'next_action' => 'approve/sync koreksi PSB agar DB customer ikut username live MikroTik.',
                    ];
                }

                return [
                    'secret' => $secret ? 'ada'.($secretProfile ? " ({$secretProfile})" : '') : 'tidak ada di MikroTik',
                    'active' => $active ? 'online'.($activeAddress ? " ({$activeAddress})" : '').($activeName && strcasecmp($activeName, $username) !== 0 ? " user {$activeName}" : '') : 'offline/tidak aktif',
                    'last_seen' => $activeUptime ? "active uptime {$activeUptime}" : $this->pppoeSnapshotSeen($customer),
                    'next_action' => $active
                        ? 'session aktif; gunakan data di atas untuk verifikasi pelanggan.'
                        : 'secret dicek read-only; jika pelanggan komplain offline, cek PPP Active/log router atau buat ticket gangguan.',
                ];
            } catch (\Throwable $e) {
                $msg = Str::limit($e->getMessage(), 90);

                return [
                    'secret' => "timeout/gagal baca live ({$msg})",
                    'active' => 'tidak terkonfirmasi',
                    'last_seen' => $this->pppoeSnapshotSeen($customer),
                    'next_action' => "router {$router->name} timeout/gagal dibaca; pakai snapshot DB dulu lalu minta NOC cek MikroTik jika perlu.",
                ];
            }
        });
    }

    protected function pendingCorrectionUsernameForCustomer(Customer $customer, string $currentUsername): string
    {
        if (! $customer->id) {
            return '';
        }

        $draft = PsbDraft::query()
            ->where('customer_id', $customer->id)
            ->where('status', PsbDraft::STATUS_CORRECTION_PENDING)
            ->orderByDesc('id')
            ->first(['meta']);
        $corr = is_array($draft?->meta) ? ($draft->meta['psb_correction'] ?? []) : [];
        $after = is_array($corr['after'] ?? null) ? $corr['after'] : [];
        $newUser = trim((string) ($after['pppoe_user'] ?? ''));

        return $newUser !== '' && strcasecmp($newUser, $currentUsername) !== 0 ? $newUser : '';
    }

    protected function pppoeSnapshotSeen(Customer $customer): string
    {
        $times = array_filter([
            $customer->mikrotik_sync_checked_at,
            $customer->mikrotik_synced_at,
            $customer->router?->last_health_check_at,
            Schema::hasTable('router_mt_secret_states')
                ? RouterMtSecretState::query()->where('router_id', $customer->router_id)->value('last_checked_at')
                : null,
        ]);

        if ($times === []) {
            return 'belum ada snapshot';
        }

        $latest = collect($times)
            ->map(fn ($time) => $time instanceof \DateTimeInterface ? $time : Carbon::parse($time))
            ->max();

        return $latest ? $latest->diffForHumans() : 'belum ada snapshot';
    }
}
