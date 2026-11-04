<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olt_operation_logs')) {
            Schema::create('olt_operation_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('olt_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('operation', 64);
                $table->string('onu_ref')->nullable();
                $table->string('status', 32)->default('success');
                $table->json('payload')->nullable();
                $table->json('result')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();

                $table->index(['olt_id', 'operation']);
                $table->index(['olt_id', 'onu_ref']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('olt_operation_logs');
    }
};
