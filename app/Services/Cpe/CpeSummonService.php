<?php

namespace App\Services\Cpe;

use App\Models\User;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RCA-3: on-demand refresh of one ONU ("Cek sekarang"). Default OFF (cpe_summon_enabled): the flag off means
 * zero HTTP calls.
 *
 * Flow: refreshObject for Hosts.Host and the primary WLANs' AssociatedDevice (both queued WITHOUT connection
 * request), then getParameterValues with the fixed leaf list WITH ?connection_request&timeout=. GenieACS runs
 * all pending tasks of that device in the one session. 200 = the ONU answered (re-read and return fresh values),
 * 202 / timeout = queued (the values arrive within about 5 minutes, at the next periodic inform).
 *
 * Limits: per-device cooldown, per-user and global per hour, N concurrent (Cache lock slots). Never runs for a
 * customer with a PSB draft / migration changed in the last 30 minutes. Never waits on a lock.
 *
 * Returned shape: ['status' => fresh|queued|skipped|error, 'reason' => code, 'message' => short Indonesian,
 *                  'data' => ?array, 'device_id' => ?string]
 */
class CpeSummonService
{
    public function __construct(
        private ?CpeNbi $nbi = null,
        private ?CpeModelMap $map = null,
        private ?CpeSampleWriter $writer = null,
    ) {
        $this->nbi ??= new CpeNbi;
        $this->map ??= new CpeModelMap;
        $this->writer ??= new CpeSampleWriter(null, $this->map);
    }

    /**
     * @param  int|string  $device  genieacs_devices.id, device_id string, or null customer lookup via summonCustomer()
     * @return array{status: string, reason: string, message: string, data: ?array, device_id: ?string}
     */
    public function summon(int|string $device, string $reason, ?User $user = null): array
    {
        if (! OutageSettings::cpeSummonEnabled() || ! OutageSettings::cpeLayerEnabled()) {
            return $this->out('skipped', 'off', 'Cek ONU belum diaktifkan.');
        }

        $row = is_int($device)
            ? DB::table('genieacs_devices')->where('id', $device)->first(['device_id', 'customer_id', 'pppoe_user', 'product_class'])
            : DB::table('genieacs_devices')->where('device_id', $device)->first(['device_id', 'customer_id', 'pppoe_user', 'product_class']);
        if ($row === null) {
            return $this->out('skipped', 'no_device', 'ONU pelanggan belum ada di GenieACS.');
        }
        $deviceId = (string) $row->device_id;

        return $this->run($row, $deviceId, $reason, $user);
    }

    /** Summon the newest-informing ONU of a customer. */
    public function summonCustomer(int $customerId, string $reason, ?User $user = null): array
    {
        if (! OutageSettings::cpeSummonEnabled() || ! OutageSettings::cpeLayerEnabled()) {
            return $this->out('skipped', 'off', 'Cek ONU belum diaktifkan.');
        }
        $row = DB::table('genieacs_devices')->where('customer_id', $customerId)->orderByDesc('last_inform_at')
            ->first(['device_id', 'customer_id', 'pppoe_user', 'product_class']);
        if ($row === null) {
            return $this->out('skipped', 'no_device', 'ONU pelanggan belum ada di GenieACS.');
        }

        return $this->run($row, (string) $row->device_id, $reason, $user);
    }

    private function run(object $row, string $deviceId, string $reason, ?User $user): array
    {
        if ($this->nbi->baseUrl() === '') {
            return $this->out('skipped', 'no_acs', 'GenieACS belum diatur.', $deviceId);
        }

        $busy = CpeGuards::busyReason($row->customer_id !== null ? (int) $row->customer_id : null, $row->pppoe_user);
        if ($busy !== null) {
            return $this->out('skipped', 'busy_'.$busy, 'Dilewati: pemasangan atau migrasi sedang jalan.', $deviceId);
        }

        $entry = $this->map->forProductClass($row->product_class);
        if (! ($entry['supported'] ?? false)) {
            return $this->out('skipped', 'unsupported', 'Tipe ONU ini belum didukung.', $deviceId);
        }

        // --- limits (checked first, counted only when a request really goes out) ---
        $cooldownKey = 'cpe-summon:dev:'.$deviceId;
        $userKey = 'cpe-summon:user:'.($user?->id ?? 0);
        $globalKey = 'cpe-summon:global';
        if (RateLimiter::tooManyAttempts($userKey, OutageSettings::cpeSummonPerUserHour())) {
            return $this->out('skipped', 'user_limit', 'Batas cek per jam sudah habis.', $deviceId);
        }
        if (RateLimiter::tooManyAttempts($globalKey, OutageSettings::cpeSummonGlobalHour())) {
            return $this->out('skipped', 'global_limit', 'Batas cek per jam sudah habis.', $deviceId);
        }

        $timeoutMs = OutageSettings::cpeSummonTimeoutMs();
        $slot = null;
        for ($n = 1; $n <= OutageSettings::cpeSummonConcurrent(); $n++) {
            $lock = Cache::lock('cpe-summon:slot:'.$n, (int) ceil($timeoutMs / 1000) + 12);
            if ($lock->get()) { // never block
                $slot = $lock;
                break;
            }
        }
        if ($slot === null) {
            return $this->out('skipped', 'busy', 'Sedang ada cek lain, coba sebentar lagi.', $deviceId);
        }

        // atomic cooldown: of two parallel summons of one device only one gets the key
        if (! Cache::add($cooldownKey, 1, now()->addMinutes(OutageSettings::cpeSummonDeviceCooldownMinutes()))) {
            $slot->release();

            return $this->out('skipped', 'cooldown', 'Tunggu '.OutageSettings::cpeSummonDeviceCooldownMinutes().' menit sebelum cek lagi.', $deviceId);
        }

        try {
            RateLimiter::hit($userKey, 3600);
            RateLimiter::hit($globalKey, 3600);
            DB::table('genieacs_devices')->where('device_id', $deviceId)->update(['cpe_summoned_at' => Carbon::now()->utc()->format('Y-m-d H:i:s')]);

            // 1. object refreshes, queued without a connection request (the GPV below opens the one session)
            $withClients = (bool) ($entry['assoc_refresh'] ?? false);
            try {
                $this->nbi->postTask($deviceId, ['name' => 'refreshObject', 'objectName' => $this->map->abs($entry, $entry['paths']['hosts'])], false);
                if ($withClients) {
                    foreach ($this->map->primaries($entry) as $instance) {
                        if ($instance !== null) {
                            $this->nbi->postTask($deviceId, [
                                'name' => 'refreshObject',
                                'objectName' => $this->map->abs($entry, str_replace('{i}', (string) $instance, $entry['paths']['wlan']).'.'.$entry['paths']['assoc_device']),
                            ], false);
                        }
                    }
                }
            } catch (\Throwable) {
                // the fixed-leaf read below is the one that matters
            }

            // 2. the fixed leaf read WITH a connection request
            $posted = $this->nbi->postTask($deviceId, [
                'name' => 'getParameterValues',
                'parameterNames' => $this->map->fixedLeaves($entry),
            ], true, $timeoutMs);

            if ($posted['status'] === 200) {
                $doc = $this->nbi->fetchDocs([$deviceId], $this->writer->projection(true))[$deviceId] ?? null;
                $applied = $doc !== null ? $this->writer->apply($doc, 'summon', true) : null;
                if ($applied !== null) {
                    return $this->out('fresh', 'ok', 'ONU menjawab. Data sudah baru.', $deviceId, $this->summary($applied));
                }

                return $this->out('queued', 'no_doc', 'ONU belum menjawab, hasil masuk paling lama 5 menit.', $deviceId);
            }
            if ($posted['status'] === 202) {
                return $this->out('queued', 'accepted', 'ONU belum menjawab, hasil masuk paling lama 5 menit.', $deviceId);
            }

            return $this->out('error', 'acs_'.$posted['status'], 'GenieACS tidak bisa dihubungi.', $deviceId);
        } catch (\Throwable $e) {
            try {
                Log::warning('[CPE] summon failed', ['exception_class' => $e::class, 'reason' => $reason]);
            } catch (\Throwable) {
            }

            // a timeout leaves the task queued in GenieACS: the answer arrives at the next inform
            return $this->out('queued', 'timeout', 'ONU belum menjawab, hasil masuk paling lama 5 menit.', $deviceId);
        } finally {
            try {
                $slot->release();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * @param  array<string,mixed>  $applied
     * @return array<string,mixed>
     */
    private function summary(array $applied): array
    {
        $c = $applied['columns'];

        return [
            'at' => $applied['at'],
            'wlan24' => ['status' => $c['wlan24_status'], 'enabled' => $c['wlan24_enabled'], 'assoc' => $c['wlan24_assoc']],
            'wlan5' => ['status' => $c['wlan5_status'], 'enabled' => $c['wlan5_enabled'], 'assoc' => $c['wlan5_assoc']],
            'ppp' => ['status' => $c['ppp_status'], 'error' => $c['ppp_last_error']],
            'rssi' => $applied['rssi'],
            'lan_active' => $applied['lan_active'],
        ];
    }

    /** @return array{status: string, reason: string, message: string, data: ?array, device_id: ?string} */
    private function out(string $status, string $reason, string $message, ?string $deviceId = null, ?array $data = null): array
    {
        return ['status' => $status, 'reason' => $reason, 'message' => $message, 'data' => $data, 'device_id' => $deviceId];
    }
}
