<?php

namespace Tests\Unit;

use App\Services\Wa\WaGroupGuards;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** Pure role guards: every refusal and every accept, including bare-number identity. */
#[Group('blocking-core')]
class WaGroupGuardsTest extends TestCase
{
    private const PSB = '120363000000000002@g.us';

    private const REPORT = '120363000000000001@g.us';

    private const OPS = '120363000000000003@g.us';

    private const OTHER = '120363000000000006@g.us';

    public function test_report_refuses_psb_group_and_accepts_others(): void
    {
        $this->assertSame('psb_group', WaGroupGuards::reportRefusal(self::PSB, [self::PSB]));
        $this->assertSame('psb_group', WaGroupGuards::reportRefusal('120363000000000002', [self::PSB]), 'bare number is the same group');
        $this->assertNull(WaGroupGuards::reportRefusal(self::OTHER, [self::PSB]));
        $this->assertNull(WaGroupGuards::reportRefusal(self::OTHER, []));
    }

    public function test_ops_alert_refuses_psb_group_only(): void
    {
        $this->assertSame('psb_group', WaGroupGuards::opsAlertRefusal(self::PSB, [self::PSB]));
        $this->assertNull(WaGroupGuards::opsAlertRefusal(self::REPORT, [self::PSB]));
        $this->assertNull(WaGroupGuards::opsAlertRefusal(self::OTHER, [self::PSB]));
    }

    public function test_shadow_refusal_matrix(): void
    {
        $psb = [self::PSB];
        $report = [self::REPORT];

        $this->assertSame('empty', WaGroupGuards::shadowRefusal('', false, $psb, $report, self::OPS));
        $this->assertSame('psb_group', WaGroupGuards::shadowRefusal(self::PSB, false, $psb, $report, self::OPS));
        $this->assertSame('psb_group', WaGroupGuards::shadowRefusal(self::PSB, true, $psb, $report, self::OPS), 'allow flag never unlocks PSB');
        $this->assertSame('report_or_ops_group', WaGroupGuards::shadowRefusal(self::REPORT, false, $psb, $report, self::OPS));
        $this->assertSame('report_or_ops_group', WaGroupGuards::shadowRefusal(self::OPS, false, $psb, $report, self::OPS));
        $this->assertNull(WaGroupGuards::shadowRefusal(self::REPORT, true, $psb, $report, self::OPS));
        $this->assertNull(WaGroupGuards::shadowRefusal(self::OPS, true, $psb, $report, self::OPS));
        $this->assertNull(WaGroupGuards::shadowRefusal(self::OTHER, false, $psb, $report, self::OPS));
    }

    public function test_undelivered_refusal_matrix(): void
    {
        $psb = [self::PSB];
        $report = [self::REPORT];

        $this->assertNull(WaGroupGuards::undeliveredRefusal('', $psb, $report, self::OPS), 'empty means no alert, not a refusal');
        $this->assertSame('psb_group', WaGroupGuards::undeliveredRefusal(self::PSB, $psb, $report, self::OPS));
        $this->assertSame('report_group', WaGroupGuards::undeliveredRefusal(self::REPORT, $psb, $report, self::OPS));
        $this->assertSame('ops_group', WaGroupGuards::undeliveredRefusal(self::OPS, $psb, $report, self::OPS));
        $this->assertSame('ops_group', WaGroupGuards::undeliveredRefusal('120363000000000003', $psb, $report, self::OPS));
        $this->assertNull(WaGroupGuards::undeliveredRefusal(self::OTHER, $psb, $report, self::OPS));
        $this->assertNull(WaGroupGuards::undeliveredRefusal(self::OTHER, $psb, $report, ''), 'no ops group configured');
    }

    public function test_group_jid_format(): void
    {
        $this->assertTrue(WaGroupGuards::isGroupJid(self::OTHER));
        $this->assertTrue(WaGroupGuards::isGroupJid('120363000000000006'));
        $this->assertFalse(WaGroupGuards::isGroupJid('abc@s.whatsapp.net'));
        $this->assertFalse(WaGroupGuards::isGroupJid('abc'));
        $this->assertFalse(WaGroupGuards::isGroupJid(''));
    }
}
