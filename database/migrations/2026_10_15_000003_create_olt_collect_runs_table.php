<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * olt_collect_runs predates the tracked migrations (it was created directly on the production DB).
 * The outage detector reads it for collector freshness, so fresh environments (CI, local, demo) need
 * the table too. Guarded by hasTable: a no-op on production. Columns mirror the prod snapshot
 * (tests/Fixtures/schema/prod-columns.json).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('olt_collect_runs')) {
            return;
        }

        Schema::create('olt_collect_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('olt_id')->index();
            $table->string('tier', 32);
            $table->string('status', 24)->default('running');
            $table->unsignedInteger('onus_total')->default(0);
            $table->unsignedInteger('onus_updated')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Never drop: on production this table is owned by the collector, not by this migration.
    }
};
