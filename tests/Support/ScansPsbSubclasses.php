<?php

namespace Tests\Support;

/**
 * Finds every subclass (production and test harness, named or anonymous) of the
 * PSB orchestrator family under app/ and tests/, by reading source only (no app
 * boot, no autoload of the scanned classes).
 *
 * Used by PsbExtractedNamesGuardTest and PsbOverrideAllowlistGuardTest.
 */
trait ScansPsbSubclasses
{
    /** @var list<string> */
    private static array $psbBases = ['PsbOrchestratorService', 'HuaweiAwarePsbOrchestratorService', 'PsbMediaOcrOrchestrator'];

    /**
     * @param  list<string>  $excludeBasenames  guard files that quote class declarations in strings
     * @return array<string, array{path: string, methods: list<string>, body: string}> class short name => info
     */
    private function scanPsbSubclasses(array $excludeBasenames): array
    {
        $files = [];
        // Plain PHPUnit TestCase here (no app boot), so no base_path().
        $repo = dirname(__DIR__, 2);
        foreach ([$repo.'/app', $repo.'/tests'] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php' && ! in_array($f->getFilename(), $excludeBasenames, true)) {
                    $files[] = $f->getPathname();
                }
            }
        }

        $found = [];
        $known = self::$psbBases;
        do {
            $before = count($found);
            foreach ($files as $path) {
                $src = (string) file_get_contents($path);
                if (! preg_match('/\bextends\s+\\\\?(?:[\w\\\\]*\\\\)?('.implode('|', array_map('preg_quote', $known)).')\b/', $src)) {
                    continue;
                }
                // One entry per class declaration: named (any indentation, final/abstract/readonly
                // in any order) or anonymous (`new class(...) extends X`).
                preg_match_all('/\b(?:new\s+(?:readonly\s+)?class\b[^{]*?|(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)\s+)extends\s+\\\\?(?:[\w\\\\]*\\\\)?(\w+)[^{]*\{/', $src, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
                foreach ($m as $decl) {
                    $named = ($decl[1][1] ?? -1) >= 0;
                    $name = $named ? $decl[1][0] : 'anonymous@'.basename($path).':'.$decl[0][1];
                    if (! in_array($decl[2][0], $known, true) || isset($found[$name])) {
                        continue;
                    }
                    $start = $decl[0][1] + strlen($decl[0][0]);
                    $body = $this->psbClassBody(substr($src, $start));
                    preg_match_all('/function\s+&?(\w+)\s*\(/', $body, $fn);
                    $found[$name] = ['path' => $path, 'methods' => $fn[1], 'body' => $body];
                    $known[] = $name;
                }
            }
        } while (count($found) > $before);

        return $found;
    }

    /** Text after a class's opening brace up to its matching closing brace. */
    private function psbClassBody(string $afterOpenBrace): string
    {
        $depth = 1;
        $body = '';
        foreach (token_get_all('<?php '.$afterOpenBrace) as $i => $token) {
            if ($i === 0) {
                continue; // the open tag
            }
            $text = is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            $body .= $text;
        }

        return $body;
    }
}
