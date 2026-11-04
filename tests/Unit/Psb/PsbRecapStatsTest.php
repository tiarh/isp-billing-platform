<?php

namespace Tests\Unit\Psb;

use App\Models\PsbDraft;
use App\Models\Setting;
use App\Services\Psb\PsbRecapStats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-psb')]
class PsbRecapStatsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function draft(string $status, array $extra = []): PsbDraft
    {
        $this->seq++;

        return PsbDraft::create(array_merge([
            'status' => $status,
            'activation_mode' => PsbDraft::MODE_FULL_OLT,
            'site_profile_key' => 'ra',
            'group_jid' => '120363000000000001@g.us',
            'message_id' => 'stats-'.$this->seq,
            'full_name' => 'Budi Contoh '.$this->seq,
            'rw' => '1',
            'daerah' => 'Randuagung',
        ], $extra));
    }

    public function test_default_schedule_and_send_hours(): void
    {
        $schedule = PsbRecapStats::recapSchedule();

        $this->assertSame(['start' => 9, 'end' => 21, 'interval' => 2], $schedule);
        $this->assertSame([9, 11, 13, 15, 17, 19, 21], PsbRecapStats::recapSendHours($schedule));
    }

    public function test_schedule_uses_settings_and_falls_back_on_bad_values(): void
    {
        Setting::set('psb_recap_start_hour', '8', 'text', 'psb');
        Setting::set('psb_recap_end_hour', '20', 'text', 'psb');
        Setting::set('psb_recap_interval_hours', '4', 'text', 'psb');
        $this->assertSame(['start' => 8, 'end' => 20, 'interval' => 4], PsbRecapStats::recapSchedule());

        Setting::set('psb_recap_start_hour', '25', 'text', 'psb');
        $this->assertSame(['start' => 9, 'end' => 20, 'interval' => 4], PsbRecapStats::recapSchedule());

        Setting::set('psb_recap_interval_hours', '0', 'text', 'psb');
        $this->assertSame(2, PsbRecapStats::recapSchedule()['interval']);

        Setting::set('psb_recap_start_hour', '18', 'text', 'psb');
        Setting::set('psb_recap_end_hour', '10', 'text', 'psb');
        $this->assertSame(['start' => 9, 'end' => 21, 'interval' => 2], PsbRecapStats::recapSchedule());

        Setting::set('psb_recap_start_hour', '9.5', 'text', 'psb');
        Setting::set('psb_recap_end_hour', '21', 'text', 'psb');
        $this->assertSame(9, PsbRecapStats::recapSchedule()['start']);
    }

    public function test_collect_today_stats_counts_statuses_and_sources(): void
    {
        $this->draft(PsbDraft::STATUS_OLT_OK);
        $this->draft(PsbDraft::STATUS_APPLIED, ['meta' => ['source' => 'manual_form']]);
        $this->draft(PsbDraft::STATUS_WAITING_SN);
        $this->draft(PsbDraft::STATUS_FAILED);
        $pending = $this->draft(PsbDraft::STATUS_PENDING_VLAN);

        $stats = (new PsbRecapStats)->collectTodayStats();

        $this->assertSame(5, $stats['total']);
        $this->assertSame(2, $stats['olt_ok'], 'olt_ok + applied');
        $this->assertSame(1, $stats['applied']);
        $this->assertSame(1, $stats['wait_sn']);
        $this->assertSame(1, $stats['pend_vlan']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['secret_ok']);
        $this->assertSame($pending->id, $stats['max_id']);
        $this->assertSame(['wa' => 4, 'manual' => 1], $stats['source_counts']);
        $this->assertCount(1, $stats['pending_rows']);
        $this->assertSame($pending->id, $stats['pending_rows'][0]['id']);
        $this->assertSame(['olt_online' => 0, 'pppoe_linked' => 0, 'attention' => []], $stats['device_sync']);
        $this->assertStringEndsWith(' WIB', $stats['label']);
    }

    public function test_fingerprint_changes_with_data_not_with_label(): void
    {
        $this->draft(PsbDraft::STATUS_WAITING_SN);
        $recap = new PsbRecapStats;

        $a = $recap->collectTodayStats();
        $b = $a;
        $b['label'] = 'another label';
        $this->assertSame($recap->todayStatsFingerprint($a), $recap->todayStatsFingerprint($b));

        $c = $a;
        $c['total'] = 2;
        $this->assertNotSame($recap->todayStatsFingerprint($a), $recap->todayStatsFingerprint($c));
        $this->assertSame(64, strlen($recap->todayStatsFingerprint($a)));
    }
}
