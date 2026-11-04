<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olts')) {
            return;
        }

        Schema::table('olts', function (Blueprint $table): void {
            if (! Schema::hasColumn('olts', 'sync_status')) {
                $table->string('sync_status', 32)->default('idle')->after('last_gpon_synced_at');
            }
            if (! Schema::hasColumn('olts', 'sync_started_at')) {
                $table->timestamp('sync_started_at')->nullable()->after('sync_status');
            }
            if (! Schema::hasColumn('olts', 'last_sync_error')) {
                $table->text('last_sync_error')->nullable()->after('sync_started_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('olts')) {
            return;
        }

        Schema::table('olts', function (Blueprint $table): void {
            foreach (['last_sync_error', 'sync_started_at', 'sync_status'] as $column) {
                if (Schema::hasColumn('olts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
