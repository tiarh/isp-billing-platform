<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ZTE 5-minute SNMP state tick already reads the ONU status code (LOS / DyingGasp / ...) and now
 * persists it as phase_state. phase_collected_at = when that phase code was last written by the tick,
 * so the outage detector can trust LOS / DyingGasp without waiting for the 15 min / hourly inventory.
 * Nullable; null means "no tick-written phase" (detector falls back to inventory_collected_at).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olt_onus') || Schema::hasColumn('olt_onus', 'phase_collected_at')) {
            return;
        }

        Schema::table('olt_onus', function (Blueprint $table) {
            $table->timestamp('phase_collected_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('olt_onus') || ! Schema::hasColumn('olt_onus', 'phase_collected_at')) {
            return;
        }

        Schema::table('olt_onus', function (Blueprint $table) {
            $table->dropColumn('phase_collected_at');
        });
    }
};
