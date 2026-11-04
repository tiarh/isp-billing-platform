<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\Monitoring\RouterPppoeReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomersLifecycleGuardReportCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturn(['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null]);
        $this->app->instance(RouterPppoeReadModel::class, $model);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_report_counts_verdicts_by_reason_and_writes_nothing(): void
    {
        $router = Router::create(['name' => 'R', 'ip_address' => '127.0.31.1', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
        $package = Package::firstOrCreate(['code' => 'GR-PKG'], ['name' => 'Guard', 'price' => 0]);
        $make = fn (string $code, string $status, bool $online) => Customer::create([
            'code' => $code, 'name' => 'Rahasia Nama '.$code, 'phone' => '0819999'.substr($code, -2), 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'secret-user-'.$code,
            'router_id' => $router->id, 'is_online' => $online, 'pppoe_online_checked_at' => now()->subMinutes(2),
        ]);
        $online = $make('GR-01', 'isolated', true);
        $offline = $make('GR-02', 'isolated', false);
        $make('GR-03', 'active', true);
        foreach (['2026-10-14', '2026-10-13'] as $day) {
            DB::table('pppoe_usage_daily')->insert([
                'usage_date_wib' => $day, 'router_id' => $router->id, 'pppoe_user' => 'hb', 'customer_id' => null,
                'download_bytes' => 0, 'upload_bytes' => 0, 'is_final' => false, 'quality' => 'ok',
                'created_at' => now()->subMinutes(5)->toDateTimeString(), 'updated_at' => now()->subMinutes(5)->toDateTimeString(),
            ]);
        }

        $before = [
            'customers' => DB::table('customers')->get()->toJson(),
            'usage' => DB::table('pppoe_usage_daily')->count(),
            'activity' => DB::table('activity_log')->count(),
        ];
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (! preg_match('/^\s*select\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->artisan('customers:lifecycle-guard-report', ['--lines' => true])
            ->expectsOutputToContain('total=2 allow=1 deny=1 unknown=0')
            ->expectsOutputToContain('allow allowed[onu_na]')
            ->expectsOutputToContain('allowed with no evidence (a signal was not applicable): 1')
            ->expectsOutputToContain('deny pppoe:pppoe_in_use')
            ->expectsOutputToContain('customer='.$online->id.' code=GR-01 verdict=deny')
            ->expectsOutputToContain('customer='.$offline->id.' code=GR-02 verdict=allow')
            ->assertSuccessful();

        $this->assertSame([], $writes, 'report must not write anything');
        $this->assertSame($before['customers'], DB::table('customers')->get()->toJson());
        $this->assertSame($before['usage'], DB::table('pppoe_usage_daily')->count());
        $this->assertSame($before['activity'], DB::table('activity_log')->count());
    }

    public function test_report_output_never_contains_names_phones_or_pppoe_users(): void
    {
        $router = Router::create(['name' => 'R2', 'ip_address' => '127.0.31.2', 'username' => 'u', 'password' => 'p', 'is_active' => true]);
        $package = Package::firstOrCreate(['code' => 'GR-PKG'], ['name' => 'Guard', 'price' => 0]);
        Customer::create([
            'code' => 'GR-77', 'name' => 'Budi Rahasia', 'phone' => '081200000043', 'address' => 'RW 1', 'package_id' => $package->id,
            'status' => 'terminated', 'pppoe_user' => 'budi-secret-pppoe', 'router_id' => $router->id,
            'is_online' => true, 'pppoe_online_checked_at' => now(),
        ]);

        Artisan::call('customers:lifecycle-guard-report', ['--lines' => true, '--status' => 'terminated']);
        $output = Artisan::output();

        $this->assertStringContainsString('code=GR-77', $output);
        foreach (['Budi', 'Rahasia', '081200000043', 'budi-secret-pppoe'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
    }

    public function test_archived_flag_reports_soft_deleted_customers_only(): void
    {
        $package = Package::firstOrCreate(['code' => 'GR-PKG'], ['name' => 'Guard', 'price' => 0]);
        $mk = fn (string $code) => Customer::create([
            'code' => $code, 'name' => 'N '.$code, 'phone' => '0817'.crc32($code), 'address' => 'RW 1', 'package_id' => $package->id,
            'status' => 'terminated', 'pppoe_user' => 'u-'.$code,
        ]);
        $mk('GA-01');
        $mk('GA-02')->delete();

        $this->artisan('customers:lifecycle-guard-report', ['--archived' => true])
            ->expectsOutputToContain('archived, read-only): total=1')
            ->assertSuccessful();
    }
}
