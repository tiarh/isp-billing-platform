<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Lock::block() throws LockTimeoutException on timeout and never returns
 * false, so `if (! $lock->block(n))` is a dead branch. Use
 * App\Support\CacheLockWait::acquire()/run() instead.
 *
 * The allowlist is empty: every site has been converted (the last one, the
 * Huawei SSH session lock, in roadmap 1.1 PR-C).
 */
#[Group('blocking-psb')]
#[Group('blocking-core')]
class NoDeadLockBlockBranchTest extends TestCase
{
    private const ALLOWLIST = [];

    public function test_no_dead_lock_block_branch_outside_allowlist(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if (in_array($relative, self::ALLOWLIST, true)) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match_all('/if\s*\(\s*!?\s*\$\w+->block\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[0] as [, $offset]) {
                    $offenders[] = $relative.':'.(substr_count(substr($source, 0, $offset), "\n") + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Dead `if (! $lock->block())` branch found; use CacheLockWait::acquire(): '.implode(', ', $offenders));
    }
}
