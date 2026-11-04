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

        Schema::table('genieacs_devices', function (Blueprint $table) {
            $columns = [
                'manufacturer' => fn () => $table->string('manufacturer', 128)->nullable()->after('product_class'),
                'model' => fn () => $table->string('model', 128)->nullable()->after('manufacturer'),
                'hardware_version' => fn () => $table->string('hardware_version', 64)->nullable()->after('software_version'),
                'mac_address' => fn () => $table->string('mac_address', 32)->nullable()->after('ip_address'),
                'tags' => fn () => $table->json('tags')->nullable()->after('ssid'),
                'pelanggan' => fn () => $table->string('pelanggan', 191)->nullable()->after('tags'),
                'genieacs_customer_name' => fn () => $table->string('genieacs_customer_name', 191)->nullable()->after('pelanggan'),
                'pppoe_user' => fn () => $table->string('pppoe_user', 191)->nullable()->after('genieacs_customer_name'),
                'match_source' => fn () => $table->string('match_source', 32)->nullable()->after('customer_id'),
                'wan_status' => fn () => $table->string('wan_status', 32)->nullable()->after('pppoe_user'),
                'uptime_seconds' => fn () => $table->unsignedBigInteger('uptime_seconds')->nullable()->after('wan_status'),
                'wlan_enabled' => fn () => $table->boolean('wlan_enabled')->nullable()->after('uptime_seconds'),
                'wlan_channel' => fn () => $table->string('wlan_channel', 16)->nullable()->after('wlan_enabled'),
                'registered_at' => fn () => $table->timestamp('registered_at')->nullable()->after('last_inform_at'),
                'last_ping_at' => fn () => $table->timestamp('last_ping_at')->nullable()->after('registered_at'),
                'last_ping_success' => fn () => $table->boolean('last_ping_success')->nullable()->after('last_ping_at'),
                'last_ping_rtt_ms' => fn () => $table->unsignedInteger('last_ping_rtt_ms')->nullable()->after('last_ping_success'),
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

        Schema::table('genieacs_devices', function (Blueprint $table) {
            $drop = [
                'manufacturer', 'model', 'hardware_version', 'mac_address', 'tags', 'pelanggan',
                'genieacs_customer_name', 'pppoe_user', 'match_source', 'wan_status', 'uptime_seconds',
                'wlan_enabled', 'wlan_channel', 'registered_at', 'last_ping_at', 'last_ping_success', 'last_ping_rtt_ms',
            ];

            foreach ($drop as $column) {
                if (Schema::hasColumn('genieacs_devices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
