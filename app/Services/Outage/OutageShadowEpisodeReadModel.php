<?php

namespace App\Services\Outage;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageSignal;
use App\Models\SupportTicket;
use App\Services\Report\ReportGroupRegistry;
use App\Services\Search\GlobalSearchService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read model of the "Deteksi Gangguan" tab (roadmap 2.4, shadow mode): lists outage_signals episodes
 * with the customers behind them. Strictly read-only: no writes, no WA, no queue, no tickets.
 *
 * Customer rows are a whitelist (id, code, name, pppoe_user, onu_port, rx_dbm, status): never phone,
 * NIK, address, coordinates or credentials.
 */
class OutageShadowEpisodeReadModel
{
    public const TZ = 'Asia/Jakarta';

    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 31;

    public const PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /** Customers shown inline per episode; the rest load on expand. */
    public const INLINE_CUSTOMERS = 10;

    /** Unresolved episodes open longer than this are pinned on top of the list. */
    public const PIN_AFTER_HOURS = 24;

    public const PIN_STATES = [OutageSignal::STATE_OPEN, OutageSignal::STATE_CONFIRMED];

    /** Never pinned: low Rx lasts for days by nature and would flood the pinned block. Only real faults pin. */
    public const PIN_EXCLUDED_TYPES = ['weak_rx'];

    /**
     * SQL + bindings of "this row is pinned" (unresolved, open > PIN_AFTER_HOURS, not an excluded type).
     * One definition for ORDER BY and for the date-window bypass, so they cannot drift apart.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function pinCondition(CarbonInterface $now): array
    {
        $states = implode(',', array_fill(0, count(self::PIN_STATES), '?'));
        $types = implode(',', array_fill(0, count(self::PIN_EXCLUDED_TYPES), '?'));
        $cutoff = $now->copy()->subHours(self::PIN_AFTER_HOURS)->utc()->format('Y-m-d H:i:s');

        return [
            "(state IN ($states) AND signal_type NOT IN ($types) AND opened_at < ?)",
            [...self::PIN_STATES, ...self::PIN_EXCLUDED_TYPES, $cutoff],
        ];
    }

    private const CHUNK = 500;

    /** Whole days an unresolved episode has been open when it is pinned (>= 1), else null. */
    public static function pinnedAgeDays(OutageSignal $s, CarbonInterface $now): ?int
    {
        if (! in_array($s->state, self::PIN_STATES, true) || in_array($s->signal_type, self::PIN_EXCLUDED_TYPES, true) || $s->opened_at === null) {
            return null;
        }
        $seconds = $now->getTimestamp() - $s->opened_at->getTimestamp();
        if ($seconds <= self::PIN_AFTER_HOURS * 3600) {
            return null;
        }

        return max(1, intdiv($seconds, 86400));
    }

    public const TYPE_FILTERS = ['mass_los', 'single_los', 'down_unknown', 'weak_rx', 'pppoe_down', 'session_burst', 'area_power', 'data_blind', 'lainnya'];

    /** Status tabs of the page. Default (no param, no old `state`) = perlu. */
    public const VIEW_FILTERS = ['perlu', 'sedang', 'selesai', 'semua'];

    public const DEFAULT_VIEW = 'perlu';

    /** Search: shorter terms are ignored, a search without explicit dates looks back 31 days. */
    public const MIN_SEARCH = 2;

    public const MAX_SEARCH_CUSTOMERS = 50;

    /** "Info" group, hidden unless `info=1`: not a fault someone has to chase. */
    public const INFO_TYPES = ['weak_rx', 'data_blind'];

    public const INFO_CAUSES = ['isolir_admin', 'not_fault'];

    private const OPEN_STATES = [OutageSignal::STATE_OPEN, OutageSignal::STATE_CONFIRMED];

    private const CLOSED_STATES = [OutageSignal::STATE_RESOLVED, OutageSignal::STATE_EXPIRED];

    /** Page-only words (plain Indonesian). TYPE_LABELS below stays as is: tickets and the report group text use it. */
    public const PAGE_TYPE_LABELS = [
        'mass_los' => 'Putus massal',
        'olt_wide' => 'Satu OLT mati',
        'single_los' => 'Putus 1 rumah',
        'down_unknown' => 'Offline, sebab belum jelas',
        'pppoe_down' => 'Internet putus, modem nyala',
        'session_burst' => 'Banyak putus bersamaan',
        'area_power' => 'Dugaan listrik padam (area)',
        'dying_gasp' => 'Listrik rumah mati',
        'weak_rx' => 'Sinyal redup',
        'data_blind' => 'Data tidak terbaca',
    ];

    public const PAGE_STATE_LABELS = [
        'open' => 'Baru',
        'confirmed' => 'Masih putus',
        'resolved' => 'Sudah normal',
        'expired' => 'Ditutup otomatis',
    ];

    /** Short chip names (filter values of TYPE_FILTERS). */
    public const CHIP_LABELS = [
        'mass_los' => 'Putus massal',
        'single_los' => 'Putus 1 rumah',
        'down_unknown' => 'Offline belum jelas',
        'pppoe_down' => 'Internet putus',
        'session_burst' => 'Banyak putus bersamaan',
        'area_power' => 'Dugaan listrik padam',
        'weak_rx' => 'Sinyal redup',
        'data_blind' => 'Data tidak terbaca',
        'lainnya' => 'Lainnya',
    ];

    public const BLIND_LABELS = [
        'router_offline' => 'Router tidak terbaca',
        'snapshot_stale' => 'Data PPPoE router lama',
        'health_stale' => 'Cek router terlambat',
        'collector_failed' => 'Ambil data OLT gagal',
        'collector_stale' => 'Data OLT lama',
        'no_run' => 'Data OLT belum pernah masuk',
        'traffic_gap' => 'Pembacaan trafik router putus-putus',
    ];

    public const STATE_FILTERS = ['open', 'confirmed', 'resolved', 'expired', 'suppressed'];

    public const TYPE_LABELS = [
        'mass_los' => 'LOS massal',
        'single_los' => 'LOS individual',
        'weak_rx' => 'Redaman lemah',
        'pppoe_down' => 'PPPoE mati',
        'dying_gasp' => 'Dying gasp',
        'olt_wide' => 'Satu OLT',
        'data_blind' => 'Data tidak terbaca',
        'down_unknown' => 'Offline tanpa sebab',
        'session_burst' => 'Sesi putus bersamaan',
        'area_power' => 'Kemungkinan listrik padam',
    ];

    public const STATE_LABELS = [
        'open' => 'Terbuka',
        'confirmed' => 'Terkonfirmasi',
        'resolved' => 'Selesai',
        'expired' => 'Kedaluwarsa',
    ];

    public const SUPPRESSION_LABELS = [
        'isolated' => 'Pelanggan terisolir',
        'open_ticket' => 'Sudah ada tiket terbuka',
        'migration' => 'Sedang migrasi',
        'psb' => 'Pemasangan baru (PSB)',
        'recovery' => 'Perangkat sedang dipulihkan',
        'olt_write' => 'Pekerjaan tulis di OLT',
        'maintenance' => 'Maintenance terjadwal',
        'stale' => 'Data OLT kedaluwarsa',
        'inside_mass' => 'Bagian dari gangguan massal',
        'quiet_hours' => 'Jam tenang',
        'cap' => 'Batas notifikasi tercapai',
        'power_off' => 'Listrik mati (bukan tiket)',
        'never_registered' => 'Belum pernah online',
    ];

    public const TICKET_STATUS_LABELS = [
        'open' => 'Terbuka',
        'assigned' => 'Ditugaskan',
        'in_progress' => 'Dikerjakan',
        'pending' => 'Menunggu',
        'resolved' => 'Selesai',
        'closed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ];

    public const HISTORY_LIMIT = 20;

    public const HISTORY_DAYS = 7;

    private const ONU_PHASE_TEXT = [
        'online' => 'ONU online',
        'los' => 'ONU putus (kabel/LOS)',
        'dying_gasp' => 'ONU mati (listrik)',
        'offline' => 'ONU offline',
    ];

    private const CPE_TEXT = [
        'boot' => 'Router/ONU restart',
        'uptime_reset' => 'Uptime kembali ke 0 (restart)',
        'wan_down' => 'WAN putus',
        'wan_up' => 'WAN nyambung',
    ];

    private const PPPOE_TEXT = [
        'connected' => 'PPPoE tersambung',
        'disconnected' => 'PPPoE putus',
        'auth_failed' => 'Gagal login PPPoE',
        'stuck' => 'Sesi nyangkut',
        'other' => 'PPPoE lain',
    ];

    /**
     * "Riwayat" of one episode's customer: the last events from the three RCA-2 history tables, newest first.
     * Read-only, bounded (7 days, 20 rows). No phone / address / credentials.
     *
     * @return list<array{at:string,kind:string,text:string}>
     */
    public function history(OutageSignal $s): array
    {
        if ($s->customer_id === null) {
            return [];
        }
        $cid = (int) $s->customer_id;
        $since = now()->subDays(self::HISTORY_DAYS)->utc()->format('Y-m-d H:i:s');
        $rows = [];

        if (Schema::hasTable('onu_state_events')) {
            foreach (DB::table('onu_state_events')->where('customer_id', $cid)->where('occurred_at', '>=', $since)->orderByDesc('occurred_at')->limit(self::HISTORY_LIMIT)->get() as $e) {
                $text = self::ONU_PHASE_TEXT[$e->to_phase] ?? 'ONU berubah';
                if ($e->cause === 'never_registered') {
                    $text = 'Belum pernah online';
                } elseif ($e->cause !== null && $e->cause !== $e->to_phase && OutageCauseText::label($e->cause) !== null) {
                    $text .= ' — '.OutageCauseText::label($e->cause);
                }
                $rows[] = ['at' => Carbon::parse($e->occurred_at, 'UTC')->toIso8601String(), 'kind' => 'onu', 'text' => $text];
            }
        }
        if (Schema::hasTable('cpe_events')) {
            foreach (DB::table('cpe_events')->where('customer_id', $cid)->where('at', '>=', $since)->orderByDesc('at')->limit(self::HISTORY_LIMIT)->get() as $e) {
                $rows[] = ['at' => Carbon::parse($e->at, 'UTC')->toIso8601String(), 'kind' => 'cpe', 'text' => self::CPE_TEXT[$e->type] ?? 'Kejadian perangkat'];
            }
        }
        if (Schema::hasTable('pppoe_session_events')) {
            foreach (DB::table('pppoe_session_events')->where('customer_id', $cid)->where('at', '>=', $since)->orderByDesc('at')->limit(self::HISTORY_LIMIT)->get() as $e) {
                $text = self::PPPOE_TEXT[$e->event] ?? 'PPPoE lain';
                if ($e->reason !== null && $e->reason !== '') {
                    $text .= ' — '.mb_substr((string) $e->reason, 0, 80);
                }
                $rows[] = ['at' => Carbon::parse($e->at, 'UTC')->toIso8601String(), 'kind' => 'pppoe', 'text' => $text];
            }
        }

        usort($rows, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return array_slice($rows, 0, self::HISTORY_LIMIT);
    }

    /**
     * Normalise raw query input into a safe filter set. Dates are WIB calendar days; the span is
     * clamped to MAX_DAYS ending at "to". An explicit episode id ignores the date range.
     *
     * @param  array<string, mixed>  $in
     * @return array{from: string, to: string, olt_id: ?int, type: ?string, state: ?string, episode: ?int, per_page: int, q: ?string, view: string, info: bool, hours?: ?int}
     */
    public static function normalizeFilters(array $in): array
    {
        $q = trim((string) ($in['q'] ?? ''));
        $q = mb_strlen($q) >= self::MIN_SEARCH ? mb_substr($q, 0, 64) : null;
        $episode = ! empty($in['episode']) ? (int) $in['episode'] : null;
        // "#123" / "kejadian 123" = open that episode (same as the episode link)
        if ($q !== null && preg_match('/^(?:#|kejadian\s*#?)\s*(\d{1,12})$/iu', $q, $m) === 1) {
            $episode = (int) $m[1];
        }

        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $to = ! empty($in['to']) ? CarbonImmutable::createFromFormat('Y-m-d', (string) $in['to'], self::TZ)->startOfDay() : $today;
        // A search without picked dates looks back 31 days (an older episode of the customer must not hide).
        $days = $q !== null && empty($in['from']) && empty($in['to']) ? self::SEARCH_DAYS : self::DEFAULT_DAYS;
        $from = ! empty($in['from']) ? CarbonImmutable::createFromFormat('Y-m-d', (string) $in['from'], self::TZ)->startOfDay() : $to->subDays($days - 1);
        if ($from->gt($to)) {
            $from = $to;
        }
        if ($from->diffInDays($to) > self::MAX_DAYS - 1) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        $type = ! empty($in['type']) ? (string) $in['type'] : null;
        $state = ! empty($in['state']) ? (string) $in['state'] : null;
        // Old links (`state=...`) keep meaning "all tabs"; otherwise the default tab is "perlu".
        $view = in_array($in['view'] ?? null, self::VIEW_FILTERS, true) ? (string) $in['view'] : ($state !== null ? 'semua' : self::DEFAULT_VIEW);
        // Asking for an info type / "ditahan" explicitly (old links) shows it without the toggle.
        $info = filter_var($in['info'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || in_array($type, self::INFO_TYPES, true) || $state === 'suppressed';

        return [
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'olt_id' => ! empty($in['olt_id']) ? (int) $in['olt_id'] : null,
            'type' => $type,
            'state' => $state,
            'episode' => $episode,
            'per_page' => min(self::MAX_PER_PAGE, max(10, (int) ($in['per_page'] ?? self::PER_PAGE))),
            'q' => $q,
            'view' => $view,
            'info' => $info,
            // "24 jam" preset: really the last 24 hours (the date window alone would be yesterday + today)
            'hours' => (int) ($in['hours'] ?? 0) === self::LAST_HOURS ? self::LAST_HOURS : null,
        ];
    }

    public const SEARCH_DAYS = 31;

    /** The only accepted `hours` value: the "24 jam" preset. */
    public const LAST_HOURS = 24;

    /**
     * @param  array{from: string, to: string, olt_id: ?int, type: ?string, state: ?string, episode: ?int, per_page: int, q: ?string, view: string, info: bool, hours?: ?int}  $f
     * @return array<string, mixed>
     */
    public function page(array $f, ?string $role = null): array
    {
        // Unresolved episodes open longer than PIN_AFTER_HOURS come first (oldest first), on every page:
        // done in ORDER BY so pagination never repeats or skips rows. The rest keep newest-first.
        // Pinned rows also bypass the date window (see scope()); every other filter still applies to them.
        $now = now();
        [$pinSql, $pinBind] = self::pinCondition($now);
        $search = $f['episode'] === null ? $this->resolveSearch($f['q']) : null;
        $paginator = $this->query($f, $now, $search)
            ->orderByRaw("CASE WHEN $pinSql THEN 0 ELSE 1 END", $pinBind)
            ->orderByRaw("CASE WHEN $pinSql THEN opened_at END ASC", $pinBind)
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($f['per_page']);
        /** @var LengthAwarePaginator $paginator */
        $signals = $paginator->getCollection();

        $oltNames = Olt::query()->pluck('name', 'id');
        $areaIds = $signals->where('signal_type', 'area_power')->pluck('area_id')->filter()->unique()->values()->all();
        $areaNames = $areaIds === [] ? [] : DB::table('areas')->whereIn('id', $areaIds)->pluck('name', 'id')->all();

        // One Customer query for every inline customer on the page.
        $inlineIds = [];
        foreach ($signals as $s) {
            $inlineIds[$s->id] = array_slice(self::customerIds($s), 0, self::INLINE_CUSTOMERS);
        }
        $customers = $this->loadCustomers(array_values(array_unique(array_merge([], ...array_values($inlineIds)))));

        // "+N lainnya" must match what the expand call lists, which skips soft-deleted customers: count only
        // the live ids beyond the inline ones (one query for the whole page).
        $extraIds = [];
        foreach ($signals as $s) {
            $extraIds[$s->id] = array_slice(self::customerIds($s), self::INLINE_CUSTOMERS);
        }
        $flat = array_values(array_unique(array_merge([], ...array_values($extraIds))));
        $live = $flat === [] ? [] : array_flip(Customer::query()->whereIn('id', $flat)->pluck('id')->all());
        $rx = $this->rxByCustomer($customers);
        [$tickets, $customerOpen, $reportWa] = $this->ticketLinks($signals);

        $rows = [];
        foreach ($signals as $s) {
            $ids = self::customerIds($s);
            $inline = [];
            foreach ($inlineIds[$s->id] as $cid) {
                if (isset($customers[$cid])) {
                    $inline[] = $this->customerRow($customers[$cid], $s, $rx);
                }
            }
            $listable = count(array_filter($extraIds[$s->id], fn ($id) => isset($live[$id])));
            $payload = (array) $s->payload;
            $downUnknown = $s->signal_type === 'single_los' && ($payload['cause'] ?? null) === 'down_unknown';
            $ticket = $s->ticket_id !== null ? ($tickets[(int) $s->ticket_id] ?? null) : null;
            $openOther = $ticket === null && $s->customer_id !== null ? ($customerOpen[(int) $s->customer_id] ?? null) : null;
            $kind = OutageTicketEvidence::kind($s);
            $subject = $s->customer_id !== null ? ($customers[(int) $s->customer_id] ?? null) : null;
            $canTicket = $ticket === null && $openOther === null && $kind !== null
                && in_array($s->state, [OutageSignal::STATE_OPEN, OutageSignal::STATE_CONFIRMED], true)
                && ($kind === 'mass' || ($subject !== null && ! in_array((string) $subject->status, OutageManualTicketService::BLOCKED_CUSTOMER_STATUSES, true)));
            $rows[] = [
                'id' => $s->id,
                'signal_type' => $s->signal_type,
                'type_label' => self::PAGE_TYPE_LABELS[$downUnknown ? 'down_unknown' : $s->signal_type] ?? $s->signal_type,
                'detail' => $s->signal_type === 'data_blind'
                    ? trim((string) (self::BLIND_LABELS[(string) ($payload['reason'] ?? '')] ?? '').(($payload['kind'] ?? '') === 'router' ? ' — router '.($payload['router_name'] ?? '') : ''))
                    : self::evidenceDetail($s, $payload, $areaNames),
                'cause_code' => $s->cause_code,
                'cause_label' => $s->cause_code === null ? null : OutageRootCause::labelFor($s->cause_code),
                // RCA-2: what the detector found out about the cause (OLT read, PPPoE log), plain text.
                'cause_text' => OutageCauseText::forPayload($payload)['label'] ?? null,
                // RCA-4: the stored automatic diagnosis of the episode ("Dugaan sistem"); null before the migration.
                'diagnosed_label' => $s->diagnosed_cause === null ? null : OutageRootCause::labelFor((string) $s->diagnosed_cause),
                'history_available' => $s->customer_id !== null,
                'olt_id' => $s->olt_id,
                'olt_name' => $s->olt_id !== null ? ($oltNames[$s->olt_id] ?? 'OLT #'.$s->olt_id) : null,
                'pon_port' => $s->pon_port,
                'odp_label' => $s->odp_label,
                'state' => $s->state,
                'state_label' => self::PAGE_STATE_LABELS[$s->state] ?? $s->state,
                'suppressed_reason' => $s->suppressed_reason,
                'suppressed_label' => $s->suppressed_reason === null ? null : (self::SUPPRESSION_LABELS[$s->suppressed_reason] ?? $s->suppressed_reason),
                'would_action' => $s->would_action,
                'would_ticket' => in_array($s->would_action, OutageShadowReporter::WOULD_TICKET, true),
                'opened_at' => $s->opened_at?->toIso8601String(),
                'pinned_age_days' => self::pinnedAgeDays($s, $now),
                'confirmed_at' => $s->confirmed_at?->toIso8601String(),
                'resolved_at' => $s->resolved_at?->toIso8601String(),
                'affected_count' => (int) $s->affected_count,
                'customers' => $inline,
                // Search: the searched customers inside this episode (also when beyond the inline ten). No phone.
                'matched_customers' => $this->matchedIn($search, $ids),
                // Honest counts: "more" = ids the expand call can list; "unlisted" = counted in affected_count
                // but neither shown nor listable (no id stored, or the customer row is gone).
                'customers_more' => $listable,
                'customers_unlisted' => max(0, (int) $s->affected_count - count($inline) - $listable),
                // Manual "Buat tiket": the episode's ticket, an open ticket of its customer, whether the button applies.
                'ticket' => $ticket,
                'customer_open_ticket' => $openOther,
                'can_ticket' => $canTicket,
                'report_wa_status' => $s->ticket_id !== null ? ($reportWa[(int) $s->ticket_id] ?? null) : null,
            ];
        }

        return [
            'data' => $rows,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'path' => $paginator->path(),
            'links' => [],
            'filters' => $f,
            ...$this->summary($f, $now, $search),
            'search' => [
                'q' => $f['q'],
                'customers_found' => $search === null ? 0 : count($search['customers']),
                'too_many' => $search !== null && $search['too_many'],
            ],
            'olts' => $oltNames->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values()->all(),
            'mode_note' => 'Mode uji — tiket hanya dibuat manual',
            'manual_ticket' => [
                'enabled' => OutageSettings::manualTicketEnabled(),
                'allowed' => OutageSettings::manualTicketAllowedTo($role),
                'report_group_set' => (new ReportGroupRegistry)->sendableGroupJids() !== [],
            ],
            // RCA-1: what still suppresses detection (counts) and which collectors / routers are blind right now.
            'suppression' => $this->reporter()->suppressionStats(),
            'data_blind' => $this->reporter()->dataBlind(now()->subDay(), now())['open'],
            // RCA-2: OLT clocks that are far off (no NTP), measured by the cause reads.
            'olt_clock_warnings' => OltClock::warnings($oltNames->all()),
        ];
    }

    /**
     * PR-D rows (written by outage:session-bursts): one plain line, counts only. Never a fact about the power company.
     *
     * @param  array<string,mixed>  $payload
     * @param  array<int|string,mixed>  $areaNames
     */
    private static function evidenceDetail(OutageSignal $s, array $payload, array $areaNames): ?string
    {
        if ($s->signal_type === 'session_burst') {
            $down = $payload['onu_down_share'] ?? null;
            $onu = ! is_numeric($down) ? 'ONU belum bisa dicek' : ((float) $down >= 0.5 ? 'ONU ikut mati' : 'ONU tetap menyala');
            $back = (int) ($payload['reconnected'] ?? 0);

            return sprintf('%d pelanggan putus bersamaan, %s. %d sudah tersambung lagi.', (int) $s->affected_count, $onu, $back);
        }
        if ($s->signal_type === 'area_power') {
            $area = $s->area_id !== null ? ($areaNames[$s->area_id] ?? null) : null;

            return sprintf('Kemungkinan listrik padam di area %s: %d ONU mati bersamaan.', $area ?? 'ini', (int) ($payload['count'] ?? $s->affected_count));
        }

        return null;
    }

    /**
     * Ticket links of a page of episodes, three bounded whereIn reads (constant query count).
     *
     * @param  iterable<int, OutageSignal>  $signals
     * @return array{0: array<int, array<string,mixed>>, 1: array<int, array<string,mixed>>, 2: array<int, string>}
     */
    private function ticketLinks(iterable $signals): array
    {
        $ticketIds = [];
        $customerIds = [];
        foreach ($signals as $s) {
            if ($s->ticket_id !== null) {
                $ticketIds[(int) $s->ticket_id] = (int) $s->ticket_id;
            } elseif ($s->customer_id !== null) {
                $customerIds[(int) $s->customer_id] = (int) $s->customer_id;
            }
        }

        $tickets = [];
        if ($ticketIds !== []) {
            foreach (SupportTicket::query()->whereIn('id', array_values($ticketIds))->get(['id', 'code', 'status']) as $t) {
                $tickets[(int) $t->id] = [
                    'id' => (int) $t->id,
                    'code' => $t->code,
                    'status' => $t->status,
                    'status_label' => self::TICKET_STATUS_LABELS[$t->status] ?? (string) $t->status,
                    'url' => route('tickets.show', $t->id),
                ];
            }
        }

        $open = [];
        if ($customerIds !== []) {
            foreach (SupportTicket::query()->whereIn('customer_id', array_values($customerIds))->whereNotIn('status', OutageManualTicketService::FINAL_STATUSES)->orderBy('id')->get(['id', 'customer_id']) as $t) {
                $open[(int) $t->customer_id] ??= ['id' => (int) $t->id, 'url' => route('tickets.show', $t->id)];
            }
        }

        $wa = [];
        if ($ticketIds !== [] && Schema::hasTable('wa_report_deliveries')) {
            $keys = array_map(fn (int $id) => 'deteksi_ticket:'.$id, array_values($ticketIds));
            foreach (DB::table('wa_report_deliveries')->whereIn('report_key', $keys)->get(['report_key', 'status']) as $d) {
                $wa[(int) substr((string) $d->report_key, strlen('deteksi_ticket:'))] = (string) $d->status;
            }
        }

        return [$tickets, $open, $wa];
    }

    private function reporter(): OutageShadowReporter
    {
        return new OutageShadowReporter;
    }

    /**
     * Full customer list of one episode (expand), chunked so a mass episode stays one bounded read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function customers(OutageSignal $s): array
    {
        $out = [];
        foreach (array_chunk(self::customerIds($s), self::CHUNK) as $chunk) {
            $loaded = $this->loadCustomers($chunk);
            $rx = $this->rxByCustomer($loaded);
            foreach ($chunk as $cid) {
                if (isset($loaded[$cid])) {
                    $out[] = $this->customerRow($loaded[$cid], $s, $rx);
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array{customers: array<int, Customer>, too_many: bool}|null  $search
     */
    private function query(array $f, CarbonInterface $now, ?array $search): Builder
    {
        if ($f['episode'] !== null) {
            return OutageSignal::query()->whereKey($f['episode']);
        }

        $q = $this->scope($f, $now, $search, $f['info']);
        $this->applyView($q, $f['view']);
        $this->applyType($q, $f['type']);

        return $q;
    }

    /**
     * Everything except tab and chip: date window (pinned rows bypass it), OLT, old `state` param, search and the
     * info group. Counts, chips and the list are all built from this one definition, so they cannot drift apart.
     *
     * @param  array<string, mixed>  $f
     * @param  array{customers: array<int, Customer>, too_many: bool}|null  $search
     */
    private function scope(array $f, CarbonInterface $now, ?array $search, bool $withInfo): Builder
    {
        $q = OutageSignal::query();

        $from = CarbonImmutable::createFromFormat('Y-m-d', $f['from'], self::TZ)->startOfDay()->utc();
        $to = CarbonImmutable::createFromFormat('Y-m-d', $f['to'], self::TZ)->endOfDay()->utc();
        if (($f['hours'] ?? null) === self::LAST_HOURS) {
            $since = CarbonImmutable::instance($now)->utc()->subHours(self::LAST_HOURS);
            $from = $since->gt($from) ? $since : $from;
        }
        // Date window, except pinned rows (long-open real faults) which stay visible however old they are.
        [$pinSql, $pinBind] = self::pinCondition($now);
        $q->where(fn ($w) => $w->whereBetween('opened_at', [$from, $to])->orWhereRaw($pinSql, $pinBind));

        if ($f['olt_id'] !== null) {
            $q->where('olt_id', $f['olt_id']);
        }
        if ($f['state'] === 'suppressed') {
            $q->whereNotNull('suppressed_reason');
        } elseif ($f['state'] !== null) {
            $q->where('state', $f['state']);
        }
        if ($search !== null) {
            $this->applySearch($q, $search);
        }
        if (! $withInfo) {
            $q->whereRaw('NOT '.self::infoSql());
        }

        return $q;
    }

    private function applyType(Builder $q, ?string $type): void
    {
        if ($type === 'lainnya') {
            $q->whereIn('signal_type', ['olt_wide', 'dying_gasp']);
        } elseif ($type === 'down_unknown') {
            $q->where('signal_type', 'single_los')->where('payload->cause', 'down_unknown');
        } elseif ($type === 'single_los') {
            $q->where('signal_type', 'single_los')->where(fn ($w) => $w->whereNull('payload->cause')->orWhere('payload->cause', '!=', 'down_unknown'));
        } elseif ($type !== null) {
            $q->where('signal_type', $type);
        }
    }

    private function applyView(Builder $q, string $view): void
    {
        $open = 'state IN ('.self::inList(self::OPEN_STATES).')';
        if ($view === 'perlu') {
            $q->whereRaw($open.' AND NOT '.self::hasTicketSql());
        } elseif ($view === 'sedang') {
            $q->whereRaw($open.' AND '.self::hasTicketSql());
        } elseif ($view === 'selesai') {
            $q->whereIn('state', self::CLOSED_STATES);
        }
    }

    /** @param  list<string>  $values  trusted constants only (never user input) */
    private static function inList(array $values): string
    {
        return implode(',', array_map(fn (string $v) => "'".addslashes($v)."'", $values));
    }

    /** "Info" rows: not a fault someone has to chase (weak signal, blind data, held back, isolated / not a fault). */
    private static function infoSql(): string
    {
        return '(signal_type IN ('.self::inList(self::INFO_TYPES).') OR suppressed_reason IS NOT NULL OR COALESCE(diagnosed_cause, \'\') IN ('.self::inList(self::INFO_CAUSES).'))';
    }

    /**
     * "Someone is already on it": the episode's own ticket or an open ticket of its customer is not final.
     * Two EXISTS lookups on support_tickets primary key / customer_id foreign-key index.
     */
    private static function hasTicketSql(): string
    {
        $final = self::inList(OutageManualTicketService::FINAL_STATUSES);

        return '((ticket_id IS NOT NULL AND EXISTS (SELECT 1 FROM support_tickets st WHERE st.id = outage_signals.ticket_id AND st.status NOT IN ('.$final.')))'
            .' OR (customer_id IS NOT NULL AND EXISTS (SELECT 1 FROM support_tickets st2 WHERE st2.customer_id = outage_signals.customer_id AND st2.status NOT IN ('.$final.'))))';
    }

    /**
     * Customers matching the search box (same matcher as the global Ctrl+K search: escaped LIKE, name terms, code /
     * PPPoE prefix, phone tail, numeric id), live customers only, capped. Phone is only matched, never returned.
     *
     * @return array{customers: array<int, Customer>, too_many: bool}|null
     */
    private function resolveSearch(?string $q): ?array
    {
        if ($q === null) {
            return null;
        }
        $like = GlobalSearchService::escapeLike($q);
        $terms = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
        $phoneTail = null;
        if (preg_match('/^[\d\s\-\.+]+$/', $q) === 1) {
            $digits = preg_replace('/^(62|0)/', '', preg_replace('/\D/', '', $q) ?? '') ?? '';
            $phoneTail = strlen($digits) >= 4 ? GlobalSearchService::escapeLike($digits) : null;
        }

        $rows = Customer::query()
            ->select(['id', 'code', 'name', 'pppoe_user'])
            ->where(function ($w) use ($like, $terms, $q, $phoneTail) {
                $w->where('code', 'like', $like.'%')->orWhere('pppoe_user', 'like', $like.'%');
                if (ctype_digit($q) && strlen($q) <= 9) {
                    $w->orWhere('id', (int) $q);
                }
                if ($phoneTail !== null) {
                    $w->orWhere('phone', 'like', '%'.$phoneTail.'%');
                }
                $w->orWhere(function ($name) use ($terms) {
                    foreach ($terms as $term) {
                        $name->where('name', 'like', '%'.GlobalSearchService::escapeLike($term).'%');
                    }
                });
            })
            ->orderBy('id')
            ->limit(self::MAX_SEARCH_CUSTOMERS + 1)
            ->get();

        $tooMany = $rows->count() > self::MAX_SEARCH_CUSTOMERS;

        return ['customers' => $tooMany ? [] : $rows->keyBy('id')->all(), 'too_many' => $tooMany];
    }

    /**
     * Episodes of the found customers: as the subject, inside `affected_customer_ids` (mass episodes) or inside the
     * legacy `payload.affected_customer_ids`.
     *
     * @param  array{customers: array<int, Customer>, too_many: bool}  $search
     */
    private function applySearch(Builder $q, array $search): void
    {
        $ids = array_map('intval', array_keys($search['customers']));
        if ($ids === []) {
            $q->whereRaw('0 = 1');

            return;
        }
        $q->where(function ($w) use ($ids) {
            $w->whereIn('customer_id', $ids);
            foreach ($ids as $id) {
                $w->orWhereJsonContains('affected_customer_ids', $id)->orWhereJsonContains('payload->affected_customer_ids', $id);
            }
        });
    }

    /**
     * @param  array{customers: array<int, Customer>, too_many: bool}|null  $search
     * @param  array<int, int>  $episodeCustomerIds
     * @return list<array{id: int, code: ?string, name: string, pppoe_user: ?string}>
     */
    private function matchedIn(?array $search, array $episodeCustomerIds): array
    {
        if ($search === null || $search['customers'] === []) {
            return [];
        }
        $out = [];
        foreach ($episodeCustomerIds as $id) {
            $c = $search['customers'][$id] ?? null;
            if ($c !== null) {
                $out[] = ['id' => (int) $c->id, 'code' => $c->code, 'name' => (string) $c->name, 'pppoe_user' => $c->pppoe_user];
            }
        }

        return $out;
    }

    /**
     * Tab counts, chip counts, hidden-info count and the pinned total, all from the same scope as the list (ignoring
     * the tab / chip being counted). Four small count queries, zeros for a single-episode link.
     *
     * @param  array<string, mixed>  $f
     * @param  array{customers: array<int, Customer>, too_many: bool}|null  $search
     * @return array{counts: array<string, int>, facets: list<array{value: string, label: string, count: int, info: bool}>, info_count: int, pinned_total: int}
     */
    private function summary(array $f, CarbonInterface $now, ?array $search): array
    {
        $empty = ['counts' => ['perlu' => 0, 'sedang' => 0, 'selesai' => 0, 'semua' => 0], 'facets' => [], 'info_count' => 0, 'pinned_total' => 0];
        if ($f['episode'] !== null) {
            return $empty;
        }

        // 1) tabs: one conditional-aggregate query
        $open = 'state IN ('.self::inList(self::OPEN_STATES).')';
        $ticket = self::hasTicketSql();
        $row = $this->scope($f, $now, $search, $f['info'])->toBase()->selectRaw(
            "COUNT(*) AS semua, COALESCE(SUM(CASE WHEN $open AND NOT $ticket THEN 1 ELSE 0 END), 0) AS perlu,"
            ." COALESCE(SUM(CASE WHEN $open AND $ticket THEN 1 ELSE 0 END), 0) AS sedang,"
            .' COALESCE(SUM(CASE WHEN state IN ('.self::inList(self::CLOSED_STATES).') THEN 1 ELSE 0 END), 0) AS selesai'
        )->first();
        $empty['counts'] = [
            'perlu' => (int) ($row->perlu ?? 0),
            'sedang' => (int) ($row->sedang ?? 0),
            'selesai' => (int) ($row->selesai ?? 0),
            'semua' => (int) ($row->semua ?? 0),
        ];

        // 2) chips: rows per type inside the current tab (type itself not applied)
        $inTab = $this->scope($f, $now, $search, $f['info']);
        $this->applyView($inTab, $f['view']);
        $byType = (clone $inTab)->toBase()->selectRaw('signal_type, COUNT(*) AS n')->groupBy('signal_type')->pluck('n', 'signal_type');
        $unknown = (clone $inTab)->where('signal_type', 'single_los')->where('payload->cause', 'down_unknown')->count();
        $chips = [
            'mass_los' => (int) ($byType['mass_los'] ?? 0),
            'single_los' => max(0, (int) ($byType['single_los'] ?? 0) - $unknown),
            'down_unknown' => $unknown,
            'pppoe_down' => (int) ($byType['pppoe_down'] ?? 0),
            'session_burst' => (int) ($byType['session_burst'] ?? 0),
            'area_power' => (int) ($byType['area_power'] ?? 0),
            'lainnya' => (int) ($byType['olt_wide'] ?? 0) + (int) ($byType['dying_gasp'] ?? 0),
            'weak_rx' => (int) ($byType['weak_rx'] ?? 0),
            'data_blind' => (int) ($byType['data_blind'] ?? 0),
        ];
        foreach (self::TYPE_FILTERS as $value) {
            if ($chips[$value] > 0) {
                $empty['facets'][] = ['value' => $value, 'label' => self::CHIP_LABELS[$value], 'count' => $chips[$value], 'info' => in_array($value, self::INFO_TYPES, true)];
            }
        }

        // 3) info rows in this tab (whether shown or hidden), for the toggle label
        $infoScope = $this->scope($f, $now, $search, true);
        $this->applyView($infoScope, $f['view']);
        $empty['info_count'] = $infoScope->whereRaw(self::infoSql())->count();

        // 4) pinned rows under the full filter, so the band header is right when it spans pages
        [$pinSql, $pinBind] = self::pinCondition($now);
        $empty['pinned_total'] = $this->query($f, $now, $search)->whereRaw($pinSql, $pinBind)->count();

        return $empty;
    }

    /** @return array<int, int> */
    private static function customerIds(OutageSignal $s): array
    {
        $ids = [];
        if ($s->customer_id !== null) {
            $ids[] = (int) $s->customer_id;
        }
        $affected = $s->affected_customer_ids;
        if (! is_array($affected) || $affected === []) {
            $affected = (array) (($s->payload ?? [])['affected_customer_ids'] ?? []);
        }
        foreach ($affected as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, Customer>
     */
    private function loadCustomers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'pppoe_user', 'olt_id', 'olt_port_label', 'status'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Latest known Rx per customer from olt_onus, one query per OLT.
     *
     * @param  array<int, Customer>  $customers
     * @return array<int, array{dbm: float, at: ?string}|null>
     */
    private function rxByCustomer(array $customers): array
    {
        $refs = [];
        foreach ($customers as $c) {
            $ref = $c->olt_id !== null ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;
            if ($ref !== null) {
                $refs[(int) $c->olt_id][$ref] = true;
            }
        }

        $byRef = [];
        foreach ($refs as $oltId => $set) {
            $variants = [];
            foreach (array_keys($set) as $ref) {
                $variants[] = $ref;
                $variants[] = 'gpon-onu_'.$ref;
            }
            foreach (OltOnu::query()->where('olt_id', $oltId)->whereIn('onu_ref', $variants)->get(['onu_ref', 'rx_power_dbm', 'rx_collected_at']) as $onu) {
                $ref = OutageDetector::normalizeRef((string) $onu->onu_ref);
                if ($ref !== null && $onu->rx_power_dbm !== null) {
                    $byRef[$oltId][$ref] = ['dbm' => (float) $onu->rx_power_dbm, 'at' => $onu->rx_collected_at?->toIso8601String()];
                }
            }
        }

        $out = [];
        foreach ($customers as $c) {
            $ref = $c->olt_id !== null ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;
            $out[(int) $c->id] = $ref !== null ? ($byRef[(int) $c->olt_id][$ref] ?? null) : null;
        }

        return $out;
    }

    /**
     * @param  array<int, array{dbm: float, at: ?string}|null>  $rx
     * @return array<string, mixed>
     */
    private function customerRow(Customer $c, OutageSignal $s, array $rx): array
    {
        $rxDbm = $rx[(int) $c->id]['dbm'] ?? null;
        $rxAt = $rx[(int) $c->id]['at'] ?? null;
        if ($s->signal_type === 'weak_rx' && (int) $s->customer_id === (int) $c->id) {
            $p = (array) $s->payload;
            $own = $p['rx_dbm'] ?? $p['worst_dbm'] ?? null;
            if (is_numeric($own)) {
                $rxDbm = (float) $own;
                $rxAt = null; // the signal's own reading, not the live snapshot
            }
        }

        return [
            'id' => (int) $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'pppoe_user' => $c->pppoe_user,
            'onu_port' => $c->olt_port_label,
            'rx_dbm' => $rxDbm,
            'rx_collected_at' => $rxAt,
            'status' => $c->status,
        ];
    }
}
