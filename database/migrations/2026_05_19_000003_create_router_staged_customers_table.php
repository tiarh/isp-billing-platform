<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_staged_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->foreignId('matched_customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('pppoe_user');
            $table->string('profile')->nullable();
            $table->text('comment')->nullable();
            $table->boolean('disabled')->default(false);
            $table->string('status')->default('unmatched');
            $table->json('raw_payload')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'pppoe_user']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_staged_customers');
    }
};
