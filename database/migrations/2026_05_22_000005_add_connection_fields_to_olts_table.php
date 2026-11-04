<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->string('management_protocol')->nullable()->after('management_ip');
            $table->unsignedInteger('management_port')->nullable()->after('management_protocol');
            $table->string('username')->nullable()->after('management_port');
            $table->text('password')->nullable()->after('username');
            $table->string('snmp_community')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('olts', function (Blueprint $table) {
            $table->dropColumn([
                'management_protocol',
                'management_port',
                'username',
                'password',
                'snmp_community',
            ]);
        });
    }
};
