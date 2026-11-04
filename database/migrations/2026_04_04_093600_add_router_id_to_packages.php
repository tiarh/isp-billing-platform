<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'router_id')) {
                $table->foreignId('router_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
            $table->dropColumn('router_id');
        });
    }
};
