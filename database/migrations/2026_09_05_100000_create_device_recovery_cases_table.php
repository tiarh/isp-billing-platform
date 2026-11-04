<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_recovery_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('support_tickets')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('status', 32)->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('exception_reason')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['area_id', 'status']);
            $table->index(['assigned_to', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_recovery_cases');
    }
};
