<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * olt_vlan_mappings, psb_import_candidates and progress_reports predate the tracked migrations (they were
 * created directly on the production DB). The app reads them, so fresh installs (CI, local, demo) need them.
 *
 * Idempotent: every table is guarded by hasTable, so on production this is a no-op and never alters existing
 * structure or data. Columns mirror the prod snapshot (tests/Fixtures/schema/prod-columns.json); the snapshot
 * carries columns only, so beyond a plain olt_id lookup index no extra indexes are invented.
 * (olt_collect_runs is handled by 2026_10_15_000003.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('olt_vlan_mappings')) {
            Schema::create('olt_vlan_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->index();
                $table->string('pon_port', 32);
                $table->string('service_type', 32)->default('internet');
                $table->unsignedSmallInteger('vlan');
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('psb_import_candidates')) {
            Schema::create('psb_import_candidates', function (Blueprint $table) {
                $table->id();
                $table->string('source_file', 255);
                $table->string('customer_code', 255)->nullable();
                $table->string('pppoe_user', 255)->nullable();
                $table->string('name', 255);
                $table->text('address')->nullable();
                $table->string('area_name', 255)->nullable();
                $table->string('phone', 255)->nullable();
                $table->string('package_name', 255)->nullable();
                $table->string('speed', 255)->nullable();
                $table->unsignedInteger('price')->nullable();
                $table->string('status', 255)->default('staged');
                $table->unsignedBigInteger('matched_customer_id')->nullable();
                $table->unsignedBigInteger('matched_router_id')->nullable();
                $table->unsignedBigInteger('matched_router_staged_customer_id')->nullable();
                $table->unsignedBigInteger('resolved_area_id')->nullable();
                $table->unsignedBigInteger('resolved_package_id')->nullable();
                $table->unsignedBigInteger('resolved_router_id')->nullable();
                $table->string('router_profile', 255)->nullable();
                $table->string('match_status', 255)->default('unmatched');
                $table->text('match_notes')->nullable();
                $table->unsignedBigInteger('applied_customer_id')->nullable();
                $table->timestamp('applied_at')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamp('imported_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('progress_reports')) {
            Schema::create('progress_reports', function (Blueprint $table) {
                $table->id();
                $table->integer('month');
                $table->integer('year');
                $table->json('sitac_data')->nullable();
                $table->json('ftth_data')->nullable();
                $table->json('project_data')->nullable();
                $table->json('migrasi_data')->nullable();
                $table->json('aktivasi_data')->nullable();
                $table->json('highlights')->nullable();
                $table->json('priorities')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Never drop: on production these tables hold real data and are not owned by this migration.
    }
};
