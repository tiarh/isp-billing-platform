<?php

namespace App\Services\Outage\Diagnosis;

use App\Exceptions\DiagnoseLimitException;
use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\Router;
use App\Models\User;
use App\Services\MikrotikService;
use App\Services\Outage\OltCauseEnricher;
use App\Services\Outage\OutageDetector;
use App\Services\Outage\OutageSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * RCA-4 "Cek sekarang": a staged, read-only live check of ONE customer. The browser calls start, then the three
 * probes (router / olt / cpe) in parallel, then finish. Everything runs in the WEB request: nothing is dispatched
 * onto the psb / migration / wa queues, and no probe ever waits for a lock.
 *
 *  - router: read-only `/ppp/active/print` + `/ppp/secret/print` (no writes), per-router mutex, never waits.
 *  - olt: OltCauseEnricher in MANUAL mode (same PSB / migration / delete / Huawei-session lock probes, own budget).
 *    A busy OLT answers "dilewati" at once.
 *  - cpe: the RCA-3 CpeSummonService when it exists (interface assumption, see probeCpe), otherwise "belum aktif".
 *
 * A probe that is skipped, failed or timed out keeps a visible reason; classification uses what came back.
 */
class DiagnoseNowService
{
    public const PROBES = ['router', 'olt', 'cpe'];

    private const SLOT_TTL_SECONDS = 60;

    /** The check must be finished within this many minutes of its start. */
    public const CHECK_TTL_MINUTES = 3;

    public function __construct(
        private readonly OltCauseEnricher $enricher,
        private readonly OutageDiagnoser $diagnoser,
    ) {}

    // ------------------------------------------------------------------ start / limits

    /**
     * @throws DiagnoseLimitException
     */
    public function start(User $user, Customer $customer, ?int $ticketId = null): OutageDiagnosis
    {
        if (! OutageSettings::diagnosisCheckNowEnabled()) {
            throw new DiagnoseLimitException('Cek sekarang belum diaktifkan.', 403);
        }
        if (config('diagnosis.probe_driver') === 'fake' && app()->isProduction()) {
            throw new DiagnoseLimitException('Cek sekarang tidak tersedia.', 403);
        }

        // 1. same customer: wait out the cool-down (checked first so CS sees how long to wait)
        // (database based, so it holds whatever the cache store is)
        $cool = OutageSettings::diagnoseNowCustomerCooldownMinutes();
        $lastCheck = OutageDiagnosis::query()->where('customer_id', $customer->id)->where('trigger', OutageDiagnosis::TRIGGER_MANUAL)
            ->where('created_at', '>', now()->subMinutes($cool))->orderByDesc('id')->first(['id', 'created_at']);
        if ($lastCheck !== null) {
            $remaining = max(1, $lastCheck->created_at->copy()->addMinutes($cool)->getTimestamp() - now()->getTimestamp());
            throw new DiagnoseLimitException('Tunggu '.max(1, (int) ceil($remaining / 60)).' menit sebelum cek lagi.', 429, $remaining);
        }
        // 2. per user per hour
        $userKey = 'diagnose-now:user:'.$user->id;
        if (RateLimiter::tooManyAttempts($userKey, OutageSettings::diagnoseNowPerUserHour())) {
            throw new DiagnoseLimitException('Terlalu banyak cek dalam satu jam. Coba lagi nanti.', 429, RateLimiter::availableIn($userKey));
        }
        // 3. global concurrency (each check keeps up to three web workers busy)
        $slot = $this->takeSlot();
        if ($slot === null) {
            throw new DiagnoseLimitException('Sedang ada cek lain. Coba lagi sebentar.', 429, 15);
        }

        RateLimiter::hit($userKey, 3600);

        $check = OutageDiagnosis::query()->create([
            'customer_id' => (int) $customer->id,
            'ticket_id' => $ticketId,
            'created_by' => (int) $user->id,
            'trigger' => OutageDiagnosis::TRIGGER_MANUAL,
            'state' => OutageDiagnosis::STATE_RUNNING,
            'probes' => [],
        ]);
        Cache::put('diagnose-now:slot:'.$slot, (int) $check->id, now()->addSeconds(self::SLOT_TTL_SECONDS));
        Cache::put('diagnose-now:check-slot:'.$check->id, $slot, now()->addSeconds(self::SLOT_TTL_SECONDS));

        return $check;
    }

    private function takeSlot(): ?int
    {
        for ($i = 1; $i <= OutageSettings::diagnoseNowConcurrent(); $i++) {
            if (Cache::add('diagnose-now:slot:'.$i, 0, now()->addSeconds(self::SLOT_TTL_SECONDS))) {
                return $i;
            }
        }

        return null;
    }

    private function releaseSlot(OutageDiagnosis $check): void
    {
        $slot = Cache::pull('diagnose-now:check-slot:'.$check->id);
        if ($slot !== null) {
            Cache::forget('diagnose-now:slot:'.$slot);
        }
    }

    // ------------------------------------------------------------------ probes

    /**
     * Runs one probe and stores its answer on the check. Never throws.
     *
     * @return array<string,mixed> the probe answer ({status, text, ...})
     */
    public function probe(OutageDiagnosis $check, string $name, ?User $user = null): array
    {
        if (! in_array($name, self::PROBES, true)) {
            return ['status' => 'failed', 'text' => 'Cek tidak dikenal.'];
        }
        // one answer per probe per check: a second call (or a call after finish) never reaches a device again
        $current = OutageDiagnosis::query()->whereKey($check->id)->first(['id', 'state', 'probes']);
        if ($current === null || $current->state !== OutageDiagnosis::STATE_RUNNING) {
            return ['status' => 'skipped', 'reason' => 'finished', 'text' => 'Cek sudah selesai.'];
        }
        if (isset(((array) $current->probes)[$name]) || ! Cache::add('diagnose-now:probe:'.$check->id.':'.$name, 1, now()->addMinutes(5))) {
            return (array) (((array) $current->probes)[$name] ?? ['status' => 'skipped', 'reason' => 'repeat', 'text' => 'Sudah dicek.']);
        }
        if (PHP_SAPI !== 'cli') {
            @set_time_limit(25); // a web probe is bounded; never in the CLI (it would cap the whole test run)
        }
        $customer = Customer::query()->find($check->customer_id);
        try {
            if ($customer === null) {
                $result = ['status' => 'skipped', 'reason' => 'no_customer', 'text' => 'dilewati: pelanggan tidak ditemukan'];
            } elseif (config('diagnosis.probe_driver') === 'fake') {
                $result = app()->isProduction() ? ['status' => 'skipped', 'reason' => 'fake_refused', 'text' => 'dilewati'] : $this->fakeProbe($name);
            } else {
                $result = match ($name) {
                    'router' => $this->probeRouter($customer),
                    'olt' => $this->probeOlt($customer),
                    default => $this->probeCpe($customer, $user),
                };
            }
        } catch (\Throwable $e) {
            $this->warn('probe '.$name.' failed', $e);
            $result = ['status' => 'failed', 'reason' => 'error', 'text' => 'gagal dicek'];
        }
        $result['at'] = now()->utc()->toIso8601String();

        // three probes arrive in parallel: merge under a row lock so none is lost
        DB::transaction(function () use ($check, $name, $result): void {
            $row = OutageDiagnosis::query()->whereKey($check->id)->lockForUpdate()->first();
            // a probe that lands after finish / failure is dropped (raw device answers are not kept on a closed check)
            if ($row !== null && $row->state === OutageDiagnosis::STATE_RUNNING) {
                $probes = (array) $row->probes;
                $probes[$name] = $result;
                $row->probes = $probes;
                $row->save();
            }
        });

        return $result;
    }

    /** @return array<string,mixed> */
    private function probeRouter(Customer $customer): array
    {
        if ($customer->router_id === null || trim((string) $customer->pppoe_user) === '') {
            return ['status' => 'skipped', 'reason' => 'no_router', 'text' => 'dilewati: pelanggan belum punya router'];
        }
        $router = Router::query()->find($customer->router_id);
        if ($router === null) {
            return ['status' => 'skipped', 'reason' => 'no_router', 'text' => 'dilewati: router tidak ditemukan'];
        }
        // one diagnosis read per router at a time, never waits
        $lock = Cache::lock('diag-router:'.$router->id, 15);
        if (! $lock->get()) {
            return ['status' => 'skipped', 'reason' => 'busy', 'text' => 'dilewati: router sedang dicek'];
        }
        $svc = app(MikrotikService::class);
        try {
            $started = microtime(true);
            $svc->connect($router, ['timeout' => 3, 'attempts' => 1]);
            $user = (string) $customer->pppoe_user;
            $session = $svc->activeSessionFor($user);   // read only: /ppp/active/print ?name=
            $secret = (microtime(true) - $started) < 4 ? $svc->secretStateFor($user) : null; // read only: /ppp/secret/print ?name=

            // only what the rules need: no caller MAC / IP is kept
            return ['status' => 'ok', 'text' => 'selesai', 'active' => $session === null ? null : array_intersect_key($session, ['uptime' => 1]), 'secret' => $secret];
        } catch (\Throwable $e) {
            $this->warn('router probe failed', $e);

            return ['status' => 'failed', 'reason' => 'unreachable', 'text' => 'router tidak terjangkau'];
        } finally {
            try {
                $svc->disconnect();
            } catch (\Throwable) {
            }
            try {
                $lock->release();
            } catch (\Throwable) {
            }
        }
    }

    /** @return array<string,mixed> */
    private function probeOlt(Customer $customer): array
    {
        $ref = $customer->olt_port_label !== null ? OutageDetector::normalizeRef((string) $customer->olt_port_label) : null;
        if ($customer->olt_id === null || $ref === null) {
            return ['status' => 'skipped', 'reason' => 'no_olt', 'text' => 'dilewati: pelanggan belum punya data OLT'];
        }
        $started = microtime(true);
        $r = $this->enricher->enrich((int) $customer->olt_id, $ref, (int) $customer->id, null, now(), OltCauseEnricher::MODE_MANUAL);
        $status = (string) $r['status'];
        if ($status === OltCauseEnricher::STATUS_OK) {
            return ['status' => 'ok', 'text' => 'selesai', 'detail' => $r['detail'] ?? [], 'ms' => (int) round((microtime(true) - $started) * 1000)];
        }
        [$state, $text] = match ($status) {
            OltCauseEnricher::STATUS_LOCKED => ['skipped', 'dilewati: OLT sedang dipakai pasang baru'],
            OltCauseEnricher::STATUS_BUSY => ['skipped', 'dilewati: OLT sedang dipakai'],
            OltCauseEnricher::STATUS_RATE_LIMITED => ['skipped', 'dilewati: batas cek OLT per jam sudah habis'],
            OltCauseEnricher::STATUS_CACHED => ['skipped', 'dilewati: baru dicek kurang dari 2 menit lalu'],
            OltCauseEnricher::STATUS_DISABLED => ['skipped', 'dilewati: cek OLT dimatikan'],
            OltCauseEnricher::STATUS_UNSUPPORTED => ['skipped', 'dilewati: OLT ini belum didukung'],
            default => ['failed', 'OLT tidak terjangkau'],
        };

        return ['status' => $state, 'reason' => $status, 'text' => $text];
    }

    /** Skip reasons of CpeSummonService => the short line CS reads. Anything else keeps the service's own message. */
    private const CPE_SKIP_TEXT = [
        'off' => 'dilewati: cek ONU belum diaktifkan',
        'no_device' => 'dilewati: ONU pelanggan belum terdaftar di ACS',
        'no_acs' => 'dilewati: ACS belum diatur',
        'busy_psb' => 'dilewati: pelanggan sedang dipasang atau dimigrasi',
        'busy_migration' => 'dilewati: pelanggan sedang dipasang atau dimigrasi',
        'unsupported' => 'dilewati: tipe ONU ini belum didukung',
        'cooldown' => 'dilewati: ONU baru dicek, tunggu sebentar',
        'user_limit' => 'dilewati: batas cek ONU per jam sudah habis',
        'global_limit' => 'dilewati: batas cek ONU per jam sudah habis',
        'busy' => 'dilewati: sedang ada cek ONU lain',
    ];

    /**
     * CPE probe: the RCA-3 `App\Services\Cpe\CpeSummonService::summonCustomer(int $customerId, string $reason,
     * ?User $user)` (never blocks; it keeps away from a customer with an open PSB draft / migration by itself). It
     * answers `['status' => fresh|queued|skipped|error, 'reason', 'message', 'data']`. A fresh answer also updates
     * genieacs_devices, which the correlator reads. Without that class (RCA-3 not merged yet) the probe is skipped
     * with a visible reason and RCA-4 keeps working on the stored data.
     *
     * @return array<string,mixed>
     */
    private function probeCpe(Customer $customer, ?User $user): array
    {
        $class = 'App\\Services\\Cpe\\CpeSummonService';
        if (! class_exists($class)) {
            return ['status' => 'skipped', 'reason' => 'not_available', 'text' => 'dilewati: cek ONU belum aktif'];
        }
        $answer = (array) app($class)->summonCustomer((int) $customer->id, 'diagnosis', $user);
        $status = strtolower((string) ($answer['status'] ?? ''));
        $reason = (string) ($answer['reason'] ?? '');

        return match ($status) {
            'fresh' => ['status' => 'ok', 'text' => 'selesai', 'data' => $this->cpeData(is_array($answer['data'] ?? null) ? $answer['data'] : [])],
            'queued' => ['status' => 'queued', 'text' => 'ONU belum menjawab, hasil masuk paling lama 5 menit'],
            'skipped' => ['status' => 'skipped', 'reason' => $reason, 'text' => self::CPE_SKIP_TEXT[$reason] ?? 'dilewati: '.lcfirst(rtrim((string) ($answer['message'] ?? 'cek ONU dibatasi'), '. '))],
            default => ['status' => 'failed', 'reason' => $reason, 'text' => 'ONU tidak terjangkau'],
        };
    }

    /**
     * RCA-3 summon data ({at, wlan24{status,enabled,assoc}, wlan5{...}, ppp{status,error}, rssi[], lan_active}) in the
     * shape the correlator merges.
     *
     * @param  array<string,mixed>  $d
     * @return array<string,mixed>
     */
    private function cpeData(array $d): array
    {
        $out = ['source' => 'summon', 'wlan' => []];
        foreach (['24' => 'wlan24', '5' => 'wlan5'] as $band => $key) {
            if (is_array($d[$key] ?? null)) {
                $out['wlan'][$band] = $d[$key];
            }
        }
        if ($out['wlan'] === []) {
            unset($out['wlan']);
        }
        if (is_array($d['ppp'] ?? null)) {
            $out['ppp_status'] = $d['ppp']['status'] ?? null;
            $out['ppp_last_error'] = $d['ppp']['error'] ?? null;
        }
        foreach (['rssi', 'lan_active'] as $key) {
            if (array_key_exists($key, $d)) {
                $out[$key] = $d[$key];
            }
        }

        return $out;
    }

    /**
     * Canned answers of the `fake` driver (sandbox browser E2E only; refused in production).
     *
     * @return array<string,mixed>
     */
    private function fakeProbe(string $name): array
    {
        usleep(max(0, (int) config('diagnosis.fake_delay_ms', 900)) * 1000);

        return match ($name) {
            'router' => ['status' => 'ok', 'text' => 'selesai', 'active' => ['name' => 'fake', 'uptime' => '2h10m', 'address' => '192.0.2.10'], 'secret' => ['disabled' => 'false', 'profile' => 'default']],
            'olt' => ['status' => 'skipped', 'reason' => OltCauseEnricher::STATUS_LOCKED, 'text' => 'dilewati: OLT sedang dipakai pasang baru'],
            default => ['status' => 'ok', 'text' => 'selesai', 'data' => [
                'present' => true,
                'wlan' => ['24' => ['status' => 'Up', 'enabled' => true, 'assoc' => 2], '5' => ['status' => 'Disabled', 'enabled' => false, 'assoc' => 0]],
                'uptime_s' => 400000,
                'source' => 'summon',
            ]],
        };
    }

    // ------------------------------------------------------------------ finish

    /**
     * Classify with whatever the probes returned and complete the check. Never waits for a probe.
     */
    public function finish(OutageDiagnosis $check): OutageDiagnosis
    {
        $customer = Customer::query()->findOrFail($check->customer_id);
        $check->refresh();
        $probes = (array) $check->probes;
        // a probe that never answered is shown as such, never silently dropped
        foreach (self::PROBES as $name) {
            $probes[$name] ??= ['status' => 'failed', 'reason' => 'no_answer', 'text' => 'tidak menjawab'];
        }
        try {
            $diagnosis = $this->diagnoser->diagnose($customer, OutageDiagnosis::TRIGGER_MANUAL, [
                'diagnosis' => $check,
                'probes' => $probes,
                'ticket_id' => $check->ticket_id,
                'created_by' => $check->created_by,
                'started_at' => $check->created_at?->getTimestamp() ?? microtime(true),
            ]);
        } catch (\Throwable $e) {
            $this->warn('finish failed', $e);
            $check->state = OutageDiagnosis::STATE_FAILED;
            $check->probes = OutageDiagnoser::probeSummary($probes); // no raw device answers on a failed check
            $check->save();
            $diagnosis = $check;
        } finally {
            $this->releaseSlot($check);
        }

        return $diagnosis;
    }

    private function warn(string $what, \Throwable $e): void
    {
        try {
            Log::warning('[Diagnose] '.$what, ['exception_class' => $e::class]);
        } catch (\Throwable) {
        }
    }

    public static function stale(OutageDiagnosis $check, ?Carbon $now = null): bool
    {
        return $check->created_at === null || $check->created_at->lt(($now ?? now())->copy()->subMinutes(self::CHECK_TTL_MINUTES));
    }
}
