<?php

namespace App\Services\Outage;

use App\Models\Customer;
use App\Models\OutageSignal;
use App\Models\Setting;
use App\Services\EvolutionApiService;
use App\Services\Report\ReportGroupRegistry;
use App\Services\Wa\WaGroupGuards;
use App\Services\Wa\WaGroupRoles;
use App\Support\WaGroupJid;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Shadow-mode summary to a technical log group. Default: no WA at all (outage_shadow_log_jid empty).
 *
 * The target is REFUSED (skip + warning) when it is a Skynet Report group, the ops alert group
 * (wa_report_group_jid) or a PSB/migration group. The Report / ops-group refusal can be bypassed only by
 * the separate explicit setting outage_shadow_allow_report_group=1; PSB groups can never be targeted.
 * Message text carries counts and OLT / PON labels only (no customer names, numbers or ids) UNLESS
 * outage_shadow_wa_include_names=1 AND the target is the shadow log group itself (namesAllowed: a group
 * JID that is never a PSB/migration/attendance/technician-input group). Then per-event lines carry
 * "code name (pppoe)" and mass lines a detail link. Entries and the KEY_HELD cache hold ids only; names
 * are looked up at send time. Flag off = text byte-identical to the counts-only version.
 */
class OutageShadowNotifier
{
    private const MAX_LINES = 8;

    /** Identical summaries are not re-sent within this window. */
    private const DEDUPE_SECONDS = 6 * 3600;

    private const MAX_HELD = 200;

    /** Entry types appended by the detector only when the names flag is on. */
    private const NAMES_ONLY_TYPES = ['weak_rx', 'pppoe_down'];

    private const NAME_MAX = 40;

    private const PPPOE_MAX = 20;

    private const KEY_HELD = 'outage:shadow-wa:held';

    private const KEY_SENT = 'outage:shadow-wa:sent';

    private const KEY_LAST = 'outage:shadow-wa:last';

    /** @return string|null reason code when the JID must not receive the summary */
    public function refusalReason(string $jid, bool $allowReportGroup): ?string
    {
        $registry = app(ReportGroupRegistry::class);
        $ops = WaGroupJid::normalize((string) Setting::get('wa_report_group_jid', config('services.evolution_api.ops_group_id', '')));

        // Shared pure guard (PSB never; Report / ops only with the allow flag).
        return WaGroupGuards::shadowRefusal($jid, $allowReportGroup, $registry->psbGroupJids(), $registry->groupJids(), $ops);
    }

    /**
     * Names may go only to the shadow log group itself, and never to a PSB/migration group or other
     * non-monitoring groups (attendance, technician Input). Any doubt = false (counts only).
     */
    public function namesAllowed(string $jid): bool
    {
        try {
            $jid = WaGroupJid::normalize($jid);
            if ($jid === '' || ! str_ends_with($jid, '@g.us')) {
                return false;
            }
            if ($jid !== WaGroupJid::normalize(OutageSettings::shadowLogJid())) {
                return false;
            }
            $others = app(ReportGroupRegistry::class)->psbGroupJids();
            $roles = (new WaGroupRoles)->snapshot();
            $others[] = (string) ($roles['attendance']['jid'] ?? '');
            $others[] = (string) ($roles['technician']['input_jid'] ?? '');
            foreach ($others as $other) {
                $other = WaGroupJid::normalize((string) $other);
                if ($other !== '' && $other === $jid) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Guards (all before any send): quiet hours hold, rate cap (default 4/hour), identical-summary
     * dedupe. Held entries ride along with the next allowed summary.
     *
     * @param  array<string,mixed>  $summary  detector summary (uses new_confirmed only)
     */
    public function send(array $summary, ?CarbonInterface $now = null): bool
    {
        try {
            $jid = OutageSettings::shadowLogJid();
            if ($jid === '') {
                return false; // default: no WA
            }
            $now = $now ? Carbon::instance($now) : now();

            // Entries held by an earlier quiet-hours / rate-cap run are sent together with the new ones.
            $held = $this->heldEntries();
            $all = array_merge($held, (array) ($summary['new_confirmed'] ?? []));
            if ($all === []) {
                return false;
            }

            $reason = $this->refusalReason($jid, OutageSettings::shadowAllowReportGroup());
            if ($reason !== null) {
                Log::warning('[Outage] shadow summary not sent: target refused', ['reason' => $reason]);

                return false;
            }

            $names = OutageSettings::shadowWaIncludeNames() && $this->namesAllowed($jid);
            if (! $names) {
                // Per-event weak_rx / pppoe_down entries exist for the names format only.
                $keep = fn ($e) => ! in_array($e['type'] ?? '', self::NAMES_ONLY_TYPES, true);
                $all = array_values(array_filter($all, $keep));
                $held = array_values(array_filter($held, $keep)); // header "termasuk tertahan" only counts what is shown
                if ($all === []) {
                    $this->hold([]);

                    return false;
                }
            }

            // Quiet hours (WIB): hold, the next allowed run sends one combined summary.
            if (OutageSettings::isQuietHour((int) $now->copy()->timezone('Asia/Jakarta')->format('G'))) {
                $this->hold($all, $now);

                return false;
            }

            // Rate cap: max N sends per rolling hour (attempts count, so a failing endpoint is capped too).
            $stamps = array_values(array_filter($this->sentStamps(), fn ($t) => $t > $now->getTimestamp() - 3600));
            if (count($stamps) >= OutageSettings::shadowWaMaxPerHour()) {
                $this->hold($all, $now);

                return false;
            }

            $text = $this->message($all, count($held), $names ? $this->lookupCustomers($all) : [], $names, $now);
            // Fingerprint = text + the sorted episode ids: two different events that read the same
            // ("LOS individual: OLT X 1 pelanggan") are not dropped; only a truly identical set is.
            $ids = array_map(fn ($e) => (int) ($e['id'] ?? 0), $all);
            sort($ids);
            $hash = hash('sha256', $text.'|'.implode(',', $ids));
            $last = $this->cacheGet(self::KEY_LAST);
            if (is_array($last) && ($last['hash'] ?? null) === $hash && (int) ($last['at'] ?? 0) > $now->getTimestamp() - self::DEDUPE_SECONDS) {
                $this->hold([]); // identical to what was already sent: drop it

                return false;
            }

            $stamps[] = $now->getTimestamp();
            $this->cachePut(self::KEY_SENT, $stamps);

            try {
                $result = app(EvolutionApiService::class)->forChannel('ops')->sendToGroup(WaGroupJid::normalize($jid), $text);
            } catch (\Throwable $e) {
                // The attempt is already counted; keep this run's entries for the next allowed run.
                Log::warning('[Outage] shadow summary send threw', ['exception_class' => $e::class]);
                $this->hold($all, $now);

                return false;
            }
            if (! (($result['success'] ?? false) === true)) {
                Log::warning('[Outage] shadow summary send failed');
                $this->hold($all, $now);

                return false;
            }
            $this->cachePut(self::KEY_LAST, ['hash' => $hash, 'at' => $now->getTimestamp()]);
            $this->hold([]);

            return true;
        } catch (\Throwable $e) {
            try {
                Log::warning('[Outage] shadow summary error', ['exception_class' => $e::class]);
            } catch (\Throwable) {
            }

            return false;
        }
    }

    // Cache errors are NOT swallowed here: send() catches them and sends nothing (fail closed, so a
    // broken cache can never remove the rate cap).
    private function cacheGet(string $key): mixed
    {
        return Cache::get($key);
    }

    private function cachePut(string $key, mixed $value): void
    {
        Cache::put($key, $value, now()->addHours(12));
    }

    /** @return list<array<string,mixed>> */
    private function heldEntries(): array
    {
        $held = $this->cacheGet(self::KEY_HELD);

        return is_array($held) ? array_values($held) : [];
    }

    /** @param  list<array<string,mixed>>  $entries */
    private function hold(array $entries, ?CarbonInterface $now = null): void
    {
        if ($entries === []) {
            Cache::forget(self::KEY_HELD);

            return;
        }
        // Remember when an entry was first held: the "dan N lagi" link starts at the day of the oldest one.
        $stamp = ($now ? Carbon::instance($now) : now())->getTimestamp();
        foreach ($entries as $i => $e) {
            if (! isset($e['held_at'])) {
                $entries[$i]['held_at'] = $stamp;
            }
        }
        $this->cachePut(self::KEY_HELD, $this->capHeld(array_values($entries)));
    }

    /**
     * Cap at MAX_HELD: mass entries (mass_los / olt_wide / router-level pppoe_down) survive first, then the
     * newest of the rest. Original order is kept.
     *
     * @param  list<array<string,mixed>>  $entries
     * @return list<array<string,mixed>>
     */
    private function capHeld(array $entries): array
    {
        $over = count($entries) - self::MAX_HELD;
        if ($over <= 0) {
            return $entries;
        }
        foreach ($entries as $i => $e) { // drop the oldest non-mass first
            if ($over > 0 && ! self::isMass($e)) {
                unset($entries[$i]);
                $over--;
            }
        }

        // Only mass entries left over the cap: drop the oldest of those.
        return array_slice(array_values($entries), -self::MAX_HELD);
    }

    /** @return list<int> */
    private function sentStamps(): array
    {
        $v = $this->cacheGet(self::KEY_SENT);

        return is_array($v) ? array_map('intval', array_values($v)) : [];
    }

    /**
     * Customer lookup for the names format: ids from the entries, ONE query for customers and one for
     * the weak_rx payload. Returns [customer_id => [code, name, pppoe_user], 'rx' => [signal_id => dBm]].
     * Any failure = empty (the message falls back to counts).
     *
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,mixed>
     */
    private function lookupCustomers(array $entries): array
    {
        try {
            $ids = [];
            $weak = [];
            foreach ($entries as $e) {
                if (! empty($e['customer_id']) && self::isPerCustomer($e)) {
                    $ids[] = (int) $e['customer_id'];
                    if (($e['type'] ?? '') === 'weak_rx') {
                        $weak[] = (int) ($e['id'] ?? 0);
                    }
                }
            }
            $out = ['c' => [], 'rx' => []];
            if ($ids === []) {
                return $out;
            }
            foreach (Customer::query()->whereIn('id', array_values(array_unique($ids)))->get(['id', 'code', 'name', 'pppoe_user']) as $c) {
                $out['c'][(int) $c->id] = ['code' => (string) $c->code, 'name' => (string) $c->name, 'pppoe_user' => (string) $c->pppoe_user];
            }
            if ($weak !== []) {
                foreach (OutageSignal::query()->whereIn('id', $weak)->get(['id', 'payload']) as $s) {
                    $p = (array) $s->payload;
                    $v = $p['rx_dbm'] ?? $p['worst_dbm'] ?? null;
                    if (is_numeric($v)) {
                        $out['rx'][(int) $s->id] = (float) $v;
                    }
                }
            }

            return $out;
        } catch (\Throwable) {
            return ['c' => [], 'rx' => []];
        }
    }

    /** One line of text: no newlines / control chars, no WhatsApp formatting characters, bounded. */
    private function clean(string $s, int $max): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
        $s = str_replace(['*', '_', '~', '`'], '', $s);
        $s = trim(preg_replace('/\s+/u', ' ', $s) ?? '');

        return mb_substr($s, 0, $max);
    }

    /** "CODE Name": a name carrying a phone-like digit run is dropped (code only). */
    private function customerLabel(array $c): string
    {
        $name = $this->clean((string) ($c['name'] ?? ''), self::NAME_MAX);
        if ($this->phoneLike($name)) {
            $name = '';
        }
        $code = $this->clean((string) ($c['code'] ?? ''), 20);
        if ($this->phoneLike($code)) {
            $code = '';
        }

        return trim($code.' '.$name);
    }

    /** PPPoE user only when short and not phone-like. */
    private function pppoeLabel(array $c): string
    {
        $p = $this->clean((string) ($c['pppoe_user'] ?? ''), 200);

        return ($p !== '' && mb_strlen($p) <= self::PPPOE_MAX && ! $this->phoneLike($p)) ? $p : '';
    }

    /** 8+ digits even when split by spaces, dots, dashes, plus or parentheses ("0812-0000-0001"). */
    private function phoneLike(string $s): bool
    {
        return preg_match('/\d{8,}/', preg_replace('/[\s.\-+()]/', '', $s) ?? $s) === 1;
    }

    /**
     * Mass entry: mass_los, olt_wide or router-level pppoe_down.
     *
     * @param  array<string,mixed>  $e
     */
    private static function isMass(array $e): bool
    {
        return in_array($e['type'] ?? '', ['mass_los', 'olt_wide'], true)
            || (($e['type'] ?? '') === 'pppoe_down' && ($e['level'] ?? '') === 'router');
    }

    /** One definition: an entry that names a single customer (everything else, incl. router-level PPPoE, is "mass"). */
    private static function isPerCustomer(array $entry): bool
    {
        return in_array($entry['type'] ?? '', ['single_los', 'weak_rx', 'pppoe_down'], true);
    }

    private function link(array $query): string
    {
        return rtrim((string) config('app.url'), '/').route('monitoring.index', ['tab' => 'deteksi'] + $query, false);
    }

    /**
     * @param  list<array<string,mixed>>  $new
     * @param  array<string,mixed>  $customers  lookupCustomers() result (names format only)
     * @param  bool  $names  names format (flag on AND target allowed); false = counts only, as before
     */
    public function message(array $new, int $heldCount = 0, array $customers = [], bool $names = false, ?CarbonInterface $now = null): string
    {
        $lines = [];
        $mass = [];
        $singles = [];
        $unknownSingles = [];
        $dg = 0;
        foreach ($new as $n) {
            $olt = trim((string) ($n['olt_name'] ?? '')) !== '' ? (string) $n['olt_name'] : 'OLT '.($n['olt_id'] ?? '?');
            $type = (string) ($n['type'] ?? '');
            if ($names && self::isPerCustomer($n)) {
                $c = $customers['c'][(int) ($n['customer_id'] ?? 0)] ?? null;
                if (self::isMass($n)) {
                    $mass[] = '- PPPoE mati massal ('.(int) ($n['affected_count'] ?? 0).' pelanggan)'."\n".'  Detail: '.$this->link(['episode' => (int) ($n['id'] ?? 0)]);

                    continue;
                }
                $label = $c !== null ? $this->customerLabel($c) : '';
                if ($label !== '') {
                    $where = $olt.(! empty($n['pon_port']) ? ' PON '.$n['pon_port'] : '');
                    if ($type === 'single_los') {
                        $pppoe = $this->pppoeLabel($c);
                        $lines[] = '- '.(($n['cause'] ?? null) === 'down_unknown' ? 'Offline tanpa sebab' : 'LOS individual').': '.$where.' - '.$label.($pppoe !== '' ? ' ('.$pppoe.')' : '');
                    } elseif ($type === 'weak_rx') {
                        $rx = $customers['rx'][(int) ($n['id'] ?? 0)] ?? null;
                        $lines[] = '- Redaman: '.$where.' - '.$label.($rx !== null ? ' ('.number_format($rx, 1, '.', '').' dBm)' : '');
                    } else {
                        $lines[] = '- PPPoE mati: '.$where.' - '.$label;
                    }

                    continue;
                }
                // Customer not resolved: fall back to a counted line.
                if ($type === 'single_los') {
                    $bucket = ($n['cause'] ?? null) === 'down_unknown' ? 'unknownSingles' : 'singles';
                    ${$bucket}[$olt] = (${$bucket}[$olt] ?? 0) + 1;
                } else {
                    $lines[] = '- '.($type === 'weak_rx' ? 'Redaman lemah' : 'PPPoE mati').': '.$olt.' 1 pelanggan';
                }

                continue;
            }
            switch ($type) {
                case 'mass_los':
                    $mass[] = '- Massal: '.$olt.' PON '.($n['pon_port'] ?? '?').' ('.(int) ($n['affected_count'] ?? 0).' pelanggan)'
                        .(! empty($n['cause_unknown']) ? ' [penyebab tidak diketahui]' : '')
                        .($names ? "\n".'  Detail: '.$this->link(['episode' => (int) ($n['id'] ?? 0)]) : '');
                    break;
                case 'single_los':
                    $bucket = ($n['cause'] ?? null) === 'down_unknown' ? 'unknownSingles' : 'singles';
                    ${$bucket}[$olt] = (${$bucket}[$olt] ?? 0) + 1;
                    break;
                case 'olt_wide':
                    $mass[] = '- Perubahan massal status ONU di '.$olt.' ('.(int) ($n['affected_count'] ?? 0).' ONU, kemungkinan gangguan collector)';
                    break;
                default:
                    $dg++;
            }
        }
        foreach ($singles as $olt => $count) {
            $lines[] = '- LOS individual (>= batas waktu): '.$olt.' '.$count.' pelanggan';
        }
        foreach ($unknownSingles as $olt => $count) {
            $lines[] = '- Offline tanpa sebab (>= batas waktu): '.$olt.' '.$count.' pelanggan';
        }
        if ($dg > 0) {
            $lines[] = '- Lainnya (hanya dicatat): '.$dg;
        }

        $lines = array_merge($mass, $lines); // mass lines first: never pushed into "dan N lagi"
        $extra = max(0, count($lines) - self::MAX_LINES);
        $lines = array_slice($lines, 0, self::MAX_LINES);
        if ($extra > 0) {
            $from = ($now ? Carbon::instance($now) : now())->getTimestamp();
            foreach ($new as $n) {
                if (isset($n['held_at'])) {
                    $from = min($from, (int) $n['held_at']);
                }
            }
            $lines[] = '- dan '.$extra.' lagi'.($names ? ' (lihat halaman): '.$this->link(['from' => Carbon::createFromTimestamp($from)->timezone('Asia/Jakarta')->format('Y-m-d')]) : '');
        }

        $head = $heldCount > 0
            ? '[SHADOW 2.4] Ringkasan deteksi (termasuk tertahan jam tenang)'
            : '[SHADOW 2.4] Deteksi gangguan baru';

        return $head." (hanya dicatat; tidak ada tiket dan tidak ada WA ke pelanggan)\n"
            .implode("\n", $lines);
    }
}
