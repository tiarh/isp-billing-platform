<?php

namespace Tests\Unit\Psb;

use App\Services\PsbOrchestratorService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\ScansPsbSubclasses;

/**
 * PSB refactor phase 1b: the facade keeps shrinking, so every override of a
 * facade method (production subclass or test harness) must be listed here.
 * A new override, or a harness that calls a protected facade method, fails
 * until someone decides it belongs on the list. Steps 2 and 3 of phase 1b
 * removed the names tagged for them (step 3 PR A: the device fakes became edges).
 *
 * Plain PHPUnit TestCase on purpose: reflection and source scan only, no app boot.
 */
#[Group('blocking-psb')]
class PsbOverrideAllowlistGuardTest extends TestCase
{
    use ScansPsbSubclasses;

    /** Production subclasses: class short name => facade names it may declare. */
    private const PRODUCTION = [
        'HuaweiAwarePsbOrchestratorService' => ['processDraft'],
        'PsbMediaOcrOrchestrator' => ['ocrReaders', 'continueAfterMediaOcr', 'draftProcessor'],
    ];

    /** Test harnesses: public entry points the harnesses wrap or record (stay). */
    private const TEST_PUBLIC = ['reply', 'processDraft', 'handleInbound', 'processStagedMediaOcr'];

    /** Test harnesses: OCR edges (MarkerHarness fake reader + clock; two job-path tests need the fake clock). */
    private const TEST_OCR_EDGES = ['ocrReaders'];

    /**
     * Test harnesses: overrides that stay, per harness class. Device overrides are gone (provisioning tests
     * use the device-boundary fakes in tests/Support/PsbEdges). WaCommandsHost proves the commands call
     * these two members late-bound; OltBusyMediaHarness needs finishFollowUpAfterMedia because the real
     * route needs a fixture change.
     */
    private const TEST_CLASS_SCOPED = [
        'WaCommandsHost' => ['hasMinPhoto', 'readVerifiedPppoePassword'],
        'OltBusyMediaHarness' => ['finishFollowUpAfterMedia'],
    ];

    /** Protected facade methods a test harness may call as $this->name(...). */
    private const TEST_CALLS = [
        'ocrReaders', // the OCR engine is built from the harness's own readers
        'createSecretAndDraftCustomer', 'applySiteToDraft', 'resolveArea', // kept: protected for HuaweiAware
    ];

    public function test_overrides_of_facade_methods_are_on_the_allowlist(): void
    {
        $classes = $this->scanPsbSubclasses([basename(__FILE__), 'PsbExtractedNamesGuardTest.php']);
        $facade = $this->facadeMethods();

        $this->assertArrayHasKey('HuaweiAwarePsbOrchestratorService', $classes);
        $this->assertArrayHasKey('PsbMediaOcrOrchestrator', $classes);
        $this->assertArrayHasKey('Sprint1PsbHarness', $classes);
        $this->assertContains('processDraft', $facade['all']);

        $testAllowed = array_merge(self::TEST_PUBLIC, self::TEST_OCR_EDGES);
        $hits = [];
        $used = [];
        foreach ($classes as $class => $info) {
            $declared = array_values(array_intersect($info['methods'], $facade['all']));
            $allowed = self::PRODUCTION[$class] ?? array_merge($testAllowed, self::TEST_CLASS_SCOPED[$class] ?? []);
            foreach ($declared as $name) {
                if (! in_array($name, $allowed, true)) {
                    $hits[] = $class.'::'.$name;
                }
                $used[$class][] = $name;
            }
        }

        $this->assertSame([], $hits, 'A subclass overrides a facade method that is not on the allowlist. Prefer testing the extracted class directly; add the name here only with a reason.');

        // Allowlist entries nobody uses any more must be removed, so the list only shrinks.
        $unused = [];
        foreach (self::PRODUCTION as $class => $names) {
            foreach ($names as $name) {
                if (! in_array($name, $used[$class] ?? [], true)) {
                    $unused[] = $class.'::'.$name;
                }
            }
        }
        $testUsed = [];
        foreach ($classes as $class => $info) {
            if (! isset(self::PRODUCTION[$class])) {
                $testUsed = array_merge($testUsed, $used[$class] ?? []);
            }
        }
        foreach ($testAllowed as $name) {
            if (! in_array($name, $testUsed, true)) {
                $unused[] = 'tests::'.$name;
            }
        }
        foreach (self::TEST_CLASS_SCOPED as $class => $names) {
            foreach ($names as $name) {
                if (! in_array($name, $used[$class] ?? [], true)) {
                    $unused[] = $class.'::'.$name;
                }
            }
        }

        $this->assertSame([], $unused, 'These allowlist entries are no longer used by any subclass. Remove them.');
    }

    public function test_test_harnesses_only_call_allowlisted_protected_facade_methods(): void
    {
        $classes = $this->scanPsbSubclasses([basename(__FILE__), 'PsbExtractedNamesGuardTest.php']);
        $protected = $this->facadeMethods()['protected'];

        $hits = [];
        foreach ($classes as $class => $info) {
            if (isset(self::PRODUCTION[$class])) {
                continue;
            }
            preg_match_all('/\$this->(\w+)\s*\(/', $info['body'], $calls);
            foreach (array_unique($calls[1]) as $name) {
                if (in_array($name, $protected, true) && ! in_array($name, self::TEST_CALLS, true)) {
                    $hits[] = $class.' calls $this->'.$name.'()';
                }
            }
        }

        $this->assertSame([], $hits, 'A test harness reaches a protected facade method. Test the extracted class directly, or go through a public entry point.');
    }

    /** @return array{all: list<string>, protected: list<string>} */
    private function facadeMethods(): array
    {
        $all = [];
        $protected = [];
        $reflection = new \ReflectionClass(PsbOrchestratorService::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
            if ($method->getDeclaringClass()->getName() !== PsbOrchestratorService::class) {
                continue;
            }
            $all[] = $method->getName();
            if ($method->isProtected()) {
                $protected[] = $method->getName();
            }
        }

        return ['all' => $all, 'protected' => $protected];
    }
}
