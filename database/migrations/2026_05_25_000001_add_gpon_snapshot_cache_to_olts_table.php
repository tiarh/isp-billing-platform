<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            if (! Schema::hasColumn('olts', 'last_gpon_snapshot')) {
                $table->json('last_gpon_snapshot')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('olts', 'last_gpon_synced_at')) {
                $table->timestamp('last_gpon_synced_at')->nullable()->after('last_gpon_snapshot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            foreach (['last_gpon_synced_at', 'last_gpon_snapshot'] as $column) {
                if (Schema::hasColumn('olts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
