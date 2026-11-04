<?php

namespace App\Services\Psb;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Enums\CustomerStatus;
use App\Exceptions\PsbLockBusyException;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Services\DueDayResolver;
use App\Services\MikrotikService;
use App\Services\PsbDraftCustomerSyncService;
use App\Services\RadiusUserService;
use App\Support\CacheLockWait;
use App\Support\OpsTime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * PSB MikroTik secret + draft customer step (moved verbatim from PsbOrchestratorService).
 * It uses the facade's own MikroTik instance and IP allocator, and runs under the router
 * lock exactly as before. Built by the facade, never by the container, no statics.
 */
final class PsbSecretProvisioner
{
    /**
     * @param  \Closure(): PsbPppoePasswordPolicy  $passwordPolicy  resolves the facade's password policy on first use
     */
    public function __construct(
        private readonly MikrotikService $mikrotik,
        private readonly RadiusUserService $radius,
        private readonly PsbPppoeIpAllocator $ip,
        private readonly PsbPppoeNaming $naming,
        private readonly \Closure $passwordPolicy,
    ) {}

    public function isIpPoolError(string $msg): bool
    {
        return (bool) preg_match('/IP_POOL_FULL|pool\s*penuh|no free|tidak ada ip|ip free|address pool|out of addresses/i', $msg);
    }

    /**
     * @return bool true if secret already existed on MikroTik (P5 idempotent)
     */
    public function createSecretAndDraftCustomer(PsbDraft $draft, Router $router, Package $package, ?Olt $olt): bool
    {
        // Idempotent: jangan alokasi IP baru / rewrite secret kalau sudah sukses
        if ($draft->secret_at && $draft->pppoe_user) {
            try {
                $this->mikrotik->connect($router, ['timeout' => 12, 'attempts' => 1]);
                try {
                    $liveSecret = $this->mikrotik->getPPPSecret((string) $draft->pppoe_user);
                    $livePassword = trim((string) ($liveSecret['password'] ?? ''));
                    if ($liveSecret && $livePassword !== '' && preg_match('/^\*+$/', $livePassword) !== 1) {
                        Log::info('[PSB] secret skip - verified live', ['draft' => $draft->id, 'user' => $draft->pppoe_user]);

                        return true;
                    }
                } finally {
                    $this->mikrotik->disconnect();
                }
            } catch (\Throwable $e) {
                throw new \RuntimeException('PPPoE belum bisa diverifikasi live di MikroTik: '.$e->getMessage());
            }

            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['mt_missing_after_db_success'] = now()->toIso8601String();
            $draft->secret_at = null;
            $draft->last_error = 'Secret PPPoE hilang di MikroTik, mencoba recreate.';
            $draft->meta = $meta;
            $draft->save();
            Log::warning('[PSB] secret missing - recreating', ['draft' => $draft->id, 'user' => $draft->pppoe_user]);
            Log::info('[PSB] secret skip — already set', ['draft' => $draft->id, 'user' => $draft->pppoe_user]);

        }

        $lock = Cache::lock('psb-ip-router-'.$router->id, 90);
        if (! CacheLockWait::acquire($lock, 15)) {
            throw new PsbLockBusyException("Antrian IP/secret {$router->name} sibuk, coba #psb lanjut sebentar lagi.");
        }

        $existed = false;
        try {
            $rt = preg_replace('/\D/', '', (string) $draft->rt);
            $rw = preg_replace('/\D/', '', (string) $draft->rw);
            $pppoeUser = $this->naming->buildPppoeUsername($draft);
            if ($pppoeUser === '' || $rt === '' || $rw === '') {
                throw new \RuntimeException('Nama/RT/RW tidak valid untuk generate PPPoE user.');
            }
            $password = ($this->passwordPolicy)()
                ->passwordForDraft($draft);

            $profile = trim((string) $package->mikrotik_profile);
            if ($profile === '') {
                throw new \RuntimeException('Paket belum memiliki MikroTik Profile; provisioning dihentikan.');
            }

            // Customer existing: jangan rusak pelanggan active orang lain
            $existing = Customer::query()->where('pppoe_user', $pppoeUser)->first();
            if ($existing && in_array((string) $existing->status, CustomerStatus::provisionedLive(), true)) {
                // Reuse only if this draft already linked, else hard stop (anti junk overwrite)
                if ($draft->customer_id && (int) $draft->customer_id === (int) $existing->id) {
                    // own customer — reuse credentials path
                } else {
                    throw new \RuntimeException(
                        "PPPoE user `{$pppoeUser}` sudah dipakai pelanggan #{$existing->id} (status {$existing->status}). ".
                        'Jangan overwrite — ganti nama/ODP atau hubungi NOC.'
                    );
                }
            }

            // Comment pada secret berisi tanggal saja, e.g. "19Jul2026" (dMY, Asia/Jakarta)
            $secretComment = now('Asia/Jakarta')->format('dMY');

            // ── Satu sesi MikroTik: alokasi IP + write secret (lebih cepat, aman) ──
            $timeout = ((int) ($router->total_pppoe_count ?? 0) >= 200) ? 28 : 20;
            $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
            try {
                $ip = $this->ip->suggestLiveIp($router, true, $draft, $profile);
                if (empty($ip['remote']) || ! $this->ip->isPppoeStaticAddressValid((string) $ip['remote'])) {
                    throw new \RuntimeException("IP_POOL_FULL: Tidak ada IP remote free di MikroTik {$router->name} (pool penuh / tidak terbaca).");
                }
                if (! empty($ip['local']) && ! $this->ip->isPppoeStaticAddressValid((string) $ip['local'])) {
                    throw new \RuntimeException("IP local invalid dari {$router->name}.");
                }

                $mtResult = $this->mikrotik->addPPPSecret(
                    $pppoeUser,
                    $password,
                    $profile,
                    $ip['local'],
                    $ip['remote'],
                    'pppoe',
                    $secretComment
                );
                $verifiedSecret = ($mtResult['success'] ?? false)
                    ? $this->mikrotik->getPPPSecret($pppoeUser)
                    : null;
                $verifiedPassword = trim((string) ($verifiedSecret['password'] ?? ''));
                if (($mtResult['success'] ?? false) && ($verifiedSecret === null || $verifiedPassword === '' || preg_match('/^\*+$/', $verifiedPassword) === 1 || ! hash_equals(hash('sha256', $password), hash('sha256', $verifiedPassword)))) {
                    $mtResult = [
                        'success' => false,
                        'message' => 'Secret PPPoE/password belum terverifikasi live setelah write.',
                        'code' => 'verify_failed',
                    ];
                } elseif ($verifiedSecret !== null) {
                    $password = $verifiedPassword;
                }
            } finally {
                $this->mikrotik->disconnect();
            }

            if (! ($mtResult['success'] ?? false)) {
                $err = (string) ($mtResult['message'] ?? 'addPPPSecret gagal');
                if ($this->isIpPoolError($err)) {
                    $this->ip->releaseAllocatedIpIfAny($router, $ip);
                    throw new \RuntimeException('IP_POOL_FULL: '.$err);
                }
                if (preg_match('/already|exist|duplicate/i', $err)) {
                    $existed = true;
                } else {
                    // A1: write MT gagal — kembalikan pointer allocator supaya IP tidak bocor
                    $this->ip->releaseAllocatedIpIfAny($router, $ip);
                    throw new \RuntimeException($err);
                }
            } else {
                $existed = ! empty($mtResult['existed']) || (($mtResult['code'] ?? '') === 'updated');
            }

            // Tandai IP terpakai di cache (PSB berikutnya tidak scan ulang full list)
            $this->ip->markRemoteIpUsed($router, (string) $ip['remote'], (string) ($ip['local'] ?? ''));

            // DB customer SETELAH MT sukses — anti orphan customer tanpa secret
            $customerData = [
                'name' => $draft->full_name,
                'address' => trim('RT '.$rt.' / RW '.$rw.' - ODP '.$draft->odp.' - '.($draft->daerah ?? '')),
                'phone' => $draft->phone,
                'nik' => $draft->nik,
                'pppoe_user' => $pppoeUser,
                'pppoe_password' => $password,
                'pppoe_local_address' => $ip['local'],
                'pppoe_remote_address' => $ip['remote'],
                'package_id' => $package->id,
                'area_id' => $draft->area_id,
                'router_id' => $router->id,
                'olt_id' => $olt?->id,
                'olt_port_label' => $draft->pon_port,
                'status' => CustomerStatus::PendingInstallation->value,
                // WIB date (app timezone is UTC): PSB at 00:30 WIB is "today" WIB.
                'join_date' => OpsTime::today(),
                'mikrotik_profile' => $profile,
                // Fixed day (default 25) for RA-OLT router / STARLITE-AKUISISI
                // area per owner 2026-10-09; otherwise the join day (WIB).
                'due_day' => app(DueDayResolver::class)->resolve(
                    (int) $router->id,
                    $draft->area_id ? (int) $draft->area_id : null,
                    OpsTime::now(),
                ),
                'mikrotik_sync_status' => 'synced',
                'mikrotik_synced_at' => now(),
            ];
            // KTP vs foto SN: jangan sampai foto stiker ONU masuk kolom KTP pelanggan
            $ktpForCustomer = $this->draftKtpPathForCustomer($draft);
            $snPhotoForCustomer = $this->draftSnPhotoPathForCustomer($draft);
            if (Schema::hasColumn('customers', 'ktp_photo_url') && $ktpForCustomer) {
                $customerData['ktp_photo_url'] = $ktpForCustomer;
            }
            if (Schema::hasColumn('customers', 'odp_photo_url') && $snPhotoForCustomer) {
                // reuse odp_photo_url slot for foto SN/ONU dari PSB WA
                $customerData['odp_photo_url'] = $snPhotoForCustomer;
            }
            if (Schema::hasColumn('customers', 'onu_serial') && $draft->sn) {
                $customerData['onu_serial'] = strtoupper(preg_replace('/\s+/', '', (string) $draft->sn) ?? (string) $draft->sn);
            }
            if (Schema::hasColumn('customers', 'odp_name')) {
                $customerData['odp_name'] = $draft->odp;
            }
            if ($draft->tikor && preg_match('/(-?\d+\.?\d*)\s*,\s*(-?\d+\.?\d*)/', $draft->tikor, $tm)) {
                if (Schema::hasColumn('customers', 'geo_lat')) {
                    $customerData['geo_lat'] = $tm[1];
                    $customerData['geo_long'] = $tm[2];
                }
            }

            if ($existing) {
                $customer = $existing;
                $update = array_filter([
                    'pppoe_password' => $password,
                    'pppoe_local_address' => $ip['local'],
                    'pppoe_remote_address' => $ip['remote'],
                    'router_id' => $router->id,
                    'package_id' => $package->id,
                    'mikrotik_sync_status' => 'synced',
                    'mikrotik_synced_at' => now(),
                    'mikrotik_profile' => $profile,
                    'onu_serial' => $customerData['onu_serial'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                // Hanya isi foto KTP jika customer belum punya ATAU yang tersimpan salah (path sn_/router_)
                if ($ktpForCustomer && Schema::hasColumn('customers', 'ktp_photo_url')) {
                    $cur = (string) ($customer->ktp_photo_url ?? '');
                    if ($cur === '' || preg_match('#/(sn_|router_)#i', $cur)) {
                        $update['ktp_photo_url'] = $ktpForCustomer;
                    }
                }
                if ($snPhotoForCustomer && Schema::hasColumn('customers', 'odp_photo_url')) {
                    $curO = (string) ($customer->odp_photo_url ?? '');
                    if ($curO === '' || preg_match('#/ktp_#i', $curO)) {
                        $update['odp_photo_url'] = $snPhotoForCustomer;
                    }
                }
                if (! in_array((string) $customer->status, CustomerStatus::provisionedLive(), true)) {
                    $update['status'] = CustomerStatus::PendingInstallation->value;
                }
                if ($draft->area_id && empty($customer->area_id)) {
                    $update['area_id'] = $draft->area_id;
                }
                $customer->update($update);
            } else {
                $customer = Customer::create($customerData);
            }

            // RADIUS non-blocking untuk latency (gagal ≠ rollback MT)
            try {
                $this->radius->syncCustomer($customer);
            } catch (\Throwable $e) {
                Log::warning('[PSB] RADIUS sync', ['error' => $e->getMessage()]);
            }

            $draft->customer_id = $customer->id;
            $draft->pppoe_user = $pppoeUser;
            $draft->pppoe_local_address = $ip['local'];
            $draft->pppoe_remote_address = $ip['remote'];
            $draft->mikrotik_profile = $profile;
            $draft->status = PsbDraft::STATUS_SECRET_OK;
            $draft->secret_at = now();
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $meta['secret_existed'] = $existed;
            $meta['mt_router_id'] = $router->id;
            $meta['mt_written_at'] = now()->toIso8601String();
            $meta['mt_comment'] = $secretComment;
            $draft->meta = $meta;
            $draft->save();
            app(PsbDraftCustomerSyncService::class)->sync($draft);

            Log::info('[PSB] secret OK', [
                'draft' => $draft->id,
                'user' => $pppoeUser,
                'existed' => $existed,
                'remote' => $ip['remote'],
                'comment' => $secretComment,
            ]);

            return $existed;
        } finally {
            $lock->release();
        }
    }

    /** Path foto aman untuk kolom KTP pelanggan (bukan foto SN). */
    private function draftKtpPathForCustomer(PsbDraft $draft): ?string
    {
        $path = trim((string) ($draft->ktp_photo_path ?? ''));
        if ($path === '') {
            return null;
        }
        // Reject mis-tagged SN photos (filename or meta)
        if (preg_match('#/(sn_|router_)#i', $path)) {
            return null;
        }
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $cls = $meta['photo_class'] ?? null;
        if (is_array($cls) && array_key_exists('ktp_score', $cls) && $cls['ktp_score'] !== null && (int) $cls['ktp_score'] < 25) {
            return null;
        }

        return $path;
    }

    private function draftSnPhotoPathForCustomer(PsbDraft $draft): ?string
    {
        $path = trim((string) ($draft->router_photo_path ?? ''));
        if ($path === '') {
            return null;
        }
        // Never put KTP file into SN/ODP photo slot if mislabeled
        if (preg_match('#/ktp_#i', $path) && ! preg_match('#/(sn_|router_)#i', $path)) {
            // allow only if meta says it was sn pick (edge)
            $meta = is_array($draft->meta) ? $draft->meta : [];
            $cls = $meta['photo_class'] ?? [];
            if (is_array($cls) && ($cls['sn_path'] ?? null) === $path) {
                return $path;
            }

            return null;
        }

        return $path;
    }
}
