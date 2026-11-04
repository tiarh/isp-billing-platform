<?php

namespace App\Exceptions;

/**
 * The OLT or SN provisioning lock stayed busy for the whole wait window.
 *
 * Deliberately NOT a PsbLockBusyException: ProcessPsbWaJob must not release
 * the WA message on this (the replay would hit the dedupe and stall the draft).
 * The orchestrator catches it inside the draft lock and schedules a
 * ResumePsbDraftJob instead.
 */
class PsbOltLockBusyException extends \RuntimeException
{
    public function __construct(
        string $message = 'Antrian OLT sibuk.',
        public readonly string $scope = 'olt',
        public readonly string $vendor = 'fiberhome',
    ) {
        parent::__construct($message);
    }
}
