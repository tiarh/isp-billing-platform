<?php

namespace Tests\Unit;

use App\Services\OltSnapshotStatsService;
use Tests\TestCase;

class OltSnapshotStatsServiceTest extends TestCase
{
    public function test_stats_are_exclusive_and_preserve_match_conflicts(): void
    {
        $snapshot = app(OltSnapshotStatsService::class)->enrich([
            'meta' => ['olt' => 'Test OLT', 'collected_at' => '2026-08-06T00:00:00Z'],
            'onus' => [
                ['onu_ref' => '1/1/1:1', 'state' => 'online', 'rx_power_dbm' => -20, 'serial_number' => 'FHTT-A'],
                ['onu_ref' => '1/1/1:2', 'state' => 'offline', 'rx_power_dbm' => null, 'serial_number' => 'FHTT-B'],
                ['onu_ref' => '1/1/1:3', 'state' => 'online', 'rx_power_dbm' => null, 'serial_number' => 'FHTT-C'],
                ['onu_ref' => '1/1/1:4', 'state' => 'online', 'rx_power_dbm' => -31, 'serial_number' => 'FHTT-D', 'match_conflict' => 'reused_ref'],
                ['onu_ref' => '1/1/1:5', 'state' => null, 'phase_state' => null, 'rx_power_dbm' => null, 'serial_number' => 'FHTT-E'],
                ['onu_ref' => '1/1/1:6', 'state' => 'online', 'rx_power_dbm' => -26, 'serial_number' => 'FHTT-A'],
            ],
            'matched_customers' => [['onu_ref' => '1/1/1:1']],
        ]);

        $stats = $snapshot['meta']['stats'];

        $this->assertTrue($snapshot['meta']['stats_invariant_ok']);
        $this->assertSame('2026-08-06T00:00:00Z', $snapshot['meta']['captured_at']);
        $this->assertSame('gpon_snapshot', $snapshot['meta']['source']);
        $this->assertFalse($snapshot['meta']['stale']);
        $this->assertSame(6, $stats['total']);
        $this->assertSame(4, $stats['online']);
        $this->assertSame(1, $stats['offline']);
        $this->assertSame(1, $stats['state_unknown']);
        $this->assertSame(1, $stats['matched']);
        $this->assertSame(1, $stats['match_conflict']);
        $this->assertSame(4, $stats['unmatched']);
        $this->assertSame(1, $stats['signal_good']);
        $this->assertSame(1, $stats['signal_weak']);
        $this->assertSame(1, $stats['signal_loss']);
        $this->assertSame(1, $stats['signal_missing']);
        $this->assertSame(2, $stats['signal_not_applicable']);
        $this->assertSame(1, $stats['sn_duplicate_groups']);
        $this->assertSame(2, $stats['sn_duplicate_rows']);
    }
}
