<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis history archive (roadmap v2 section 9): data that is lost soon if it is not kept.
 * Additive and guarded; nothing reads these tables yet (they feed the January co-failure analysis).
 * Ids and numbers only: no PPPoE usernames, names, phones or addresses. Retention 400 days via outage:events-prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One row per ONU per WIB day. The tracker keeps only 8 days of rx_daily.
        if (! Schema::hasTable('onu_rx_daily')) {
            Schema::create('onu_rx_daily', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('olt_id');
                $table->string('onu_ref', 64);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->date('day_wib');
                $table->decimal('rx_median', 6, 2)->nullable();
                $table->decimal('rx_min', 6, 2)->nullable();
                $table->decimal('rx_max', 6, 2)->nullable();
                $table->decimal('rx_last', 6, 2)->nullable();
                $table->unsignedSmallInteger('samples')->default(0);
                $table->unique(['olt_id', 'onu_ref', 'day_wib'], 'onu_rx_daily_unique');
                $table->index('day_wib', 'onu_rx_daily_day_idx');
                $table->index(['customer_id', 'day_wib'], 'onu_rx_daily_customer_idx');
            });
        }

        // PPPoE session gaps (a session disappeared from Prometheus and came back), derived nightly.
        // The PPPoE name is never stored: user_hash is a keyed hash, customer_id is the mapped customer.
        // Times are UTC. up_at NULL = still gone when last checked.
        if (! Schema::hasTable('pppoe_session_presence')) {
            Schema::create('pppoe_session_presence', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('router_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->char('user_hash', 16);
                $table->dateTime('down_at');
                $table->dateTime('up_at')->nullable();
                $table->unsignedInteger('minutes')->nullable();
                // 1 = part of the gap overlaps minutes in which the router poll failed (see pppoe_presence_runs)
                $table->boolean('uncertain')->default(false);
                $table->unique(['router_id', 'user_hash', 'down_at'], 'pppoe_presence_unique');
                $table->index('down_at', 'pppoe_presence_down_idx');
                $table->index(['customer_id', 'down_at'], 'pppoe_presence_customer_idx');
            });
        }

        // Progress marker of the presence archive: one row per router per WIB day that was read completely.
        // This is what makes the backfill resumable and the nightly catch-up cheap.
        if (! Schema::hasTable('pppoe_presence_runs')) {
            Schema::create('pppoe_presence_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedInteger('router_id');
                $table->date('day_wib');
                $table->unsignedInteger('series')->default(0);
                $table->unsignedInteger('gaps')->default(0);
                // Minutes of the WIB day in which the router poll failed (skynet_scrape_up != 1): every session of
                // the router is missing then, which is not a real drop. ranges = [[start_minute, end_minute), ...]
                // as minute offsets from 00:00 WIB. The analysis must leave these windows out.
                $table->unsignedSmallInteger('unknown_minutes')->default(0);
                $table->json('unknown_ranges')->nullable();
                $table->dateTime('finished_at');
                $table->unique(['router_id', 'day_wib'], 'pppoe_presence_runs_unique');
            });
        }
    }

    public function down(): void
    {
        // Intentionally additive: keep recorded history.
    }
};
