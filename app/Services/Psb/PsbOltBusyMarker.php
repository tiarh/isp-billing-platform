<?php

namespace App\Services\Psb;

use App\Models\PsbDraft;
use App\Services\PsbOrchestratorService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * OLT-busy resume bookkeeping (moved verbatim from PsbOrchestratorService).
 * The resume token is passed in (it is per-run state of the facade). The facade keeps
 * the give-up, reply and job dispatch of deferOltAfterBusyLock.
 */
final class PsbOltBusyMarker
{
    /** @return array<string, mixed> */
    public function oltBusyMarker(PsbDraft $draft): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $marker = $meta['olt_busy_resume'] ?? null;

        return is_array($marker) ? $marker : [];
    }

    /** Removes meta.olt_busy_resume (caller saves). */
    public function clearOltBusyMarker(PsbDraft $draft): void
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        if (array_key_exists('olt_busy_resume', $meta)) {
            unset($meta['olt_busy_resume']);
            $draft->meta = $meta;
        }
    }

    /**
     * True when the current run is a ResumePsbDraftJob run whose token is no
     * longer current (superseded by lanjut / a newer job) or the draft is no
     * longer waiting for the OLT.
     */
    public function oltResumeTokenStale(PsbDraft $draft, ?string $oltResumeToken): bool
    {
        if ($oltResumeToken === null) {
            return false;
        }
        $marker = $this->oltBusyMarker($draft);

        return ($marker['token'] ?? null) !== $oltResumeToken
            || ! $this->oltBusyResumable($draft);
    }

    /** Still waiting for the OLT: secret_ok, no olt_at, and last_error set by the defer step. */
    public function oltBusyResumable(PsbDraft $draft): bool
    {
        return $draft->status === PsbDraft::STATUS_SECRET_OK
            && $draft->olt_at === null
            && str_starts_with((string) $draft->last_error, 'OLT antri:');
    }

    /**
     * Chain/attempt maths of deferOltAfterBusyLock.
     *
     * @param  array<string, mixed>  $prev  the previous marker
     * @return array{first: CarbonInterface, live: bool, done: int, next: int}
     */
    public function chain(array $prev, CarbonInterface $now): array
    {
        $first = null;
        if (! empty($prev['first_at'])) {
            try {
                $first = Carbon::parse((string) $prev['first_at']);
            } catch (\Throwable) {
                $first = null;
            }
        }
        $live = $first !== null && $first->gt($now->copy()->subMinutes(PsbOrchestratorService::OLT_BUSY_CHAIN_MINUTES));
        $done = $live ? (int) ($prev['attempt'] ?? 0) : 0;
        $first = $live ? $first : $now->copy();
        $next = $done + 1;

        return ['first' => $first, 'live' => $live, 'done' => $done, 'next' => $next];
    }

    /** Seconds before automatic retry $next (1-based), with 0-15 s jitter. */
    public function backoffSeconds(int $next): int
    {
        return PsbOrchestratorService::OLT_BUSY_BACKOFF[min($next, count(PsbOrchestratorService::OLT_BUSY_BACKOFF)) - 1] + random_int(0, 15);
    }
}
