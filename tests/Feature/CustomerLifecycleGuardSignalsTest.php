<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\GenieAcsDevice;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\Package;
use App\Models\Router;
use App\Models\User;
use App\Services\Lifecycle\CustomerLifecycleGuard;
use App\Services\Lifecycle\GuardContext;
use App\Services\Lifecycle\GuardDecision;
use App\Services\Monitoring\RouterPppoeReadModel;
use App\Services\PppoeOnlineFlagWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Support\Canary;
use Tests\TestCase;

/**
 * Roadmap 1.3 PR-1: signal matrix of CustomerLifecycleGuard (PPPoE, ONU,
 * traffic). Fail-closed: unknown = deny; stale positive = in_use.
 */
#[Group('blocking-core')]
class CustomerLifecycleGuardSignalsTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    /** @var array<string, mixed>|\Closure */
    private $grafanaLive;

    protected function setUp(): void
    {
        parent::setUp();
        PppoeOnlineFlagWriter::flushSchemaCache();
        // 2026-10-14 10:00 WIB
        Carbon::setTestNow(Carbon::parse('2026-10-14 03:00:00', 'UTC'));
        $this->grafanaLive = ['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null];

        $model = Mockery::mock(RouterPppoeReadModel::class);
        $model->shouldReceive('live')->andReturnUsing(function () {
            $live = $this->grafanaLive;

            return $live instanceof \Closure ? $live() : $live;
        });
        $this->app->instance(RouterPppoeReadModel::class, $model);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- PPPoE

    public function test_fresh_online_pppoe_denies(): void
    {
        [$customer] = $this->scenario(['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(3)]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::DENY, $decision->verdict);
        $this->assertContains('pppoe:pppoe_in_use', $decision->reasonCodes());
        $this->assertStringContainsString('Sesi PPPoE masih aktif', $decision->message());
    }

    public function test_fresh_offline_with_everything_clear_allows(): void
    {
        [$customer] = $this->scenario();

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::ALLOW, $decision->verdict);
        $this->assertTrue($decision->allowed());
        // No ONU serial: the ONU signal was not applicable (nothing checked) and says so.
        $this->assertSame(['allowed[onu_na]'], $decision->reasonCodes());
        $this->assertTrue($decision->allowedWithNoEvidence());
    }

    public function test_stale_positive_pppoe_is_still_in_use(): void
    {
        [$customer] = $this->scenario(['is_online' => true, 'pppoe_online_checked_at' => now()->subHours(6)]);
        $this->grafanaLive = $this->liveOk([]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::DENY, $decision->verdict);
        $this->assertContains('pppoe:pppoe_in_use', $decision->reasonCodes());
    }

    public function test_stale_negative_pppoe_is_unknown_when_grafana_is_unavailable(): void
    {
        [$customer] = $this->scenario(['is_online' => false, 'pppoe_online_checked_at' => now()->subHours(6)]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertFalse($decision->allowed());
        $this->assertContains('pppoe:pppoe_unknown', $decision->reasonCodes());
    }

    public function test_never_checked_pppoe_falls_back_to_grafana_absent_is_clear(): void
    {
        [$customer] = $this->scenario(['is_online' => false, 'pppoe_online_checked_at' => null]);
        $this->grafanaLive = $this->liveOk(['someone-else' => ['name' => 'someone-else', 'download' => 1.0, 'upload' => 1.0]]);

        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict);
    }

    public function test_grafana_present_user_is_in_use_even_with_stale_negative_flag(): void
    {
        [$customer] = $this->scenario(['is_online' => false, 'pppoe_online_checked_at' => now()->subHours(6)]);
        $this->grafanaLive = $this->liveOk([mb_strtolower($customer->pppoe_user) => ['name' => $customer->pppoe_user, 'download' => 0.0, 'upload' => 0.0]]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::DENY, $decision->verdict);
        $this->assertContains('pppoe:pppoe_in_use', $decision->reasonCodes());
    }

    public function test_grafana_stale_cache_is_not_proof(): void
    {
        [$customer] = $this->scenario(['pppoe_online_checked_at' => now()->subHours(6)]);
        $this->grafanaLive = ['status' => 'stale', 'fetched_at' => null, 'reason' => 'old', 'users' => []];

        $this->assertSame(GuardDecision::UNKNOWN, $this->terminate($customer)->verdict);
    }

    public function test_trashed_customer_ignores_its_flag_and_uses_grafana(): void
    {
        [$customer] = $this->scenario(['is_online' => true, 'pppoe_online_checked_at' => now()->subDays(20)]);
        $customer->delete();
        $trashed = Customer::withTrashed()->findOrFail($customer->id);
        $this->grafanaLive = $this->liveOk(['someone-else' => ['name' => 'someone-else', 'download' => 1.0, 'upload' => 1.0]]);

        $absent = app(CustomerLifecycleGuard::class)->canArchive($trashed, GuardContext::automated('test'));
        $this->assertSame(GuardDecision::ALLOW, $absent->verdict, 'stale flag of a trashed customer is not evidence');

        $this->grafanaLive = $this->liveOk([mb_strtolower($trashed->pppoe_user) => ['name' => 'x', 'download' => 0.0, 'upload' => 0.0]]);
        $present = app(CustomerLifecycleGuard::class)->canArchive($trashed, GuardContext::automated('test'));
        $this->assertSame(GuardDecision::DENY, $present->verdict);

        $this->grafanaLive = ['status' => 'unavailable', 'fetched_at' => null, 'reason' => 'down', 'users' => null];
        $down = app(CustomerLifecycleGuard::class)->canArchive($trashed, GuardContext::automated('test'));
        $this->assertSame(GuardDecision::UNKNOWN, $down->verdict);
    }

    public function test_grafana_ok_with_empty_user_list_is_unknown_not_clear(): void
    {
        [$customer] = $this->scenario(['pppoe_online_checked_at' => now()->subHours(6)]);
        $this->grafanaLive = $this->liveOk([]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('pppoe:pppoe_unknown', $decision->reasonCodes());
    }

    public function test_unknown_vendor_is_never_treated_as_non_strict_for_d1(): void
    {
        // No olt, no snapshot, PPPoE and traffic clear: ONU stays unknown.
        [$customer] = $this->scenario(['onu_serial' => 'MYSTERY00001']);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('onu:onu_unknown', $decision->reasonCodes());
    }

    public function test_serial_matching_ignores_dashes_colons_spaces_and_case_on_both_sides(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'zteg-online-09', 'vendor' => 'zte_c300']);
        OltOnu::create([
            'olt_id' => $customer->olt_id,
            'onu_ref' => '1/1/1:9',
            'serial_number' => 'ZTEG:ONLINE 09',
            'state' => 'online',
            'phase_state' => 'working',
            'state_collected_at' => now()->subMinutes(2),
        ]);

        $this->assertContains('onu:onu_in_use', $this->terminate($customer)->reasonCodes());

        [$other] = $this->scenario(['onu_serial' => 'HIOS0-0000-77', 'vendor' => 'hioso']);
        $this->genie($other, now()->subMinutes(5));
        GenieAcsDevice::query()->update(['customer_id' => null, 'serial_number' => 'hios0 0000 77']);
        $this->assertContains('onu:onu_in_use', $this->terminate($other)->reasonCodes());
    }

    public function test_window_start_day_without_rollup_rows_is_unknown(): void
    {
        [$customer, $router] = $this->scenario(['skipAlive' => true]);
        // Rollup is alive today, but yesterday (window start) has no rows for this router.
        $this->usage($router, null, '2026-10-14', 0, 0, 'heartbeat-only', null);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('traffic:traffic_unknown', $decision->reasonCodes());
    }

    public function test_signal_exception_becomes_unknown(): void
    {
        [$customer] = $this->scenario(['pppoe_online_checked_at' => now()->subHours(6)]);
        $this->grafanaLive = function () {
            throw new RuntimeException('boom');
        };

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('pppoe:pppoe_unknown', $decision->reasonCodes());
    }

    // -------------------------------------------------------------- Traffic

    public function test_traffic_inside_window_denies_and_message_names_the_day(): void
    {
        [$customer, $router] = $this->scenario();
        $this->usage($router, $customer, '2026-10-13', 5_000_000);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::DENY, $decision->verdict);
        $this->assertContains('traffic:traffic_in_use', $decision->reasonCodes());
        $this->assertStringContainsString('13/10/2026 WIB', $decision->message());
    }

    public function test_wib_window_boundary_day_minus_one_counts_day_minus_two_does_not(): void
    {
        [$customer, $router] = $this->scenario();
        $this->usage($router, $customer, '2026-10-12', 9_000_000);
        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict, 'day -2 is outside the 2-day window');

        $this->usage($router, $customer, '2026-10-13', 9_000_000);
        $decision = $this->terminate($customer);
        $this->assertSame(GuardDecision::DENY, $decision->verdict, 'day -1 is inside the window');
        $this->assertStringContainsString('dalam 2 hari terakhir (terakhir 13/10/2026 WIB)', $decision->message());
    }

    public function test_traffic_threshold_is_one_megabyte_total_download_plus_upload(): void
    {
        [$customer, $router] = $this->scenario();
        $this->usage($router, $customer, '2026-10-13', 524_288, 524_287);
        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict, '1 MB - 1 byte is clear');

        $this->usage($router, $customer, '2026-10-14', 1, 0, 'extra-user-'.$customer->id, $customer->id);
        $this->assertSame(GuardDecision::DENY, $this->terminate($customer)->verdict, 'exactly 1 MB is traffic');
    }

    public function test_invalid_quality_rows_are_ignored(): void
    {
        [$customer, $router] = $this->scenario();
        $this->usage($router, $customer, '2026-10-13', 90_000_000, 0, null, null, 'invalid');

        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict);
    }

    public function test_rows_match_by_lowercase_pppoe_user_when_customer_id_is_null(): void
    {
        [$customer, $router] = $this->scenario();
        $this->usage($router, null, '2026-10-13', 5_000_000, 0, strtoupper($customer->pppoe_user), null);

        $this->assertSame(GuardDecision::DENY, $this->terminate($customer)->verdict);
    }

    public function test_dead_rollup_makes_traffic_unknown_not_clear(): void
    {
        [$customer, $router] = $this->scenario();
        DB::table('pppoe_usage_daily')->update(['updated_at' => now()->subHours(3)->toDateTimeString()]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('traffic:traffic_unknown', $decision->reasonCodes());
    }

    public function test_router_without_any_rollup_rows_is_unknown(): void
    {
        [$customer] = $this->scenario(['skipAlive' => true]);

        $this->assertContains('traffic:traffic_unknown', $this->terminate($customer)->reasonCodes());
    }

    public function test_genieacs_inform_in_window_is_traffic_fallback_when_rollup_is_dead(): void
    {
        [$customer] = $this->scenario(['skipAlive' => true, 'onu_serial' => 'ZTEGINFORM1']);
        $this->genie($customer, now()->subHours(40));

        $decision = $this->terminate($customer);

        $this->assertContains('traffic:traffic_in_use', $decision->reasonCodes());
    }

    // ------------------------------------------------------------------ ONU

    public function test_fresh_online_onu_on_strict_vendor_denies(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'ZTEGONLINE1', 'vendor' => 'zte_c300']);
        $this->snapshot($customer, 'online', 'working', 2);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::DENY, $decision->verdict);
        $this->assertContains('onu:onu_in_use', $decision->reasonCodes());
    }

    public function test_stale_positive_onu_is_in_use(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'ZTEGSTALE01', 'vendor' => 'zte_c300']);
        $this->snapshot($customer, 'online', 'working', 60 * 24 * 5);

        $this->assertContains('onu:onu_in_use', $this->terminate($customer)->reasonCodes());
    }

    public function test_fresh_offline_onu_on_strict_vendor_is_clear(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'ZTEGOFF0001', 'vendor' => 'zte_c300']);
        $this->snapshot($customer, 'offline', 'LOS', 3);

        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict);
    }

    public function test_stale_negative_onu_on_strict_vendor_is_unknown_even_with_pppoe_and_traffic_clear(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'ZTEGSTALEN1', 'vendor' => 'zte_c300']);
        $this->snapshot($customer, 'offline', 'LOS', 300);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('onu:onu_unknown', $decision->reasonCodes());
    }

    public function test_hioso_unknown_onu_is_tolerated_when_pppoe_and_traffic_are_proven_clear(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'HIOSO0000001', 'vendor' => 'hioso']);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::ALLOW, $decision->verdict);
        $onu = collect($decision->signals)->firstWhere('name', 'onu');
        $this->assertSame('onu_unknown_tolerated_non_strict', $onu->reason);
    }

    public function test_hioso_unknown_onu_is_not_tolerated_when_pppoe_is_not_proven_clear(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'HIOSO0000002', 'vendor' => 'hioso', 'pppoe_online_checked_at' => now()->subHours(6)]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('onu:onu_unknown', $decision->reasonCodes());
    }

    public function test_hioso_unknown_onu_is_not_tolerated_when_traffic_is_not_proven_clear(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'HIOSO0000003', 'vendor' => 'hioso', 'skipAlive' => true]);

        $decision = $this->terminate($customer);

        $this->assertSame(GuardDecision::UNKNOWN, $decision->verdict);
        $this->assertContains('onu:onu_unknown', $decision->reasonCodes());
    }

    public function test_hioso_online_onu_still_denies(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'HIOSO0000004', 'vendor' => 'hioso']);
        $this->snapshot($customer, 'online', null, 30);

        $this->assertContains('onu:onu_in_use', $this->terminate($customer)->reasonCodes());
    }

    public function test_genieacs_recent_inform_is_positive_only_evidence(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'HIOSO0000005', 'vendor' => 'hioso']);
        $this->genie($customer, now()->subMinutes(45));
        $this->assertSame(GuardDecision::ALLOW, $this->terminate($customer)->verdict, 'inform older than 30 min is not evidence');

        GenieAcsDevice::query()->update(['last_inform_at' => now()->subMinutes(5)]);
        $this->assertContains('onu:onu_in_use', $this->terminate($customer)->reasonCodes());
    }

    public function test_onu_reused_by_another_customer_makes_onu_signal_not_applicable(): void
    {
        [$customer] = $this->scenario(['onu_serial' => 'ZTEGREUSED1', 'vendor' => 'zte_c300']);
        $this->snapshot($customer, 'online', 'working', 2);

        $decision = app(CustomerLifecycleGuard::class)->canTerminate($customer, GuardContext::automated('takeover', $customer->id + 999));

        $this->assertSame(GuardDecision::ALLOW, $decision->verdict);
        $onu = collect($decision->signals)->firstWhere('name', 'onu');
        $this->assertSame('not_applicable', $onu->state);
    }

    public function test_traffic_genieacs_fallback_ignores_serial_match_only_for_reused_onu_context(): void
    {
        // Rollup not proven (no heartbeat) => the GenieACS fallback decides.
        [$old] = $this->scenario(['onu_serial' => 'ZTEGREUSED2', 'vendor' => 'zte_c300', 'skipAlive' => true]);
        [$new] = $this->scenario(['onu_serial' => 'ZTEGREUSED2', 'vendor' => 'zte_c300', 'skipAlive' => true]);
        // Fresh inform of the NEW account's device (same serial), linked to the new account only.
        $this->genie($new, now()->subMinutes(5));
        $guard = app(CustomerLifecycleGuard::class);

        $plain = $guard->canTerminate($old->fresh(), GuardContext::automated('test'));
        $this->assertContains('traffic:traffic_in_use', $plain->reasonCodes(), 'without takeover context the serial match still counts');

        $reused = $guard->canTerminate($old->fresh(), GuardContext::automated('deferred_sweeper', (int) $new->id));
        $this->assertNotContains('traffic:traffic_in_use', $reused->reasonCodes());
        $this->assertContains('traffic:traffic_unknown', $reused->reasonCodes());

        // The old account's own linked device still counts under the takeover context.
        GenieAcsDevice::create(['device_id' => 'DEV-OLD', 'customer_id' => $old->id, 'serial_number' => 'ZTEGOLDOWN', 'last_inform_at' => now()->subMinutes(5)]);
        $own = $guard->canTerminate($old->fresh(), GuardContext::automated('deferred_sweeper', (int) $new->id));
        $this->assertContains('traffic:traffic_in_use', $own->reasonCodes());
    }

    // ------------------------------------------------- per-ref + override

    public function test_per_ref_live_offline_clears_online_denies_failed_read_is_unknown(): void
    {
        [$customer] = $this->scenario();
        $guard = app(CustomerLifecycleGuard::class);
        $ctx = GuardContext::automated('olt-onu-delete');

        $offline = $guard->canDeleteOnuRef('case', $customer, ['ok' => true, 'absent' => false, 'state' => 'offline'], $ctx);
        $this->assertSame(GuardDecision::ALLOW, $offline->verdict);

        $online = $guard->canDeleteOnuRef('case', $customer, ['ok' => true, 'absent' => false, 'state' => 'online'], $ctx);
        $this->assertSame(GuardDecision::DENY, $online->verdict);

        $failed = $guard->canDeleteOnuRef('manual', $customer, ['ok' => false, 'absent' => false, 'state' => 'unknown'], $ctx);
        $this->assertSame(GuardDecision::UNKNOWN, $failed->verdict);

        $none = $guard->canDeleteOnuRef('case', $customer, null, $ctx);
        $this->assertSame(GuardDecision::UNKNOWN, $none->verdict);
    }

    public function test_per_ref_case_mode_also_checks_pppoe(): void
    {
        [$customer] = $this->scenario(['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(2)]);

        $decision = app(CustomerLifecycleGuard::class)->canDeleteOnuRef('case', $customer, ['ok' => true, 'state' => 'offline'], GuardContext::automated('x'));

        $this->assertContains('pppoe:pppoe_in_use', $decision->reasonCodes());
    }

    public function test_duplicate_mode_needs_offline_target_and_online_twin_and_no_pppoe_check(): void
    {
        $guard = app(CustomerLifecycleGuard::class);
        $ctx = GuardContext::automated('olt-duplicates');
        $off = ['ok' => true, 'state' => 'offline'];
        $on = ['ok' => true, 'state' => 'online'];

        $this->assertSame(GuardDecision::ALLOW, $guard->canDeleteOnuRef('duplicate', null, $off, $ctx, $on)->verdict);
        $this->assertSame(GuardDecision::UNKNOWN, $guard->canDeleteOnuRef('duplicate', null, $off, $ctx, null)->verdict);
        $this->assertSame(GuardDecision::UNKNOWN, $guard->canDeleteOnuRef('duplicate', null, $off, $ctx, $off)->verdict);
        $this->assertSame(GuardDecision::DENY, $guard->canDeleteOnuRef('duplicate', null, $on, $ctx, $on)->verdict);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(CustomerLifecycleGuard::class)->canDeleteOnuRef('bogus', null, null, GuardContext::automated('x'));
    }

    public function test_override_rules(): void
    {
        [$customer] = $this->scenario(['is_online' => true, 'pppoe_online_checked_at' => now()->subMinutes(2)]);
        $guard = app(CustomerLifecycleGuard::class);
        $manual = GuardContext::manual('ui');
        $denied = $guard->canTerminate($customer, $manual);
        $super = (new User)->forceFill(['role' => 'superadmin']);
        $admin = (new User)->forceFill(['role' => 'admin']);
        $reason = 'Pelanggan sudah pindah, router lama masih tercatat';

        $this->assertTrue($guard->authorizeOverride($super, $reason, $denied, $manual)['ok']);
        $this->assertSame('superadmin_required', $guard->authorizeOverride($admin, $reason, $denied, $manual)['error']);
        $this->assertSame('superadmin_required', $guard->authorizeOverride(null, $reason, $denied, $manual)['error']);
        $this->assertSame('reason_too_short', $guard->authorizeOverride($super, 'terlalu pendek', $denied, $manual)['error']);
        $this->assertSame('automated_never_overridable', $guard->authorizeOverride($super, $reason, $denied, GuardContext::automated('job'))['error']);

        [$clear] = $this->scenario();
        $this->assertSame('override_not_needed', $guard->authorizeOverride($super, $reason, $guard->canTerminate($clear, $manual), $manual)['error']);
    }

    // -------------------------------------------------------------- helpers

    private function terminate(Customer $customer): GuardDecision
    {
        return app(CustomerLifecycleGuard::class)->canTerminate($customer->fresh(), GuardContext::automated('test'));
    }

    /** @return array<string, mixed> */
    private function liveOk(array $users): array
    {
        return ['status' => 'ok', 'fetched_at' => now()->toIso8601String(), 'reason' => null, 'users' => $users];
    }

    /**
     * Customer whose guard verdict is ALLOW by default (fresh offline PPPoE,
     * rollup alive, no ONU serial); options override single pieces.
     *
     * @param  array<string, mixed>  $o
     * @return array{0: Customer, 1: Router}
     */
    private function scenario(array $o = []): array
    {
        $this->seq++;
        $router = Router::create([
            'name' => 'Guard router '.$this->seq,
            'ip_address' => '127.0.30.'.$this->seq,
            'username' => 'test',
            'password' => Canary::of('test'),
            'is_active' => true,
        ]);
        $oltId = null;
        if (isset($o['vendor'])) {
            $oltId = Olt::create(['name' => 'Guard OLT '.$this->seq, 'code' => 'GUARD-OLT-'.$this->seq, 'vendor' => $o['vendor']])->id;
        }
        $package = Package::firstOrCreate(['code' => 'GUARD-PKG'], ['name' => 'Guard', 'price' => 0]);

        $customer = Customer::create([
            'code' => 'GUARD-'.$this->seq,
            'name' => 'Guard customer '.$this->seq,
            'phone' => '0813100'.$this->seq,
            'address' => 'RW 1',
            'package_id' => $package->id,
            'status' => 'isolated',
            'pppoe_user' => 'Guard-User-'.$this->seq,
            'router_id' => $router->id,
            'olt_id' => $oltId,
            'onu_serial' => $o['onu_serial'] ?? null,
            'is_online' => $o['is_online'] ?? false,
            'pppoe_online_checked_at' => array_key_exists('pppoe_online_checked_at', $o) ? $o['pppoe_online_checked_at'] : now()->subMinutes(2),
        ]);

        if (! ($o['skipAlive'] ?? false)) {
            // Rollup heartbeat of this router: another user's row written 5 minutes ago.
            // Both window days (today + yesterday WIB) have rolled-up rows.
            $this->usage($router, null, '2026-10-14', 0, 0, 'heartbeat-'.$this->seq, null);
            $this->usage($router, null, '2026-10-13', 0, 0, 'heartbeat-y-'.$this->seq, null);
        }

        return [$customer, $router];
    }

    private function usage(Router $router, ?Customer $customer, string $day, int $down, int $up = 0, ?string $user = null, ?int $customerId = null, string $quality = 'ok'): void
    {
        $name = $user ?? ($customer?->pppoe_user ?? 'anon');
        DB::table('pppoe_usage_daily')->insert([
            'usage_date_wib' => $day,
            'router_id' => $router->id,
            'pppoe_user' => $name,
            'customer_id' => $customerId ?? ($user === null ? $customer?->id : null),
            'download_bytes' => $down,
            'upload_bytes' => $up,
            'is_final' => false,
            'quality' => $quality,
            'created_at' => now()->subMinutes(5)->toDateTimeString(),
            'updated_at' => now()->subMinutes(5)->toDateTimeString(),
        ]);
    }

    private function snapshot(Customer $customer, string $state, ?string $phase, int $ageMinutes): void
    {
        OltOnu::create([
            'olt_id' => $customer->olt_id,
            'onu_ref' => '1/1/1:'.$this->seq,
            'serial_number' => $customer->onu_serial,
            'state' => $state,
            'phase_state' => $phase,
            'state_collected_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    private function genie(Customer $customer, Carbon $at): void
    {
        GenieAcsDevice::create([
            'device_id' => 'DEV-'.$this->seq,
            'customer_id' => $customer->id,
            'serial_number' => $customer->onu_serial,
            'last_inform_at' => $at,
        ]);
    }
}
