<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbHuaweiConcurrencyEvidenceTest extends TestCase
{
    public function test_huawei_olt_lock_has_one_active_claim_across_processes(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl_fork is required for the staging concurrency evidence run.');
        }

        $cachePath = storage_path('framework/testing/huawei-concurrency-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($cachePath);
        config([
            'cache.default' => 'file',
            'cache.stores.file.path' => $cachePath,
            'cache.stores.file.lock_path' => $cachePath,
        ]);

        $startedPath = $cachePath.'/started';
        $releasePath = $cachePath.'/release';
        $child = pcntl_fork();

        $this->assertNotSame(-1, $child, 'Unable to fork the isolated concurrency worker.');

        if ($child === 0) {
            $lock = Cache::store('file')->lock('psb-huawei-olt-11', 10);
            $acquired = $lock->get();
            File::put($startedPath, $acquired ? 'acquired' : 'rejected');

            while (! File::exists($releasePath)) {
                usleep(10_000);
            }

            if ($acquired) {
                $lock->release();
            }

            exit($acquired ? 0 : 1);
        }

        try {
            $deadline = microtime(true) + 2.0;
            while (! File::exists($startedPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }

            $this->assertFileExists($startedPath);
            $this->assertSame('acquired', File::get($startedPath));

            $contender = Cache::store('file')->lock('psb-huawei-olt-11', 10)->get();
            $this->assertFalse($contender, 'A second Huawei PSB worker claimed the same OLT lock.');

            File::put($releasePath, 'release');
            $status = 0;
            pcntl_waitpid($child, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if (File::exists($releasePath) === false) {
                File::put($releasePath, 'release');
            }
            if (function_exists('posix_kill') && $child > 0 && posix_kill($child, 0)) {
                posix_kill($child, SIGTERM);
                pcntl_waitpid($child, $ignored);
            }
            File::deleteDirectory($cachePath);
        }
    }
}
