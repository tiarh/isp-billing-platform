<?php

namespace Tests;

use App\Jobs\ProcessPsbMediaOcrJob;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** memory_limit of the test process at its first test (CI runs `php -d memory_limit=512M`). */
    private static ?string $memoryLimit = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$memoryLimit ??= (string) ini_get('memory_limit');
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // GenieAcsService::syncDevices() lowers memory_limit to 256M for the rest of the process; every later test of the
        // run then competes for 256M (the `test (core)` job peaked at 249 MB). Give the process its limit back.
        if (self::$memoryLimit !== null && (string) ini_get('memory_limit') !== self::$memoryLimit) {
            @ini_set('memory_limit', self::$memoryLimit);
        }

        // App code calls set_time_limit() (GenieAcs sync 90s, auto PPPoE 120s, ...). In the one long PHPUnit process that
        // limit would leak and kill a later test with "Maximum execution time exceeded". CLI default is no limit.
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');

        // Static test seams must never leak into the next test.
        ProcessPsbMediaOcrJob::fakeShutdownRegistrar(null);

        parent::tearDown();
    }
}
