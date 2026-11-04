<?php

namespace App\Services\Olt\ReadBack;

use App\Models\Olt;

interface OnuReadBackVerifier
{
    public function verify(Olt $olt, string $onuRef, array $expected): bool;
}
