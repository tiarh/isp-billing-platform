<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            if (! Schema::hasColumn('routers', 'sync_status')) {
                $table->string('sync_status')->default('idle')->after('isolation_profile');
            }

            if (! Schema::hasColumn('routers', 'sync_started_at')) {
                $table->timestamp('sync_started_at')->nullable()->after('sync_status');
            }

            if (! Schema::hasColumn('routers', 'sync_finished_at')) {
                $table->timestamp('sync_finished_at')->nullable()->after('sync_started_at');
            }

            if (! Schema::hasColumn('routers', 'sync_lock_until')) {
                $table->timestamp('sync_lock_until')->nullable()->after('sync_finished_at');
            }

            if (! Schema::hasColumn('routers', 'sync_message')) {
                $table->text('sync_message')->nullable()->after('sync_lock_until');
            }

            if (! Schema::hasColumn('routers', 'last_sync_stats')) {
                $table->json('last_sync_stats')->nullable()->after('sync_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            foreach ([
                'last_sync_stats',
                'sync_message',
                'sync_lock_until',
                'sync_finished_at',
                'sync_started_at',
                'sync_status',
            ] as $column) {
                if (Schema::hasColumn('routers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
