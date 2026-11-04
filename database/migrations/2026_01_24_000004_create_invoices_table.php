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
        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->nullable()->unique();
                $table->string('code')->unique();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->date('period');
                $table->decimal('amount', 10, 2);
                $table->enum('status', ['unpaid', 'paid', 'overdue', 'void'])->default('unpaid');
                $table->date('due_date');
                $table->timestamp('generated_at')->nullable();
                $table->string('payment_link')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'period']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
