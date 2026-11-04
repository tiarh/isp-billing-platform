<?php

namespace App\Exceptions;

use RuntimeException;

final class HuaweiOltCommandException extends RuntimeException
{
    public function __construct(
        public string $failureType,
        public string $stage,
        public ?int $commandIndex,
        public ?string $commandLabel,
        public string $causeClass,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
