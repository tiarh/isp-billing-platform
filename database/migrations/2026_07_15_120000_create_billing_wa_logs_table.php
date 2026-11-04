<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_wa_logs')) {
            return;
        }

        Schema::create('billing_wa_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64); // payment, customer_created, isolation
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('phone', 32)->nullable();
            $table->text('message')->nullable();
            $table->string('status', 32)->default('pending'); // pending, sent, failed, skipped
            $table->text('error')->nullable();
            $table->string('dedupe_key', 191)->nullable()->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['event', 'status']);
            $table->index(['customer_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_wa_logs');
    }
};
