<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Second-VA-after-method-switch PR-2: ledger of customer credits (saldo).
 *
 * A second FULL gateway payment for an already settled invoice becomes an
 * `available` credit; the excess of a payment larger than the remaining balance
 * becomes a `needs_review` row (admin decides). No refunds are issued.
 *
 * source_key = "{provider}:{gateway credit key}" and is UNIQUE, so a retried
 * callback can never credit twice. Rows created by a partial apply (split) carry
 * parent_credit_id and a NULL source_key (MySQL UNIQUE allows many NULLs).
 * Additive only: no existing table is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_credits')) {
            return;
        }

        Schema::create('customer_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedBigInteger('source_invoice_id')->nullable()->index();
            $table->unsignedBigInteger('payment_gateway_event_id')->nullable()->index();
            $table->string('provider', 40)->nullable();
            $table->string('gateway_reference', 128)->nullable();
            $table->string('source_key', 191)->nullable()->unique();
            $table->unsignedBigInteger('parent_credit_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            // double_payment | overpayment | void_invoice
            $table->string('reason_code', 30);
            // available | needs_review | applied | refunded_manual | dismissed
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('applied_invoice_id')->nullable()->index();
            $table->unsignedBigInteger('applied_transaction_id')->nullable();
            $table->dateTime('applied_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credits');
    }
};
