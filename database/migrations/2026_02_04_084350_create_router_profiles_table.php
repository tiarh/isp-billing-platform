<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('router_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // Profile name (e.g., "20MB")
            $table->text('rate_limit')->nullable(); // Full rate limit string from Mikrotik
            $table->string('bandwidth')->nullable(); // Extracted bandwidth (e.g., "20M")
            $table->string('local_address')->nullable();
            $table->string('remote_address')->nullable();
            $table->string('only_one')->nullable();
            $table->timestamps();

            // Unique: Router + Profile Name
            $table->unique(['router_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_profiles');
    }
};
