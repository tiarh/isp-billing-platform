<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Plan C / C3: secrets hash cache for skip-unchanged fullSync */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('router_mt_secret_states')) {
            return;
        }

        Schema::create('router_mt_secret_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->unique()->constrained('routers')->cascadeOnDelete();
            $table->string('secrets_hash', 64)->nullable();
            $table->unsignedInteger('secret_count')->default(0);
            $table->unsignedInteger('mapped_count')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->string('last_status', 32)->nullable(); // skipped|synced|failed
            $table->string('last_message', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_mt_secret_states');
    }
};
