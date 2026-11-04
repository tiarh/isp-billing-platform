<?php

namespace App\Exceptions;

/**
 * A non-resumable lock stayed busy for the whole wait window.
 *
 * The message is safe to show to the user (WA reply / admin JSON). Deliberately
 * NOT a PsbLockBusyException: the WA job must not release() and replay the
 * message; callers report the message and leave state unchanged.
 */
class LockBusyException extends \RuntimeException
{
    /** User-safe text (never contains exception internals). */
    public function userMessage(): string
    {
        return $this->getMessage();
    }
}
