<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Lists with a "Baris per halaman" select must read the chosen size (App\Support\PerPage).
 * A fixed page size (a number, a class constant or an empty paginate()) silently ignores the
 * select, so controllers may not use one unless the page has no selector (listed below with
 * the reason).
 *
 * The scan reads whole files (a call may be split over lines) and every controller folder.
 * It cannot see a size that is first put in a variable (`$n = 20; paginate($n)`): code review
 * and the per-list tests in PerPageListsTest cover that case.
 */
#[Group('blocking-core')]
class NoHardcodedPaginateInControllersTest extends TestCase
{
    /** @var array<string, string> controller file (path relative to app/Http/Controllers) => reason a fixed page size is fine */
    private const ALLOWED = [
        'CustomerCreditController.php' => 'CustomerCredits/Index has prev/next links only, no page-size select',
        'PaymentGatewayTransactionController.php' => 'PaymentGateway/Transactions has prev/next links only, no page-size select',
        'CustomerMigrationController.php' => 'Migration list uses its own import-row pager (fixed 50), no page-size select',
    ];

    public function test_controllers_do_not_hardcode_the_page_size(): void
    {
        $offenders = [];

        $base = dirname(__DIR__, 2).'/app/Http/Controllers';
        foreach ($this->phpFiles($base) as $file) {
            // Path relative to the controllers folder (a same-named file in a subfolder is not allowlisted).
            $name = ltrim(str_replace('\\', '/', substr($file, strlen($base))), '/');
            if (isset(self::ALLOWED[$name])) {
                continue;
            }

            $code = (string) file_get_contents($file);
            foreach (self::paginateCalls($code) as [$offset, $argument]) {
                if (! self::readsTheRequest($argument)) {
                    $line = substr_count(substr($code, 0, $offset), "\n") + 1;
                    $offenders[] = $name.':'.$line.' paginate('.preg_replace('/\s+/', ' ', $argument).')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A fixed paginate() size ignores the rows-per-page select. Use App\\Support\\PerPage::from(\$request, <default>), or add the file to ALLOWED with a reason if the page has no select:\n".implode("\n", $offenders),
        );
    }

    public function test_allowlisted_files_still_exist(): void
    {
        foreach (array_keys(self::ALLOWED) as $name) {
            $this->assertFileExists(dirname(__DIR__, 2).'/app/Http/Controllers/'.$name);
        }
    }

    public function test_the_scan_catches_the_bypass_forms(): void
    {
        $bad = [
            '$q->paginate(20)',
            'Customer::paginate(20)',
            '$q->paginate()',
            '$q->paginate(self::PER_PAGE)',
            '$q->paginate(static::PER_PAGE, [\'*\'])',
            '$q->paginate(perPage: 20)',
            "\$q->paginate(\n    25\n)",
            '$q->simplePaginate(15)',
            '$q->cursorPaginate(10)',
        ];
        foreach ($bad as $code) {
            $calls = self::paginateCalls($code);
            $this->assertCount(1, $calls, $code);
            $this->assertFalse(self::readsTheRequest($calls[0][1]), $code);
        }

        $good = [
            '$q->paginate($limit)',
            '$q->paginate(PerPage::from($request, 20))',
            '$q->paginate((int) ($validated[\'limit\'] ?? 20))',
            '$q->paginate(min(max((int) $request->input(\'limit\', 50), 1), 100))',
            '$q->paginate($limit, [\'id\'])',
            '$q->paginate(perPage: $limit)',
        ];
        foreach ($good as $code) {
            $calls = self::paginateCalls($code);
            $this->assertCount(1, $calls, $code);
            $this->assertTrue(self::readsTheRequest($calls[0][1]), $code);
        }
    }

    /**
     * Contract with the frontend: a DataTable that manages its own URL (syncUrl={false}) and still
     * shows the pager must say what happens when the size changes (onPageSizeChange). Without it
     * the select changes the local state only and the list keeps its old size.
     */
    public function test_datatables_with_own_url_handle_page_size_changes(): void
    {
        $offenders = [];
        $seen = 0;

        foreach ($this->tsxFiles(dirname(__DIR__, 2).'/resources/js') as $file) {
            $code = (string) file_get_contents($file);
            $offset = 0;
            while (($pos = strpos($code, '<DataTable', $offset)) !== false) {
                $offset = $pos + 10;
                if (! preg_match('/[\s<>]/', $code[$pos + 10] ?? ' ')) {
                    continue; // <DataTablePagination ...>
                }
                $tagStart = $pos + 10;
                if (($code[$tagStart] ?? '') === '<') { // generic: <DataTable<Row> ...>
                    $tagStart = (int) strpos($code, '>', $tagStart) + 1;
                }
                $tag = self::jsxOpeningTag($code, $tagStart);
                if (! str_contains($tag, 'syncUrl={false}')) {
                    continue;
                }
                $seen++;
                if (! str_contains($tag, 'hidePagination') && ! str_contains($tag, 'onPageSizeChange')) {
                    $line = substr_count(substr($code, 0, $pos), "\n") + 1;
                    $offenders[] = str_replace(dirname(__DIR__, 2).DIRECTORY_SEPARATOR, '', $file).':'.$line;
                }
            }
        }

        $this->assertGreaterThan(0, $seen, 'The scan found no DataTable with syncUrl={false}: the pattern is stale.');
        $this->assertSame([], $offenders, "DataTable with syncUrl={false} and a pager needs onPageSizeChange (or hidePagination):\n".implode("\n", $offenders));
    }

    /**
     * @return list<array{0: int, 1: string}> [offset of the call, first argument text]
     */
    private static function paginateCalls(string $code): array
    {
        $calls = [];
        if (! preg_match_all('/(?:->|::)\s*(?:simple|cursor)?[pP]aginate\s*\(/', $code, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        foreach ($matches[0] as [$text, $offset]) {
            $start = $offset + strlen($text);
            $depth = 1;
            $argument = '';
            $length = strlen($code);
            for ($i = $start; $i < $length; $i++) {
                $char = $code[$i];
                if ($char === '(' || $char === '[') {
                    $depth++;
                } elseif ($char === ')' || $char === ']') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($char === ',' && $depth === 1) {
                    break;
                }
                $argument .= $char;
            }
            $calls[] = [$offset, trim($argument)];
        }

        return $calls;
    }

    private static function readsTheRequest(string $argument): bool
    {
        $argument = preg_replace('/^(?:perPage|per_page)\s*:\s*/', '', $argument) ?? $argument;

        return $argument !== ''
            && (str_contains($argument, '$') || str_contains($argument, 'PerPage::') || str_contains($argument, 'request('));
    }

    private static function jsxOpeningTag(string $code, int $start): string
    {
        $depth = 0;
        $length = strlen($code);
        for ($i = $start; $i < $length; $i++) {
            $char = $code[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            } elseif ($char === '>' && $depth === 0 && ($code[$i - 1] ?? '') !== '=') {
                return substr($code, $start, $i - $start);
            }
        }

        return substr($code, $start);
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        return $this->files($dir, 'php');
    }

    /**
     * @return list<string>
     */
    private function tsxFiles(string $dir): array
    {
        return $this->files($dir, 'tsx');
    }

    /**
     * @return list<string>
     */
    private function files(string $dir, string $extension): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
