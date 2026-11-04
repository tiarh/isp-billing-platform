<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('transactions')) {
            Schema::create('transactions', function (Blueprint $table) {
                $table->id();
                $table->string('reference')->unique();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->enum('channel', ['whatsapp', 'manual'])->default('manual');
                $table->enum('method', ['cash', 'transfer', 'qris', 'other'])->nullable();
                $table->enum('status', ['pending', 'verified', 'rejected', 'paid', 'failed'])->default('verified');
                $table->string('proof_url')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
