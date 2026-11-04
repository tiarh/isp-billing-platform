<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Encrypt JSON audit fields while tolerating legacy plaintext JSON rows.
 */
class SafeEncryptedJson implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $stored = (string) $value;
        $decoded = json_decode($stored, true);
        if (is_string($decoded)) {
            $stored = $decoded;
        }

        try {
            $json = Crypt::decryptString($stored);

            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $legacy = json_decode((string) $value, true);

            return $legacy ?? $value;
        }
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if ($value === null || $value === '') {
            return [$key => $value];
        }

        $json = json_encode($value, JSON_THROW_ON_ERROR);

        return [$key => json_encode(Crypt::encryptString($json), JSON_THROW_ON_ERROR)];
    }
}
