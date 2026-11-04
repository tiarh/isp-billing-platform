<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AttendanceExportService
{
    public const TIMEZONE = 'Asia/Jakarta';

    public function verifyToken(Request $request): bool
    {
        $token = (string) config('services.attendance_export.token', '');
        if ($token === '') {
            return false;
        }

        $provided = (string) (
            $request->bearerToken()
            ?: $request->header('X-Attendance-Token', '')
            ?: $request->header('X-Attendance-Export-Token', '')
        );

        return $provided !== '' && hash_equals($token, $provided);
    }

    /**
     * @return array{
     *   date: string,
     *   timezone: string,
     *   from: string|null,
     *   to: string|null,
     *   count: int,
     *   unique_senders: int,
     *   attendances: array<int, array<string, mixed>>
     * }
     */
    public function export(Request $request): array
    {
        $perPage = min(max((int) $request->query('per_page', 200), 1), 500);
        $page = max((int) $request->query('page', 1), 1);

        [$from, $to, $dateLabel] = $this->resolveDateWindow($request);

        $query = $this->baseQuery($from, $to);
        $total = (clone $query)->count();

        $rows = $query
            ->orderBy('created_at')
            ->forPage($page, $perPage)
            ->get();

        $uniqueSenders = (clone $query)
            ->select('sender_name')
            ->distinct()
            ->count('sender_name');

        return [
            'date' => $dateLabel,
            'timezone' => self::TIMEZONE,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'count' => $rows->count(),
            'unique_senders' => $uniqueSenders,
            'attendances' => $rows->map(fn (Attendance $row) => $this->serialize($row))->values()->all(),
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolveDateWindow(Request $request): array
    {
        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        if ($fromParam || $toParam) {
            $from = Carbon::parse($fromParam ?: $toParam, self::TIMEZONE)->startOfDay();
            $to = Carbon::parse($toParam ?: $fromParam, self::TIMEZONE)->endOfDay();
            if ($from->gt($to)) {
                [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
            }

            $label = $from->isSameDay($to)
                ? $from->toDateString()
                : $from->toDateString().'..'.$to->toDateString();

            return [$from, $to, $label];
        }

        $date = (string) $request->query('date', now(self::TIMEZONE)->toDateString());
        $day = Carbon::parse($date, self::TIMEZONE);

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay(), $day->toDateString()];
    }

    protected function baseQuery(Carbon $from, Carbon $to): Builder
    {
        return Attendance::query()
            ->whereBetween('created_at', [
                $from->copy()->utc(),
                $to->copy()->utc(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serialize(Attendance $row): array
    {
        $checkedInAt = $row->created_at
            ? Carbon::parse($row->created_at)->timezone(self::TIMEZONE)
            : null;

        $photoPath = (string) ($row->photo_url ?? '');

        return array_filter([
            'id' => $row->id,
            'sender_name' => $row->sender_name,
            'caption' => $row->caption,
            'photo_path' => $photoPath !== '' ? $photoPath : null,
            'photo_url' => $photoPath !== '' ? $this->publicPhotoUrl($photoPath) : null,
            'checked_in_at' => $checkedInAt?->toIso8601String(),
            'message_id' => $row->message_id ?? null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    public function publicPhotoUrl(string $photoPath): string
    {
        if (filter_var($photoPath, FILTER_VALIDATE_URL)) {
            return $photoPath;
        }

        if (str_starts_with($photoPath, 'storage:')) {
            return Storage::disk(config('filesystems.default'))->url(substr($photoPath, 8));
        }

        $base = rtrim((string) config('services.attendance_export.drive_public_url', config('site.drive_public_url').'/files'), '/');
        $segments = array_map('rawurlencode', explode('/', ltrim($photoPath, '/')));

        return $base.'/'.implode('/', $segments);
    }
}
