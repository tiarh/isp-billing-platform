<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'router_id')) {
                $table->foreignId('router_id')->nullable()->after('area_id')->constrained()->nullOnDelete();
            }
            if (! Schema::hasColumn('customers', 'mikrotik_profile')) {
                $table->string('mikrotik_profile')->nullable()->after('router_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['router_id']);
            $table->dropColumn(['router_id', 'mikrotik_profile']);
        });
    }
};
