<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4 follow-up: the PPPoE session uptime the CPE itself reports (WANPPPConnection.1.Uptime, refreshed hourly).
 * A drop of it between two syncs, without an ONU restart, is a PPP reconnect seen by the CPE (cpe_events
 * `ppp_reconnect`). Additive and guarded; down() keeps data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('genieacs_devices') && ! Schema::hasColumn('genieacs_devices', 'ppp_uptime_s')) {
            Schema::table('genieacs_devices', function (Blueprint $table): void {
                $table->unsignedInteger('ppp_uptime_s')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Evidence only: keep the data (additive migration).
    }
};
