<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_portal_accounts')) {
            return;
        }

        Schema::create('customer_portal_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('password');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_portal_accounts');
    }
};
