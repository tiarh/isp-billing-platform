<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\Package;
use App\Models\Setting;
use App\Services\Outage\Diagnosis\DiagnosisContextSnapshot;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Detector episodes (auto_episode) keep a bounded context snapshot for the back-test (learn & measure section 2.1):
 * a setting (default on) turns it off, ticket_open / manual_check are unaffected, the 15 minute refresh never gets one,
 * and `outage:diagnosis-context-prune` clears only auto_episode snapshots older than 180 days.
 */
#[Group('blocking-core')]
class DiagnosisEpisodeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-10-26 03:00:00', 'UTC');
        Carbon::setTestNow($this->now);
        Setting::flushRequestMemo();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(): Customer
    {
        $olt = Olt::create(['name' => 'OLT EP', 'code' => 'EP-OLT', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::create(['code' => 'EP-PKG', 'name' => 'EP pkg', 'price' => 100000]);
        $c = Customer::create(['code' => 'EP-C1', 'name' => 'Pelanggan EP', 'phone' => '0810000012', 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_EP_1', 'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => '1/2/3:4']);
        OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => '1/2/3:4', 'customer_id' => $c->id, 'phase' => 'online', 'last_state_collected_at' => $this->now]);

        return $c->fresh();
    }

    private function diagnoser(): OutageDiagnoser
    {
        return app(OutageDiagnoser::class);
    }

    public function test_the_setting_defaults_on_and_an_episode_gets_a_bounded_snapshot_without_identifiers(): void
    {
        $this->assertTrue(OutageSettings::diagnosisEpisodeSnapshotEnabled());
        $c = $this->customer();

        $episode = $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_EPISODE, ['now' => $this->now]);

        $this->assertIsArray($episode->fresh()->context);
        $json = (string) DB::table('outage_diagnoses')->where('id', $episode->id)->value('context');
        $this->assertLessThanOrEqual(DiagnosisContextSnapshot::MAX_BYTES, strlen($json));
        $this->assertStringNotContainsString('USER_EP_', $json);
        $this->assertStringNotContainsString('"pppoe_user"', $json);
    }

    public function test_the_setting_off_stops_episode_snapshots_but_not_the_ticket_ones(): void
    {
        Setting::set('diagnosis_episode_snapshot_enabled', '0');
        Setting::flushRequestMemo();
        $c = $this->customer();

        $episode = $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_EPISODE, ['now' => $this->now]);
        $open = $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_TICKET_OPEN, ['now' => $this->now]);
        $refresh = $this->diagnoser()->diagnose($c, OutageDiagnosis::TRIGGER_AUTO_REFRESH, ['now' => $this->now]);

        $this->assertNull($episode->fresh()->context);
        $this->assertIsArray($open->fresh()->context);
        $this->assertNull($refresh->fresh()->context);
    }

    public function test_the_prune_clears_old_episode_snapshots_only(): void
    {
        $c = $this->customer();
        $mk = function (string $trigger, int $daysAgo) use ($c): OutageDiagnosis {
            $d = $this->diagnoser()->diagnose($c, $trigger, ['now' => $this->now]);
            DB::table('outage_diagnoses')->where('id', $d->id)->update(['created_at' => $this->now->copy()->subDays($daysAgo)->format('Y-m-d H:i:s')]);

            return $d;
        };
        $oldEpisode = $mk(OutageDiagnosis::TRIGGER_AUTO_EPISODE, 190);
        $freshEpisode = $mk(OutageDiagnosis::TRIGGER_AUTO_EPISODE, 100);
        $oldTicket = $mk(OutageDiagnosis::TRIGGER_TICKET_OPEN, 190);

        $this->assertSame(0, Artisan::call('outage:diagnosis-context-prune', ['--dry-run' => true]));
        $this->assertStringContainsString('snapshots to clear: 1', Artisan::output());
        $this->assertIsArray($oldEpisode->fresh()->context, 'dry run changes nothing');

        $this->assertSame(0, Artisan::call('outage:diagnosis-context-prune'));

        $this->assertNull($oldEpisode->fresh()->context, 'older than 180 days');
        $this->assertNotNull($oldEpisode->fresh()->cause_code, 'the diagnosis row itself stays');
        $this->assertIsArray($freshEpisode->fresh()->context);
        $this->assertIsArray($oldTicket->fresh()->context, 'ticket snapshots are scored later and never touched here');
        $this->assertSame(1, Artisan::call('outage:diagnosis-context-prune', ['--days' => 0]));
    }
}
