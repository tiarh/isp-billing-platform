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
        Schema::create('wa_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wa_campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone_number');
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wa_campaign_recipients');
    }
};
