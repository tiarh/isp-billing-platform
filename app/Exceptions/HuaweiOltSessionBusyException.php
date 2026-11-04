<?php

namespace App\Exceptions;

/**
 * The Huawei OLT SSH session lock stayed busy for the whole wait window.
 *
 * Thrown by OltHuaweiMa5608tService::connect() BEFORE any SSH connection or
 * command exists, so nothing has been sent to the OLT by that call. Callers
 * that already sent commands in the same flow (later phases) must treat it as
 * an ordinary transport failure (manual reconciliation), see
 * HuaweiOltOnlyProvisioningService::executeCommandPhase().
 */
class HuaweiOltSessionBusyException extends LockBusyException
{
    public const MESSAGE = 'OLT Huawei sedang dipakai sesi lain. Belum ada perintah dikirim; coba lagi +-1 menit.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
