<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageDiagnosis;
use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\OltHuaweiMa5608tService;
use App\Services\OltZteC300Service;
use App\Services\Outage\Diagnosis\DiagnoseNowService;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OltCauseEnricher;
use App\Support\OltOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * RCA-4 must never disturb PSB WA / migration WA: no job on the psb / migration-* / wa queues from any diagnosis
 * path, and the manual OLT probe answers in under a second while PSB / migration / delete / Huawei-session locks
 * are held (it only probes, it never waits).
 */
#[Group('blocking-psb')]
class Rca4PsbIsolationTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
        Cache::flush();
        config(['diagnosis.probe_driver' => 'fake', 'diagnosis.fake_delay_ms' => 0, 'outage.olt_enrich_dispatch' => false]);
        app()->instance(NotificationService::class, Mockery::mock(NotificationService::class)->shouldIgnoreMissing(true));
    }

    private function olt(string $vendor = 'zte_c300'): Olt
    {
        $olt = Olt::create(['name' => 'OLT ISO '.($this->seq + 1), 'code' => 'ISO-'.++$this->seq, 'vendor' => $vendor, 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        config(['outage.olt_ids' => [$olt->id]]);

        return $olt;
    }

    private function customer(Olt $olt, string $ref, array $o = []): Customer
    {
        $package = Package::firstOrCreate(['code' => 'ISO-PKG'], ['name' => 'ISO pkg', 'price' => 100000]);
        $n = ++$this->seq;

        return Customer::create(array_merge([
            'code' => 'ISO-C'.$n, 'name' => 'Pelanggan ISO '.$n, 'phone' => '0812777'.$n, 'address' => 'Jl. Contoh', 'pppoe_user' => 'USER_ISO_'.$n,
            'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => $ref,
        ], $o));
    }

    public function test_no_diagnosis_path_pushes_a_job_or_sends_a_message(): void
    {
        Queue::fake();
        Http::fake();
        $olt = $this->olt();
        $router = Router::create(['name' => 'RA-ISO', 'ip_address' => '198.18.6.9', 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        $c = $this->customer($olt, '1/1/1:1', ['router_id' => $router->id]);
        OutageOnuTracker::create(['olt_id' => $olt->id, 'onu_ref' => '1/1/1:1', 'customer_id' => $c->id, 'phase' => 'los', 'phase_since_at' => now(), 'last_state_collected_at' => now()]);
        OutageSignal::create(['signal_type' => 'single_los', 'scope_key' => 'customer:'.$c->id, 'state' => 'open', 'olt_id' => $olt->id, 'customer_id' => $c->id, 'opened_at' => now(), 'last_seen_at' => now(), 'observations' => 1, 'affected_count' => 1, 'payload' => ['family' => 'zte', 'cause' => 'los']]);
        Setting::set('diagnosis_check_now_enabled', '1');
        Setting::flushRequestMemo();
        $admin = User::factory()->create(['role' => 'admin']);

        // ticket store -> ticket_open diagnosis (the ticket form itself may notify; RCA-4 adds nothing to that)
        $this->actingAs($admin)->post(route('tickets.store'), ['customer_id' => $c->id, 'customer_name' => '', 'customer_phone' => '', 'customer_coords' => '', 'gangguan' => 'Internet mati', 'type' => 'incident', 'priority' => 'normal'])->assertSessionHasNoErrors();
        $afterStore = $this->pushedCount();
        // the 15 minute refresh
        Artisan::call('outage:diagnose-open');
        // "Cek sekarang": start, three probes, finish
        $id = $this->actingAs($admin)->postJson(route('tickets.diagnose.start'), ['customer_id' => $c->id])->assertOk()->json('check');
        foreach (['router', 'olt', 'cpe'] as $probe) {
            $this->actingAs($admin)->postJson(route('tickets.diagnose.probe', ['diagnosis' => $id, 'probe' => $probe]))->assertOk();
        }
        $this->actingAs($admin)->postJson(route('tickets.diagnose.finish', ['diagnosis' => $id]))->assertOk();
        // the weekly report
        Artisan::call('outage:diagnosis-precision');
        // the stored diagnosis of a detector episode
        app(OutageDiagnoser::class)->forEpisode(OutageSignal::query()->firstOrFail());

        $this->assertGreaterThanOrEqual(3, OutageDiagnosis::query()->count());
        $this->assertSame($afterStore, $this->pushedCount(), 'diagnose-open, Cek sekarang, the precision report and the episode diagnosis queued nothing');
        foreach ($this->pushedQueues() as $queue) {
            $this->assertNotContains($queue, ['psb', 'migration-command', 'migration-live', 'wa'], 'nothing on the PSB / migration / WA queues');
        }
        Http::assertNothingSent();
        $this->assertSame(1, SupportTicket::query()->count());
    }

    private function pushedCount(): int
    {
        return count($this->pushedQueues());
    }

    /** @return list<string|null> queue name of every job pushed so far (Queue::fake) */
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

    public function test_the_manual_olt_probe_answers_in_under_a_second_with_every_lock_held(): void
    {
        config(['diagnosis.probe_driver' => 'real']);
        $zteOlt = $this->olt();
        $huaweiOlt = $this->olt('huawei_ma5608t');
        $zteCalls = 0;
        $zte = new class extends OltZteC300Service
        {
            public int $calls = 0;

            public function onuDetailAndClock(Olt $olt, string $onuRef, int $timeoutSeconds = 25): array
            {
                $this->calls++;

                return ['detail' => '', 'clock' => null];
            }
        };
        $huawei = new class extends OltHuaweiMa5608tService
        {
            public int $calls = 0;

            public function ontInfoAndClockManual(Olt $olt, string $onuRef): array
            {
                $this->calls++;

                return ['info' => '', 'clock' => null];
            }
        };
        $svc = new DiagnoseNowService(new OltCauseEnricher($zte, $huawei), app(OutageDiagnoser::class));
        $cz = $this->customer($zteOlt, '1/5/3:1');
        $ch = $this->customer($huaweiOlt, '0/0/5:3');
        $admin = User::factory()->create(['role' => 'admin']);

        $held = [];
        foreach ([
            OltOperationLock::psbKey($zteOlt), OltOperationLock::migrationKey($zteOlt, '1/5/3'), OltOperationLock::key($zteOlt),
            OltOperationLock::psbKey($huaweiOlt), OltOperationLock::migrationKey($huaweiOlt, '0/0/5'), 'huawei-olt-ssh-session:'.$huaweiOlt->id,
        ] as $key) {
            $lock = Cache::lock($key, 120);
            $this->assertTrue($lock->get(), $key);
            $held[] = $lock;
        }

        foreach ([$cz, $ch] as $customer) {
            $check = OutageDiagnosis::create(['customer_id' => $customer->id, 'created_by' => $admin->id, 'trigger' => 'manual_check', 'state' => 'running', 'probes' => []]);
            $t = microtime(true);
            $r = $svc->probe($check, 'olt');
            $this->assertLessThan(1.0, microtime(true) - $t, 'the OLT probe never waits for a held lock');
            $this->assertSame('skipped', $r['status']);
            $this->assertStringStartsWith('dilewati: OLT sedang dipakai', $r['text']);
        }
        $this->assertSame(0, $zte->calls + $huawei->calls, 'no OLT session while PSB / migration is on the OLT');

        // PSB can still take its own lock: the probe holds none of the keys it only probed
        foreach ($held as $lock) {
            $lock->release();
        }
        foreach ([OltOperationLock::psbKey($zteOlt), OltOperationLock::migrationKey($zteOlt, '1/5/3')] as $key) {
            $probe = Cache::lock($key, 5);
            $this->assertTrue($probe->get(), $key.' is free after the probe');
            $probe->release();
        }
    }

    public function test_the_diagnosis_code_never_blocks_on_a_lock_and_never_touches_the_protected_flows(): void
    {
        foreach ([
            'app/Services/Outage/OltCauseEnricher.php', 'app/Services/Outage/Diagnosis/DiagnoseNowService.php', 'app/Services/Outage/Diagnosis/OutageCorrelator.php',
            'app/Services/Outage/Diagnosis/OutageDiagnoser.php', 'app/Http/Controllers/DiagnoseController.php',
        ] as $file) {
            $source = (string) file_get_contents(base_path($file));
            $this->assertStringNotContainsString('->block(', $source, $file.' must only probe locks (get), never wait for them');
            $this->assertStringNotContainsString('::dispatch(', $source, $file.' must not queue anything');
            foreach (['PsbWaMessages', 'PsbOrchestratorService', 'CustomerMigrationService', 'ProcessCustomerMigrationWaJob', 'GenieAcsWebhookController', 'ProcessGenieAcsInformJob'] as $protected) {
                $this->assertStringNotContainsString($protected, $source, $file);
            }
        }
    }
}
