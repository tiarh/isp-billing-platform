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
        if (! Schema::hasTable('invoice_broadcasts')) {
            Schema::create('invoice_broadcasts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
                $table->string('type')->default('invoice_created');
                $table->enum('channel', ['whatsapp', 'email', 'sms'])->default('whatsapp');
                $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
                $table->text('message')->nullable();
                $table->string('message_id')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                $table->index(['invoice_id', 'channel']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_broadcasts');
    }
};
