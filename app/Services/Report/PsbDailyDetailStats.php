<?php

namespace App\Services\Report;

use App\Models\Area;
use App\Models\Customer;
use App\Models\PsbDraft;
use App\Services\PsbStarliteReportService;
use App\Support\OpsTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Counts of PSB drafts for the daily Skynet Report section "Draft PSB dan kendala".
 * Read-only: at most 5 bounded SELECTs on psb_drafts / customers / areas. Counts only: no names,
 * phones, NIK, draft ids or last_error text ever leave this class.
 */
class PsbDailyDetailStats
{
    /** Drafts older than this (days) are not "open" any more (old leftovers, not a daily matter). */
    public const OPEN_WINDOW_DAYS = 14;

    public const DEFAULT_STUCK_DAYS = 3;

    public const ROW_LIMIT = 2000;

    public const NO_AREA = 'Tanpa area';

    /** Open = not finished yet. correction_pending_review is a real prod status. */
    public const OPEN_STATUSES = [
        PsbDraft::STATUS_RECEIVED,
        PsbDraft::STATUS_WAITING_SN,
        PsbDraft::STATUS_PENDING_VLAN,
        PsbDraft::STATUS_SECRET_OK,
        PsbDraft::STATUS_CORRECTION_PENDING,
    ];

    private const STAGE_LABELS = [
        PsbDraft::STATUS_RECEIVED => 'Baru masuk',
        PsbDraft::STATUS_WAITING_SN => 'Tunggu SN',
        PsbDraft::STATUS_PENDING_VLAN => 'Tunggu VLAN',
        PsbDraft::STATUS_SECRET_OK => 'Tunggu OLT',
        PsbDraft::STATUS_CORRECTION_PENDING => 'Cek koreksi',
    ];

    /**
     * @param  CarbonImmutable  $day  reported WIB day
     * @param  CarbonImmutable|null  $now  "posisi" moment (default now)
     * @return array{
     *   masuk: array{total: int, jadi: int, batal: int},
     *   open: array{total: int, stages: array<string, int>, by_area: array<string, int>, stuck: int, stuck_days: int},
     *   failed: array{total: int, buckets: array<string, int>}
     * }
     */
    public function collect(CarbonImmutable $day, ?CarbonImmutable $now = null, int $stuckDays = self::DEFAULT_STUCK_DAYS): array
    {
        $now ??= OpsTime::now();
        $stuckDays = max(1, min(14, $stuckDays));
        [$from, $to] = OpsTime::dayBounds($day);

        return [
            'masuk' => $this->masuk($from, $to),
            'open' => $this->open($now, $stuckDays),
            'failed' => $this->failed($from, $to),
        ];
    }

    /**
     * @return array{total: int, jadi: int, batal: int}
     */
    private function masuk(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $drafts = PsbDraft::query()
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->limit(self::ROW_LIMIT)
            ->get(['id', 'status', 'activation_mode', 'pppoe_user', 'customer_id', 'meta']);
        $this->warnIfCapped('masuk', $drafts->count());

        $total = 0;
        $jadi = 0;
        $batal = 0;
        foreach ($drafts as $d) {
            if ($this->isMigration($d)) {
                continue;
            }
            $total++;
            if ($d->status === PsbDraft::STATUS_CANCELLED) {
                $batal++;
            } elseif (PsbStarliteReportService::isDraftSuccess($d) || $d->customer_id !== null) {
                $jadi++;
            }
        }

        return ['total' => $total, 'jadi' => $jadi, 'batal' => $batal];
    }

    /**
     * @return array{total: int, stages: array<string, int>, by_area: array<string, int>, stuck: int, stuck_days: int}
     */
    private function open(CarbonImmutable $now, int $stuckDays): array
    {
        $drafts = PsbDraft::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where('created_at', '>=', $now->subDays(self::OPEN_WINDOW_DAYS)->utc())
            ->orderBy('id')
            ->limit(self::ROW_LIMIT)
            ->get(['id', 'status', 'activation_mode', 'pppoe_user', 'customer_id', 'area_id', 'meta', 'created_at']);
        $this->warnIfCapped('open', $drafts->count());

        $open = [];
        foreach ($drafts as $d) {
            if ($this->isMigration($d) || PsbStarliteReportService::isDraftSuccess($d)) {
                continue;
            }
            $open[] = $d;
        }

        $stages = [];
        $byArea = [];
        $stuck = 0;
        if ($open !== []) {
            $customerAreas = $this->customerAreas($open);
            $areaNames = Area::query()->pluck('name', 'id')->all();
            $stuckBefore = $now->subDays($stuckDays);

            foreach ($open as $d) {
                $stage = self::STAGE_LABELS[$d->status] ?? $d->status;
                $stages[$stage] = ($stages[$stage] ?? 0) + 1;

                $areaId = $d->customer_id !== null && isset($customerAreas[(int) $d->customer_id])
                    ? $customerAreas[(int) $d->customer_id]
                    : $d->area_id;
                $label = $areaId !== null ? (string) ($areaNames[(int) $areaId] ?? self::NO_AREA) : self::NO_AREA;
                $byArea[$label] = ($byArea[$label] ?? 0) + 1;

                if ($d->created_at !== null && $d->created_at->lt($stuckBefore)) {
                    $stuck++;
                }
            }
            arsort($byArea);
            // Fixed stage order, only stages with drafts.
            $ordered = [];
            foreach (self::STAGE_LABELS as $label) {
                if (isset($stages[$label])) {
                    $ordered[$label] = $stages[$label];
                }
            }
            $stages = $ordered;
        }

        return ['total' => count($open), 'stages' => $stages, 'by_area' => $byArea, 'stuck' => $stuck, 'stuck_days' => $stuckDays];
    }

    /**
     * @return array{total: int, buckets: array<string, int>}
     */
    private function failed(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $drafts = PsbDraft::query()
            ->whereIn('status', [PsbDraft::STATUS_FAILED, PsbDraft::STATUS_TIMEOUT])
            ->whereBetween('updated_at', [$from, $to])
            ->orderBy('id')
            ->limit(self::ROW_LIMIT)
            ->get(['id', 'status', 'activation_mode', 'sn', 'olt_at', 'last_error', 'meta']);
        $this->warnIfCapped('failed', $drafts->count());

        $buckets = [];
        $total = 0;
        foreach ($drafts as $d) {
            if ($this->isMigration($d)) {
                continue;
            }
            $total++;
            $name = $this->failureBucket($d);
            $buckets[$name] = ($buckets[$name] ?? 0) + 1;
        }

        return ['total' => $total, 'buckets' => $buckets];
    }

    /** The row limit cuts silently (oldest ids kept): make a cut visible in the log. */
    private function warnIfCapped(string $part, int $rows): void
    {
        if ($rows >= self::ROW_LIMIT) {
            Log::warning('[REPORT-DAILY] psb detail row cap hit, counts may be too low', ['part' => $part, 'limit' => self::ROW_LIMIT]);
        }
    }

    /** From status + columns only. The last_error text is looked at for a prefix but never returned. */
    private function failureBucket(PsbDraft $d): string
    {
        if ($d->status === PsbDraft::STATUS_TIMEOUT) {
            return 'timeout SN';
        }
        if (str_starts_with((string) $d->last_error, 'Perlu area')) {
            return 'perlu area';
        }
        $mode = (string) ($d->activation_mode ?? '');
        if ($d->sn !== null && $d->sn !== '' && $d->olt_at === null && ($mode === '' || $mode === PsbDraft::MODE_FULL_OLT)) {
            return 'gagal di OLT';
        }

        return 'gagal lain';
    }

    /**
     * @param  list<PsbDraft>  $drafts
     * @return array<int, int> customer id => area id
     */
    private function customerAreas(array $drafts): array
    {
        $ids = [];
        foreach ($drafts as $d) {
            if ($d->customer_id !== null) {
                $ids[(int) $d->customer_id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Customer::query()->whereIn('id', array_keys($ids))->whereNotNull('area_id')->get(['id', 'area_id']) as $c) {
            $out[(int) $c->id] = (int) $c->area_id;
        }

        return $out;
    }

    /** Migration drafts are not PSB. */
    private function isMigration(PsbDraft $d): bool
    {
        $meta = is_array($d->meta) ? $d->meta : [];

        return ! empty($meta['migration_id']);
    }
}
