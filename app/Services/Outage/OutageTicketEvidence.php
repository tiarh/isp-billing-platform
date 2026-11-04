<?php

namespace App\Services\Outage;

use App\Models\OutageSignal;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Pure text builder of the manual "Buat tiket" action in Deteksi Gangguan: no queries, no writes. The service
 * gathers a plain context array; this class turns it into the ticket fields, the preview lines and the one
 * Report-group message. Texts are short plain Indonesian. Never a phone number, address, NIK or coordinates.
 *
 * ctx keys: olt_name, pon, onu_ref, odp, router_name, customer {id,code,name,area_id}|null, affected list<{code,name}>,
 * affected_total, rx {dbm,at}|null, history list<{at,text}>, pon_total, segment {from_m,to_m}|null,
 * cause_text, diagnosed_label.
 */
final class OutageTicketEvidence
{
    public const TZ = 'Asia/Jakarta';

    /** Customers listed in the ticket text / preview; the rest is "dan N lagi". */
    public const LIST_LIMIT = 30;

    /** Customers named in the WA message. */
    public const REPORT_LIST_LIMIT = 8;

    public const KETERANGAN_MAX = 3000;

    /** @return 'single'|'mass'|null null = this episode can never get a manual ticket */
    public static function kind(OutageSignal $s): ?string
    {
        return match ($s->signal_type) {
            'single_los', 'weak_rx' => 'single',
            'mass_los', 'olt_wide' => 'mass',
            'pppoe_down' => $s->customer_id !== null ? 'single' : 'mass',
            default => null,
        };
    }

    public static function typeLabel(OutageSignal $s): string
    {
        $downUnknown = $s->signal_type === 'single_los' && (((array) $s->payload)['cause'] ?? null) === 'down_unknown';

        return OutageShadowEpisodeReadModel::TYPE_LABELS[$downUnknown ? 'down_unknown' : $s->signal_type] ?? (string) $s->signal_type;
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @return array{kind:string,title:string,customer_name:string,priority:string,affected_count:int,lines:list<string>}
     */
    public static function build(OutageSignal $s, array $ctx): array
    {
        $kind = self::kind($s) ?? 'single';
        $label = self::typeLabel($s);
        $affected = (int) $s->affected_count;
        $olt = (string) ($ctx['olt_name'] ?? '');
        $pon = (string) ($ctx['pon'] ?? '');

        if ($kind === 'single') {
            $c = (array) ($ctx['customer'] ?? []);
            $who = trim(((string) ($c['code'] ?? '')).' '.((string) ($c['name'] ?? '')));
            $title = '[Deteksi] '.$label.' — '.$who;
            $customerName = (string) ($c['name'] ?? '');
            $priority = $s->signal_type === 'weak_rx' ? 'normal' : 'high';
        } else {
            $priority = 'urgent';
            $router = (string) ($ctx['router_name'] ?? '');
            if ($s->signal_type === 'pppoe_down') {
                $title = '[Deteksi] PPPoE massal router '.$router.' — '.$affected.' pelanggan';
                $customerName = 'Router '.$router.': '.$affected.' pelanggan PPPoE putus';
            } elseif ($s->signal_type === 'olt_wide') {
                $title = '[Deteksi] Satu OLT bermasalah '.$olt.' — '.$affected.' pelanggan';
                $customerName = 'Satu OLT: '.$olt;
            } else {
                $title = '[Deteksi] LOS massal '.$olt.($pon !== '' ? ' PON '.$pon : '').' — '.$affected.' pelanggan';
                $customerName = 'Gangguan massal — '.$olt.($pon !== '' ? ' PON '.$pon : '').' ('.$affected.' pelanggan)';
            }
        }

        $lines = [];
        $lines[] = 'Kejadian #'.$s->id.' · '.$label.' · '.(OutageShadowEpisodeReadModel::STATE_LABELS[$s->state] ?? $s->state);
        $lines[] = 'Mulai: '.self::wib($s->opened_at);
        if ($s->confirmed_at !== null) {
            $lines[] = 'Terkonfirmasi: '.self::wib($s->confirmed_at);
        }
        if (! empty($ctx['cause_text'])) {
            $lines[] = 'Dugaan sebab: '.$ctx['cause_text'];
        }
        if (! empty($ctx['diagnosed_label'])) {
            $lines[] = 'Dugaan sistem: '.$ctx['diagnosed_label'];
        }
        $net = self::network($s, $ctx, $kind);
        if ($net !== '') {
            $lines[] = 'Jaringan: '.$net;
        }
        if (! empty($ctx['rx']) && isset($ctx['rx']['dbm'])) {
            $lines[] = 'RX terakhir: '.self::dbm((float) $ctx['rx']['dbm']).(! empty($ctx['rx']['at']) ? ' ('.self::wib($ctx['rx']['at']).')' : '');
        }
        if ($kind === 'mass') {
            $seg = self::segment($ctx['segment'] ?? null);
            if ($seg !== null) {
                $lines[] = 'Perkiraan titik putus: '.$seg;
            }
        }
        foreach (array_slice((array) ($ctx['history'] ?? []), 0, 5) as $h) {
            $lines[] = 'Riwayat: '.self::wib($h['at']).' '.$h['text'];
        }
        if ($kind === 'mass') {
            $lines[] = 'Terdampak ('.$affected.' pelanggan):';
            $list = (array) ($ctx['affected'] ?? []);
            foreach (array_slice($list, 0, self::LIST_LIMIT) as $a) {
                $lines[] = '- '.trim(((string) ($a['code'] ?? '')).' '.((string) ($a['name'] ?? '')));
            }
            $rest = max(0, $affected - min(count($list), self::LIST_LIMIT));
            if ($rest > 0) {
                $lines[] = 'dan '.$rest.' lagi';
            }
        }
        $lines[] = 'Lihat kejadian: '.rtrim((string) config('app.url'), '/').'/monitoring?tab=deteksi&episode='.$s->id;

        return [
            'kind' => $kind,
            'title' => mb_substr($title, 0, 255),
            'customer_name' => mb_substr($customerName, 0, 255),
            'priority' => $priority,
            'affected_count' => $affected,
            'lines' => $lines,
        ];
    }

    /** @param list<string> $lines */
    public static function keterangan(array $lines, ?string $note): string
    {
        $text = implode("\n", $lines);
        $note = trim((string) $note);
        if ($note !== '') {
            $text .= "\n\nCatatan NOC: ".$note;
        }

        return mb_substr($text, 0, self::KETERANGAN_MAX);
    }

    /**
     * The one Report-group message. Lines without data are left out. Plain text, no phone, address or coordinates.
     *
     * @param  array<string,mixed>  $ctx
     */
    public static function reportMessage(OutageSignal $s, array $ctx, int $ticketId, string $ticketCode, string $creator): string
    {
        $kind = self::kind($s) ?? 'single';
        $out = [];
        $out[] = $kind === 'mass' ? 'TIKET GANGGUAN MASSAL DARI DETEKSI' : 'TIKET GANGGUAN DARI DETEKSI';
        $out[] = 'Tiket #'.$ticketId.' ('.$ticketCode.')';
        $out[] = 'Jenis: '.self::typeLabel($s);
        if ($kind === 'single') {
            $c = (array) ($ctx['customer'] ?? []);
            $who = trim(((string) ($c['code'] ?? '')).' - '.((string) ($c['name'] ?? '')), ' -');
            if ($who !== '') {
                $out[] = 'Pelanggan: '.$who;
            }
        }
        $net = self::network($s, $ctx, $kind);
        if ($net !== '') {
            $out[] = 'Jaringan: '.$net;
        }
        if ($kind === 'mass') {
            $list = (array) ($ctx['affected'] ?? []);
            $names = array_map(fn ($a) => (string) ($a['code'] ?? $a['name'] ?? ''), array_slice($list, 0, self::REPORT_LIST_LIMIT));
            $names = array_values(array_filter($names, fn (string $n) => $n !== ''));
            if ($names !== []) {
                $rest = max(0, (int) $s->affected_count - count($names));
                $out[] = 'Terdampak: '.implode(', ', $names).($rest > 0 ? ', dan '.$rest.' lagi' : '');
            }
        }
        $out[] = 'Mulai: '.self::wib($s->opened_at);
        if (! empty($ctx['cause_text'])) {
            $out[] = 'Dugaan sebab: '.$ctx['cause_text'];
        }
        if (! empty($ctx['rx']) && isset($ctx['rx']['dbm'])) {
            $out[] = 'RX terakhir: '.self::dbm((float) $ctx['rx']['dbm']);
        }
        if ($kind === 'mass') {
            $seg = self::segment($ctx['segment'] ?? null);
            if ($seg !== null) {
                $out[] = 'Perkiraan titik putus: '.$seg;
            }
        }
        if ($creator !== '') {
            $out[] = 'Dibuat oleh: '.$creator;
        }
        $out[] = 'Belum ada teknisi. Pelanggan tidak dikirimi pesan.';
        $out[] = 'Detail: '.rtrim((string) config('app.url'), '/').'/tickets/'.$ticketId;

        return implode("\n", $out);
    }

    /** @param array<string,mixed> $ctx */
    private static function network(OutageSignal $s, array $ctx, string $kind): string
    {
        $parts = [];
        if ($s->signal_type === 'pppoe_down' && $kind === 'mass') {
            if (! empty($ctx['router_name'])) {
                $parts[] = 'Router '.$ctx['router_name'];
            }

            return implode(', ', $parts);
        }
        if (! empty($ctx['olt_name'])) {
            $parts[] = 'OLT '.preg_replace('/^OLT\s+/i', '', (string) $ctx['olt_name']);
        }
        if ($s->signal_type === 'olt_wide') {
            return implode(', ', $parts);
        }
        $pon = (string) ($ctx['pon'] ?? '');
        if ($pon !== '') {
            $mass = $kind === 'mass';
            $total = (int) ($ctx['pon_total'] ?? 0);
            $parts[] = 'PON '.$pon.($mass ? ' ('.(int) $s->affected_count.($total > 0 ? ' dari '.$total : '').' pelanggan)' : '');
        }
        if ($kind === 'single' && ! empty($ctx['onu_ref'])) {
            $parts[] = 'ONU '.$ctx['onu_ref'];
        }
        if (! empty($ctx['odp'])) {
            $parts[] = 'ODP '.$ctx['odp'];
        }

        return implode(', ', $parts);
    }

    /** @param array{from_m?:int,to_m?:int}|null $seg */
    private static function segment(?array $seg): ?string
    {
        if (! is_array($seg) || ! isset($seg['to_m'])) {
            return null;
        }
        $km = fn (int $m): string => number_format($m / 1000, 1, ',', '');

        return $km((int) ($seg['from_m'] ?? 0)).'–'.$km((int) $seg['to_m']).' km dari OLT';
    }

    private static function dbm(float $v): string
    {
        return number_format($v, 1, ',', '').' dBm';
    }

    /** "19/10 16:09 WIB" */
    private static function wib(CarbonInterface|string|null $t): string
    {
        if ($t === null || $t === '') {
            return '-';
        }
        $c = $t instanceof CarbonInterface ? Carbon::instance($t) : Carbon::parse($t);

        return $c->copy()->timezone(self::TZ)->format('d/m H:i').' WIB';
    }
}
