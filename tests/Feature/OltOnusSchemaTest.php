<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Lock the olt_onus schema so the flaky "Unknown column phase_state" failure
 * caused by ad-hoc test tables cannot return. The migration creates a
 * consistent table for fresh/test databases; production is left untouched.
 */
#[Group('blocking-psb')]
#[Group('blocking-olt-mysql')]
class OltOnusSchemaTest extends TestCase
{
    public function test_olt_onus_table_has_phase_state_column(): void
    {
        $this->assertTrue(Schema::hasTable('olt_onus'), 'olt_onus table must exist after migrate.');
        $this->assertTrue(Schema::hasColumn('olt_onus', 'phase_state'), 'olt_onus must have phase_state column.');
        $this->assertTrue(Schema::hasColumn('olt_onus', 'serial_number'), 'olt_onus must have serial_number column.');
        $this->assertTrue(Schema::hasColumn('olt_onus', 'rx_power_dbm'), 'olt_onus must have rx_power_dbm column.');
    }
}
