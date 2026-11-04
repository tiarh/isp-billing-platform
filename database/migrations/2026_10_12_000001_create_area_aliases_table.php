<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PSB B1: alternative spellings / locality names that resolve to an area.
 * alias_key = normalized alias (see AreaLocalityResolver::normalize), globally unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('area_aliases')) {
            return;
        }

        Schema::create('area_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->string('alias');
            $table->string('alias_key')->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_aliases');
    }
};
