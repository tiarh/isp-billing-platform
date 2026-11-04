<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendances')) {
            return;
        }

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->string('sender_number')->nullable();
            $table->string('sender_name')->nullable();
            $table->text('caption')->nullable();
            $table->string('photo_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
