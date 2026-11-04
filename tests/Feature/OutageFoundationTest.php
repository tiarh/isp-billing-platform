<?php

namespace Tests\Feature;

use App\Models\OutageOnuTracker;
use App\Models\OutageSignal;
use App\Models\Setting;
use App\Services\Outage\OutageSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
#[Group('blocking-olt-mysql')]
class OutageFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('outage_signals'));
        $this->assertTrue(Schema::hasTable('outage_onu_tracker'));
        foreach (['open_key', 'scope_key', 'affected_customer_ids', 'payload', 'would_action', 'matched_ticket_id', 'flap_count'] as $col) {
            $this->assertTrue(Schema::hasColumn('outage_signals', $col), $col);
        }
        foreach (['rx_samples', 'rx_daily', 'last_onu_distance_m', 'phase', 'pppoe_down_since'] as $col) {
            $this->assertTrue(Schema::hasColumn('outage_onu_tracker', $col), $col);
        }
    }

    public function test_migrations_are_idempotent_and_down_is_safe(): void
    {
        $signals = require database_path('migrations/2026_10_15_000001_create_outage_signals_table.php');
        $tracker = require database_path('migrations/2026_10_15_000002_create_outage_onu_tracker_table.php');

        // Re-running up() on existing tables is a no-op.
        $signals->up();
        $tracker->up();
        $this->assertTrue(Schema::hasTable('outage_signals'));

        // down() refuses to drop a table that holds evidence.
        $this->signal(['scope_key' => 'customer:1']);
        $signals->down();
        $this->assertTrue(Schema::hasTable('outage_signals'));

        // Drop/recreate is DDL (implicit commit on MySQL), so the empty-table down() path is
        // exercised by the CI migrate:fresh / rollback steps, not inside this transactional test.
        $this->assertTrue(Schema::hasTable('outage_onu_tracker'));
    }

    public function test_open_key_allows_one_open_episode_per_scope(): void
    {
        $this->signal(['scope_key' => 'pon:1:1/3/3', 'open_key' => 'pon:1:1/3/3']);

        $this->expectException(QueryException::class);
        $this->signal(['scope_key' => 'pon:1:1/3/3', 'open_key' => 'pon:1:1/3/3']);
    }

    public function test_closed_rows_with_null_open_key_do_not_collide(): void
    {
        $this->signal(['scope_key' => 'customer:5', 'open_key' => null, 'state' => 'resolved']);
        $this->signal(['scope_key' => 'customer:5', 'open_key' => null, 'state' => 'expired']);
        $this->signal(['scope_key' => 'customer:5', 'open_key' => 'customer:5', 'state' => 'open']);

        $this->assertSame(3, OutageSignal::query()->where('scope_key', 'customer:5')->count());
        $this->assertSame(1, OutageSignal::query()->open()->count());
    }

    public function test_model_scopes_and_casts(): void
    {
        $this->signal(['scope_key' => 'a', 'open_key' => 'a', 'state' => 'open']);
        $this->signal(['scope_key' => 'b', 'open_key' => 'b', 'state' => 'confirmed', 'affected_customer_ids' => [1, 2], 'payload' => ['x' => 1]]);
        $this->signal(['scope_key' => 'c', 'state' => 'resolved']);

        $this->assertSame(2, OutageSignal::query()->open()->count());
        $this->assertSame(1, OutageSignal::query()->confirmed()->count());
        $b = OutageSignal::query()->confirmed()->firstOrFail();
        $this->assertSame([1, 2], $b->affected_customer_ids);
        $this->assertSame(['x' => 1], $b->payload);
        $this->assertTrue(OutageSignal::query()->where('scope_key', 'c')->firstOrFail()->isClosed());
    }

    public function test_tracker_is_unique_per_onu_and_casts_json(): void
    {
        OutageOnuTracker::create([
            'olt_id' => 1, 'onu_ref' => '1/3/3:5', 'phase' => 'online',
            'rx_samples' => [['at' => '2026-10-15 01:00:00', 'dbm' => -21.5]],
            'rx_daily' => [['date' => '2026-10-15', 'dbm' => -21.5, 'n' => 4]],
            'last_onu_distance_m' => 1234,
        ]);

        $row = OutageOnuTracker::query()->inPhase('online')->forOlt(1)->firstOrFail();
        $this->assertSame(-21.5, $row->rx_daily[0]['dbm']);
        $this->assertSame(1234, $row->last_onu_distance_m);

        $this->expectException(QueryException::class);
        OutageOnuTracker::create(['olt_id' => 1, 'onu_ref' => '1/3/3:5']);
    }

    public function test_master_switch_defaults_off_and_all_defaults_are_safe(): void
    {
        $this->assertFalse(OutageSettings::enabled());
        $this->assertSame('off', OutageSettings::customerWaMode());
        foreach (OutageSettings::SIGNALS as $signal) {
            $this->assertSame('shadow', OutageSettings::mode($signal), $signal);
        }
        $this->assertSame([1, 9, 11], OutageSettings::oltIds());
        $this->assertSame(4, OutageSettings::massMinCustomers());
        $this->assertSame(5, OutageSettings::massWindowMinutes());
        $this->assertSame(30, OutageSettings::singleLosMinutes());
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        $this->assertSame(3, OutageSettings::weakRxDays());
        $this->assertSame(2.0, OutageSettings::weakRxTrendDb());
        $this->assertSame(10, OutageSettings::pppoeDownMinutes());
        $this->assertSame(12, OutageSettings::stateMaxAgeMinutes());
        $this->assertSame(30, OutageSettings::recoveryStableMinutes());
        $this->assertSame(10, OutageSettings::liveTicketCapPerHour());
        $this->assertSame([22, 7], OutageSettings::quietHours());
    }

    public function test_setting_row_overrides_config_fallback(): void
    {
        Setting::set('outage_automation_enabled', '1');
        Setting::set('outage_mode_single_los', 'live');
        Setting::set('outage_mass_min_customers', '6');
        Setting::set('outage_olt_ids', '1, 9');
        Setting::set('outage_weak_rx_dbm', '28');

        $this->assertTrue(OutageSettings::enabled());
        $this->assertSame('live', OutageSettings::mode('single_los'));
        $this->assertSame(6, OutageSettings::massMinCustomers());
        $this->assertSame([1, 9], OutageSettings::oltIds());
        $this->assertSame(-28.0, OutageSettings::weakRxDbm());

        Setting::set('outage_automation_enabled', '0');
        $this->assertFalse(OutageSettings::enabled());
    }

    public function test_dying_gasp_and_olt_wide_can_never_be_live(): void
    {
        Setting::set('outage_mode_dying_gasp', 'live');
        Setting::set('outage_mode_olt_wide', 'LIVE');
        Setting::set('outage_mode_mass_los', 'live');

        $this->assertSame('shadow', OutageSettings::mode('dying_gasp'));
        $this->assertSame('shadow', OutageSettings::mode('olt_wide'));
        $this->assertSame('live', OutageSettings::mode('mass_los'));

        Setting::set('outage_mode_dying_gasp', 'off');
        $this->assertSame('off', OutageSettings::mode('dying_gasp'));
    }

    public function test_invalid_modes_fall_back_to_safe_defaults(): void
    {
        Setting::set('outage_mode_weak_rx', 'banana');
        $this->assertSame('shadow', OutageSettings::mode('weak_rx'));
        $this->assertSame('off', OutageSettings::mode('not_a_signal'));

        Setting::set('outage_customer_wa_mode', 'shadow');
        $this->assertSame('off', OutageSettings::customerWaMode());
        Setting::set('outage_customer_wa_mode', 'live');
        $this->assertSame('live', OutageSettings::customerWaMode());

        Setting::set('outage_quiet_hours', 'nonsense');
        $this->assertSame([22, 7], OutageSettings::quietHours());
        Setting::set('outage_quiet_hours', '25-99');
        $this->assertSame([22, 7], OutageSettings::quietHours());

        Setting::set('outage_mass_min_customers', '0');
        $this->assertSame(2, OutageSettings::massMinCustomers());
        Setting::set('outage_olt_ids', 'abc');
        $this->assertSame([], OutageSettings::oltIds());
    }

    public function test_quiet_hours_wrap_past_midnight(): void
    {
        $this->assertTrue(OutageSettings::isQuietHour(22));
        $this->assertTrue(OutageSettings::isQuietHour(0));
        $this->assertTrue(OutageSettings::isQuietHour(5));
        $this->assertTrue(OutageSettings::isQuietHour(6));
        $this->assertFalse(OutageSettings::isQuietHour(7));
        $this->assertFalse(OutageSettings::isQuietHour(12));
    }

    public function test_settings_reads_fail_safe_when_the_store_throws(): void
    {
        config(['outage.enabled' => true]); // env could say true; a store failure must still fail closed
        Setting::flushRequestMemo();
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));

        $this->assertFalse(OutageSettings::enabled());
        $this->assertSame('off', OutageSettings::mode('mass_los'));
        $this->assertSame('off', OutageSettings::customerWaMode());
        $this->assertSame([], OutageSettings::oltIds());
        // Thresholds fall back to config.
        $this->assertSame(4, OutageSettings::massMinCustomers());
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        $this->assertSame([22, 7], OutageSettings::quietHours());
    }

    public function test_weak_rx_dbm_is_clamped_to_a_sane_range(): void
    {
        Setting::set('outage_weak_rx_dbm', '0');
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        Setting::set('outage_weak_rx_dbm', '5');
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        Setting::set('outage_weak_rx_dbm', '-60');
        $this->assertSame(-27.0, OutageSettings::weakRxDbm());
        Setting::set('outage_weak_rx_dbm', '-25');
        $this->assertSame(-25.0, OutageSettings::weakRxDbm());
    }

    public function test_stored_empty_olt_list_means_none(): void
    {
        Setting::set('outage_olt_ids', []);
        $this->assertSame([], OutageSettings::oltIds());
    }

    public function test_saving_hook_keeps_open_key_in_sync_with_state(): void
    {
        $s = $this->signal(['scope_key' => 'pon:1:1/1/1', 'open_key' => 'ignored']);
        $this->assertSame('pon:1:1/1/1', $s->fresh()->open_key);

        $s->update(['state' => 'resolved', 'resolved_at' => now()]);
        $this->assertNull($s->fresh()->open_key);

        // Scope is free again after closing.
        $again = $this->signal(['scope_key' => 'pon:1:1/1/1']);
        $this->assertSame('pon:1:1/1/1', $again->fresh()->open_key);
    }

    public function test_prune_dry_run_deletes_nothing(): void
    {
        $this->seedPruneRows();

        $this->artisan('outage:prune')
            ->expectsOutputToContain('2 row(s) eligible')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(6, OutageSignal::query()->count());
    }

    public function test_prune_execute_deletes_only_old_closed_rows(): void
    {
        $this->seedPruneRows();

        $this->artisan('outage:prune', ['--execute' => true])
            ->expectsOutputToContain('Deleted 2 row(s)')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            ['recent-resolved', 'chronic-resolved', 'old-open', 'recent-open'],
            OutageSignal::query()->pluck('scope_key')->all()
        );
    }

    public function test_prune_rejects_invalid_days_and_is_not_scheduled(): void
    {
        $this->artisan('outage:prune', ['--days' => 0])->assertFailed();

        // Only outage:detect (PR-2, flag-gated) is scheduled; prune is never scheduled.
        $this->assertStringNotContainsString('outage:prune', (string) file_get_contents(base_path('routes/console.php')));
    }

    private function seedPruneRows(): void
    {
        $this->signal(['scope_key' => 'old-resolved', 'state' => 'resolved', 'opened_at' => now()->subDays(200)]);
        $this->signal(['scope_key' => 'old-expired', 'state' => 'expired', 'opened_at' => now()->subDays(181)]);
        $this->signal(['scope_key' => 'recent-resolved', 'state' => 'resolved', 'opened_at' => now()->subDays(10)]);
        // Long episode opened 200 days ago but resolved yesterday: kept (prune is by resolved_at).
        $this->signal(['scope_key' => 'chronic-resolved', 'state' => 'resolved', 'opened_at' => now()->subDays(200), 'resolved_at' => now()->subDay()]);
        // Still-open episodes are never pruned, however old.
        $this->signal(['scope_key' => 'old-open', 'open_key' => 'old-open', 'state' => 'open', 'opened_at' => now()->subDays(300)]);
        $this->signal(['scope_key' => 'recent-open', 'open_key' => 'recent-open', 'state' => 'confirmed', 'opened_at' => now()->subDay()]);
    }

    /** @param array<string,mixed> $attrs */
    private function signal(array $attrs): OutageSignal
    {
        return OutageSignal::create($attrs + [
            'signal_type' => 'single_los',
            'state' => 'open',
            'opened_at' => now(),
        ]);
    }
}
