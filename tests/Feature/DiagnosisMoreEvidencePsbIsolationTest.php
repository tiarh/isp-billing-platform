<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OutageSignal;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
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
 * PR-D (session bursts, area power, traffic evidence) must never disturb PSB WA / migration WA: the new code queues and
 * sends nothing, touches no PSB / migration / WA class, and the new command runs with Queue::fake and Http::fake without
 * a single job or request (roadmap v2 section 3a, Rca34PsbIsolationTest style).
 */
#[Group('blocking-psb')]
class DiagnosisMoreEvidencePsbIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_FILES = [
        'app/Services/Outage/SessionBurstDetector.php',
        'app/Services/Outage/AreaPowerDetector.php',
        'app/Console/Commands/OutageSessionBursts.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushRequestMemo();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_new_code_has_no_queue_no_message_and_no_psb_or_migration_class(): void
    {
        foreach (self::NEW_FILES as $path) {
            $source = (string) file_get_contents(base_path($path));
            $this->assertNotSame('', $source, $path);
            $this->assertDoesNotMatchRegularExpression(
                '/::dispatch|\bdispatch(Sync|Now|AfterResponse)?\s*\(|\bQueue::|\bBus::|\bHttp::|EvolutionApiService|NotificationService|CustomerMigrationService|PsbOrchestratorService|PsbWaMessages|ProcessPsbWaJob|ProcessCustomerMigrationWaJob|ResumePsbDraftJob|App\\\\Services\\\\Wa\\\\/',
                $source,
                $path.' must not queue, send or touch PSB / migration / WA code',
            );
            // it reads the billing ledger and the activity log, it never writes them
            $this->assertDoesNotMatchRegularExpression("/table\('(billing_enforcement_actions|activity_log|customer_migrations|psb_drafts|customers|pppoe_session_events|onu_state_events)'\)\s*->\s*(insert|update|delete|upsert)/", $source, $path);
        }
    }

    public function test_the_command_with_every_flag_on_queues_nothing_sends_nothing_and_writes_only_signals(): void
    {
        Queue::fake();
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-10-28 03:10:00', 'UTC'));
        foreach (['diagnosis_session_burst_enabled', 'diagnosis_area_power_enabled', 'diagnosis_traffic_guard_enabled'] as $key) {
            Setting::set($key, '1');
        }
        $router = Router::create(['name' => 'RA-OLT iso', 'ip_address' => '198.18.8.2', 'username' => 'admin', 'password' => Canary::of('pw'), 'port' => 8728, 'is_active' => true, 'connection_status' => 'online']);
        $olt = Olt::create(['name' => 'OLT iso', 'code' => 'BI-1', 'vendor' => 'zte_c300', 'management_ip' => '198.18.8.9', 'username' => 'u', 'password' => Canary::of('pw')]);
        $package = Package::create(['code' => 'BI-PKG', 'name' => 'Iso pkg', 'price' => 100000]);
        $customersBefore = null;
        for ($i = 1; $i <= 10; $i++) {
            $c = Customer::create([
                'code' => 'BI-C'.$i, 'name' => 'SITI CONTOH '.$i, 'phone' => '628120000010'.$i % 10, 'address' => 'Jl. Contoh', 'pppoe_user' => 'iso.user.'.$i,
                'package_id' => $package->id, 'status' => 'active', 'olt_id' => $olt->id, 'olt_port_label' => '1/2/3:'.$i, 'router_id' => $router->id,
            ]);
            DB::table('pppoe_session_events')->insert([
                'router_id' => $router->id, 'pppoe_user' => $c->pppoe_user, 'customer_id' => $c->id, 'event' => 'disconnected', 'reason' => 'peer not responding',
                'at' => now()->subMinutes(8)->addSeconds($i)->format('Y-m-d H:i:s'), 'source' => 'syslog',
            ]);
        }
        $customersBefore = DB::table('customers')->orderBy('id')->get()->toArray();

        $this->assertSame(0, Artisan::call('outage:session-bursts'));

        $this->assertSame(1, OutageSignal::query()->where('signal_type', 'session_burst')->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
        $this->assertEquals($customersBefore, DB::table('customers')->orderBy('id')->get()->toArray());
        foreach (['psb_drafts', 'customer_migrations', 'support_tickets'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
