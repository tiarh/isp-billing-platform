<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odp_odc', function (Blueprint $table) {
            if (! Schema::hasColumn('odp_odc', 'olt_port')) {
                $table->string('olt_port')->nullable()->after('type');
            }
            if (! Schema::hasColumn('odp_odc', 'total_ports')) {
                $table->integer('total_ports')->nullable()->after('olt_port');
            }
            if (! Schema::hasColumn('odp_odc', 'description')) {
                $table->text('description')->nullable()->after('total_ports');
            }
        });
    }

    public function down(): void
    {
        Schema::table('odp_odc', function (Blueprint $table) {
            $table->dropColumn(['olt_port', 'total_ports', 'description']);
        });
    }
};
