<?php

namespace App\Services\Wa;

use App\Models\Setting;
use App\Models\User;
use App\Services\Outage\OutageSettingsWriter;
use App\Services\Report\ReportGroupRegistry;
use App\Support\WaGroupJid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The write path of the Grup WhatsApp page. One section (report | ops_alert | shadow | undelivered | ticket |
 * connectivity | attendance | technician) per call.
 * Validation runs on the FULL after-state (every role guard re-checked, so a change in one role can never
 * silently break another), storage formats are identical to the CLI commands, and every changed key writes
 * an activity row with masked JIDs. PSB/migration groups and the global
 * psb_recap_cmd_enabled switch are never touched here. The version check and the write are not locked
 * (admin-only page; a concurrent CLI edit inside that tiny window is last-write-wins).
 */
class WaGroupSettingsWriter
{
    private const FLAG_KEYS = [
        'daily_enabled' => 'report_daily_enabled',
        'recap_cmd_enabled' => 'report_recap_cmd_enabled',
        'pppoe_cmd_enabled' => 'report_pppoe_cmd_enabled',
        'onu_daily_enabled' => 'report_daily_onu_enabled',
        'psb_detail_daily_enabled' => 'report_daily_psb_detail_enabled',
        'onu_recap_cmd_enabled' => 'recap_onu_cmd_enabled',
    ];

    /** Fields accepted per section (anything else, e.g. psb_wa_group_ids, is ignored and never written). */
    public const FIELDS = [
        'report' => ['group_jid', 'channel', 'daily_enabled', 'recap_cmd_enabled', 'pppoe_cmd_enabled', 'onu_daily_enabled', 'psb_detail_daily_enabled', 'onu_recap_cmd_enabled'],
        'ops_alert' => ['jid'],
        'shadow' => ['jid', 'allow_report_group'],
        'undelivered' => ['jid'],
        'ticket' => ['jid'],
        'connectivity' => ['jid'],
        'attendance' => ['jid', 'label'],
        'technician' => ['share_jid', 'share_label', 'input_jid', 'input_label'],
    ];

    /** Violation (role:reason) of a NEW section => the setting key whose value caused it. */
    private const VIOLATION_KEY = [
        'ticket:psb_group' => 'wa_ticket_group_jid',
        'connectivity:psb_group' => 'connectivity_alerts_group_id',
        'attendance:psb_group' => 'attendance_group_id',
        'technician:share_psb_group' => 'wa_ticket_progress_share_group_jid',
        'technician:input_psb_group' => 'wa_ticket_progress_input_group_jid',
    ];

    public function __construct(
        private readonly WaGroupRoles $roles,
        private readonly WaGroupDirectory $directory,
    ) {}

    /**
     * @param  array<string, mixed>  $input  section fields only
     * @param  list<string>  $forceUnverified  sections whose "membership unknown" refusal is overridden
     * @return array{ok: bool, errors: array<string, string>, changed: list<string>}
     */
    public function apply(string $section, array $input, string $version, array $forceUnverified, User $actor): array
    {
        return $this->run($section, $input, $version, $forceUnverified, $actor, null);
    }

    /**
     * Write path of the ops CLI (report:wa-group set/clear): same guards, membership check and masked activity
     * row as the page, no User (causer null, properties.source = $actorLabel). The version is the live one, so
     * there is no stale-form check.
     *
     * @param  array<string, mixed>  $input  section fields only
     * @return array{ok: bool, errors: array<string, string>, changed: list<string>}
     */
    public function applyAsSystem(string $section, array $input, bool $forceUnverified = false, string $actorLabel = 'cli'): array
    {
        return $this->run($section, $input, $this->roles->version($this->roles->snapshot()), $forceUnverified ? [$section] : [], null, $actorLabel);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $forceUnverified
     * @return array{ok: bool, errors: array<string, string>, changed: list<string>}
     */
    private function run(string $section, array $input, string $version, array $forceUnverified, ?User $actor, ?string $source): array
    {
        if (! in_array($section, WaGroupRoles::SECTIONS, true)) {
            return $this->fail(['section' => 'Bagian tidak dikenal.']);
        }

        $before = $this->roles->snapshot();
        if (! hash_equals($this->roles->version($before), $version)) {
            return $this->fail(['version' => 'Pengaturan sudah berubah sejak halaman dibuka (mungkin diubah orang lain atau lewat CLI). Muat ulang halaman lalu coba lagi.']);
        }

        [$after, $formatErrors] = $this->propose($section, $before, $input);
        if ($formatErrors !== []) {
            return $this->fail($formatErrors);
        }

        $guardErrors = $this->guardErrors($section, $before, $after);
        if ($guardErrors !== []) {
            return $this->fail($guardErrors);
        }

        $changes = $this->diff($section, $before, $after);
        if ($changes === []) {
            return ['ok' => true, 'errors' => [], 'changed' => []];
        }

        // Membership of every CHANGED, non-empty JID on the channel that will actually send to it.
        $membership = [];
        $force = in_array($section, $forceUnverified, true);
        $membershipErrors = [];
        foreach ($this->membershipTargets($section, $before, $after) as $field => [$channel, $jid]) {
            // 'undelivered_channel' = the undelivered alert group re-probed because the Report channel changed
            // (the alert is sent on the Report channel). Shown on the Report card under the channel field.
            $isUndelivered = $field === 'undelivered_channel';
            $errKey = $isUndelivered ? 'channel' : $field;
            $subject = $isUndelivered ? 'grup Peringatan laporan gagal' : 'grup ini';
            $m = $this->directory->membership($channel, $jid);
            if ($m['member'] === false) {
                $membershipErrors[$errKey] = 'Nomor WA ('.$channel.') bukan anggota '.$subject.'. Tambahkan nomor ke grup atau pilih saluran lain.';
                $membership[$field] = 'tidak';
            } elseif ($m['member'] === null) {
                if (! $force) {
                    $membershipErrors[$errKey] = 'Keanggotaan nomor WA ('.$channel.') di '.$subject.' belum bisa diverifikasi. Centang "Simpan walau belum terverifikasi" bila yakin.';
                    $membership[$field] = 'tidak_diketahui';
                } else {
                    $membership[$field] = 'dipaksa';
                }
            } else {
                $membership[$field] = 'ya';
            }
        }
        if ($membershipErrors !== []) {
            return $this->fail($membershipErrors);
        }

        try {
            DB::transaction(function () use ($section, $before, $after, $changes, $membership, $actor, $source): void {
                $this->write($section, $before, $after, $actor, $source);
                $this->audit($section, $changes, $membership, $after, $actor, $source);
            });
        } catch (\RuntimeException $e) {
            return $this->fail([$this->defaultField($section) => $e->getMessage()]);
        }
        // Setting::set() forgets the cache INSIDE the transaction; a concurrent reader may have re-cached the
        // old committed row since. Forget each written key again now that the commit is visible (never flush).
        foreach (array_keys($changes) as $key) {
            Cache::forget('setting.'.$key);
        }
        Setting::flushRequestMemo();
        Log::info('[WA-GROUPS] settings changed', ['section' => $section, 'keys' => array_keys($changes), 'actor_role' => $actor?->role ?? $source]);

        return ['ok' => true, 'errors' => [], 'changed' => array_keys($changes)];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function propose(string $section, array $before, array $input): array
    {
        $after = $before;
        $errors = [];

        // normalizeTarget keeps a personal JID personal (WaGroupJid::normalize would turn it into a fake @g.us
        // group), so it is then refused by the isGroupJid() format check instead of silently accepted.
        $jid = static fn (mixed $v): string => WaGroupGuards::normalizeTarget(is_scalar($v) ? (string) $v : '');

        if ($section === 'report') {
            $g = $jid($input['group_jid'] ?? '');
            if ($g !== '' && ! WaGroupGuards::isGroupJid($g)) {
                $errors['group_jid'] = 'Format JID grup tidak valid (harus berakhiran @g.us).';
            }
            $channel = strtolower(trim((string) ($input['channel'] ?? $before['report']['channel'])));
            if (! in_array($channel, ReportGroupRegistry::CHANNELS, true)) {
                $errors['channel'] = 'Saluran harus ops atau ticket.';
            }
            $after['report']['group_jid'] = $g;
            $after['report']['extra_jids'] = [];
            $after['report']['channel'] = $channel;
            foreach (array_keys(self::FLAG_KEYS) as $f) {
                $after['report'][$f] = $this->bool($input[$f] ?? $before['report'][$f]);
            }
        } elseif ($section === 'ops_alert' || $section === 'undelivered') {
            $j = $jid($input['jid'] ?? '');
            if ($j !== '' && ! WaGroupGuards::isGroupJid($j)) {
                $errors['jid'] = 'Format JID grup tidak valid (harus berakhiran @g.us).';
            }
            $after[$section]['jid'] = $j;
            if ($section === 'ops_alert') {
                $after['ops_alert']['effective_jid'] = $j !== '' ? $j : WaGroupJid::normalize((string) config('services.evolution_api.ops_group_id', ''));
            }
        } elseif ($section === 'ticket' || $section === 'connectivity' || $section === 'attendance') {
            $j = $jid($input['jid'] ?? '');
            if ($j !== '' && ! WaGroupGuards::isGroupJid($j)) {
                $errors['jid'] = 'Format JID grup tidak valid (harus berakhiran @g.us).';
            }
            $after[$section]['jid'] = $j;
            if ($section === 'ticket') {
                $after['ticket']['effective_jid'] = $j !== '' ? $j : WaGroupJid::normalize((string) config('services.evolution_api.ticket_group_id', ''));
                $after['readonly']['ticket_group_jid'] = $after['ticket']['effective_jid'];
            } elseif ($section === 'connectivity') {
                $after['connectivity']['effective_jid'] = $j !== '' ? $j : WaGroupJid::normalize((string) config('services.connectivity_alerts.group_id', ''));
            } else {
                $label = array_key_exists('label', $input)
                    ? trim(is_scalar($input['label']) ? (string) $input['label'] : '')
                    : (string) $before['attendance']['label'];
                if (mb_strlen($label) > 255) {
                    $errors['label'] = 'Label maksimal 255 karakter.';
                }
                $after['attendance']['label'] = $label;
            }
        } elseif ($section === 'technician') {
            $share = $jid($input['share_jid'] ?? '');
            if ($share !== '' && ! WaGroupGuards::isGroupJid($share)) {
                $errors['share_jid'] = 'Format JID grup tidak valid (harus berakhiran @g.us).';
            }
            // The Input target may also be a personal chat (<digits>@s.whatsapp.net): never rewritten to @g.us.
            // A bare phone number (10-15 digits) becomes a personal JID, never a fake @g.us group.
            $rawIn = is_scalar($input['input_jid'] ?? null) ? (string) $input['input_jid'] : '';
            $in = WaGroupGuards::normalizeInputTarget($rawIn);
            if (WaGroupGuards::isTooShortInputNumber($rawIn)) {
                $errors['input_jid'] = 'Nomor terlalu pendek: nomor pribadi harus 10-15 digit (contoh 081234567890). Periksa kembali nomornya.';
            } elseif ($in !== '' && ! WaGroupGuards::isGroupJid($in) && ! WaGroupGuards::isPersonalJid($in)) {
                $errors['input_jid'] = 'Format JID tidak valid (harus berakhiran @g.us atau @s.whatsapp.net, atau nomor pribadi berupa angka saja).';
            }
            // A missing label keeps the stored value. An empty label means "default", except that an already
            // stored empty label stays empty (it is never rewritten to the default just by saving the section).
            foreach (['share_label' => WaGroupRoles::TECH_SHARE_LABEL_DEFAULT, 'input_label' => WaGroupRoles::TECH_INPUT_LABEL_DEFAULT] as $field => $default) {
                $stored = (string) $before['technician'][$field];
                $label = array_key_exists($field, $input)
                    ? trim(is_scalar($input[$field]) ? (string) $input[$field] : '')
                    : $stored;
                if (mb_strlen($label) > 120) {
                    $errors[$field] = 'Label maksimal 120 karakter.';
                }
                $after['technician'][$field] = $label === '' ? ($stored === '' ? '' : $default) : $label;
            }
            $after['technician']['share_jid'] = $share;
            $after['technician']['input_jid'] = $in;
        } else {
            $j = $jid($input['jid'] ?? '');
            if ($j !== '' && ! WaGroupGuards::isGroupJid($j)) {
                $errors['jid'] = 'Format JID grup tidak valid (harus berakhiran @g.us).';
            }
            $after['shadow']['jid'] = $j;
            $after['shadow']['allow_report_group'] = $this->bool($input['allow_report_group'] ?? $before['shadow']['allow_report_group']);
        }

        return [$after, $errors];
    }

    /**
     * Role guard violations of a state: list of "role:reason".
     *
     * @param  array<string, mixed>  $s
     * @return list<string>
     */
    public function violations(array $s): array
    {
        $psb = $s['readonly']['psb_group_jids'];
        // The runtime uses every configured Report JID (ReportGroupRegistry::groupJids), so legacy extras count.
        $report = $s['report']['group_jid'] !== '' ? array_merge([$s['report']['group_jid']], $s['report']['extra_jids']) : [];
        $ops = (string) $s['ops_alert']['effective_jid'];
        $out = [];

        foreach ($report as $reportJid) {
            if (($r = WaGroupGuards::reportRefusal($reportJid, $psb)) !== null) {
                $out[] = 'report:'.$r;
                break;
            }
        }
        if ($s['report']['extra_jids'] !== []) {
            // Single Report group rule: a legacy multi-group list is a violation until a Report save normalises it.
            $out[] = 'report:legacy_extra_jids';
        }
        if ($ops !== '' && ($r = WaGroupGuards::opsAlertRefusal($ops, $psb, (string) ($s['readonly']['ticket_group_jid'] ?? ''))) !== null) {
            $out[] = 'ops_alert:'.$r;
        }
        if ($s['shadow']['jid'] !== '' && ($r = WaGroupGuards::shadowRefusal($s['shadow']['jid'], (bool) $s['shadow']['allow_report_group'], $psb, $report, $ops)) !== null) {
            $out[] = 'shadow:'.$r;
        }
        if ($s['undelivered']['jid'] !== '' && ($r = WaGroupGuards::undeliveredRefusal($s['undelivered']['jid'], $psb, $report, $ops)) !== null) {
            $out[] = 'undelivered:'.$r;
        }
        // Ticket / connectivity / attendance / technician: never a PSB group. (ticket == ops is already covered
        // by ops_alert:ticket_group.) Labels are free text and never judged.
        if (($s['ticket']['effective_jid'] ?? '') !== '' && WaGroupGuards::notPsbRefusal((string) $s['ticket']['effective_jid'], $psb) !== null) {
            $out[] = 'ticket:psb_group';
        }
        if (($s['connectivity']['effective_jid'] ?? '') !== '' && WaGroupGuards::notPsbRefusal((string) $s['connectivity']['effective_jid'], $psb) !== null) {
            $out[] = 'connectivity:psb_group';
        }
        if (($s['attendance']['jid'] ?? '') !== '' && WaGroupGuards::notPsbRefusal((string) $s['attendance']['jid'], $psb) !== null) {
            $out[] = 'attendance:psb_group';
        }
        if (($s['technician']['share_jid'] ?? '') !== '' && WaGroupGuards::notPsbRefusal((string) $s['technician']['share_jid'], $psb) !== null) {
            $out[] = 'technician:share_psb_group';
        }
        if (WaGroupGuards::isGroupJid((string) ($s['technician']['input_jid'] ?? '')) && WaGroupGuards::notPsbRefusal((string) $s['technician']['input_jid'], $psb) !== null) {
            $out[] = 'technician:input_psb_group';
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, string>
     */
    private function guardErrors(string $section, array $before, array $after): array
    {
        $old = $this->violations($before);
        $errors = [];
        foreach ($this->violations($after) as $v) {
            [$role, $reason] = explode(':', $v, 2);
            // Pre-existing problems of OTHER roles do not block this save; new ones and this section's own do.
            if ($role !== $section && in_array($v, $old, true)) {
                continue;
            }
            // New sections only judge a CHANGED value: an unchanged pre-existing conflict (e.g. an old
            // attendance group that is also a PSB group) never blocks saving the section's other fields.
            if (isset(self::VIOLATION_KEY[$v]) && in_array($v, $old, true)
                && ! array_key_exists(self::VIOLATION_KEY[$v], $this->diff($role, $before, $after))) {
                continue;
            }
            $field = $this->violationField($role, $reason);
            if ($role !== $section) {
                $errors[$this->defaultField($section)] = 'Perubahan ini membuat bagian "'.$this->roleLabel($role).'" melanggar aturan: '.$this->reasonText($role, $reason);

                continue;
            }
            $errors[$field] = $this->reasonText($role, $reason);
        }

        return $errors;
    }

    private function defaultField(string $section): string
    {
        return match ($section) {
            'report' => 'group_jid',
            'technician' => 'share_jid',
            default => 'jid',
        };
    }

    private function violationField(string $role, string $reason): string
    {
        return match (true) {
            $role === 'report' => 'group_jid',
            $role === 'technician' && $reason === 'input_psb_group' => 'input_jid',
            $role === 'technician' => 'share_jid',
            default => 'jid',
        };
    }

    private function roleLabel(string $role): string
    {
        return [
            'report' => 'Skynet Report',
            'ops_alert' => 'Alert Ops',
            'shadow' => 'Log teknis',
            'undelivered' => 'Peringatan laporan harian gagal',
            'ticket' => 'Grup Tiket',
            'connectivity' => 'Alert Connectivity (NOC)',
            'attendance' => 'Absensi',
            'technician' => 'Progres teknisi',
        ][$role] ?? $role;
    }

    private function reasonText(string $role, string $reason): string
    {
        return match ($role.':'.$reason) {
            'report:psb_group' => 'Grup Report tidak boleh sama dengan grup PSB/Migrasi.',
            'ops_alert:psb_group' => 'Alert Ops tidak boleh dikirim ke grup PSB/Migrasi.',
            'ops_alert:ticket_group' => 'Alert Ops tidak boleh sama dengan grup Tiket (laporan tiket selesai tidak akan terkirim).',
            'report:legacy_extra_jids' => 'Ada lebih dari satu grup Report di pengaturan lama; hanya satu grup yang diizinkan.',
            'shadow:psb_group' => 'Log teknis tidak boleh dikirim ke grup PSB/Migrasi.',
            'shadow:report_or_ops_group' => 'Grup ini adalah grup Report/Alert Ops. Aktifkan "Izinkan ke grup Report/Ops" dulu, atau pilih grup lain.',
            'undelivered:psb_group' => 'Peringatan tidak boleh dikirim ke grup PSB/Migrasi.',
            'undelivered:report_group' => 'Peringatan laporan gagal tidak boleh dikirim ke grup Report.',
            'undelivered:ops_group' => 'Peringatan laporan gagal tidak boleh dikirim ke grup Alert Ops.',
            'ticket:psb_group' => 'Grup Tiket tidak boleh sama dengan grup PSB/Migrasi.',
            'connectivity:psb_group' => 'Alert Connectivity tidak boleh dikirim ke grup PSB/Migrasi.',
            'attendance:psb_group' => 'Grup Absensi tidak boleh sama dengan grup PSB/Migrasi.',
            'technician:share_psb_group' => 'Grup Share progres teknisi tidak boleh sama dengan grup PSB/Migrasi.',
            'technician:input_psb_group' => 'Grup Input progres teknisi tidak boleh sama dengan grup PSB/Migrasi.',
            default => 'Pengaturan ditolak oleh aturan grup ('.$reason.').',
        };
    }

    /**
     * Changed keys: key => [old, new] (JIDs normalised, flags as bool).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function diff(string $section, array $before, array $after): array
    {
        $out = [];
        $cmp = function (string $key, mixed $old, mixed $new) use (&$out): void {
            if ($old !== $new) {
                $out[$key] = [$old, $new];
            }
        };

        if ($section === 'report') {
            $oldAll = $before['report']['group_jid'] === '' ? [] : array_merge([$before['report']['group_jid']], $before['report']['extra_jids']);
            $newAll = $after['report']['group_jid'] === '' ? [] : [$after['report']['group_jid']];
            $cmp('report_wa_group_ids', $oldAll, $newAll);
            $cmp('report_wa_channel', $before['report']['channel'], $after['report']['channel']);
            foreach (self::FLAG_KEYS as $field => $key) {
                $cmp($key, (bool) $before['report'][$field], (bool) $after['report'][$field]);
            }
        } elseif ($section === 'ops_alert') {
            $cmp('wa_report_group_jid', $before['ops_alert']['jid'], $after['ops_alert']['jid']);
        } elseif ($section === 'shadow') {
            $cmp('outage_shadow_log_jid', $before['shadow']['jid'], $after['shadow']['jid']);
            $cmp('outage_shadow_allow_report_group', (bool) $before['shadow']['allow_report_group'], (bool) $after['shadow']['allow_report_group']);
        } elseif ($section === 'ticket') {
            $cmp('wa_ticket_group_jid', $before['ticket']['jid'], $after['ticket']['jid']);
        } elseif ($section === 'connectivity') {
            $cmp('connectivity_alerts_group_id', $before['connectivity']['jid'], $after['connectivity']['jid']);
        } elseif ($section === 'attendance') {
            $cmp('attendance_group_id', $before['attendance']['jid'], $after['attendance']['jid']);
            $cmp('attendance_group_label', $before['attendance']['label'], $after['attendance']['label']);
        } elseif ($section === 'technician') {
            $cmp('wa_ticket_progress_share_group_jid', $before['technician']['share_jid'], $after['technician']['share_jid']);
            $cmp('wa_ticket_progress_share_group_label', $before['technician']['share_label'], $after['technician']['share_label']);
            $cmp('wa_ticket_progress_input_group_jid', $before['technician']['input_jid'], $after['technician']['input_jid']);
            $cmp('wa_ticket_progress_input_group_label', $before['technician']['input_label'], $after['technician']['input_label']);
        } else {
            $cmp('report_undelivered_alert_jid', $before['undelivered']['jid'], $after['undelivered']['jid']);
        }

        return $out;
    }

    /**
     * field => [channel, jid] for JIDs that changed (or whose sending channel changed).
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{0: string, 1: string}>
     */
    private function membershipTargets(string $section, array $before, array $after): array
    {
        $out = [];
        if ($section === 'report') {
            $jid = $after['report']['group_jid'];
            if ($jid !== '' && ($jid !== $before['report']['group_jid'] || $after['report']['channel'] !== $before['report']['channel'])) {
                $out['group_jid'] = [$after['report']['channel'], $jid];
            }
            // The undelivered alert is sent on the Report channel: a channel switch re-probes its membership too.
            $undelivered = $after['undelivered']['jid'];
            if ($undelivered !== '' && $after['report']['channel'] !== $before['report']['channel']) {
                $out['undelivered_channel'] = [$after['report']['channel'], $undelivered];
            }
        } elseif ($section === 'ops_alert') {
            if ($after['ops_alert']['jid'] !== '' && $after['ops_alert']['jid'] !== $before['ops_alert']['jid']) {
                $out['jid'] = ['ops', $after['ops_alert']['jid']];
            }
        } elseif ($section === 'shadow') {
            if ($after['shadow']['jid'] !== '' && $after['shadow']['jid'] !== $before['shadow']['jid']) {
                $out['jid'] = ['ops', $after['shadow']['jid']];
            }
        } elseif ($section === 'ticket') {
            if ($after['ticket']['jid'] !== '' && $after['ticket']['jid'] !== $before['ticket']['jid']) {
                $out['jid'] = ['ticket', $after['ticket']['jid']];
            }
        } elseif ($section === 'connectivity') {
            if ($after['connectivity']['jid'] !== '' && $after['connectivity']['jid'] !== $before['connectivity']['jid']) {
                $out['jid'] = ['ops', $after['connectivity']['jid']];
            }
        } elseif ($section === 'attendance') {
            if ($after['attendance']['jid'] !== '' && $after['attendance']['jid'] !== $before['attendance']['jid']) {
                $out['jid'] = ['ops', $after['attendance']['jid']];
            }
        } elseif ($section === 'technician') {
            $t = $after['technician'];
            if ($t['share_jid'] !== '' && $t['share_jid'] !== $before['technician']['share_jid']) {
                $out['share_jid'] = ['ticket', $t['share_jid']];
            }
            // Personal Input targets have no group membership to probe.
            if ($t['input_jid'] !== '' && WaGroupGuards::isGroupJid($t['input_jid']) && $t['input_jid'] !== $before['technician']['input_jid']) {
                $replyChannel = strtolower(trim((string) Setting::get('ticket_progress_reply_channel', 'ticket')));
                $out['input_jid'] = [$replyChannel === 'ops' ? 'ops' : 'ticket', $t['input_jid']];
            }
        } else {
            if ($after['undelivered']['jid'] !== '' && $after['undelivered']['jid'] !== $before['undelivered']['jid']) {
                $out['jid'] = [$after['report']['channel'], $after['undelivered']['jid']];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function write(string $section, array $before, array $after, ?User $actor, ?string $source = null): void
    {
        if ($section === 'report') {
            $newJid = $after['report']['group_jid'];
            Setting::set('report_wa_group_ids', $newJid === '' ? [] : [$newJid], 'json', 'report', 'Skynet Report WA group JIDs');
            Cache::forget('setting.report_wa_group_ids');
            Setting::set('report_wa_channel', $after['report']['channel'], 'text', 'report', 'Skynet Report WA channel (ops|ticket)');
            Cache::forget('setting.report_wa_channel');
            foreach (self::FLAG_KEYS as $field => $key) {
                Setting::set($key, $after['report'][$field] ? '1' : '0', 'text', 'report');
            }
        } elseif ($section === 'ops_alert') {
            if ($after['ops_alert']['jid'] === '') {
                // Empty = fall back to the .env default (services.evolution_api.ops_group_id).
                Setting::where('key', 'wa_report_group_jid')->delete();
                Cache::forget('setting.wa_report_group_jid');
            } else {
                Setting::set('wa_report_group_jid', $after['ops_alert']['jid'], 'text', 'wa');
            }
        } elseif ($section === 'shadow') {
            $writer = new OutageSettingsWriter;
            $actorName = $actor === null ? 'wa-groups-'.($source ?? 'system') : 'wa-groups-page:'.$actor->role;
            $jidChanged = $before['shadow']['jid'] !== $after['shadow']['jid'];
            $allowChanged = (bool) $before['shadow']['allow_report_group'] !== (bool) $after['shadow']['allow_report_group'];
            $steps = [];
            $allowStep = fn () => $writer->set('outage_shadow_allow_report_group', $after['shadow']['allow_report_group'] ? '1' : '0', $actorName);
            $jidStep = fn () => $writer->set('outage_shadow_log_jid', $after['shadow']['jid'], $actorName);
            // Turning "allow" on must land before the JID that needs it; turning it off must land after.
            if ($after['shadow']['allow_report_group']) {
                $allowChanged && $steps[] = $allowStep;
                $jidChanged && $steps[] = $jidStep;
            } else {
                $jidChanged && $steps[] = $jidStep;
                $allowChanged && $steps[] = $allowStep;
            }
            foreach ($steps as $step) {
                $r = $step();
                if (! $r['ok']) {
                    throw new \RuntimeException($r['message']);
                }
            }
        } elseif ($section === 'ticket') {
            if ($after['ticket']['jid'] === '') {
                // Empty = fall back to the .env default (services.evolution_api.ticket_group_id).
                Setting::where('key', 'wa_ticket_group_jid')->delete();
                Cache::forget('setting.wa_ticket_group_jid');
            } else {
                Setting::set('wa_ticket_group_jid', $after['ticket']['jid'], 'text', 'wa');
            }
        } elseif ($section === 'connectivity') {
            // '' = fall back to services.connectivity_alerts.group_id (ConnectivityAlertSettings::groupId).
            Setting::set('connectivity_alerts_group_id', $after['connectivity']['jid']);
        } elseif ($section === 'attendance') {
            $changed = $this->diff('attendance', $before, $after);
            if (isset($changed['attendance_group_id'])) {
                Setting::set('attendance_group_id', $after['attendance']['jid'], 'text', 'attendance', 'Grup WA Absensi');
            }
            if (isset($changed['attendance_group_label'])) {
                Setting::set('attendance_group_label', $after['attendance']['label'], 'text', 'attendance', 'Label Grup WA Absensi');
            }
        } elseif ($section === 'technician') {
            $labels = [
                'wa_ticket_progress_share_group_jid' => [$after['technician']['share_jid'], 'Grup share progress harian teknisi'],
                'wa_ticket_progress_share_group_label' => [$after['technician']['share_label'], 'Label grup share progress harian teknisi'],
                'wa_ticket_progress_input_group_jid' => [$after['technician']['input_jid'], 'Grup input manual progress harian teknisi'],
                'wa_ticket_progress_input_group_label' => [$after['technician']['input_label'], 'Label grup input manual progress harian teknisi'],
            ];
            foreach (array_keys($this->diff('technician', $before, $after)) as $key) {
                Setting::set($key, $labels[$key][0], 'text', 'wa', $labels[$key][1]);
            }
        } else {
            Setting::set('report_undelivered_alert_jid', $after['undelivered']['jid'], 'text', 'report');
        }
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, string>  $membership
     * @param  array<string, mixed>  $after
     */
    private function audit(string $section, array $changes, array $membership, array $after, ?User $actor, ?string $source = null): void
    {
        $mask = static function (mixed $v): mixed {
            if (is_array($v)) {
                return array_map(static fn ($j) => WaGroupJid::mask((string) $j), $v);
            }
            if (is_string($v) && WaGroupGuards::isPersonalJid($v)) {
                return 'pribadi:'.substr(md5(strtolower($v)), 0, 8);
            }
            if (is_string($v) && (str_ends_with($v, '@g.us') || $v === '')) {
                return WaGroupJid::mask($v);
            }

            return $v;
        };
        $m = $membership === [] ? 'tidak_dicek' : (string) reset($membership);

        foreach ($changes as $key => [$old, $new]) {
            // Labels are free text: the log only records that a label changed, never its content.
            if (str_ends_with($key, '_label')) {
                [$old, $new] = ['-', 'berubah'];
            }
            // Shadow keys already write their own 'outage' activity row via OutageSettingsWriter.
            activity('wa-groups')
                ->causedBy($actor)
                ->withProperties(array_filter([
                    'source' => $source,
                    'role' => $section,
                    'key' => $key,
                    'old' => $mask($old),
                    'new' => $mask($new),
                    'channel' => $section === 'report' ? $after['report']['channel'] : null,
                    'membership' => $m,
                    'actor_role' => $actor?->role ?? $source,
                ], static fn ($v, $k) => $k !== 'source' || $v !== null, ARRAY_FILTER_USE_BOTH))
                ->event('wa_group_setting_changed')
                ->log('wa_group_setting_changed');
        }
    }

    private function bool(mixed $v): bool
    {
        return in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /**
     * @param  array<string, string>  $errors
     * @return array{ok: false, errors: array<string, string>, changed: list<string>}
     */
    private function fail(array $errors): array
    {
        return ['ok' => false, 'errors' => $errors, 'changed' => []];
    }
}
