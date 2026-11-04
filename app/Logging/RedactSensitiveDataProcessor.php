<?php

namespace App\Logging;

use App\Support\SensitiveDataRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactSensitiveDataProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: SensitiveDataRedactor::redact($record->message),
            context: SensitiveDataRedactor::redactContext($record->context),
        );
    }
}
