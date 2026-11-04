<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_reversals')) {
            return;
        }

        Schema::create('payment_reversals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reversal_reference', 191)->unique();
            $table->string('idempotency_key', 191)->unique();
            $table->text('reason');
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'reversed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reversals');
    }
};
