<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        $length = max(8, (int) config('totp.recovery_code_length', 16));
        $count = max(1, (int) config('totp.recovery_codes', 8));
        $codes = [];

        while (count($codes) < $count) {
            $code = substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
            if (! in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function codeForSecret(string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $period = max(1, (int) config('totp.period', 30));
        $counter = intdiv($timestamp, $period);
        $key = $this->base32Decode($secret);
        $message = pack('N2', (int) floor($counter / 4294967296), $counter % 4294967296);
        $hash = hash_hmac((string) config('totp.algorithm', 'SHA1'), $message, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $modulo = 10 ** (int) config('totp.digits', 6);

        return str_pad((string) ($binary % $modulo), (int) config('totp.digits', 6), '0', STR_PAD_LEFT);
    }

    public function verifyCode(string $secret, string $code, ?int $timestamp = null): ?int
    {
        $normalized = preg_replace('/\s+/', '', $code) ?? '';
        $digits = (int) config('totp.digits', 6);
        if (preg_match('/^\d{'.$digits.'}$/', $normalized) !== 1) {
            return null;
        }

        $timestamp ??= time();
        $period = max(1, (int) config('totp.period', 30));
        $currentCounter = intdiv($timestamp, $period);
        $drift = max(0, (int) config('totp.verification_drift', 1));

        for ($offset = -$drift; $offset <= $drift; $offset++) {
            $counter = $currentCounter + $offset;
            if ($counter >= 0 && hash_equals($this->codeForSecret($secret, $counter * $period), $normalized)) {
                return $counter;
            }
        }

        return null;
    }

    public function verifyForUser(User $user, string $code): bool
    {
        $secret = (string) ($user->totp_secret ?? '');
        if ($secret === '') {
            return false;
        }

        $counter = $this->verifyCode($secret, $code);
        if ($counter !== null) {
            $updated = User::query()
                ->whereKey($user->getKey())
                ->where(function ($query) use ($counter): void {
                    $query->whereNull('totp_last_used_counter')
                        ->orWhere('totp_last_used_counter', '<', $counter);
                })
                ->update(['totp_last_used_counter' => $counter]);

            return $updated === 1;
        }

        $remaining = [];
        $matched = false;
        foreach ((array) ($user->totp_recovery_codes ?? []) as $hashedCode) {
            if (! $matched && Hash::check($code, (string) $hashedCode)) {
                $matched = true;

                continue;
            }
            $remaining[] = $hashedCode;
        }

        if (! $matched) {
            return false;
        }

        $user->forceFill(['totp_recovery_codes' => $remaining])->saveQuietly();

        return true;
    }

    /** @param list<string> $recoveryCodes */
    public function enable(User $user, string $secret, array $recoveryCodes): void
    {
        if ($this->verifyCode($secret, $this->codeForSecret($secret)) === null) {
            throw new RuntimeException('TOTP secret tidak valid.');
        }

        $user->forceFill([
            'totp_secret' => $secret,
            'totp_recovery_codes' => array_map(static fn (string $code): string => Hash::make($code), $recoveryCodes),
            'totp_confirmed_at' => now(),
            'totp_last_used_counter' => null,
        ])->saveQuietly();
    }

    public function reset(User $user): void
    {
        $user->forceFill([
            'totp_secret' => null,
            'totp_recovery_codes' => null,
            'totp_confirmed_at' => null,
            'totp_last_used_counter' => null,
        ])->saveQuietly();
    }

    public function provisioningUri(string $account, string $secret): string
    {
        $issuer = (string) config('totp.issuer', 'Skynet');
        $label = rawurlencode($issuer.':'.$account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper((string) config('totp.algorithm', 'SHA1')),
            'digits' => (int) config('totp.digits', 6),
            'period' => (int) config('totp.period', 30),
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::BASE32_ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }

    private function base32Decode(string $secret): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret) ?? '');
        if ($normalized === '') {
            throw new RuntimeException('TOTP secret tidak valid.');
        }

        $bits = '';
        foreach (str_split($normalized) as $character) {
            $position = strpos(self::BASE32_ALPHABET, $character);
            if ($position === false) {
                throw new RuntimeException('TOTP secret tidak valid.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
