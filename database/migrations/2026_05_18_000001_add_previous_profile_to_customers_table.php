<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'previous_profile')) {
                $table->string('previous_profile')->nullable()->after('mikrotik_profile');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'previous_profile')) {
                $table->dropColumn('previous_profile');
            }
        });
    }
};
