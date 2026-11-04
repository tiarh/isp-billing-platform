<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_enforcement_actions')) {
            return;
        }

        Schema::create('billing_enforcement_actions', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('action', 32);
            $table->string('policy_version', 64);
            $table->string('status', 32)->default('pending');
            $table->json('metadata')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'action', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_enforcement_actions');
    }
};
