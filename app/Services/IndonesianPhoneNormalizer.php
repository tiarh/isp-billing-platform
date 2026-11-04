<?php

namespace App\Services;

final class IndonesianPhoneNormalizer
{
    /**
     * @return array{raw:string,digits:string,canonical_local:?string,evolution_recipient:?string,is_valid:bool,reason:?string,was_changed:bool}
     */
    public function normalize(?string $raw): array
    {
        $rawValue = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $rawValue) ?? '';

        if ($digits === '') {
            return $this->invalid($rawValue, $digits, 'empty');
        }

        if (str_starts_with($digits, '62')) {
            $local = '0'.substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $local = $digits;
        } elseif (str_starts_with($digits, '8')) {
            $local = '0'.$digits;
        } else {
            return $this->invalid($rawValue, $digits, 'not_indonesian');
        }

        // Indonesian mobile numbers start with 08 and are normally 10–13 digits.
        if (! preg_match('/^08\d{8,11}$/', $local)) {
            return $this->invalid($rawValue, $digits, 'invalid_length_or_prefix');
        }

        $evolution = '62'.substr($local, 1);

        return [
            'raw' => $rawValue,
            'digits' => $digits,
            'canonical_local' => $local,
            'evolution_recipient' => $evolution,
            'is_valid' => true,
            'reason' => null,
            'was_changed' => $rawValue !== $local,
        ];
    }

    public function canonicalLocal(?string $raw): ?string
    {
        return $this->normalize($raw)['canonical_local'];
    }

    public function evolutionRecipient(?string $raw): ?string
    {
        return $this->normalize($raw)['evolution_recipient'];
    }

    /** @return array{raw:string,digits:string,canonical_local:null,evolution_recipient:null,is_valid:false,reason:string,was_changed:false} */
    private function invalid(string $raw, string $digits, string $reason): array
    {
        return [
            'raw' => $raw,
            'digits' => $digits,
            'canonical_local' => null,
            'evolution_recipient' => null,
            'is_valid' => false,
            'reason' => $reason,
            'was_changed' => false,
        ];
    }
}
