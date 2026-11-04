<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_recovery_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('recovery_case_id')->constrained('device_recovery_cases')->restrictOnDelete();
            $table->string('device_type', 32);
            $table->string('expected_serial_number', 128)->nullable()->index();
            $table->string('captured_serial_number', 128)->nullable()->index();
            $table->string('expected_source', 32)->nullable();
            $table->string('status', 32)->index();
            $table->string('condition', 32)->default('unknown')->index();
            $table->foreignId('captured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->json('photo_paths')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['recovery_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_recovery_items');
    }
};
