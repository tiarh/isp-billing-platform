<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odp_odc', function (Blueprint $table) {
            if (! Schema::hasColumn('odp_odc', 'status')) {
                $table->string('status')->default('active')->after('description'); // active, damaged, maintenance
            }
        });
    }

    public function down(): void
    {
        Schema::table('odp_odc', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
