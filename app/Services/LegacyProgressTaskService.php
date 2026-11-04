<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LegacyProgressTaskService
{
    /**
     * @return array{jobs: list<array<string, mixed>>, errors: list<string>, endpoints: array<string, string|null>}
     */
    public function fetchActiveTasks(Carbon|string|null $date = null): array
    {
        $workDate = $date instanceof Carbon
            ? $date
            : ($date ? Carbon::parse($date, 'Asia/Jakarta') : now('Asia/Jakarta'));

        $cacheKey = 'legacy_progress_tasks:'.$workDate->toDateString();

        return Cache::remember($cacheKey, now()->addMinutes(5), fn () => $this->fetchFresh($workDate));
    }

    /**
     * @return array{jobs: list<array<string, mixed>>, errors: list<string>, endpoints: array<string, string|null>}
     */
    private function fetchFresh(Carbon $workDate): array
    {

        $jobs = [];
        $errors = [];
        $endpoints = [];

        [$ticketRows, $ticketEndpoint, $ticketError] = $this->firstPayload([
            '/api/v1/tickets',
            '/api/v1/support-tickets',
            '/api/v1/customer-tickets',
        ]);
        $endpoints['tickets'] = $ticketEndpoint;
        if ($ticketError) {
            $errors[] = 'tickets: '.$ticketError;
        }
        foreach ($ticketRows as $row) {
            $job = $this->normalizeRow($row, 'legacy_ticket', 'maintenance', $workDate);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        [$psbRows, $psbEndpoint, $psbError] = $this->firstPayload([
            '/api/v1/psb',
            '/api/v1/psb-tasks',
            '/api/v1/installations',
            '/api/v1/activations',
        ]);
        $endpoints['psb'] = $psbEndpoint;
        if ($psbError) {
            $errors[] = 'psb: '.$psbError;
        }
        foreach ($psbRows as $row) {
            $job = $this->normalizeRow($row, 'legacy_psb', 'psb', $workDate);
            if ($job !== null) {
                $jobs[] = $job;
            }
        }

        return [
            'jobs' => array_values($jobs),
            'errors' => $errors,
            'endpoints' => $endpoints,
        ];
    }

    /**
     * @param  list<string>  $paths
     * @return array{0: list<array<string, mixed>>, 1: string|null, 2: string|null}
     */
    private function firstPayload(array $paths): array
    {
        $lastError = null;
        foreach ($this->baseCandidates() as $base) {
            foreach ($paths as $path) {
                $endpoint = $base.$path;
                try {
                    $response = Http::timeout($this->timeout())
                        ->connectTimeout(4)
                        ->acceptJson()
                        ->get($endpoint);

                    if (! $response->successful()) {
                        $lastError = 'HTTP '.$response->status().' '.$path;

                        continue;
                    }

                    $rows = $this->unwrapRows($response->json());
                    if ($rows !== []) {
                        return [$rows, $endpoint, null];
                    }

                    $lastError = 'empty '.$path;
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    Log::debug('[LegacyProgressTask] endpoint failed', [
                        'endpoint' => $endpoint,
                        'error' => $lastError,
                    ]);
                }
            }
        }

        return [[], null, $lastError ?: 'no legacy progress endpoint returned data'];
    }

    /**
     * @return list<string>
     */
    private function baseCandidates(): array
    {
        $candidates = [];
        $primary = rtrim((string) config('services.legacy_scraper.url', ''), '/');
        if ($primary !== '') {
            $candidates[] = $primary;
        }

        $fallbacks = config('services.legacy_scraper.fallback_urls', []);
        if (is_array($fallbacks)) {
            foreach ($fallbacks as $url) {
                $url = rtrim((string) $url, '/');
                if ($url !== '' && ! in_array($url, $candidates, true)) {
                    $candidates[] = $url;
                }
            }
        }

        foreach ((array) config('site.legacy_scraper.internal_urls') as $url) {
            if (! in_array($url, $candidates, true)) {
                $candidates[] = $url;
            }
        }

        return $candidates;
    }

    private function timeout(): int
    {
        return max(6, min(15, (int) config('services.legacy_scraper.timeout', 12)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unwrapRows(mixed $payload): array
    {
        if (! is_array($payload) || $payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['data', 'items', 'rows', 'tickets', 'psb', 'result', 'results'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $this->unwrapRows($payload[$key]);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row, string $source, string $fallbackType, Carbon $workDate): ?array
    {
        $status = Str::lower((string) $this->pick($row, ['status', 'state', 'progress', 'ticket_status']));
        if ($this->isClosedStatus($status)) {
            return null;
        }

        $customer = $this->pick($row, [
            'customer_name',
            'nama_pelanggan',
            'pelanggan',
            'name',
            'customer.name',
            'client.name',
        ]);
        $task = $this->pick($row, [
            'gangguan',
            'subject',
            'description',
            'keterangan',
            'task',
            'jenis_gangguan',
            'paket',
            'pppoe',
        ]);
        $site = $this->pick($row, [
            'site',
            'area',
            'area_name',
            'daerah',
            'lokasi',
            'wilayah',
            'customer.area.name',
            'customer.daerah',
        ]);
        $id = (string) $this->pick($row, ['code', 'ticket_code', 'id', 'uuid', 'no_tiket', 'nomor']);

        if (trim($customer.$task) === '') {
            return null;
        }

        return [
            'source' => $source,
            'source_ref' => $source.':'.($id !== '' ? $id : md5(json_encode($row) ?: serialize($row))),
            'task_type' => $this->normalizeType((string) $this->pick($row, ['type', 'jenis', 'category']), $fallbackType),
            'priority' => $this->normalizePriority((string) $this->pick($row, ['priority', 'prioritas', 'severity'])),
            'status' => $status !== '' ? $status : 'open',
            'customer_name' => Str::upper(Str::limit((string) $customer, 60, '')),
            'site' => Str::upper(Str::limit((string) $site, 80, '')),
            'task' => Str::upper(Str::limit((string) ($task ?: $fallbackType), 90, '')),
            'progress' => Str::upper(str_replace('_', ' ', $status ?: 'open')),
            'work_date' => $workDate->toDateString(),
            'raw' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     */
    private function pick(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = str_contains($key, '.') ? Arr::get($row, $key) : ($row[$key] ?? null);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return $value;
            }
        }

        return '';
    }

    private function isClosedStatus(string $status): bool
    {
        return in_array($status, [
            'closed',
            'close',
            'resolved',
            'done',
            'complete',
            'completed',
            'cancelled',
            'canceled',
            'applied',
            'aktif',
        ], true);
    }

    private function normalizeType(string $value, string $fallback): string
    {
        $value = Str::lower($value);
        if (Str::contains($value, ['psb', 'pasang', 'install', 'aktivasi'])) {
            return 'psb';
        }
        if (Str::contains($value, ['deploy', 'pembangunan', 'odp'])) {
            return 'pembangunan';
        }

        return $fallback;
    }

    private function normalizePriority(string $value): string
    {
        $value = Str::lower($value);

        return in_array($value, ['low', 'normal', 'high', 'urgent'], true) ? $value : 'normal';
    }
}
