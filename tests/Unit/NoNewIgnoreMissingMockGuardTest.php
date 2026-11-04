<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Test policy (AGENTS.md, "Test policy: mocking"): tests must not mock our own
 * services whole with a "ignore missing calls" Mockery double. That hides the
 * real controller -> service path (see #582). Mock only I/O edges.
 *
 * Files that already do it are grandfathered below. A NEW test file that uses it
 * fails this guard. Remove a file from the list when you clean it up.
 */
#[Group('blocking-core')]
class NoNewIgnoreMissingMockGuardTest extends TestCase
{
    /** @var list<string> */
    private const GRANDFATHERED = [
        'Feature/CustomerMigrationSiteRoutingTest.php',
        'Feature/DeviceRecoveryAutoAssignTest.php',
        'Feature/DeviceRecoveryBackfillAssigneeTest.php',
        'Feature/DeviceRecoveryDismantleTicketReasonTest.php',
        'Feature/DeviceRecoveryDismantleTicketTest.php',
        'Feature/DeviceRecoveryDuplicateTicketTest.php',
        'Feature/DeviceRecoveryTechnicianReportTest.php',
        'Feature/ManualProvisionMigrationLinkTest.php',
        'Feature/MigrationLockGuardTest.php',
        'Feature/MigrationPartialExecutionRecoveryTest.php',
        'Feature/OltHuaweiUiDetectRealServiceTest.php',
        'Feature/OutageRca4DiagnosisTest.php',
        'Feature/Rca4PsbIsolationTest.php',
        'Feature/TicketPhotoUploadTest.php',
        'Feature/TicketRootCauseCloseTest.php',
    ];

    public function test_new_tests_do_not_use_ignore_missing_mocks(): void
    {
        $needle = 'should'.'IgnoreMissing';
        $root = dirname(__DIR__);
        $offenders = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($relative === 'Unit/'.basename(__FILE__) || str_starts_with($relative, 'fakes/')) {
                continue;
            }
            if (str_contains((string) file_get_contents($file->getPathname()), $needle) && ! in_array($relative, self::GRANDFATHERED, true)) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These tests mock a service with ignore-missing. Use the real service and mock only I/O edges (see AGENTS.md, Test policy: mocking).'
        );
    }
}
