<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->integer('port')->default(8728);
            // winbox_port removed
            $table->string('username');
            $table->text('password'); // Encrypted
            $table->boolean('is_active')->default(true);
            $table->enum('connection_status', ['unknown', 'online', 'offline'])->default('unknown');

            // Monitoring & Health
            $table->string('isolation_profile')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->integer('last_scan_customers_count')->default(0);
            $table->integer('current_online_count')->nullable();
            $table->integer('total_pppoe_count')->default(0);
            $table->integer('cpu_load')->nullable();
            $table->string('uptime')->nullable();
            $table->string('version')->nullable();
            $table->string('board_name')->nullable();

            $table->timestamps();

            $table->index(['ip_address', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routers');
    }
};
