<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Services\OltOnuSerialResolver;
use App\Services\PppoeOnlineFlagWriter;
use App\Services\UnifiedConnectivityReadModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Bug 2026-10-09 (ZTE C300 RA, olt_id=1): one ONU serial registered twice,
 * a stale old registration (offline/DyingGasp on 1/3/3:19) next to the live
 * one (online/working on 1/5/1:9), both collected the same minute; plus a
 * leftover row on another OLT. The offline duplicate won, so online customers
 * stayed pending_installation with skip reason onu_not_online.
 * (Named OnlineStatusFreshness* so it runs in the blocking CI `test` job.)
 */
#[Group('blocking-core')]
class OnlineStatusFreshnessDuplicateSerialTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private Router $router;

    private Olt $olt;

    private Olt $otherOlt;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        Carbon::setTestNow(Carbon::parse('2026-10-09 03:00:00', 'UTC'));
        $this->router = Router::create([
            'name' => 'RA dup', 'ip_address' => '127.0.31.1', 'username' => 't', 'password' => 't', 'is_active' => true,
        ]);
        $this->olt = Olt::create(['name' => 'RA OLT', 'code' => 'RA-OLT-DUP', 'vendor' => 'zte_c300']);
        $this->otherOlt = Olt::create(['name' => 'Gadang', 'code' => 'GDG-OLT-DUP', 'vendor' => 'huawei_ma5608t']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_offline_duplicate_on_same_olt_does_not_hide_the_live_row_and_customer_is_activated(): void
    {
        $customer = $this->pending();
        // Live registration first (lower id) ...
        $this->row($this->olt, '1/5/1:9', $customer->onu_serial, 'online', 'working', -21.4, Carbon::now()->subMinutes(3));
        // ... stale registration last (higher id, same minute, a few seconds later).
        $this->row($this->olt, '1/3/3:19', $customer->onu_serial, 'offline', 'DyingGasp', null, Carbon::now()->subMinutes(3)->addSeconds(20));

        $row = app(UnifiedConnectivityReadModel::class)->composeMany([$customer->load('olt')])[$customer->id];
        $this->assertSame('online', $row['evidence']['olt']['state']);
        $this->assertTrue($row['evidence']['olt']['fresh']);
        $this->assertTrue($row['evidence']['rx']['valid']);

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $customer->refresh()->status);
        // Nothing deleted: the field team cleans the OLT.
        $this->assertSame(2, OltOnu::query()->where('serial_number', $customer->onu_serial)->count());
    }

    public function test_stale_offline_row_on_another_olt_is_ignored(): void
    {
        $customer = $this->pending();
        $this->row($this->olt, '1/5/1:9', $customer->onu_serial, 'online', 'working', -20.2, Carbon::now()->subMinutes(2));
        $this->row($this->otherOlt, '0/0/0:0', $customer->onu_serial, 'offline', null, null, Carbon::parse('2026-09-19 02:00:00', 'UTC'));

        $this->artisan('customers:auto-activate-online')->assertExitCode(0);

        $this->assertSame('active', $customer->refresh()->status);
    }

    public function test_fresh_offline_only_still_blocks_activation(): void
    {
        $customer = $this->pending();
        $this->row($this->olt, '1/5/1:9', $customer->onu_serial, 'online', 'working', -20.0, Carbon::now()->subHours(2));
        $this->row($this->olt, '1/3/3:19', $customer->onu_serial, 'offline', 'DyingGasp', null, Carbon::now()->subMinutes(2));

        $this->artisan('customers:auto-activate-online')
            ->expectsOutputToContain('skip onu_not_online: '.$customer->id)
            ->assertExitCode(0);

        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    public function test_tie_breaking_by_freshness_then_own_olt_then_id(): void
    {
        $resolver = app(OltOnuSerialResolver::class);
        $sn = 'ZTEGTIE00001';
        $ownOlt = (int) $this->olt->id;

        // Two live rows on the own OLT: the most recent state wins.
        $older = $this->row($this->olt, '1/1/1:1', $sn, 'online', 'working', -22.0, Carbon::now()->subMinutes(8));
        $newer = $this->row($this->olt, '1/1/1:2', $sn, 'online', 'working', -19.0, Carbon::now()->subMinutes(1));
        $this->assertSame($newer->id, $resolver->pick([$older, $newer], $ownOlt)->id);
        $this->assertSame($newer->id, $resolver->pick([$newer, $older], $ownOlt)->id);

        // A live row on the own OLT beats a fresher live row elsewhere.
        $elsewhere = $this->row($this->otherOlt, '0/1/1:1', $sn, 'online', null, -18.0, Carbon::now()->subSeconds(10));
        $this->assertSame($newer->id, $resolver->pick([$elsewhere, $older, $newer], $ownOlt)->id);

        // No live row: the most recent evidence wins; equal timestamps keep the own OLT, then highest id.
        $at = Carbon::now()->subHours(3);
        $offA = $this->row($this->olt, '1/2/1:1', 'ZTEGTIE00002', 'offline', 'LOS', null, $at);
        $offB = $this->row($this->olt, '1/2/1:2', 'ZTEGTIE00002', 'offline', 'LOS', null, $at);
        $offOther = $this->row($this->otherOlt, '0/2/1:1', 'ZTEGTIE00002', 'offline', null, null, $at);
        $this->assertSame($offB->id, $resolver->pick([$offB, $offOther, $offA], $ownOlt)->id);
        $offNewest = $this->row($this->otherOlt, '0/2/1:2', 'ZTEGTIE00002', 'offline', null, null, $at->copy()->addMinute());
        $this->assertSame($offNewest->id, $resolver->pick([$offA, $offB, $offNewest], $ownOlt)->id);

        // A stale online row never beats a fresh offline one.
        $staleOnline = $this->row($this->olt, '1/3/1:1', 'ZTEGTIE00003', 'online', 'working', -20.0, Carbon::now()->subHours(5));
        $freshOffline = $this->row($this->olt, '1/3/1:2', 'ZTEGTIE00003', 'offline', 'DyingGasp', null, Carbon::now()->subMinutes(1));
        $this->assertSame($freshOffline->id, $resolver->pick([$staleOnline, $freshOffline], $ownOlt)->id);

        $this->assertNull($resolver->pick([], $ownOlt));
    }

    public function test_duplicate_serials_report_is_read_only(): void
    {
        $customer = $this->pending();
        $this->row($this->olt, '1/5/1:9', $customer->onu_serial, 'online', 'working', -21.4, Carbon::now()->subMinutes(3));
        $this->row($this->olt, '1/3/3:19', $customer->onu_serial, 'offline', 'DyingGasp', null, Carbon::now()->subMinutes(3));
        $this->row($this->olt, '1/1/1:1', 'ZTEGSINGLE01', 'online', 'working', -20.0, Carbon::now()->subMinutes(3));
        $before = OltOnu::query()->count();

        $this->artisan('olt:duplicate-serials')
            ->expectsOutputToContain('1 serial(s) with more than one olt_onus row')
            ->assertExitCode(0);
        $this->artisan('olt:duplicate-serials', ['--json' => true])
            ->expectsOutputToContain('1/3/3:19')
            ->assertExitCode(0);

        $this->assertSame($before, OltOnu::query()->count());
        $this->assertSame('pending_installation', $customer->refresh()->status);
    }

    private function pending(): Customer
    {
        $this->seq++;
        $package = Package::firstOrCreate(['code' => 'DUP-100'], ['name' => 'Paket 100', 'price' => 100000]);

        return Customer::create([
            'name' => 'Dup SN '.$this->seq,
            'phone' => '0813222'.$this->seq,
            'address' => 'RT 2 / RW 2',
            'package_id' => $package->id,
            'status' => 'pending_installation',
            'router_id' => $this->router->id,
            'olt_id' => $this->olt->id,
            'onu_serial' => sprintf('ZTEGDUPS%04d', $this->seq),
            'pppoe_user' => 'dup-sn-'.$this->seq,
            'join_date' => '2026-09-20',
            'due_day' => 20,
            'is_online' => true,
            'pppoe_online_checked_at' => Carbon::now()->subMinutes(4),
        ]);
    }

    private function row(Olt $olt, string $ref, string $serial, string $state, ?string $phase, ?float $rx, Carbon $at): OltOnu
    {
        return OltOnu::create([
            'olt_id' => $olt->id,
            'onu_ref' => $ref,
            'serial_number' => $serial,
            'state' => $state,
            'phase_state' => $phase,
            'rx_power_dbm' => $rx,
            'state_collected_at' => $at,
            'rx_collected_at' => $at,
            'inventory_collected_at' => $at,
        ]);
    }
}
