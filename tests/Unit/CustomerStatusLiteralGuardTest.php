<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * 'isolir' and 'dismantled' can never be stored in customers.status (DB enum is
 * active/suspended/inactive/isolated/terminated/pending_installation). Use
 * App\Enums\CustomerStatus instead of literals. New hits in app/ fail this guard.
 *
 * The allowlist pins the exact number of quoted literals per file, so a file that
 * is allowed to hold one literal cannot silently grow a second.
 */
#[Group('blocking-core')]
class CustomerStatusLiteralGuardTest extends TestCase
{
    private const PATTERN = "/(['\"])(isolir|dismantled)\\1/";

    /**
     * Files that legitimately contain the literals: path => [exact count, reason].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const ALLOWLIST = [
        'Enums/CustomerStatus.php' => [2, 'doc comment naming the phantom values'],
        'Services/PsbAreaRecapService.php' => [3, 'recap bucket text + normalize aliases (PsbRecapCommandDataTest pins mapping)'],
        'Services/PsbWaMessages.php' => [1, 'recap bucket key in WA text'],
        'Services/Report/DailyReportMessages.php' => [1, 'recap bucket key in report text'],
        'Http/Controllers/CustomerController.php' => [1, 'comment only (explains dismantled is not a valid status)'],
        'Http/Controllers/IsolatedController.php' => [1, 'MikroTik profile names'],
        'Services/Outage/OutageDetector.php' => [1, 'MikroTik profile name match'],
        'Console/Commands/SyncRouterProfilesDraft.php' => [1, 'MikroTik profile name match'],
        'Services/RouterSyncService.php' => [2, 'MikroTik profile names'],
        'Http/Controllers/PublicPaymentController.php' => [1, 'payment transaction source, not customer status'],
        'Services/TicketCustomerLifecycleService.php' => [2, 'ticket lifecycle action key (maps to isolated)'],
    ];

    public function test_no_customer_status_literals_of_phantom_values_in_app(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        $offenders = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $src = (string) file_get_contents($file->getPathname());
            $count = preg_match_all(self::PATTERN, $src, $m);
            if ($count === 0 || $count === false) {
                continue;
            }
            $allowed = self::ALLOWLIST[$rel][0] ?? 0;
            if ($count > $allowed) {
                $offenders[] = $rel.' => '.implode(',', array_unique($m[2])).' ('.$count.' found, '.$allowed.' allowed)';
            }
        }

        $this->assertSame([], $offenders, 'Use App\Enums\CustomerStatus instead of phantom status literals.');
    }

    public function test_allowlist_counts_are_exact(): void
    {
        $root = dirname(__DIR__, 2).'/app';
        foreach (self::ALLOWLIST as $rel => [$expected]) {
            $path = $root.'/'.$rel;
            $this->assertFileExists($path, $rel);
            $this->assertSame(
                $expected,
                preg_match_all(self::PATTERN, (string) file_get_contents($path)),
                "Allowlist count out of date for {$rel} (lower it when a literal is removed)"
            );
        }
    }
}
