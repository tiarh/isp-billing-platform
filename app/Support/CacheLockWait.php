<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Lock::block() throws LockTimeoutException on timeout and never returns
 * false, so a negated block() call in an if-condition is a dead branch. This
 * helper turns the timeout into the boolean the call sites expect.
 */
final class CacheLockWait
{
    /**
     * @param  Lock  $lock  any object exposing block(int) (kept duck-typed for test doubles)
     *
     * Test seam: when config `locks.wait_cap_seconds` is set (never in
     * production) the wait is capped to that value.
     */
    public static function acquire(object $lock, int $seconds): bool
    {
        $cap = config('locks.wait_cap_seconds');
        if ($cap !== null) {
            $seconds = min($seconds, max(0, (int) $cap));
        }

        try {
            return (bool) $lock->block($seconds);
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Acquire, run $fn and always release. When the lock stays busy $onBusy
     * runs instead (no release: the lock was never taken).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @param  callable(): mixed  $onBusy
     */
    public static function run(object $lock, int $seconds, callable $fn, callable $onBusy): mixed
    {
        if (! self::acquire($lock, $seconds)) {
            return $onBusy();
        }

        try {
            return $fn();
        } finally {
            try {
                $lock->release();
            } catch (\Throwable) {
                // Releasing must never mask the result or the original error.
            }
        }
    }
}
