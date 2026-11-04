<?php

namespace App\Exceptions;

/**
 * Thrown by the post-OCR "KTP missing" decision when another photo batch of the
 * same draft is still being read. ProcessPsbAfterMediaOcrJob catches it and
 * queues the same continuation again a few seconds later. Not an error.
 */
class PsbAlbumOcrWaitException extends \RuntimeException
{
    public function __construct(
        public readonly int $waitSince,
        public readonly int $remainingSeconds,
    ) {
        parent::__construct('PSB KTP decision waits for another photo batch.');
    }
}
