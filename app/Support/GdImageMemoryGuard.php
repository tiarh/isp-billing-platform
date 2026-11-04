<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Memory check before any full-size GD decode in the OCR path.
 *
 * GD holds a decoded image as ~5 bytes per pixel (truecolor + overhead). A 12 MP
 * phone photo needs ~60 MB for the source plus ~30 MB for the resized canvas,
 * which exceeds PHP's default 128 MB limit and ends the process with a fatal
 * error that no try/catch can handle. Callers ask {@see decodeFits()} first and
 * fall back to a smaller copy (or the original bytes) when it says no.
 */
final class GdImageMemoryGuard
{
    /** Bytes GD needs per decoded JPEG pixel (estimate), and per resize-canvas pixel. */
    public const BYTES_PER_PIXEL = 5;

    /** PNG/WebP/GIF/BMP decoders keep a raw buffer next to the GD image. */
    public const BYTES_PER_PIXEL_NON_JPEG = 8;

    /** Spare memory kept for the JPEG encode buffer and the rest of the job. */
    public const SAFE_MARGIN_BYTES = 24 * 1024 * 1024;

    /** Never decode more than this many pixels with GD, whatever memory_limit says. */
    public const MAX_PIXELS = 24_000_000;

    /**
     * Estimated peak GD memory for decoding w x h and resizing to the target
     * width used by KtpOcrService (upscale below 1200 px, downscale above 2800 px).
     */
    public static function estimateBytes(int $w, int $h, bool $jpeg = true): int
    {
        $source = $w * $h * ($jpeg ? self::BYTES_PER_PIXEL : self::BYTES_PER_PIXEL_NON_JPEG);
        $target = 0;
        if ($w > 0 && $w < 1200) {
            $target = (int) round(1800 * (1800 / $w) * $h * self::BYTES_PER_PIXEL);
        } elseif ($w > 2800) {
            $target = (int) round(2200 * (2200 / $w) * $h * self::BYTES_PER_PIXEL);
        }

        return $source + $target;
    }

    /**
     * Bytes still free under memory_limit, or null when there is no limit.
     * $limitBytes / $usedBytes can be injected (tests).
     */
    public static function availableBytes(?int $limitBytes = null, ?int $usedBytes = null): ?int
    {
        $limit = $limitBytes ?? self::parseBytes((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return null;
        }

        return max(0, $limit - ($usedBytes ?? memory_get_usage(true)));
    }

    /**
     * True when decoding these bytes with GD is expected to fit in memory.
     * Unreadable dimensions return false (do not decode what cannot be sized).
     * Logs one warning, without any draft or customer data, when it says no
     * for a readable image.
     */
    public static function decodeFits(string $bytes, ?int $limitBytes = null, ?int $usedBytes = null): bool
    {
        $info = @getimagesizefromstring($bytes);
        if (! is_array($info) || ($info[0] ?? 0) <= 0 || ($info[1] ?? 0) <= 0) {
            return false;
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        $jpeg = ($info[2] ?? null) === IMAGETYPE_JPEG;

        $available = self::availableBytes($limitBytes, $usedBytes);
        $need = self::estimateBytes($w, $h, $jpeg) + self::SAFE_MARGIN_BYTES;
        $tooManyPixels = $w * $h > self::MAX_PIXELS;

        if (! $tooManyPixels && ($available === null || $need <= $available)) {
            return true;
        }

        Log::warning('OCR image too large for GD preprocess', [
            'width' => $w,
            'height' => $h,
            'bytes' => strlen($bytes),
            'estimate_bytes' => $need,
            'available_bytes' => $available,
            'over_pixel_cap' => $tooManyPixels,
        ]);

        return false;
    }

    /** True when the bytes have readable image dimensions. */
    public static function hasReadableSize(string $bytes): bool
    {
        $info = @getimagesizefromstring($bytes);

        return is_array($info) && ($info[0] ?? 0) > 0 && ($info[1] ?? 0) > 0;
    }

    /** Parse a php.ini size such as 128M, 1G, 512K or a plain byte count. -1 stays -1. */
    public static function parseBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
