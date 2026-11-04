<?php

namespace App\Services\Outage;

/** A manual ticket request that must be refused. The message is plain Indonesian and safe to show. */
class OutageManualTicketException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?int $ticketId = null)
    {
        parent::__construct($message);
    }
}
