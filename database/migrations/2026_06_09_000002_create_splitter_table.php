<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('splitter', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type'); // 1:2, 1:4, 1:8, 1:16, 1:32
            $table->integer('total_ports');
            $table->integer('used_ports')->default(0);
            $table->string('status')->default('active'); // active, damaged, maintenance
            $table->foreignId('parent_odp_odc_id')->nullable()->constrained('odp_odc')->nullOnDelete();
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('splitter');
    }
};
