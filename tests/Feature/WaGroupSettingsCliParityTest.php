<?php

namespace Tests\Feature;

use App\Console\Commands\ReportDailyWaCommand;
use App\Models\Setting;
use App\Services\Outage\OutageShadowNotifier;
use App\Services\Report\ReportGroupRegistry;
use App\Services\Wa\WaGroupGuards;
use App\Services\Wa\WaGroupRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\FakesWaGroups;
use Tests\TestCase;

/**
 * Parity: the page accepts/refuses exactly what the existing CLI commands and runtime guards accept/refuse,
 * and stores the same value format. Guards are shared pure functions; this test pins that nobody forks them.
 */
#[Group('blocking-core')]
class WaGroupSettingsCliParityTest extends TestCase
{
    use FakesWaGroups;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedWaSettings();
        $this->fakeEvolution();
    }

    private function st(string $key, mixed $default = null): mixed
    {
        Setting::flushRequestMemo();

        return Setting::get($key, $default);
    }

    /** @return list<string> */
    private function candidates(): array
    {
        return [self::G_REPORT, self::G_PSB, '120363000000000002', self::G_OPS, self::G_SHADOW, self::G_UNDELIVERED, self::G_NEW, 'bukan-jid'];
    }

    public function test_shadow_runtime_guard_equals_shared_guard_for_every_candidate(): void
    {
        $registry = app(ReportGroupRegistry::class);
        foreach ([false, true] as $allow) {
            foreach ($this->candidates() as $jid) {
                $this->assertSame(
                    WaGroupGuards::shadowRefusal($jid, $allow, $registry->psbGroupJids(), $registry->groupJids(), app(WaGroupRoles::class)->effectiveOpsJid()),
                    (new OutageShadowNotifier)->refusalReason($jid, $allow),
                    "shadow guard drift for {$jid} allow=".var_export($allow, true),
                );
            }
        }
    }

    public function test_shadow_page_and_outage_settings_cli_agree_on_accept_refuse_and_stored_value(): void
    {
        foreach ([false, true] as $allow) {
            foreach ($this->candidates() as $jid) {
                Setting::set('outage_shadow_allow_report_group', $allow ? '1' : '0', 'text', 'outage');
                Setting::set('outage_shadow_log_jid', '', 'text', 'outage');

                $cliOk = Artisan::call('outage:settings', ['action' => 'set', 'key' => 'outage_shadow_log_jid', 'value' => $jid]) === 0;
                $cliStored = $this->st('outage_shadow_log_jid');

                Setting::set('outage_shadow_log_jid', '', 'text', 'outage');
                $this->putSection($this->superadmin(), 'shadow', ['jid' => $jid, 'allow_report_group' => $allow]);
                $uiStored = $this->st('outage_shadow_log_jid');
                $uiOk = $uiStored !== '' && $uiStored !== null;

                $this->assertSame($cliOk, $uiOk, "shadow accept/refuse differs for {$jid} allow=".var_export($allow, true));
                if ($cliOk) {
                    $this->assertSame($cliStored, $uiStored, 'stored shadow JID format differs');
                }
            }
        }
    }

    public function test_report_page_and_report_wa_group_cli_agree(): void
    {
        foreach ($this->candidates() as $jid) {
            Setting::set('report_wa_group_ids', [], 'json', 'report');
            $cliOk = Artisan::call('report:wa-group', ['action' => 'set', 'jid' => $jid]) === 0;
            $cliStored = $this->st('report_wa_group_ids');

            Setting::set('report_wa_group_ids', [], 'json', 'report');
            Cache::flush();
            $this->putSection($this->superadmin(), 'report', ['group_jid' => $jid, 'channel' => 'ops']);
            $uiStored = $this->st('report_wa_group_ids');

            $this->assertSame($cliOk, $uiStored !== [], "report accept/refuse differs for {$jid}");
            if ($cliOk) {
                $this->assertSame($cliStored, $uiStored, 'stored Report JID list format differs');
            }
        }
    }

    public function test_report_membership_rules_match_the_cli_including_force(): void
    {
        $unknown = ['member' => null, 'subject' => null, 'size' => null, 'error' => 'timeout'];
        $no = ['member' => false, 'subject' => null, 'size' => null, 'error' => null];

        foreach ([['unknown', $unknown], ['no', $no]] as [$label, $membership]) {
            foreach ([false, true] as $force) {
                $this->membershipByJid[self::G_NEW] = $membership;
                Cache::flush();

                Setting::set('report_wa_group_ids', [], 'json', 'report');
                $args = ['action' => 'set', 'jid' => self::G_NEW] + ($force ? ['--force' => true] : []);
                $cliOk = Artisan::call('report:wa-group', $args) === 0;

                Setting::set('report_wa_group_ids', [], 'json', 'report');
                $this->putSection($this->superadmin(), 'report', ['group_jid' => self::G_NEW, 'channel' => 'ops'], null, $force ? ['report'] : []);
                $uiOk = $this->st('report_wa_group_ids') !== [];

                $this->assertSame($cliOk, $uiOk, "membership {$label} force=".var_export($force, true));
            }
        }
    }

    public function test_undelivered_runtime_guard_equals_page_decision(): void
    {
        $registry = app(ReportGroupRegistry::class);
        $method = new \ReflectionMethod(ReportDailyWaCommand::class, 'alertJid');
        $method->setAccessible(true);
        $command = (new \ReflectionClass(ReportDailyWaCommand::class))->newInstanceWithoutConstructor();

        // The runtime guard only decides by role (format is validated at save time), so compare role decisions.
        foreach (array_filter($this->candidates(), fn (string $j) => WaGroupGuards::isGroupJid($j)) as $jid) {
            Setting::set('report_undelivered_alert_jid', $jid, 'text', 'report');
            $runtimeAccepts = $method->invoke($command, $registry) !== null;

            Setting::set('report_undelivered_alert_jid', '', 'text', 'report');
            $this->putSection($this->superadmin(), 'undelivered', ['jid' => $jid]);
            $uiAccepts = $this->st('report_undelivered_alert_jid') !== '';

            $this->assertSame($runtimeAccepts, $uiAccepts, "undelivered accept/refuse differs for {$jid}");
        }
    }
}
