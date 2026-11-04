<?php

namespace App\Exceptions;

/**
 * A RouterOS write (secret profile set, active session remove) was rejected
 * by the router (!trap) or its precondition failed (target profile missing).
 *
 * PR-R1 (Q3): these used to be swallowed, so the ledger recorded "succeeded"
 * while the router kept the old profile.
 */
class RouterOsWriteException extends \RuntimeException
{
    public const ROUTEROS_TRAP = 'routeros_trap';

    public const PROFILE_NOT_FOUND = 'profile_not_found';

    public const TARGET_IS_ISOLATION_PROFILE = 'reconnect_target_is_isolation_profile';

    /**
     * Data problems (missing / wrong target profile) do not heal on retry,
     * unlike transient RouterOS/network errors.
     */
    public function isPermanent(): bool
    {
        return in_array($this->reasonCode, [self::PROFILE_NOT_FOUND, self::TARGET_IS_ISOLATION_PROFILE], true);
    }

    public function __construct(string $message, public readonly string $reasonCode = self::ROUTEROS_TRAP)
    {
        parent::__construct($message);
    }
}
