<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache-only list of OCR batches per PSB draft. A WhatsApp album reaches the
 * bot as one message per photo, and the photos can end up in separate OCR
 * batches. The post-OCR "KTP missing" decision asks this ledger whether another
 * batch of the same draft is still open. Every failure is fail-open: callers
 * then see "no siblings", which is the old behaviour.
 */
final class PsbOcrBatchLedger
{
    public const TTL_SECONDS = 900;

    public const STATE_OPEN = 'open';

    public const STATE_RETRY = 'retry';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    public const STATE_SKIPPED = 'skipped';

    public static function open(int $draftId, string $batchKey): void
    {
        self::write($draftId, $batchKey, self::STATE_OPEN);
    }

    public static function markRetry(int $draftId, string $batchKey): void
    {
        self::write($draftId, $batchKey, self::STATE_RETRY);
    }

    public static function close(int $draftId, string $batchKey, string $state = self::STATE_DONE): void
    {
        self::write($draftId, $batchKey, $state);
    }

    /**
     * Keys of other batches of this draft that are still open or waiting for a retry.
     *
     * @return list<string>
     */
    public static function openSiblings(int $draftId, string $exceptKey, int $maxAgeSeconds = 600): array
    {
        if ($draftId <= 0) {
            return [];
        }
        try {
            $entries = Cache::get(self::key($draftId));
            if (! is_array($entries)) {
                return [];
            }
            $now = now()->getTimestamp();
            $out = [];
            foreach ($entries as $key => $entry) {
                if ((string) $key === $exceptKey || ! is_array($entry)) {
                    continue;
                }
                if (! in_array($entry['state'] ?? '', [self::STATE_OPEN, self::STATE_RETRY], true)) {
                    continue;
                }
                if ($now - (int) ($entry['staged_at'] ?? 0) > $maxAgeSeconds) {
                    continue;
                }
                $out[] = (string) $key;
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('[PSB] OCR ledger read failed', ['draft' => $draftId, 'exception_class' => $e::class]);

            return [];
        }
    }

    private static function key(int $draftId): string
    {
        return 'psb:ocr-ledger:'.$draftId;
    }

    private static function write(int $draftId, string $batchKey, string $state): void
    {
        if ($draftId <= 0 || $batchKey === '') {
            return;
        }
        try {
            Cache::lock('psb:ocr-ledger-lock:'.$draftId, 5)->block(2, function () use ($draftId, $batchKey, $state): void {
                $now = now()->getTimestamp();
                $entries = Cache::get(self::key($draftId));
                $entries = is_array($entries) ? $entries : [];
                foreach ($entries as $key => $entry) {
                    if (! is_array($entry) || $now - (int) ($entry['staged_at'] ?? 0) > self::TTL_SECONDS * 4) {
                        unset($entries[$key]);
                    }
                }
                $previous = is_array($entries[$batchKey] ?? null) ? $entries[$batchKey] : [];
                $isClosed = ! in_array($state, [self::STATE_OPEN, self::STATE_RETRY], true);
                $entries[$batchKey] = [
                    'staged_at' => $state === self::STATE_OPEN ? $now : (int) ($previous['staged_at'] ?? $now),
                    'state' => $state,
                    'closed_at' => $isClosed ? $now : null,
                ];
                Cache::put(self::key($draftId), $entries, self::TTL_SECONDS);
            });
        } catch (Throwable $e) {
            Log::warning('[PSB] OCR ledger write failed', [
                'draft' => $draftId,
                'state' => $state,
                'exception_class' => $e::class,
            ]);
        }
    }
}
