<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('device_action_logs')) {
            return;
        }

        Schema::create('device_action_logs', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 40)->index();
            $table->string('target_type', 60)->index();
            $table->string('target_id', 120)->nullable()->index();
            $table->string('action', 80)->index();
            $table->string('status', 40)->default('requested')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('payload_hash', 64)->index();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->json('preflight')->nullable();
            $table->json('result')->nullable();
            $table->text('rollback_hint')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['domain', 'status', 'created_at'], 'device_action_logs_domain_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_action_logs');
    }
};
