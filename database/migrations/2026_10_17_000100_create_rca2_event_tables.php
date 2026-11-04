<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outage RCA-2: event history (transitions only, 90 day retention via outage:events-prune).
 * Additive and guarded; nothing reads these tables except the shadow detector and the "Riwayat" panel.
 * All times are stored in UTC.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('onu_state_events')) {
            Schema::create('onu_state_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('olt_id');
                $table->string('onu_ref', 40);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('from_phase', 24)->nullable();
                $table->string('to_phase', 24);
                $table->string('cause', 32)->nullable();
                $table->string('source', 16); // olt_poll | olt_detail | huawei_info
                $table->dateTime('occurred_at');
                $table->dateTime('collected_at');
                $table->index(['olt_id', 'onu_ref', 'occurred_at'], 'onu_state_events_onu_idx');
                $table->index('customer_id', 'onu_state_events_customer_idx');
                $table->index('occurred_at', 'onu_state_events_at_idx');
            });
        }

        if (! Schema::hasTable('cpe_events')) {
            Schema::create('cpe_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('device_id', 191);
                $table->string('type', 24); // boot | uptime_reset | wan_down | wan_up
                $table->dateTime('at');
                $table->json('meta')->nullable();
                $table->index(['customer_id', 'at'], 'cpe_events_customer_idx');
                $table->index(['device_id', 'at'], 'cpe_events_device_idx');
                $table->index('at', 'cpe_events_at_idx');
            });
        }

        if (! Schema::hasTable('pppoe_session_events')) {
            Schema::create('pppoe_session_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('router_id');
                $table->string('pppoe_user', 120);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('event', 16); // connected | disconnected | auth_failed | stuck | other
                $table->string('reason', 120)->nullable();
                $table->string('caller_mac', 17)->nullable();
                $table->dateTime('at');
                $table->string('source', 8); // syslog | poll
                $table->index(['router_id', 'pppoe_user', 'at'], 'pppoe_events_user_idx');
                $table->index(['customer_id', 'at'], 'pppoe_events_customer_idx');
                $table->index('at', 'pppoe_events_at_idx');
            });
        }
    }

    public function down(): void
    {
        // Intentionally additive: keep recorded history.
    }
};
