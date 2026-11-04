<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageOutcomeLabel;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Learn & measure (outcome labels, back-test, calibration, close-form chip) must never disturb PSB WA / migration WA:
 * nothing on the psb / migration-* / wa queues, no message sent, no PSB / migration / WA class in the new code, and the
 * labeler only READS customer_migrations and psb_drafts (roadmap v2 section 3a).
 */
#[Group('blocking-psb')]
class DiagnosisLearnMeasurePsbIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_FILES = [
        'app/Services/Outage/Outcome/OutcomeLabeler.php',
        'app/Services/Outage/Diagnosis/Backtest/SnapshotHydrator.php',
        'app/Services/Outage/Diagnosis/Backtest/BacktestRunner.php',
        'app/Services/Outage/Diagnosis/Backtest/BacktestResult.php',
        'app/Services/Outage/Diagnosis/Backtest/BacktestGate.php',
        'app/Services/Outage/Diagnosis/Backtest/CorpusReader.php',
        'app/Services/Outage/Diagnosis/Backtest/CorpusFiles.php',
        'app/Services/Outage/Diagnosis/Backtest/CorpusLabels.php',
        'app/Services/Outage/Diagnosis/Backtest/CorpusScrubber.php',
        'app/Console/Commands/OutageOutcomeLabelCommand.php',
        'app/Console/Commands/OutageDiagnosisBacktest.php',
        'app/Console/Commands/OutageDiagnosisCorpusExport.php',
        'app/Console/Commands/OutageDiagnosisContextPrune.php',
        'app/Models/OutageOutcomeLabel.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
    }

    public function test_the_new_code_has_no_queue_no_message_and_no_psb_or_migration_class(): void
    {
        foreach (self::NEW_FILES as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertNotSame('', $source, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/::dispatch|\bdispatch(Sync|Now|AfterResponse)?\s*\(|\bQueue::|\bBus::|EvolutionApiService|NotificationService|CustomerMigrationService|PsbOrchestratorService|PsbWaMessages|ProcessPsbWaJob|ProcessCustomerMigrationWaJob|ResumePsbDraftJob|App\\\\Services\\\\Wa\\\\/',
                $source,
                $path.' must not queue, send or touch PSB / migration / WA code',
            );
            // the two tables it reads are never written
            $this->assertDoesNotMatchRegularExpression("/table\('(customer_migrations|psb_drafts)'\)\s*->\s*(insert|update|delete|upsert)/", $source, $path);
        }
    }

    public function test_every_new_command_and_the_close_flow_queue_nothing_and_send_nothing(): void
    {
        Queue::fake();
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-23 12:00:00', 'UTC'));
        Setting::set('diagnosis_signature_chip_enabled', '1');
        Setting::flushRequestMemo();

        $olt = Olt::create(['name' => 'OLT ISO', 'code' => 'ISO-OLT', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::create(['code' => 'ISO-PKG', 'name' => 'ISO pkg', 'price' => 100000]);
        $admin = User::factory()->create(['role' => 'admin']);
        $made = [];
        foreach ([1, 2] as $n) {
            $ref = '1/2/10:'.$n;
            $c = Customer::create(['code' => 'ISO-C'.$n, 'name' => 'Pelanggan ISO '.$n, 'phone' => '0812666'.$n, 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_ISO_'.$n, 'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => $ref]);
            OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'customer_id' => $c->id, 'phase' => 'online', 'rx_daily' => [['date' => '2026-10-20', 'dbm' => -28.0, 'n' => 20], ['date' => '2026-10-21', 'dbm' => -27.9, 'n' => 20], ['date' => '2026-10-23', 'dbm' => -21.0, 'n' => 20]]]);
            OltOnu::create(['olt_id' => $olt->id, 'onu_ref' => $ref, 'pon_port' => '1/2/10', 'state' => 'working', 'phase_state' => 'working', 'rx_power_dbm' => -20.7, 'state_collected_at' => now()->subHour(), 'phase_collected_at' => now()->subHour(), 'rx_collected_at' => now()->subHour()]);
            $ticket = SupportTicket::create(['created_by' => $admin->id, 'customer_id' => $c->id, 'gangguan' => 'Mati', 'status' => $n === 1 ? 'resolved' : 'assigned', 'type' => 'incident', 'priority' => 'normal']);
            DB::table('support_tickets')->where('id', $ticket->id)->update(['created_at' => '2026-10-22 03:00:00', 'resolved_at' => $n === 1 ? '2026-10-22 09:00:00' : null]);
            $made[$n] = $ticket->fresh();
        }
        $queuedBefore = $this->pushedCount();
        $migrations = DB::table('customer_migrations')->count();
        $drafts = DB::table('psb_drafts')->count();

        // hourly outcome labels, the weekly report (calibration), the back-test, the export and the snapshot prune
        Artisan::call('outage:outcome-label');
        Artisan::call('outage:diagnosis-precision');
        Artisan::call('outage:diagnosis-backtest', ['--corpus' => 'db']);
        Artisan::call('outage:diagnosis-corpus-export', ['--out' => 'iso-test.jsonl.gz']);
        Artisan::call('outage:diagnosis-context-prune');
        @unlink(storage_path('app/diagnosis-corpus/iso-test.jsonl.gz'));
        $this->assertSame($queuedBefore, $this->pushedCount(), 'the new commands queued nothing');
        Http::assertNothingSent();
        // the close form: the chip page and a close with via = signature (the close itself is the existing flow)
        $this->actingAs($admin)->get(route('tickets.show', $made[2]))->assertInertia(fn ($page) => $page->where('repairSignature.code', 'optical_degrade'));
        $this->actingAs($admin)->put(route('tickets.update', $made[2]), ['status' => 'resolved', 'root_cause' => 'optical_degrade', 'root_cause_via' => 'signature'])->assertSessionHasNoErrors();

        $this->assertGreaterThanOrEqual(1, OutageOutcomeLabel::query()->count(), 'the labeler did run');
        $this->assertSame($migrations, DB::table('customer_migrations')->count());
        $this->assertSame($drafts, DB::table('psb_drafts')->count());
        foreach ($this->pushedQueues() as $queue) {
            $this->assertNotContains($queue, ['psb', 'migration-command', 'migration-live', 'wa'], 'nothing on the PSB / migration / WA queues');
        }
        $this->assertSame(0, OutageDiagnosis::query()->where('trigger', 'auto_refresh')->count());
        Carbon::setTestNow();
    }

    private function pushedCount(): int
    {
        return count($this->pushedQueues());
    }

    /** @return list<string|null> */
    private function pushedQueues(): array
    {
        $out = [];
        foreach (Queue::pushedJobs() as $entries) {
            foreach ($entries as $entry) {
                $out[] = $entry['queue'] ?? null;
            }
        }

        return $out;
    }
}
