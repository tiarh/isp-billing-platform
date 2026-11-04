<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'olt_id')) {
                $table->foreignId('olt_id')->nullable()->after('router_id')->constrained('olts')->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'olt_port_label')) {
                $table->string('olt_port_label')->nullable()->after('olt_id');
            }
            if (! Schema::hasColumn('customers', 'onu_serial')) {
                $table->string('onu_serial')->nullable()->after('olt_port_label');
            }
            if (! Schema::hasColumn('customers', 'olt_status')) {
                $table->string('olt_status', 50)->nullable()->after('onu_serial');
            }
            if (! Schema::hasColumn('customers', 'onu_rx_power_dbm')) {
                $table->decimal('onu_rx_power_dbm', 6, 2)->nullable()->after('olt_status');
            }
            if (! Schema::hasColumn('customers', 'onu_tx_power_dbm')) {
                $table->decimal('onu_tx_power_dbm', 6, 2)->nullable()->after('onu_rx_power_dbm');
            }
            if (! Schema::hasColumn('customers', 'fiber_distance_m')) {
                $table->unsignedInteger('fiber_distance_m')->nullable()->after('onu_tx_power_dbm');
            }
            if (! Schema::hasColumn('customers', 'olt_last_synced_at')) {
                $table->timestamp('olt_last_synced_at')->nullable()->after('fiber_distance_m');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['olt_last_synced_at', 'fiber_distance_m', 'onu_tx_power_dbm', 'onu_rx_power_dbm', 'olt_status', 'onu_serial', 'olt_port_label'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }

            if (Schema::hasColumn('customers', 'olt_id')) {
                $table->dropConstrainedForeignId('olt_id');
            }
        });
    }
};
