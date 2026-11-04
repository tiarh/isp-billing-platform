<?php

namespace App\Exceptions;

use RuntimeException;

/** RCA-4: "Cek sekarang" refused (disabled / rate limit / too many running checks). The message is shown to CS. */
class DiagnoseLimitException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 429, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }
}
