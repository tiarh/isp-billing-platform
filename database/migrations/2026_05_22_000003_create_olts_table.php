<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('olts', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->unique();
            $table->foreignId('area_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('management_ip')->nullable();
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('olts');
    }
};
