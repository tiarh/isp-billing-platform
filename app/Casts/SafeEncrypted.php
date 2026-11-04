<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Like Laravel's encrypted cast, but tolerates legacy plaintext values
 * (DecryptException "The payload is invalid.") instead of blowing up
 * OLT inventory / activitylog / any save path that touches the attribute.
 */
class SafeEncrypted implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Legacy plain text (e.g. scraper import "gondang") or wrong APP_KEY residue.
            return $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }

        if (! is_string($value)) {
            return [$key => Crypt::encryptString((string) $value)];
        }

        // Already a valid ciphertext — avoid double-encrypt when raw attrs are re-set.
        if (str_starts_with($value, 'eyJ')) {
            try {
                Crypt::decryptString($value);

                return [$key => $value];
            } catch (Throwable) {
                // Not valid ciphertext; encrypt as plaintext below.
            }
        }

        return [$key => Crypt::encryptString($value)];
    }
}
