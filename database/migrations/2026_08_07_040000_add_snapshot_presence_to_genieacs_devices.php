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
            if (! Schema::hasColumn('genieacs_devices', 'snapshot_status')) {
                $table->string('snapshot_status', 16)->default('active')->index()->after('match_source');
            }

            if (! Schema::hasColumn('genieacs_devices', 'last_seen_sync_at')) {
                $table->timestamp('last_seen_sync_at')->nullable()->index()->after('snapshot_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return;
        }

        Schema::table('genieacs_devices', function (Blueprint $table): void {
            if (Schema::hasColumn('genieacs_devices', 'last_seen_sync_at')) {
                $table->dropColumn('last_seen_sync_at');
            }

            if (Schema::hasColumn('genieacs_devices', 'snapshot_status')) {
                $table->dropColumn('snapshot_status');
            }
        });
    }
};
