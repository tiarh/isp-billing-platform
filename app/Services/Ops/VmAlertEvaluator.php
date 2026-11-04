<?php

namespace App\Services\Ops;

use Carbon\CarbonImmutable;

/**
 * Pure decision logic for `ops:vm-alert` (no I/O, no clock, no config() calls).
 *
 * Three independent types, each ok -> alert -> ok: `steal` (VM CPU steal),
 * `inform` (modems that informed GenieACS drop below normal) and `stale` (the
 * GenieACS snapshot itself stopped updating). State is one plain array kept by
 * VmAlertService; this class only turns (state, now, readings) into a new state
 * plus the texts that still have to be sent.
 *
 * @phpstan-type TypeState array{status: string, pending: bool, suppressed: bool, sent: bool, sent_mode: ?string, recover_pending: bool, last_alert_at: ?int, since: ?int, bad: int, good: int, normal: ?float, value: mixed, recover_value: mixed}
 */
final class VmAlertEvaluator
{
    public const TYPES = ['steal', 'inform', 'stale'];

    private const TZ = 'Asia/Jakarta';

    private const RING_MAX = 8;

    private const SLICE_MIN_SECONDS = 300;

    private const SLICE_MAX_SECONDS = 900;

    private const RING_MAX_AGE_SECONDS = 2700;

    private const RAW_KEEP_SECONDS = 10800;

    private const HOURS_KEEP = 192;

    private const FALLBACK_MIN_SAMPLES = 6;

    private const RUN_GAP_SECONDS = 1800;

    private const OPEN_EVENT_MAX_AGE_SECONDS = 21600;

    /** @var array<string, int|float> */
    private array $cfg;

    /** @param  array<string, mixed>  $cfg */
    public function __construct(array $cfg = [])
    {
        $this->cfg = array_merge(self::defaults(), array_intersect_key($cfg, self::defaults()));
    }

    /** @return array<string, int|float> */
    public static function defaults(): array
    {
        return [
            'steal_threshold_pct' => 30.0,
            'steal_window_minutes' => 30,
            'steal_recover_pct' => 25.0,
            'inform_window_minutes' => 30,
            'inform_drop_pct' => 10.0,
            'inform_recover_pct' => 5.0,
            'inform_confirm_samples' => 2,
            'snapshot_fresh_minutes' => 30,
            'snapshot_stale_alert_minutes' => 60,
            'dedupe_seconds' => 3600,
            'history_days' => 7,
            'min_same_hour_days' => 3,
        ];
    }

    /** @return array<string, mixed> */
    public function newState(int $now): array
    {
        return [
            'v' => 1,
            'started_at' => $now,
            'last_run_at' => $now,
            'inform_snapshot_at' => null,
            'ring' => [],
            'raw' => [],
            'hours' => [],
            'types' => [
                'steal' => self::freshType(),
                'inform' => self::freshType(),
                'stale' => self::freshType(),
            ],
        ];
    }

    /**
     * Accept a stored state only when it has the expected shape.
     *
     * @return array<string, mixed>|null
     */
    public function sanitizeState(mixed $state): ?array
    {
        if (! is_array($state) || ($state['v'] ?? null) !== 1 || ! isset($state['started_at'])
            || ! is_array($state['ring'] ?? null) || ! is_array($state['raw'] ?? null)
            || ! is_array($state['hours'] ?? null) || ! is_array($state['types'] ?? null)) {
            return null;
        }
        foreach (self::TYPES as $type) {
            if (! is_array($state['types'][$type] ?? null)) {
                return null;
            }
            $state['types'][$type] = array_merge(self::freshType(), $state['types'][$type]);
        }

        return $state;
    }

    /** @return TypeState */
    private static function freshType(): array
    {
        return [
            'status' => 'ok',
            'pending' => false,
            'suppressed' => false,
            'sent' => false,
            'sent_mode' => null,
            'recover_pending' => false,
            'last_alert_at' => null,
            'since' => null,
            'bad' => 0,
            'good' => 0,
            'normal' => null,
            'value' => null,
            'recover_value' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array{cpu: ?array{steal: int, total: int}, inform_count: ?int, snapshot_at: ?int, snapshot_known: bool, hold: bool}  $in
     * @return array{state: array<string, mixed>, decisions: array<string, string>}
     */
    public function step(array $state, int $now, array $in, string $mode = 'on'): array
    {
        // After a gap (mode was off, scheduler down) old open events must not produce a days-old alert or pulih.
        $lastRun = $state['last_run_at'] ?? null;
        if ($lastRun !== null && ($now - (int) $lastRun) > self::RUN_GAP_SECONDS) {
            foreach (self::TYPES as $type) {
                $t = &$state['types'][$type];
                if ($t['status'] === 'alert' && $t['since'] !== null && ($now - (int) $t['since']) > self::OPEN_EVENT_MAX_AGE_SECONDS) {
                    $keep = $t['last_alert_at'];
                    $t = self::freshType();
                    $t['last_alert_at'] = $keep;
                }
                $t['recover_pending'] = false;
                unset($t);
            }
        }
        $state['last_run_at'] = $now;

        foreach (self::TYPES as $type) {
            $t = &$state['types'][$type];
            if ($t['status'] === 'alert') {
                // An alert only "sent" in log mode goes out once when the mode becomes on.
                if ($mode === 'on' && $t['sent_mode'] === 'log') {
                    $t['pending'] = true;
                    $t['sent'] = false;
                    $t['sent_mode'] = null;
                    $t['suppressed'] = false;
                }
                // A re-trigger suppressed by the dedupe hour is sent once that hour has passed, if still open.
                if ($t['suppressed'] && $t['last_alert_at'] !== null
                    && ($now - (int) $t['last_alert_at']) >= (int) $this->cfg['dedupe_seconds']) {
                    $t['suppressed'] = false;
                    $t['pending'] = true;
                }
            }
            unset($t);
        }

        $fresh = $in['snapshot_known'] && $in['snapshot_at'] !== null
            && ($now - $in['snapshot_at']) <= (int) $this->cfg['snapshot_fresh_minutes'] * 60;

        $decisions = [];
        $decisions['steal'] = $this->stepSteal($state, $now, $in['cpu']);
        $decisions['stale'] = $this->stepStale($state, $now, $in, $fresh);
        $decisions['inform'] = $this->stepInform($state, $now, $in['inform_count'], $fresh, $in['snapshot_known'], $in['snapshot_at']);

        return ['state' => $state, 'decisions' => $decisions];
    }

    // ------------------------------------------------------------------ steal

    /** @param  array<string, mixed>  $state */
    private function stepSteal(array &$state, int $now, ?array $cpu): string
    {
        if ($cpu === null) {
            return 'no_data';
        }

        $ring = array_values($state['ring']);
        $last = $ring === [] ? null : $ring[count($ring) - 1];
        $entry = [$now, (int) $cpu['steal'], (int) $cpu['total']];

        if ($last === null || $now < $last[0] || ($now - $last[0]) > self::RING_MAX_AGE_SECONDS) {
            $state['ring'] = [$entry];

            return 'learning';
        }
        if ($entry[1] < $last[1] || $entry[2] < $last[2]) {
            // counter reset (reboot / migration): start over, never alert on it
            $state['ring'] = [$entry];

            return 'learning';
        }
        if (($now - $last[0]) < self::SLICE_MIN_SECONDS) {
            return 'too_soon';
        }

        $ring[] = $entry;
        $ring = array_slice($ring, -self::RING_MAX);
        $state['ring'] = $ring;

        $t = &$state['types']['steal'];
        // 90 s tolerance for scheduler jitter: 3 slices of ~10 min count as the 30 min window.
        $need = (int) $this->cfg['steal_window_minutes'] * 60 - 90;
        $threshold = (float) $this->cfg['steal_threshold_pct'];

        $cover = 0;
        $sumSteal = 0;
        $sumTotal = 0;
        $allHigh = true;
        $reached = false;
        foreach ($this->trailingSlices($ring) as $slice) {
            if ($slice['pct'] <= $threshold) {
                $allHigh = false;
            }
            $cover += $slice['dt'];
            $sumSteal += $slice['steal'];
            $sumTotal += $slice['total'];
            if ($cover >= $need) {
                $reached = true;
                break;
            }
            if ($t['status'] === 'ok' && ! $allHigh) {
                break;
            }
        }
        $avg = $sumTotal > 0 ? $sumSteal / $sumTotal * 100 : 0.0;

        if ($t['status'] === 'ok') {
            if ($reached && $allHigh) {
                return $this->raise($t, $now, (int) round($avg));
            }

            return $reached ? 'ok' : 'insufficient';
        }

        if (! $reached) {
            return 'alert_insufficient';
        }
        if ($t['pending']) {
            $t['value'] = (int) round($avg);
        }
        if ($avg < (float) $this->cfg['steal_recover_pct']) {
            return $this->clear($t, (int) round($avg));
        }

        return 'alert_ongoing';
    }

    /**
     * Newest-first slices of the ring that are contiguous and valid (5-15 min each).
     *
     * @param  list<array{0: int, 1: int, 2: int}>  $ring
     * @return list<array{dt: int, pct: float, steal: int, total: int}>
     */
    private function trailingSlices(array $ring): array
    {
        $slices = [];
        for ($i = count($ring) - 1; $i >= 1; $i--) {
            $dt = $ring[$i][0] - $ring[$i - 1][0];
            $dSteal = $ring[$i][1] - $ring[$i - 1][1];
            $dTotal = $ring[$i][2] - $ring[$i - 1][2];
            if ($dt < self::SLICE_MIN_SECONDS || $dt > self::SLICE_MAX_SECONDS || $dTotal <= 0 || $dSteal < 0) {
                break;
            }
            $slices[] = ['dt' => $dt, 'pct' => $dSteal / $dTotal * 100, 'steal' => $dSteal, 'total' => $dTotal];
        }

        return $slices;
    }

    // ------------------------------------------------------------------ stale

    /**
     * @param  array<string, mixed>  $state
     * @param  array{snapshot_at: ?int, snapshot_known: bool, hold: bool}  $in
     */
    private function stepStale(array &$state, int $now, array $in, bool $fresh): string
    {
        if (! $in['snapshot_known']) {
            return 'no_data';
        }
        $t = &$state['types']['stale'];

        if ($t['status'] === 'alert') {
            return $fresh ? $this->clear($t, null) : 'alert_ongoing';
        }

        $running = ($now - (int) $state['started_at']) >= (int) $this->cfg['snapshot_stale_alert_minutes'] * 60;
        $at = $in['snapshot_at'];
        $old = $at === null || ($now - $at) > (int) $this->cfg['snapshot_stale_alert_minutes'] * 60;
        if ($in['hold']) {
            return 'hold';
        }
        if (! $running) {
            return $old ? 'learning' : 'ok';
        }

        return $old ? $this->raise($t, $now, ['since' => $at]) : 'ok';
    }

    // ----------------------------------------------------------------- inform

    /** @param  array<string, mixed>  $state */
    private function stepInform(array &$state, int $now, ?int $count, bool $fresh, bool $snapshotKnown, ?int $snapshotAt): string
    {
        if (! $snapshotKnown || $count === null) {
            return 'no_data';
        }
        if (! $fresh) {
            return 'skipped_stale';
        }
        // Two runs on the same snapshot are the same data: never count them as two samples.
        if ($snapshotAt === ($state['inform_snapshot_at'] ?? null)) {
            return 'same_snapshot';
        }

        $t = &$state['types']['inform'];
        $normal = $this->normal($state, $now);
        $this->addSample($state, $now, $count);
        $state['inform_snapshot_at'] = $snapshotAt;
        $confirm = (int) $this->cfg['inform_confirm_samples'];

        if ($t['status'] === 'alert') {
            $frozen = (float) ($t['normal'] ?? 0);
            if ($t['pending'] && $frozen > 0) {
                // not sent yet (suppressed earlier / WA down): say the current numbers
                $t['value'] = ['n' => $count, 'm' => (int) round($frozen), 'd' => max(0, (int) round((1 - $count / $frozen) * 100))];
            }
            if ($frozen > 0 && $count >= $frozen * (1 - (float) $this->cfg['inform_recover_pct'] / 100)) {
                $t['good']++;
                if ($t['good'] >= $confirm) {
                    return $this->clear($t, ['n' => $count, 'm' => (int) round($frozen)]);
                }
            } else {
                $t['good'] = 0;
            }

            return 'alert_ongoing';
        }

        if ($normal === null) {
            $t['bad'] = 0;

            return 'learning';
        }

        if ($count < $normal['value'] * (1 - (float) $this->cfg['inform_drop_pct'] / 100)) {
            $t['bad']++;
            if ($t['bad'] >= $confirm) {
                $t['normal'] = $normal['value'];
                $drop = (int) round((1 - $count / $normal['value']) * 100);

                return $this->raise($t, $now, ['n' => $count, 'm' => (int) round($normal['value']), 'd' => $drop]);
            }

            return 'bad_1';
        }
        $t['bad'] = 0;

        return 'ok';
    }

    /**
     * Normal inform count now: median of the same WIB hour on previous days,
     * else median of samples 30-180 minutes ago, else null (learning).
     *
     * @param  array<string, mixed>  $state
     * @return array{value: float, source: string, samples: int}|null
     */
    public function normal(array $state, int $now): ?array
    {
        $local = self::local($now);
        $hour = $local->format('H');
        $values = [];
        for ($d = 1; $d <= (int) $this->cfg['history_days']; $d++) {
            $key = $local->subDays($d)->format('Y-m-d').' '.$hour;
            if (isset($state['hours'][$key])) {
                $values[] = (float) $state['hours'][$key];
            }
        }
        if (count($values) >= (int) $this->cfg['min_same_hour_days']) {
            return ['value' => self::median($values), 'source' => 'same_hour', 'samples' => count($values)];
        }

        $recent = [];
        foreach ($state['raw'] as $row) {
            if ($row[0] <= $now - 1800 && $row[0] >= $now - 10800) {
                $recent[] = (float) $row[1];
            }
        }
        if (count($recent) >= self::FALLBACK_MIN_SAMPLES) {
            return ['value' => self::median($recent), 'source' => 'recent', 'samples' => count($recent)];
        }

        return null;
    }

    /** @param  array<string, mixed>  $state */
    private function addSample(array &$state, int $now, int $count): void
    {
        $raw = [];
        foreach ($state['raw'] as $row) {
            if ($row[0] >= $now - self::RAW_KEEP_SECONDS && $row[0] <= $now) {
                $raw[] = $row;
            }
        }
        $raw[] = [$now, $count];
        $state['raw'] = $raw;

        $key = self::local($now)->format('Y-m-d H');
        $sameHour = [];
        foreach ($raw as $row) {
            if (self::local($row[0])->format('Y-m-d H') === $key) {
                $sameHour[] = (float) $row[1];
            }
        }
        $state['hours'][$key] = self::median($sameHour);
        ksort($state['hours']);
        $state['hours'] = array_slice($state['hours'], -self::HOURS_KEEP, null, true);
    }

    // ------------------------------------------------------- state transitions

    /**
     * @param  TypeState  $t
     */
    private function raise(array &$t, int $now, mixed $value): string
    {
        $t['status'] = 'alert';
        $t['since'] = $now;
        $t['value'] = $value;
        $t['bad'] = 0;
        $t['good'] = 0;
        $t['sent'] = false;
        $t['sent_mode'] = null;
        $t['recover_pending'] = false;

        $last = $t['last_alert_at'];
        if ($last !== null && ($now - (int) $last) < (int) $this->cfg['dedupe_seconds']) {
            $t['pending'] = false;
            $t['suppressed'] = true;

            return 'alert_suppressed';
        }
        $t['pending'] = true;
        $t['suppressed'] = false;

        return 'alert_new';
    }

    /**
     * Back to ok. "Pulih" is only queued when the alert really went out.
     *
     * @param  TypeState  $t
     */
    private function clear(array &$t, mixed $value): string
    {
        $wasSent = $t['sent'];
        $t['status'] = 'ok';
        $t['pending'] = false;
        $t['suppressed'] = false;
        $t['bad'] = 0;
        $t['good'] = 0;
        $t['sent'] = false;
        $t['sent_mode'] = null;
        $t['recover_pending'] = $wasSent;
        $t['recover_value'] = $value;

        return 'recovered';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    public function markAlertSent(array $state, array $types, int $now, string $mode): array
    {
        foreach ($types as $type) {
            $t = &$state['types'][$type];
            $t['pending'] = false;
            $t['sent'] = true;
            $t['sent_mode'] = $mode;
            $t['last_alert_at'] = $now;
            unset($t);
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    public function markRecoverySent(array $state, array $types): array
    {
        foreach ($types as $type) {
            $state['types'][$type]['recover_pending'] = false;
        }

        return $state;
    }

    // ------------------------------------------------------------------ texts

    /**
     * @param  array<string, mixed>  $state
     * @return array{alerts: array<string, string>, recoveries: array<string, string>}
     */
    public function pending(array $state): array
    {
        $alerts = [];
        $recoveries = [];
        foreach (self::TYPES as $type) {
            $t = $state['types'][$type];
            if ($t['status'] === 'alert' && $t['pending']) {
                $alerts[$type] = $this->text($type, 'alert', $t['value']);
            }
            if ($t['status'] === 'ok' && $t['recover_pending']) {
                $recoveries[$type] = $this->text($type, 'recover', $t['recover_value']);
            }
        }

        return ['alerts' => $alerts, 'recoveries' => $recoveries];
    }

    public function text(string $type, string $kind, mixed $value): string
    {
        $v = is_array($value) ? $value : [];

        return match ([$type, $kind]) {
            ['steal', 'alert'] => 'eBilling ops: server eBilling (VM102) lambat.'."\n"
                .'CPU steal '.(int) $value.'% selama '.(int) $this->cfg['steal_window_minutes'].' menit (batas '.self::num((float) $this->cfg['steal_threshold_pct']).'%).'."\n"
                .'Biasanya karena VM lain di Proxmox sedang berat.',
            ['steal', 'recover'] => 'eBilling ops: VM102 pulih. CPU steal '.(int) $value.'% ('.(int) $this->cfg['steal_window_minutes'].' menit terakhir).',
            ['inform', 'alert'] => 'eBilling ops: modem yang lapor ke GenieACS turun.'."\n"
                .(int) ($v['n'] ?? 0).' modem lapor ≤'.(int) $this->cfg['inform_window_minutes'].' menit, normal ±'.(int) ($v['m'] ?? 0).' (turun '.(int) ($v['d'] ?? 0).'%).'."\n"
                .'Bisa karena listrik padam, jaringan putus, atau GenieACS bermasalah.',
            ['inform', 'recover'] => 'eBilling ops: modem lapor GenieACS normal lagi ('.(int) ($v['n'] ?? 0).', normal ±'.(int) ($v['m'] ?? 0).').',
            ['stale', 'alert'] => 'eBilling ops: data GenieACS tidak update'
                .(isset($v['since']) && $v['since'] !== null ? ' sejak '.self::local((int) $v['since'])->format('H:i').' WIB' : '')
                .' (>'.(int) $this->cfg['snapshot_stale_alert_minutes'].' menit).'."\n"
                .'Sync gagal atau tertahan; jumlah modem belum bisa dicek.',
            ['stale', 'recover'] => 'eBilling ops: data GenieACS update lagi.',
            default => '',
        };
    }

    // ---------------------------------------------------------------- helpers

    /** @param  list<float|int>  $values */
    public static function median(array $values): float
    {
        sort($values);
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mid = intdiv($n, 2);

        return $n % 2 === 1 ? (float) $values[$mid] : ((float) $values[$mid - 1] + (float) $values[$mid]) / 2;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.');
    }

    private static function local(int $ts): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp($ts)->setTimezone(self::TZ);
    }
}
