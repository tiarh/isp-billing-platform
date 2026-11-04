<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            if (! Schema::hasColumn('olts', 'vendor')) {
                $table->string('vendor')->default('zte_c300')->after('code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            if (Schema::hasColumn('olts', 'vendor')) {
                $table->dropColumn('vendor');
            }
        });
    }
};
