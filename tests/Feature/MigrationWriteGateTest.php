<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Migration\MigrationWriteGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** The global read-only switch of the migration write gate: missing row = ON, '0' = OFF, and the WA-only canary matrix. */
#[Group('blocking-core')]
class MigrationWriteGateTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'control_plane.device_read_only_mode';

    public function test_a_missing_row_counts_as_read_only_and_zero_turns_it_off(): void
    {
        $gate = new MigrationWriteGate;
        $this->assertTrue($gate->globalReadOnly());

        Setting::set(self::KEY, '1');
        $this->assertTrue($gate->globalReadOnly());

        Setting::set(self::KEY, '0');
        $this->assertFalse($gate->globalReadOnly());
    }

    public function test_canary_matrix(): void
    {
        $gate = new MigrationWriteGate;
        $none = ['migration_actions' => false, 'global_read_only' => false, 'olt' => false, 'mikrotik' => false, 'genieacs' => false];
        $all = ['migration_actions' => true, 'global_read_only' => false, 'olt' => true, 'mikrotik' => true, 'genieacs' => true];

        // WA-only live OFF: the canary opens nothing.
        Setting::set('migration_wa_only_live_enabled', '0');
        $this->assertFalse($gate->writesAllowed($none, false));
        $this->assertFalse($gate->writesAllowed($none, true));
        $this->assertTrue($gate->writesAllowed($all, false));

        // WA-only live ON: the canary opens the lane without the per-domain flags.
        Setting::set('migration_wa_only_live_enabled', '1');
        $this->assertFalse($gate->writesAllowed($none, false));
        $this->assertTrue($gate->writesAllowed($none, true));

        // Read-only ON wins over every other flag, the canary included.
        foreach ([$none, $all] as $flags) {
            $this->assertFalse($gate->writesAllowed(['global_read_only' => true] + $flags, true));
            $this->assertFalse($gate->writesAllowed(['global_read_only' => true] + $flags, false));
        }
    }
}
