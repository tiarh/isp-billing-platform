<?php

namespace Tests\Feature;

use App\Services\Outage\HuaweiOntInfoParser;
use App\Services\Outage\OltClock;
use App\Services\Outage\OutageCauseText;
use App\Services\Outage\RouterOsPppoeLogParser;
use App\Services\Outage\ZteDetailInfoParser;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * RCA-2 parsers against anonymised REAL OLT / RouterOS output (tests/Fixtures/outage). Pure: no DB, no device.
 */
#[Group('blocking-core')]
class OutageRca2ParsersTest extends TestCase
{
    private function fx(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/outage/'.$name));
    }

    // ------------------------------------------------------------------ ZTE

    public function test_zte_online_los_flap_history_and_flapping(): void
    {
        $p = ZteDetailInfoParser::parse($this->fx('zte_detail_online_los_flap.txt'));

        $this->assertSame('working', $p['phase']);
        $this->assertSame(697 * 3600 + 44 * 60 + 36, $p['online_duration_seconds']);
        $this->assertCount(6, $p['history'], 'the four zero rows are ignored');
        $this->assertSame('LOS', $p['history'][1]['cause']);
        $this->assertNull($p['history'][5]['offline_at'], 'row 6 is the current session');
        // the ZTE clock is UTC (owner checked `show clock`); the drift correction is applied by the enricher
        $this->assertSame('2026-09-18 03:29:20', $p['last_offline_at']->format('Y-m-d H:i:s'));
        $this->assertNull($p['cause'], 'online now: no current drop');

        $this->assertTrue(ZteDetailInfoParser::isFlapping($p['history'], Carbon::parse('2026-09-18 04:00:00', 'UTC')), '3+ drops in 30 min, last one recent');
        $this->assertFalse(ZteDetailInfoParser::isFlapping($p['history'], Carbon::parse('2026-10-17 04:00:00', 'UTC')), 'an old burst does not label today');
    }

    public function test_zte_offline_dying_gasp(): void
    {
        $p = ZteDetailInfoParser::parse($this->fx('zte_detail_offline_dyinggasp.txt'));

        $this->assertSame('DyingGasp', $p['phase']);
        $this->assertSame(0, $p['online_duration_seconds']);
        $this->assertCount(1, $p['history']);
        $this->assertSame('dying_gasp', $p['cause']);
        $this->assertSame('2026-09-30 12:00:15', $p['last_offline_at']->format('Y-m-d H:i:s'));
        $this->assertFalse(ZteDetailInfoParser::isFlapping($p['history'], Carbon::parse('2026-09-30 12:30:00', 'UTC')));
    }

    public function test_zte_garbage_and_cause_codes(): void
    {
        $p = ZteDetailInfoParser::parse("% Invalid input\r\n");
        $this->assertNull($p['phase']);
        $this->assertSame([], $p['history']);
        $this->assertNull($p['cause']);

        $this->assertSame('los', ZteDetailInfoParser::causeCode('LOS', null));
        $this->assertSame('other', ZteDetailInfoParser::causeCode('OffLine', 'AdminDown'));
        $this->assertNull(ZteDetailInfoParser::causeCode('OffLine', null));
        $this->assertSame(3661, ZteDetailInfoParser::duration('1h 1m 1s'));
    }

    // --------------------------------------------------------------- Huawei

    public function test_huawei_dying_gasp_reads_only_the_first_block_and_normalises_the_offset(): void
    {
        $p = HuaweiOntInfoParser::parse($this->fx('huawei_ont_dying_gasp.txt'));

        $this->assertSame('online', $p['run_state']);
        $this->assertSame('dying-gasp', $p['last_down_cause'], 'the second block after "Power reduction status" is ignored');
        $this->assertSame('dying_gasp', $p['cause']);
        $this->assertSame('2026-10-17 01:53:17', $p['last_down_at']->format('Y-m-d H:i:s'), '09:53:17+08:00 = 01:53:17Z');
        $this->assertSame('2026-10-17 01:56:50', $p['last_up_at']->format('Y-m-d H:i:s'));
        $this->assertSame($p['last_down_at']->getTimestamp(), $p['last_dying_gasp_at']->getTimestamp());
        $this->assertSame(2 * 3600 + 44 * 60 + 46, $p['online_seconds']);
        $this->assertSame(2 * 3600 + 45 * 60 + 39, $p['system_up_seconds']);
        $this->assertTrue($p['rebooted'], 'online duration ~ system up duration: the ONT restarted (power)');
        $this->assertFalse($p['never_registered']);
    }

    public function test_huawei_never_registered(): void
    {
        $p = HuaweiOntInfoParser::parse($this->fx('huawei_ont_never_registered.txt'));

        $this->assertSame('offline', $p['run_state']);
        $this->assertSame('initial', $p['config_state']);
        $this->assertTrue($p['never_registered']);
        $this->assertSame('never_registered', $p['cause']);
        $this->assertNull($p['last_down_at']);
        $this->assertNull($p['online_seconds']);
    }

    public function test_huawei_online_without_cause_and_link_drop_versus_reboot(): void
    {
        $p = HuaweiOntInfoParser::parse($this->fx('huawei_ont_online_no_cause.txt'));

        $this->assertSame('online', $p['run_state']);
        $this->assertNull($p['last_down_cause']);
        $this->assertNull($p['cause'], 'no recorded drop, no cause');
        $this->assertNull($p['last_down_at']);
        $this->assertSame('2026-10-11 22:47:50', $p['last_up_at']->format('Y-m-d H:i:s'));
        $this->assertSame(5 * 86400 + 6 * 3600 + 60 + 3, $p['online_seconds']);
        $this->assertFalse($p['rebooted'], 'online 5 days vs system up 21 days: the link dropped, the ONT did not restart');
    }

    public function test_huawei_cause_mapping(): void
    {
        $this->assertSame('los', HuaweiOntInfoParser::causeCode(false, 'LOSi', null, 'online'));
        $this->assertSame('los', HuaweiOntInfoParser::causeCode(false, 'LOS', null, 'online'));
        $this->assertSame('dying_gasp', HuaweiOntInfoParser::causeCode(false, 'dying-gasp', true, 'online'));
        $this->assertSame('other', HuaweiOntInfoParser::causeCode(false, 'ONT-reset', null, 'online'));
        $this->assertSame(93784, HuaweiOntInfoParser::duration('1 day(s), 2 hour(s), 3 minute(s), 4 second(s)'));
        $this->assertNull(HuaweiOntInfoParser::duration('-'));
    }

    // ------------------------------------------------------------- RouterOS

    /** @return array<int,array<string,mixed>> */
    private function parsedLog(): array
    {
        $out = [];
        foreach (explode("\n", trim($this->fx('routeros_ra_olt_pppoe.txt'))) as $line) {
            $out[] = RouterOsPppoeLogParser::parse($line);
        }

        return $out;
    }

    public function test_routeros_real_lines(): void
    {
        $r = $this->parsedLog();

        $this->assertSame(['event' => 'disconnected', 'user' => 'USER_UJI_A_2', 'reason' => null, 'mac' => null], $r[0]);
        $this->assertSame(['event' => 'mac_seen', 'user' => null, 'reason' => null, 'mac' => '64:9C:F3:00:00:03'], $r[1]);
        $this->assertSame(['event' => 'connected', 'user' => 'USER_UJI_A_2', 'reason' => null, 'mac' => '64:9C:F3:00:00:03'], $r[2]);
        $this->assertSame('other', $r[3]['event'], '"authenticated" is a step, not an event');
        $this->assertSame('connected', $r[4]['event']);
        $this->assertSame('stuck', $r[6]['event']);
        $this->assertSame('USER_UJI_B_06', $r[6]['user']);
        $this->assertSame('ignored', $r[7]['event'], 'l2tp radius noise is ignored');
        $this->assertSame('disconnected', $r[8]['event']);
        $this->assertSame('perangkat pelanggan tidak merespons', $r[8]['reason']);
        $this->assertSame('disconnected', $r[9]['event']);
        $this->assertSame('64:9C:F3:00:00:44', $r[9]['mac']);
        $this->assertSame('auth_failed', $r[10]['event']);
        $this->assertSame('USER_UJI_D', $r[10]['user']);
        $this->assertSame('unknown', $r[11]['event'], 'a pppoe line in an unknown shape is counted, not dropped silently');
    }

    public function test_routeros_line_without_syslog_prefix_and_non_pppoe_lines(): void
    {
        $this->assertSame('connected', RouterOsPppoeLogParser::parse('pppoe,ppp,info <pppoe-ABC_1>: connected')['event']);
        $this->assertSame('ignored', RouterOsPppoeLogParser::parse('<30>Oct 17 11:40:02 R system,info item added by admin')['event']);
        $this->assertSame('ignored', RouterOsPppoeLogParser::parse('random text')['event']);
        $this->assertSame('ignored', RouterOsPppoeLogParser::parse('')['event']);
    }

    public function test_olt_clock_drift_with_the_real_numbers(): void
    {
        // owner: `show clock` on OLT RA = "06:25:22 Sat Oct 17 2026 UTC" while the real time was about 05:40 UTC
        $olt = OltClock::parseZte("show clock\r\n06:25:22 Sat Oct 17 2026 UTC\r\nZXAN#");
        $this->assertSame('2026-10-17 06:25:22', $olt->format('Y-m-d H:i:s'));
        $drift = OltClock::drift($olt, Carbon::parse('2026-10-17 05:40:30', 'UTC'));
        $this->assertSame(2692, $drift, 'about +45 minutes');
        $this->assertSame('2026-09-18 02:45:08', OltClock::correct(Carbon::parse('2026-09-18 03:30:00', 'UTC'), $drift)->format('Y-m-d H:i:s'));

        // cross-check from the sample: Authpass 2026-09-18 03:30:03 + Online Duration 697h44m36s = OLT now,
        // the sample was taken at about 04:30 UTC => the same ~44 minute drift
        $p = ZteDetailInfoParser::parse((string) file_get_contents(base_path('tests/Fixtures/outage/zte_detail_online_los_flap.txt')));
        $oltNow = $p['history'][5]['online_at']->copy()->addSeconds($p['online_duration_seconds']);
        $this->assertSame('2026-10-17 05:14:39', $oltNow->format('Y-m-d H:i:s'));
        $est = OltClock::drift($oltNow, Carbon::parse('2026-10-17 04:30:00', 'UTC'));
        $this->assertGreaterThan(40 * 60, $est);
        $this->assertLessThan(50 * 60, $est);

        $this->assertNull(OltClock::parseZte('% Invalid input'));
        $this->assertNull(OltClock::drift(null, now()));
        $this->assertSame('2026-10-17 01:40:00', OltClock::parseHuawei('display time'."\r\n".'2026-10-17 09:40:00+08:00')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-17 01:40:00', OltClock::parseHuawei('2026-10-17 09:40:00')->format('Y-m-d H:i:s'), 'no offset is read as +08:00');
        $this->assertNull(OltClock::parseHuawei('Invalid'));
    }

    public function test_cause_text_is_plain_indonesian(): void
    {
        $this->assertSame('Kabel/LOS', OutageCauseText::forPayload(['cause' => 'los'])['label']);
        $this->assertSame('Listrik mati', OutageCauseText::forPayload(['cause' => 'down_unknown', 'cause_detail' => 'dying_gasp'])['label']);
        $this->assertSame('ONU restart', OutageCauseText::forPayload(['cause_detail' => 'onu_reboot'])['label']);
        $this->assertSame('Belum pernah online', OutageCauseText::forPayload(['cause_detail' => 'never_registered', 'enrich' => ['flapping' => true]])['label']);
        $this->assertSame('Putus-sambung', OutageCauseText::forPayload(['cause' => 'los', 'cause_detail' => 'los', 'enrich' => ['flapping' => true]])['label']);
        $this->assertSame('Sesi nyangkut', OutageCauseText::forPayload(['cause_detail' => 'pppoe_stuck'])['label']);
        $this->assertNull(OutageCauseText::forPayload([]));
    }
}
