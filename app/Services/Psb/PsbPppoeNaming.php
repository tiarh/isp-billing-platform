<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Support\SensitiveDataRedactor;

/**
 * PSB WA: PPPoE username building and short error text.
 *
 * Moved as-is from PsbOrchestratorService (PR 1 of the PSB refactor).
 */
final class PsbPppoeNaming
{
    public function buildPppoeUsername(PsbDraft $draft): string
    {
        $name = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $draft->full_name) ?? '');
        $rt = preg_replace('/\D/', '', (string) $draft->rt);
        $rw = preg_replace('/\D/', '', (string) $draft->rw);
        if ($name === '' || $rt === '' || $rw === '') {
            return '';
        }

        $odpShort = $this->odpUsernameSuffix((string) $draft->odp);
        $user = "{$name}_RT{$rt}_RW{$rw}_{$odpShort}";

        return strlen($user) > 64 ? substr($user, 0, 64) : $user;
    }

    public function odpUsernameSuffix(string $odp): string
    {
        $odp = trim($odp);
        if (preg_match('/([A-Za-z]*\d+(?:\.\d+)*)\s*$/', $odp, $m)) {
            return strtoupper($m[1]);
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $odp) ?? 'ODP') ?: 'ODP';
    }

    public function shortError(string $error): string
    {
        $error = SensitiveDataRedactor::redact($error);
        $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);

        return mb_strlen($error) > 160 ? mb_substr($error, 0, 157).'...' : $error;
    }
}
