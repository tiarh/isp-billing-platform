<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PG-3: one row per online payment attempt (charge) for an invoice.
 * Money is integer rupiah. amount_invoice is what the invoice is credited;
 * amount_charged = amount_invoice + fee when the customer bears the fee.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_gateway_transactions')) {
            return;
        }

        Schema::create('payment_gateway_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40)->index();
            $table->string('environment', 20)->default('sandbox');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('merchant_order_id', 50)->unique();
            $table->string('provider_reference', 100)->nullable()->index();
            $table->string('payment_method', 20);
            $table->string('method_group', 20)->default('other');
            $table->string('method_name', 100)->nullable();
            $table->string('kind', 20)->nullable();
            $table->unsignedBigInteger('amount_invoice');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('fee_bearer', 20)->default('customer');
            $table->unsignedBigInteger('amount_charged');
            $table->string('status', 20)->default('created')->index();
            $table->text('payment_url')->nullable();
            $table->string('va_number', 64)->nullable();
            $table->text('qr_string')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->unsignedSmallInteger('status_check_count')->default(0);
            $table->timestamp('last_status_check_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->string('source', 20)->default('public');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_transactions');
    }
};
