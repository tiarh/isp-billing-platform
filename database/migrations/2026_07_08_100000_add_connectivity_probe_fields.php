<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('genieacs_devices', function (Blueprint $table) {
            if (! Schema::hasColumn('genieacs_devices', 'last_ping_at')) {
                $table->timestamp('last_ping_at')->nullable()->after('last_inform_at');
                $table->boolean('last_ping_success')->nullable()->after('last_ping_at');
                $table->string('last_ping_host')->nullable()->after('last_ping_success');
                $table->unsignedSmallInteger('last_ping_success_count')->nullable()->after('last_ping_host');
                $table->unsignedSmallInteger('last_ping_failure_count')->nullable()->after('last_ping_success_count');
                $table->unsignedInteger('last_ping_rtt_ms')->nullable()->after('last_ping_failure_count');
                $table->string('last_ping_error')->nullable()->after('last_ping_rtt_ms');
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'service_online')) {
                $table->boolean('service_online')->nullable()->after('is_online');
                $table->timestamp('service_online_at')->nullable()->after('service_online');
            }
        });
    }

    public function down(): void
    {
        // Existing production data is intentionally preserved.
    }
};
