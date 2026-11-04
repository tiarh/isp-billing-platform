<?php

namespace App\Services;

use App\Exceptions\CustomerTrafficChunkConflict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CustomerTrafficIngestService
{
    private const CURRENT_PREFIX = 'skynet:traffic:current:';

    private const ACTIVE_KEY = 'skynet:traffic:current:active';

    private const SUMMARY_KEY = 'skynet:traffic:current:summary';

    private const VERSION_TTL = 1800;

    public function __construct(private TrafficPppoeKeyService $keys) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function ingestCurrent(array $payload): array
    {
        $cycleId = trim((string) ($payload['cycle_id'] ?? ''));
        $routerId = (int) ($payload['router_id'] ?? 0);
        $chunkIndex = (int) ($payload['chunk_index'] ?? -1);
        $chunkCount = (int) ($payload['chunk_count'] ?? 0);
        $rows = array_values(array_filter((array) ($payload['rows'] ?? []), 'is_array'));
        $completeFleet = filter_var($payload['complete_fleet'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $scope = trim((string) ($payload['scope'] ?? ($completeFleet ? 'full_fleet' : '')));

        if (! $completeFleet && $scope !== 'router_canary') {
            throw new \InvalidArgumentException('Scope current partial tidak valid.');
        }

        $this->assertChunk($cycleId, $chunkIndex, $chunkCount, $rows);
        $entries = [];
        $unmapped = 0;
        foreach ($rows as $row) {
            $normalized = $this->normalizeCurrentRow($row, $routerId);
            if ($normalized === null) {
                $unmapped++;

                continue;
            }

            $owner = $this->keys->resolveForRouter($normalized['pppoe_key_hash'], $normalized['router_id']);
            if ($owner === null) {
                $unmapped++;

                continue;
            }

            $entries[(string) $owner['customer_id']] = array_merge($normalized, [
                'customer_id' => $owner['customer_id'],
                'router_id' => $normalized['router_id'],
            ]);
        }

        $redis = Redis::connection('cache');
        $key = self::CURRENT_PREFIX.$cycleId;
        $chunksKey = $key.':chunks';
        $checksumsKey = $key.':checksums';
        $metaKey = $key.':meta';
        $chunkChecksum = trim((string) ($payload['chunk_checksum'] ?? ''));

        if (! preg_match('/^[a-f0-9]{64}$/', $chunkChecksum)) {
            throw new \InvalidArgumentException('chunk_checksum tidak valid.');
        }
        $calculatedChunkChecksum = hash('sha256', $this->canonicalJson($rows));
        if (! hash_equals($calculatedChunkChecksum, $chunkChecksum)) {
            throw new \InvalidArgumentException('chunk_checksum tidak cocok dengan payload.');
        }

        $existingChecksum = $redis->hget($checksumsKey, (string) $chunkIndex);
        if ($existingChecksum !== false && $existingChecksum !== null) {
            if (! hash_equals((string) $existingChecksum, $chunkChecksum)) {
                throw new CustomerTrafficChunkConflict('Chunk index dipakai ulang dengan body berbeda.');
            }

            return [
                'cycle_id' => $cycleId,
                'chunk_index' => $chunkIndex,
                'accepted_rows' => (int) $redis->hget($metaKey, 'accepted_rows_'.$chunkIndex),
                'unmapped_rows' => (int) $redis->hget($metaKey, 'unmapped_rows_'.$chunkIndex),
                'complete' => false,
                'status' => 'duplicate_ack',
            ];
        }

        $redis->pipeline(function ($pipe) use ($entries, $key, $chunksKey, $checksumsKey, $metaKey, $chunkIndex, $chunkCount, $chunkChecksum, $payload, $unmapped, $completeFleet, $scope, $rows): void {
            foreach ($entries as $customerId => $entry) {
                $pipe->hset($key, $customerId, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            }
            $pipe->sadd($chunksKey, (string) $chunkIndex);
            $pipe->hset($checksumsKey, (string) $chunkIndex, $chunkChecksum);
            $pipe->hset($metaKey, 'chunk_count', (string) $chunkCount);
            $pipe->hset($metaKey, 'cycle_row_count', (string) max(0, (int) ($payload['cycle_row_count'] ?? 0)));
            $pipe->hset($metaKey, 'received_rows_'.$chunkIndex, (string) count($rows));
            $pipe->hset($metaKey, 'accepted_rows_'.$chunkIndex, (string) count($entries));
            $pipe->hset($metaKey, 'unmapped_rows_'.$chunkIndex, (string) $unmapped);
            $pipe->hset($metaKey, 'sampled_at_utc', (string) ($payload['sampled_at_utc'] ?? ''));
            $pipe->hset($metaKey, 'complete_fleet', $completeFleet ? '1' : '0');
            $pipe->hset($metaKey, 'scope', $scope);
            $pipe->expire($key, self::VERSION_TTL);
            $pipe->expire($chunksKey, self::VERSION_TTL);
            $pipe->expire($checksumsKey, self::VERSION_TTL);
            $pipe->expire($metaKey, self::VERSION_TTL);
        });

        $chunkIndexes = array_map('intval', (array) $redis->smembers($chunksKey));
        sort($chunkIndexes);
        $complete = count($chunkIndexes) === $chunkCount
            && $chunkIndexes === range(0, $chunkCount - 1);

        $result = [
            'cycle_id' => $cycleId,
            'chunk_index' => $chunkIndex,
            'accepted_rows' => count($entries),
            'unmapped_rows' => $unmapped,
            'complete' => false,
            'status' => 'accepted',
        ];

        if (! $complete) {
            return $result;
        }

        $checksums = (array) $redis->hgetall($checksumsKey);
        uksort($checksums, static fn (string $a, string $b): int => ((int) $a) <=> ((int) $b));
        $calculatedCycleChecksum = hash('sha256', implode('|', array_values($checksums)));
        $expectedCycleChecksum = trim((string) ($payload['cycle_checksum'] ?? ''));
        if (! hash_equals($expectedCycleChecksum, $calculatedCycleChecksum)) {
            return array_merge($result, [
                'status' => 'checksum_mismatch',
                'cycle_checksum' => $calculatedCycleChecksum,
            ]);
        }

        $meta = (array) $redis->hgetall($metaKey);
        $acceptedRowCount = 0;
        $unmappedRowCount = 0;
        $receivedRowCount = 0;
        foreach ($meta as $metaName => $metaValue) {
            if (str_starts_with((string) $metaName, 'received_rows_')) {
                $receivedRowCount += max(0, (int) $metaValue);
            }
            if (str_starts_with((string) $metaName, 'accepted_rows_')) {
                $acceptedRowCount += max(0, (int) $metaValue);
            }
            if (str_starts_with((string) $metaName, 'unmapped_rows_')) {
                $unmappedRowCount += max(0, (int) $metaValue);
            }
        }
        $hashLength = (int) $redis->hlen($key);
        $expectedCycleRows = max(0, (int) ($payload['cycle_row_count'] ?? 0));
        if ($hashLength !== $acceptedRowCount || $receivedRowCount !== $expectedCycleRows) {
            return array_merge($result, [
                'status' => 'row_count_mismatch',
                'hash_rows' => $hashLength,
                'cycle_rows' => $acceptedRowCount,
                'received_rows' => $receivedRowCount,
                'expected_rows' => $expectedCycleRows,
            ]);
        }

        $snapshot = (array) $redis->hgetall($key);
        $serializedBytes = $this->valuesBytes($snapshot);
        $summary = [
            'cycle_id' => $cycleId,
            'row_count' => $hashLength,
            'serialized_bytes' => $serializedBytes,
            'sampled_at_utc' => (string) ($payload['sampled_at_utc'] ?? ''),
            'unmapped_rows' => $unmappedRowCount,
            'complete_fleet' => $completeFleet,
            'scope' => $scope,
            'activated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
        $summaryJson = json_encode($summary, JSON_THROW_ON_ERROR);
        $activeCycle = trim((string) $redis->get(self::ACTIVE_KEY));
        $budget = (int) config('services.monitoring.traffic.current_cache_budget_bytes', 16777216);
        $currentBytes = $this->cycleBytes($redis, $cycleId);
        $activeBytes = $activeCycle !== '' && $activeCycle !== $cycleId
            ? $this->cycleBytes($redis, $activeCycle)
            : 0;
        $totalBytes = $currentBytes + $activeBytes + strlen($summaryJson);
        if ($totalBytes > $budget) {
            $redis->del($this->cycleKeys($cycleId));

            return array_merge($result, [
                'status' => 'cache_budget_exceeded',
                'serialized_bytes' => $serializedBytes,
                'total_bytes' => $totalBytes,
                'budget_bytes' => $budget,
            ]);
        }

        if (! $completeFleet) {
            return array_merge($result, [
                'complete' => true,
                'status' => 'partial_accepted',
                'summary' => $summary,
            ]);
        }

        $redis->setex(self::ACTIVE_KEY, self::VERSION_TTL, $cycleId);
        $redis->setex(self::SUMMARY_KEY, self::VERSION_TTL, $summaryJson);
        if ($activeCycle !== '' && $activeCycle !== $cycleId) {
            // The new pointer is active before the previous complete version
            // is removed. This keeps last-good available through the switch
            // while bounding Redis to the current transition pair.
            $redis->del($this->cycleKeys($activeCycle));
        }

        return array_merge($result, [
            'complete' => true,
            'status' => 'active',
            'summary' => $summary,
        ]);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function ingestDaily(array $payload): array
    {
        $batchId = trim((string) ($payload['batch_id'] ?? ''));
        $chunkIndex = (int) ($payload['chunk_index'] ?? -1);
        $chunkCount = (int) ($payload['chunk_count'] ?? 0);
        $trafficDate = trim((string) ($payload['traffic_date_wib'] ?? ''));
        $rows = array_values(array_filter((array) ($payload['rows'] ?? []), 'is_array'));
        $bodyHash = trim((string) ($payload['_body_hash'] ?? ''));
        $schemaVersion = trim((string) ($payload['schema_version'] ?? ''));

        $this->assertChunk($batchId, $chunkIndex, $chunkCount, $rows);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trafficDate)) {
            throw new \InvalidArgumentException('traffic_date_wib tidak valid.');
        }
        if (! preg_match('/^\d+$/', $schemaVersion)) {
            throw new \InvalidArgumentException('schema_version tidak valid.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $bodyHash)) {
            throw new \InvalidArgumentException('body_hash tidak valid.');
        }

        return DB::transaction(function () use ($batchId, $chunkIndex, $chunkCount, $trafficDate, $rows, $bodyHash, $schemaVersion): array {
            $existing = DB::table('customer_traffic_ingest_batches')
                ->where('batch_id', $batchId)
                ->where('chunk_index', $chunkIndex)
                ->first();
            if ($existing !== null) {
                if (! hash_equals((string) $existing->body_hash, $bodyHash)) {
                    throw new \RuntimeException('Batch/chunk id dipakai ulang dengan body berbeda.');
                }

                return [
                    'batch_id' => $batchId,
                    'chunk_index' => $chunkIndex,
                    'status' => 'duplicate_ack',
                    'accepted_rows' => (int) $existing->row_count,
                ];
            }

            // A collector may receive an HTTP timeout after the server committed
            // a chunk. It can then retry with a new batch id. The body hash is
            // the stable idempotency key for that exact chunk, preventing a
            // partial daily batch from being counted twice.
            $sameBody = DB::table('customer_traffic_ingest_batches')
                ->where('body_hash', $bodyHash)
                ->where('status', 'committed')
                ->first();
            if ($sameBody !== null) {
                return [
                    'batch_id' => $batchId,
                    'chunk_index' => $chunkIndex,
                    'status' => 'duplicate_ack',
                    'accepted_rows' => (int) $sameBody->row_count,
                    'deduplicated_from' => (string) $sameBody->batch_id,
                ];
            }

            $accepted = 0;
            $unmapped = 0;
            $now = now();
            $currentRows = [];
            foreach ($rows as $row) {
                $normalized = $this->normalizeDailyRow($row);
                if ($normalized === null) {
                    $unmapped++;

                    continue;
                }
                $owner = $this->keys->resolveForRouter($normalized['pppoe_key_hash'], $normalized['router_id']);
                if ($owner === null) {
                    $unmapped++;

                    continue;
                }

                $dailyKey = [
                    'traffic_date_wib' => $trafficDate,
                    'customer_id' => $owner['customer_id'],
                    'router_id' => $normalized['router_id'],
                ];
                $daily = DB::table('customer_traffic_daily')
                    ->where($dailyKey)
                    ->lockForUpdate()
                    ->first();
                $sampleCount = max(0, $normalized['sample_count']);
                $expectedCount = max(0, $normalized['expected_sample_count']);
                $download = max(0, $normalized['download_delta_bytes']);
                $upload = max(0, $normalized['upload_delta_bytes']);

                if ($daily === null) {
                    DB::table('customer_traffic_daily')->insert(array_merge($dailyKey, [
                        'download_bytes' => $download,
                        'upload_bytes' => $upload,
                        'sample_count' => $sampleCount,
                        'expected_sample_count' => $expectedCount,
                        'reset_count' => max(0, $normalized['reset_count']),
                        'gap_count' => max(0, $normalized['gap_count']),
                        'coverage_ratio' => $this->coverage($sampleCount, $expectedCount),
                        'quality' => $this->quality($normalized),
                        'source' => 'api_poll',
                        'first_sampled_at' => $normalized['first_sampled_at'],
                        'last_sampled_at' => $normalized['last_sampled_at'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]));
                } else {
                    $newSamples = (int) $daily->sample_count + $sampleCount;
                    $newExpected = (int) $daily->expected_sample_count + $expectedCount;
                    DB::table('customer_traffic_daily')->where('id', $daily->id)->update([
                        'download_bytes' => (int) $daily->download_bytes + $download,
                        'upload_bytes' => (int) $daily->upload_bytes + $upload,
                        'sample_count' => $newSamples,
                        'expected_sample_count' => $newExpected,
                        'reset_count' => (int) $daily->reset_count + max(0, $normalized['reset_count']),
                        'gap_count' => (int) $daily->gap_count + max(0, $normalized['gap_count']),
                        'coverage_ratio' => $this->coverage($newSamples, $newExpected),
                        'quality' => $this->quality($normalized, (string) $daily->quality),
                        'first_sampled_at' => $this->minTimestamp($daily->first_sampled_at, $normalized['first_sampled_at']),
                        'last_sampled_at' => $this->maxTimestamp($daily->last_sampled_at, $normalized['last_sampled_at']),
                        'updated_at' => $now,
                    ]);
                }

                $currentRows[] = [
                    'customer_id' => $owner['customer_id'],
                    'router_id' => $normalized['router_id'],
                    'pppoe_key_hash' => $normalized['pppoe_key_hash'],
                    'online' => $normalized['online'],
                    'session_hash' => $normalized['session_hash'],
                    'download_bps' => $normalized['download_bps'],
                    'upload_bps' => $normalized['upload_bps'],
                    'download_bytes_total' => $normalized['download_bytes_total'],
                    'upload_bytes_total' => $normalized['upload_bytes_total'],
                    'quality' => $normalized['quality'],
                    'source' => 'mikrotik_pppoe',
                    'sampled_at' => $normalized['sampled_at'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $accepted++;
            }

            if ($currentRows !== []) {
                DB::table('customer_traffic_current')->upsert(
                    $currentRows,
                    ['customer_id', 'router_id'],
                    ['pppoe_key_hash', 'online', 'session_hash', 'download_bps', 'upload_bps', 'download_bytes_total', 'upload_bytes_total', 'quality', 'source', 'sampled_at', 'updated_at'],
                );

                // Bounded 24h ring buffer per customer for the moving usage chart.
                $this->appendSeriesPoints($currentRows);
            }

            DB::table('customer_traffic_ingest_batches')->insert([
                'batch_id' => $batchId,
                'chunk_index' => $chunkIndex,
                'chunk_count' => $chunkCount,
                'schema_version' => $schemaVersion,
                'source' => (string) config('services.monitoring.traffic.ledger_source', 'collector_107'),
                'traffic_date_wib' => $trafficDate,
                'row_count' => $accepted,
                'body_hash' => $bodyHash,
                'status' => 'committed',
                'committed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'batch_id' => $batchId,
                'chunk_index' => $chunkIndex,
                'status' => 'committed',
                'accepted_rows' => $accepted,
                'unmapped_rows' => $unmapped,
            ];
            // One retry is part of the daily write contract. The transaction is
            // already bounded to one <=250-row chunk, so a single Laravel retry
            // is enough to recover a transient InnoDB deadlock without creating a
            // retry storm on the critical database.
        }, 2);
    }

    /**
     * Append one compact point per customer to a bounded Redis ring buffer
     * (~96 points ≈ 24h at the 15-minute daily-flush cadence; 26h TTL).
     * Best-effort: never fail ingest because the series write failed.
     *
     * @param  list<array<string, mixed>>  $currentRows
     */
    private function appendSeriesPoints(array $currentRows): void
    {
        try {
            $redis = Redis::connection('cache');
            $pipeline = $redis->pipeline();
            foreach ($currentRows as $row) {
                $key = 'traffic:series:'.$row['customer_id'].':'.$row['router_id'];
                $pipeline->lpush($key, json_encode([
                    't' => $row['sampled_at'] ?? now()->toIso8601String(),
                    'd' => (int) ($row['download_bps'] ?? 0),
                    'u' => (int) ($row['upload_bps'] ?? 0),
                ], JSON_UNESCAPED_SLASHES));
                $pipeline->ltrim($key, 0, 95);
                $pipeline->expire($key, 93600);
            }
            $pipeline->exec();
        } catch (\Throwable) {
            // Series is best-effort.
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    private function normalizeCurrentRow(array $row, int $routerId): ?array
    {
        $hash = strtolower(trim((string) ($row['pppoe_key_hash'] ?? '')));
        $rowRouterId = (int) ($row['router_id'] ?? 0);
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || $rowRouterId <= 0 || ($routerId > 0 && $rowRouterId !== $routerId)) {
            return null;
        }

        return [
            'pppoe_key_hash' => $hash,
            'router_id' => $rowRouterId,
            'online' => (bool) ($row['online'] ?? true),
            'session_hash' => $this->nullableHash($row['session_hash'] ?? null),
            'download_bps' => $this->nonNegativeInt($row['download_bps'] ?? 0),
            'upload_bps' => $this->nonNegativeInt($row['upload_bps'] ?? 0),
            'download_bytes_total' => $this->nullableInt($row['download_bytes_total'] ?? null),
            'upload_bytes_total' => $this->nullableInt($row['upload_bytes_total'] ?? null),
            'quality' => $this->qualityValue($row['quality'] ?? 'good'),
            'sampled_at' => $this->mysqlUtcTimestamp($row['sampled_at_utc'] ?? null),
        ];
    }

    /** @param array<string, mixed> $row @return array<string, mixed>|null */
    private function normalizeDailyRow(array $row): ?array
    {
        $hash = strtolower(trim((string) ($row['pppoe_key_hash'] ?? '')));
        $routerId = (int) ($row['router_id'] ?? 0);
        if (! preg_match('/^[a-f0-9]{64}$/', $hash) || $routerId <= 0) {
            return null;
        }

        return [
            'pppoe_key_hash' => $hash,
            'router_id' => $routerId,
            'online' => (bool) ($row['online'] ?? false),
            'session_hash' => $this->nullableHash($row['session_hash'] ?? null),
            'download_delta_bytes' => $this->nonNegativeInt($row['download_delta_bytes'] ?? 0),
            'upload_delta_bytes' => $this->nonNegativeInt($row['upload_delta_bytes'] ?? 0),
            'sample_count' => $this->nonNegativeInt($row['sample_count'] ?? 0),
            'expected_sample_count' => $this->nonNegativeInt($row['expected_sample_count'] ?? 0),
            'reset_count' => $this->nonNegativeInt($row['reset_count'] ?? 0),
            'gap_count' => $this->nonNegativeInt($row['gap_count'] ?? 0),
            'quality' => $this->qualityValue($row['quality'] ?? 'good'),
            'download_bps' => $this->nonNegativeInt($row['download_bps'] ?? 0),
            'upload_bps' => $this->nonNegativeInt($row['upload_bps'] ?? 0),
            'download_bytes_total' => $this->nullableInt($row['download_bytes_total'] ?? null),
            'upload_bytes_total' => $this->nullableInt($row['upload_bytes_total'] ?? null),
            'sampled_at' => $this->mysqlUtcTimestamp($row['sampled_at_utc'] ?? null),
            'first_sampled_at' => $this->mysqlUtcTimestamp($row['first_sampled_at_utc'] ?? ($row['sampled_at_utc'] ?? null)),
            'last_sampled_at' => $this->mysqlUtcTimestamp($row['last_sampled_at_utc'] ?? ($row['sampled_at_utc'] ?? null)),
        ];
    }

    private function mysqlUtcTimestamp(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            throw new \InvalidArgumentException('Timestamp trafik kosong.');
        }

        try {
            return CarbonImmutable::parse($raw)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            throw new \InvalidArgumentException('Timestamp trafik tidak valid.');
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertChunk(string $id, int $index, int $count, array $rows): void
    {
        if ($id === '' || strlen($id) > 128 || ! preg_match('/^[A-Za-z0-9_.:-]+$/', $id)) {
            throw new \InvalidArgumentException('batch/cycle id tidak valid.');
        }
        if ($count < 1 || $count > 1000 || $index < 0 || $index >= $count) {
            throw new \InvalidArgumentException('chunk index/count tidak valid.');
        }
        $maxRows = (int) config('services.monitoring.traffic.max_rows_per_chunk', 250);
        if (count($rows) > $maxRows) {
            throw new \InvalidArgumentException('Jumlah row melebihi batas chunk.');
        }
    }

    private function cycleBytes(mixed $redis, string $cycleId): int
    {
        $key = self::CURRENT_PREFIX.$cycleId;

        return $this->valuesBytes((array) $redis->hgetall($key))
            + $this->valuesBytes((array) $redis->hgetall($key.':checksums'))
            + $this->valuesBytes((array) $redis->hgetall($key.':meta'))
            + $this->valuesBytes((array) $redis->smembers($key.':chunks'));
    }

    /** @param mixed $redis @return list<string> */
    private function cycleKeys(string $cycleId): array
    {
        $key = self::CURRENT_PREFIX.$cycleId;

        return [$key, $key.':chunks', $key.':checksums', $key.':meta'];
    }

    /** @param array<int|string, mixed> $values */
    private function valuesBytes(array $values): int
    {
        $bytes = 0;
        foreach ($values as $value) {
            $bytes += strlen((string) $value);
        }

        return $bytes;
    }

    private function nonNegativeInt(mixed $value): int
    {
        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, min(PHP_INT_MAX, (int) $value));
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : $this->nonNegativeInt($value);
    }

    private function nullableHash(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return preg_match('/^[a-f0-9]{64}$/', $value) === 1 ? $value : null;
    }

    private function qualityValue(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['good', 'first_sample', 'reset', 'gap', 'stale', 'offline', 'unmapped'], true)
            ? $value
            : 'unmapped';
    }

    /** @param array<string, mixed> $row */
    private function quality(array $row, string $previous = 'good'): string
    {
        if (($row['gap_count'] ?? 0) > 0) {
            return 'gap';
        }
        if (($row['reset_count'] ?? 0) > 0) {
            return 'reset';
        }

        return $row['quality'] !== 'good' ? (string) $row['quality'] : $previous;
    }

    private function coverage(int $samples, int $expected): float
    {
        return round(min(1, $expected > 0 ? $samples / $expected : 0), 4);
    }

    private function minTimestamp(?string $old, string $new): ?string
    {
        if ($old === null || $old === '') {
            return $new !== '' ? $new : null;
        }

        return $new !== '' && strtotime($new) < strtotime($old) ? $new : $old;
    }

    private function maxTimestamp(?string $old, string $new): ?string
    {
        if ($old === null || $old === '') {
            return $new !== '' ? $new : null;
        }

        return $new !== '' && strtotime($new) > strtotime($old) ? $new : $old;
    }

    private function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(fn (mixed $item): string => $this->canonicalJson($item), $value)).']';
            }

            ksort($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string) $key, JSON_THROW_ON_ERROR).':'.$this->canonicalJson($item);
            }

            return '{'.implode(',', $parts).'}';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
