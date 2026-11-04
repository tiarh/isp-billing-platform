<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 2.4 PR-1: one row per outage episode (shadow mode records "would have
 * ticketed"; nothing reads or writes this table yet).
 *
 * open_key is the "one open episode per scope" trick: it equals scope_key while the
 * episode is open/confirmed and is set to NULL when it is closed. MySQL UNIQUE allows
 * many NULLs, so closed rows never collide (no partial index needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outage_signals')) {
            return;
        }

        Schema::create('outage_signals', function (Blueprint $table) {
            $table->id();
            // mass_los|single_los|dying_gasp|weak_rx|pppoe_down|olt_wide
            $table->string('signal_type', 24);
            // e.g. pon:1:1/3/3, customer:123, olt:1
            $table->string('scope_key', 120);
            $table->string('open_key', 120)->nullable()->unique();
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->string('pon_port', 40)->nullable();
            $table->string('odp_label', 120)->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('area_id')->nullable();
            // open|confirmed|resolved|expired
            $table->string('state', 16)->default('open');
            $table->dateTime('opened_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->unsignedInteger('observations')->default(0);
            $table->unsignedInteger('affected_count')->default(0);
            $table->json('affected_customer_ids')->nullable();
            $table->json('payload')->nullable();
            // isolated|open_ticket|migration|psb|recovery|olt_write|maintenance|stale|inside_mass|quiet_hours|cap
            $table->string('suppressed_reason', 32)->nullable();
            $table->unsignedBigInteger('parent_signal_id')->nullable();
            // shadow|live (mode in force when the episode was confirmed)
            $table->string('mode_at_confirm', 8)->nullable();
            // ticket_mass|ticket_single|ticket_maintenance|noc_alarm|log_only|none
            $table->string('would_action', 24)->nullable();
            // none|draft_ticket|ticket|alarm|customer_wa
            $table->string('action_taken', 24)->default('none');
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('matched_ticket_id')->nullable();
            $table->string('match_method', 32)->nullable();
            $table->integer('match_lag_minutes')->nullable();
            // tp|fp|unsure (NOC label)
            $table->string('label', 8)->nullable();
            $table->unsignedBigInteger('labelled_by')->nullable();
            $table->unsignedInteger('flap_count')->default(0);
            $table->timestamps();

            $table->index(['signal_type', 'state', 'confirmed_at'], 'outage_signals_type_state_confirmed_idx');
            $table->index(['customer_id', 'opened_at'], 'outage_signals_customer_opened_idx');
            $table->index(['olt_id', 'pon_port', 'opened_at'], 'outage_signals_olt_pon_opened_idx');
            $table->index('ticket_id', 'outage_signals_ticket_idx');
            $table->index('opened_at', 'outage_signals_opened_at_idx');
        });
    }

    public function down(): void
    {
        // Shadow evidence is the whole point of the table: only drop when empty.
        if (Schema::hasTable('outage_signals') && DB::table('outage_signals')->count() === 0) {
            Schema::drop('outage_signals');
        }
    }
};
