<?php

namespace App\Support;

use Throwable;

final class SensitiveDataRedactor
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'pppoe_password',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'bearer',
        'community',
    ];

    public static function redact(string $value): string
    {
        $value = preg_replace_callback(
            '/(?<![A-Za-z0-9_])((?:pppoe[_-])?password|passwd|secret|token|api[_-]?key|authorization|bearer|community)'
            .'(\s*[:=]\s*|\s+)(?:Bearer\s+)?(?:"[^"]*"|\'[^\']*\'|\S+)/iu',
            static fn (array $match): string => $match[1].$match[2].'[REDACTED]',
            $value,
        ) ?? $value;

        $value = preg_replace_callback(
            '/(Authorization\s*:\s*Bearer\s+)(\S+)/iu',
            static fn (array $match): string => $match[1].'[REDACTED]',
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '/([?&](?:token|api[_-]?key|password|secret)=)([^&\s]+)/iu',
            static fn (array $match): string => $match[1].'[REDACTED]',
            $value,
        ) ?? $value;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redactContext(array $context): array
    {
        $redacted = [];
        foreach ($context as $key => $value) {
            $redacted[(string) $key] = self::redactValue((string) $key, $value);
        }

        return $redacted;
    }

    private static function redactValue(string $key, mixed $value): mixed
    {
        if (self::isSensitiveKey($key)) {
            return '[REDACTED]';
        }

        if ($value instanceof Throwable) {
            return [
                'class' => $value::class,
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }

        if (is_array($value)) {
            $nested = [];
            foreach ($value as $nestedKey => $nestedValue) {
                $nested[(string) $nestedKey] = self::redactValue((string) $nestedKey, $nestedValue);
            }

            return $nested;
        }

        return is_string($value) ? self::redact($value) : $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($key)));

        return in_array($normalized, self::SENSITIVE_KEYS, true)
            || str_ends_with($normalized, '_password')
            || str_ends_with($normalized, '_secret')
            || str_ends_with($normalized, '_token');
    }
}
