<?php

namespace App\Exceptions;

use RuntimeException;

class PsbPasswordPolicyException extends RuntimeException
{
    public function __construct(public readonly string $status)
    {
        parent::__construct((string) config('site.psb_password_error'));
    }
}
