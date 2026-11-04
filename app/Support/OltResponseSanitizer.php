<?php

namespace App\Support;

final class OltResponseSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'password', 'passwd', 'pppoe_password', 'secret', 'token', 'api_key',
        'apikey', 'authorization', 'bearer', 'community', 'snmp_community',
    ];

    /** @var list<string> */
    private const OUTPUT_KEYS = [
        'output', 'raw', 'detail_raw', 'command_output', 'stdout', 'stderr',
    ];

    /** @var list<string> */
    private const COMMAND_KEYS = ['commands', 'command', 'cmd'];

    /**
     * Remove device output/commands before an OLT JSON response reaches a browser.
     * Credential-shaped strings in user-facing errors are redacted as well.
     *
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    public function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            if ($normalized === 'log' && is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            if (in_array($normalized, self::OUTPUT_KEYS, true)) {
                $sanitized[$key] = '[REDACTED_DEVICE_OUTPUT]';

                continue;
            }

            if (in_array($normalized, self::COMMAND_KEYS, true)) {
                $sanitized[$key] = '[REDACTED_DEVICE_COMMANDS]';

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);

                continue;
            }

            $sanitized[$key] = is_string($value)
                ? SensitiveDataRedactor::redact($value)
                : $value;
        }

        return $sanitized;
    }
}
