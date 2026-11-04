<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_recovery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recovery_case_id')->constrained('device_recovery_cases')->restrictOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('device_recovery_items')->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->string('source', 32)->default('web');
            $table->string('idempotency_key', 191)->unique();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['recovery_case_id', 'created_at']);
            $table->index(['item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_recovery_events');
    }
};
