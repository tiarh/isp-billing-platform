<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The PSB draft state machine as a golden (PSB refactor PR 0, tests only).
 *
 * PsbFlowGoldenTest records, for every step of every scenario, which draft status changed
 * and what triggered it. This test takes the union of those (from, to, trigger) triples over
 * all scenario goldens and compares it with tests/Fixtures/psb-flow/transition-table.golden.json.
 * It documents the observed state machine without changing runtime code (no state-machine
 * package, owner decision Q5). A new transition or a lost one fails here.
 *
 * Triggers: psb_text, follow_up_photo, cmd:<name> (the WA command), job:<Job classes run in
 * the step>. "(none)" as `from` means the draft was created by that trigger.
 *
 * Written ONLY with env PSB_FLOW_GOLDEN_WRITE=1 (never in CI); a change needs owner OK (Q8).
 */
#[Group('blocking-psb')]
class PsbTransitionTableGoldenTest extends TestCase
{
    /** @return list<array{0: string, 1: string, 2: string}> */
    private function union(): array
    {
        $seen = [];
        $files = glob(__DIR__.'/../Fixtures/psb-flow/s*.golden.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $scenario = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('transitions', $scenario, basename($file));
            foreach ($scenario['transitions'] as [$from, $to, $trigger]) {
                $seen[$from.' > '.$to.' > '.$trigger] = [$from, $to, $trigger];
            }
        }
        ksort($seen);

        return array_values($seen);
    }

    public function test_scenarios_exist(): void
    {
        $this->assertGreaterThanOrEqual(20, count(glob(__DIR__.'/../Fixtures/psb-flow/s*.golden.json') ?: []));
    }

    public function test_transition_table_matches_golden(): void
    {
        $table = $this->union();
        $this->assertNotEmpty($table);
        $recorded = json_encode(['transitions' => $table], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        $path = __DIR__.'/../Fixtures/psb-flow/transition-table.golden.json';

        if (getenv('PSB_FLOW_GOLDEN_WRITE') === '1') {
            file_put_contents($path, $recorded);
            $this->addToAssertionCount(1);

            return;
        }
        $this->assertFileExists($path, 'No transition table golden. Record it with PSB_FLOW_GOLDEN_WRITE=1 (needs owner OK).');
        $this->assertSame(str_replace("\r\n", "\n", (string) file_get_contents($path)), $recorded, 'The observed PSB status transitions changed');
    }

    public function test_the_table_has_the_known_end_states(): void
    {
        $targets = array_unique(array_map(fn (array $t): string => $t[1], $this->union()));
        foreach (['received', 'waiting_sn', 'secret_ok', 'olt_ok', 'correction_pending_review', 'cancelled', 'failed'] as $status) {
            $this->assertContains($status, $targets, $status.' is no longer reached by any scenario');
        }
    }
}
