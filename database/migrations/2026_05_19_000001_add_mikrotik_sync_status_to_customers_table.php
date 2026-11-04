<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'mikrotik_sync_status')) {
                $table->string('mikrotik_sync_status')->default('unknown')->after('previous_profile');
            }

            if (! Schema::hasColumn('customers', 'mikrotik_synced_at')) {
                $table->timestamp('mikrotik_synced_at')->nullable()->after('mikrotik_sync_status');
            }

            if (! Schema::hasColumn('customers', 'mikrotik_sync_checked_at')) {
                $table->timestamp('mikrotik_sync_checked_at')->nullable()->after('mikrotik_synced_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'mikrotik_sync_checked_at')) {
                $table->dropColumn('mikrotik_sync_checked_at');
            }

            if (Schema::hasColumn('customers', 'mikrotik_synced_at')) {
                $table->dropColumn('mikrotik_synced_at');
            }

            if (Schema::hasColumn('customers', 'mikrotik_sync_status')) {
                $table->dropColumn('mikrotik_sync_status');
            }
        });
    }
};
