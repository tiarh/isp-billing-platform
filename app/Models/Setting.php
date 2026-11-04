<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'type', 'group', 'label'];

    /** Marks a value written by setSecret(); everything after it is Laravel ciphertext. */
    public const ENCRYPTED_PREFIX = 'enc:v1:';

    /**
     * Cached marker for "row does not exist". Laravel treats a cached null as
     * a miss, so a missing key used to hit the DB on every call (and the first
     * caller's $default was cached forever for everyone). Short TTL so a row
     * inserted outside Setting::set() still shows up within minutes.
     */
    private const MISSING = "\0setting:missing";

    private const MISSING_TTL_SECONDS = 300;

    /**
     * Per-request memo (HTTP only). One page calls Setting::get() 1-6 times
     * for the same handful of keys; each call was a Redis round-trip +
     * unserialize. Queue workers and artisan never memoize so long-running
     * processes keep reading live values (kill switches, PSB/migration flags).
     *
     * @var array<string, mixed>
     */
    private static array $requestMemo = [];

    private static ?bool $memoize = null;

    /**
     * Get a setting value by key
     */
    public static function get(string $key, $default = null)
    {
        $memoize = self::$memoize ?? ! app()->runningInConsole();

        if ($memoize && array_key_exists($key, self::$requestMemo)) {
            $cached = self::$requestMemo[$key];

            return $cached === self::MISSING ? $default : $cached;
        }

        $cacheKey = "setting.{$key}";
        $cached = Cache::get($cacheKey);

        if ($cached === null) {
            $setting = self::where('key', $key)->first();

            if (! $setting) {
                Cache::put($cacheKey, self::MISSING, self::MISSING_TTL_SECONDS);
                $cached = self::MISSING;
            } else {
                $cached = $setting->castValue($setting->value, $setting->type);
                // Same key/shape as before (rememberForever) so existing
                // Cache::forget("setting.{$key}") call sites keep working.
                Cache::forever($cacheKey, $cached);
            }
        }

        if ($memoize) {
            self::$requestMemo[$key] = $cached;
        }

        return $cached === self::MISSING ? $default : $cached;
    }

    /**
     * Drop the per-request memo (called by set(); tests may call it too).
     */
    public static function flushRequestMemo(): void
    {
        self::$requestMemo = [];
    }

    /**
     * Force memoization on/off regardless of the runtime (tests).
     */
    public static function memoizeForRequest(?bool $enabled): void
    {
        self::$memoize = $enabled;
        self::$requestMemo = [];
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, $value, $type = 'text', $group = 'general', $label = null)
    {
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value);
            $type = 'json';
        }

        self::updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'type' => $type,
                'group' => $group,
                'label' => $label ?? ucfirst(str_replace('_', ' ', $key)),
            ]
        );

        // Hapus cache di semua store yang dipakai (file/redis) biar worker queue & app sejalan
        unset(self::$requestMemo[$key]);
        Cache::forget("setting.{$key}");
        try {
            Cache::store()->forget("setting.{$key}");
        } catch (\Throwable) {
            // ignore
        }
    }

    /**
     * Store a credential encrypted at rest (DB and cache only ever hold the
     * ciphertext). Read it back with secret(). Empty/null clears the value.
     */
    public static function setSecret(string $key, ?string $value, string $group = 'general', ?string $label = null): void
    {
        $value = trim((string) $value);
        $stored = $value === '' ? '' : self::ENCRYPTED_PREFIX.Crypt::encryptString($value);

        self::set($key, $stored, 'encrypted', $group, $label);
    }

    /**
     * Decrypt a credential written by setSecret(). Legacy plaintext rows
     * (written before encryption existed) are returned as-is so existing keys
     * keep working until they are saved again. Undecryptable values (APP_KEY
     * rotated) read as '' instead of throwing.
     */
    public static function secret(string $key, string $default = ''): string
    {
        $raw = self::get($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        $raw = (string) $raw;
        if (! str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
            return trim($raw);
        }

        try {
            return Crypt::decryptString(substr($raw, strlen(self::ENCRYPTED_PREFIX)));
        } catch (DecryptException) {
            Log::warning('[Setting] encrypted value could not be decrypted', ['key' => $key]);

            return $default;
        }
    }

    /**
     * Cast value based on type
     */
    private function castValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                // Avoid PHP (bool)"0" === true
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return (int) $value !== 0;
                }
                $s = strtolower(trim((string) $value));

                return in_array($s, ['1', 'true', 'yes', 'on'], true);
            case 'int':
            case 'integer':
                return (int) $value;
            case 'json':
                return json_decode($value, true);
            default:
                return $value;
        }
    }
}
