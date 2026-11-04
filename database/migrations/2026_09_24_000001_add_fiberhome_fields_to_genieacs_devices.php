<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return;
        }

        Schema::table('genieacs_devices', function (Blueprint $table): void {
            $columns = [
                'ssid_5g' => fn () => $table->string('ssid_5g', 191)->nullable()->after('ssid'),
                'ip_tr069' => fn () => $table->string('ip_tr069', 64)->nullable()->after('ip_address'),
                'pon_mode' => fn () => $table->string('pon_mode', 32)->nullable()->after('wan_status'),
                'wifi_clients' => fn () => $table->unsignedInteger('wifi_clients')->nullable()->after('pon_mode'),
                'uptime_text' => fn () => $table->string('uptime_text', 32)->nullable()->after('uptime_seconds'),
                'pppoe_uptime' => fn () => $table->string('pppoe_uptime', 32)->nullable()->after('uptime_text'),
            ];

            foreach ($columns as $name => $callback) {
                if (! Schema::hasColumn('genieacs_devices', $name)) {
                    $callback();
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return;
        }

        Schema::table('genieacs_devices', function (Blueprint $table): void {
            foreach (['ssid_5g', 'ip_tr069', 'pon_mode', 'wifi_clients', 'uptime_text', 'pppoe_uptime'] as $column) {
                if (Schema::hasColumn('genieacs_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
