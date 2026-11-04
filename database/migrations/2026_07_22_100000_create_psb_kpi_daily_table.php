<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3 materialize daily KPI (optional cache). Source of truth remains psb_drafts + starlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('psb_kpi_daily')) {
            return;
        }
        Schema::create('psb_kpi_daily', function (Blueprint $table) {
            $table->id();
            $table->date('work_date');
            $table->string('site_key', 64)->default('unknown');
            $table->unsignedBigInteger('area_id')->nullable();
            $table->unsignedInteger('input_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('pending_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
            $table->unique(['work_date', 'site_key', 'area_id'], 'psb_kpi_daily_unique');
            $table->index(['work_date', 'site_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psb_kpi_daily');
    }
};
