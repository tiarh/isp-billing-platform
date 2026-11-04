<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outage RCA-3: WiFi / CPE layer. Additive and guarded; down() keeps data (the new columns and tables are
 * history / evidence only and nothing else depends on them). All times are UTC.
 *
 * - genieacs_devices: fresh WLAN / PPP state + the age of those values (stale values are stored as NULL).
 * - cpe_daily: one row per device per WIB day (upserted by every sync), 400 day retention.
 * - cpe_wifi_samples: 5-minute samples for symptomatic devices only (watch / summon), 14 day retention.
 * No MAC, hostname or SSID is stored in the new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('genieacs_devices')) {
            Schema::table('genieacs_devices', function (Blueprint $table): void {
                if (! Schema::hasColumn('genieacs_devices', 'last_boot_at')) {
                    $table->dateTime('last_boot_at')->nullable();
                }
                foreach (['wlan24_status', 'wlan5_status'] as $col) {
                    if (! Schema::hasColumn('genieacs_devices', $col)) {
                        $table->string($col, 12)->nullable();
                    }
                }
                foreach (['wlan24_enabled', 'wlan5_enabled'] as $col) {
                    if (! Schema::hasColumn('genieacs_devices', $col)) {
                        $table->boolean($col)->nullable();
                    }
                }
                foreach (['wlan24_assoc', 'wlan5_assoc'] as $col) {
                    if (! Schema::hasColumn('genieacs_devices', $col)) {
                        $table->smallInteger($col)->nullable();
                    }
                }
                if (! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
                    $table->dateTime('wlan_values_at')->nullable();
                }
                if (! Schema::hasColumn('genieacs_devices', 'ppp_status')) {
                    $table->string('ppp_status', 24)->nullable();
                }
                if (! Schema::hasColumn('genieacs_devices', 'ppp_last_error')) {
                    $table->string('ppp_last_error', 48)->nullable();
                }
                if (! Schema::hasColumn('genieacs_devices', 'ppp_values_at')) {
                    $table->dateTime('ppp_values_at')->nullable();
                }
                if (! Schema::hasColumn('genieacs_devices', 'cpe_summoned_at')) {
                    $table->dateTime('cpe_summoned_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('cpe_daily')) {
            Schema::create('cpe_daily', function (Blueprint $table): void {
                $table->id();
                $table->string('device_id', 191);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->date('day_wib');
                $table->smallInteger('samples')->default(0);
                $table->smallInteger('fresh_wlan_samples')->default(0);
                $table->smallInteger('wlan24_down_samples')->default(0);
                $table->smallInteger('wlan5_down_samples')->default(0);
                $table->smallInteger('assoc24_max')->nullable();
                $table->smallInteger('assoc5_max')->nullable();
                $table->smallInteger('assoc_zero_samples')->default(0);
                $table->unsignedTinyInteger('boots')->default(0);
                $table->unsignedInteger('min_uptime_s')->nullable();
                $table->smallInteger('ppp_error_samples')->default(0);
                $table->decimal('rx_min', 5, 2)->nullable();
                $table->decimal('rx_max', 5, 2)->nullable();
                $table->dateTime('updated_at')->nullable();
                $table->unique(['device_id', 'day_wib'], 'cpe_daily_device_day_unique');
                $table->index(['customer_id', 'day_wib'], 'cpe_daily_customer_day_idx');
            });
        }

        if (! Schema::hasTable('cpe_wifi_samples')) {
            Schema::create('cpe_wifi_samples', function (Blueprint $table): void {
                $table->id();
                $table->string('device_id', 191);
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->dateTime('at');
                $table->string('source', 8); // watch | summon
                $table->string('wlan24_status', 12)->nullable();
                $table->string('wlan5_status', 12)->nullable();
                $table->smallInteger('assoc24')->nullable();
                $table->smallInteger('assoc5')->nullable();
                $table->unsignedInteger('uptime_s')->nullable();
                $table->string('ppp_status', 24)->nullable();
                $table->json('rssi')->nullable(); // <= 32 ints, anonymous
                $table->unsignedTinyInteger('lan_active')->nullable();
                $table->index(['customer_id', 'at'], 'cpe_wifi_samples_customer_idx');
                $table->index('at', 'cpe_wifi_samples_at_idx');
            });
        }
    }

    public function down(): void
    {
        // History / evidence only: keep the data (additive migration).
    }
};
