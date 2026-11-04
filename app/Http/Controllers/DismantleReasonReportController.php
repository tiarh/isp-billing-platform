<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Support\AreaScope;
use App\Support\DismantleReason;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-only: count of Dismantle tickets per reason per month (WIB).
 * A ticket counts when type = dismantle or it carries a dismantle reason
 * (backfilled legacy tickets filed under another type).
 */
class DismantleReasonReportController extends Controller
{
    public const UNSET = 'unset';

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:24'],
        ]);
        $months = (int) ($validated['months'] ?? 12);

        return Inertia::render('Tickets/DismantleReasons', [
            'report' => $this->build($request, $months),
            'months' => $months,
        ]);
    }

    /**
     * @return array{reasons: list<array{value: string, label: string}>, rows: list<array{month: string, counts: array<string, int>, total: int}>, totals: array<string, int>, total: int}
     */
    public function build(Request $request, int $months): array
    {
        $tz = 'Asia/Jakarta';
        $start = CarbonImmutable::now($tz)->startOfMonth()->subMonths($months - 1);

        $query = SupportTicket::query()
            ->where(fn ($q) => $q->where('type', 'dismantle')->orWhereNotNull('dismantle_reason'))
            ->where('created_at', '>=', $start->utc());
        AreaScope::applyToTickets($query, $request->user());

        $reasons = [...DismantleReason::options(), ['value' => self::UNSET, 'label' => 'Belum diisi']];
        $zero = array_fill_keys(array_column($reasons, 'value'), 0);

        $rows = [];
        $totals = $zero;
        foreach ($query->get(['id', 'created_at', 'dismantle_reason']) as $ticket) {
            $month = CarbonImmutable::parse($ticket->created_at)->timezone($tz)->format('Y-m');
            $code = (string) $ticket->dismantle_reason;
            $reason = isset(DismantleReason::OPTIONS[$code]) ? $code : self::UNSET;
            $rows[$month] ??= $zero;
            $rows[$month][$reason]++;
            $totals[$reason]++;
        }
        krsort($rows);

        return [
            'reasons' => $reasons,
            'rows' => array_values(array_map(
                fn (string $month, array $counts): array => ['month' => $month, 'counts' => $counts, 'total' => array_sum($counts)],
                array_keys($rows),
                $rows,
            )),
            'totals' => $totals,
            'total' => array_sum($totals),
        ];
    }
}
