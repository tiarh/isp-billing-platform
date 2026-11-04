<?php

namespace App\Logging;

use Illuminate\Log\Logger;

final class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(new RedactSensitiveDataProcessor);
    }
}
