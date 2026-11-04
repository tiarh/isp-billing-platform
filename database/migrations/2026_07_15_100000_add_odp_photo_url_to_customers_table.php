<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'odp_photo_url')) {
                $table->string('odp_photo_url', 512)->nullable()->after('ktp_photo_url');
            }
            if (! Schema::hasColumn('customers', 'odp_name')) {
                $table->string('odp_name', 128)->nullable()->after('odp_photo_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'odp_photo_url')) {
                $table->dropColumn('odp_photo_url');
            }
            if (Schema::hasColumn('customers', 'odp_name')) {
                $table->dropColumn('odp_name');
            }
        });
    }
};
