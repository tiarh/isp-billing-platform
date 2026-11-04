<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Rows-per-page chosen by the "Baris per halaman" select (query param `limit`).
 * Only allowed sizes are honoured; anything else falls back to the page default.
 */
final class PerPage
{
    public const ALLOWED = [10, 20, 25, 50, 100];

    /**
     * @param  list<int>  $allowed
     */
    public static function from(Request $request, int $default, array $allowed = self::ALLOWED, string $key = 'limit'): int
    {
        $raw = $request->input($key);

        if (! is_scalar($raw) || ! preg_match('/^\d+$/', (string) $raw)) {
            return $default;
        }

        $value = (int) $raw;

        return in_array($value, $allowed, true) || $value === $default ? $value : $default;
    }
}
