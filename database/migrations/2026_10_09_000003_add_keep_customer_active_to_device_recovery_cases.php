<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-09: when a pickup reaches `received`, the customer becomes
 * `terminated` — except cases flagged keep_customer_active (multi-device
 * homes where only an extra device is collected). Additive, default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('device_recovery_cases') || Schema::hasColumn('device_recovery_cases', 'keep_customer_active')) {
            return;
        }

        Schema::table('device_recovery_cases', function (Blueprint $table): void {
            $table->boolean('keep_customer_active')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('device_recovery_cases') && Schema::hasColumn('device_recovery_cases', 'keep_customer_active')) {
            Schema::table('device_recovery_cases', function (Blueprint $table): void {
                $table->dropColumn('keep_customer_active');
            });
        }
    }
};
