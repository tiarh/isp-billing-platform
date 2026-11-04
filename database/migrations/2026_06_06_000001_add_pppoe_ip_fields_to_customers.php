<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'pppoe_local_address')) {
                $table->string('pppoe_local_address')->nullable()->after('pppoe_password');
            }
            if (! Schema::hasColumn('customers', 'pppoe_remote_address')) {
                $table->string('pppoe_remote_address')->nullable()->after('pppoe_local_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['pppoe_local_address', 'pppoe_remote_address']);
        });
    }
};
