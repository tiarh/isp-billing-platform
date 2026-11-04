<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monitoring M-7: drop the legacy "Target NOC" tables and router_uplink_mappings.
 *
 * DESTRUCTIVE. Nothing in the application reads these tables any more
 * (M-5 #441, M-6 #447). Deploy only after a mysqldump backup was taken and verified.
 *
 * Data comes back ONLY from the dump on VM102:
 *   /home/hosting/backups/db/legacy-monitoring_<TS>.sql.gz   (kept 30 days)
 * down() recreates the three tables EMPTY (schema only) so a rollback of the code
 * has somewhere to land. To get the rows back, restore the FULL dump: it carries its
 * own DROP TABLE / CREATE TABLE, so it replaces down()'s empty schema (do not load
 * a data-only extract onto it).
 *
 * Safe on CI / fresh databases where the tables do not exist (dropIfExists).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Child first by convention. (Production has no FK constraint between
        // monitoring_probe_results and monitoring_targets; down() declares one.)
        Schema::dropIfExists('monitoring_probe_results');
        Schema::dropIfExists('monitoring_targets');
        Schema::dropIfExists('router_uplink_mappings');
    }

    public function down(): void
    {
        if (! Schema::hasTable('monitoring_targets')) {
            Schema::create('monitoring_targets', function (Blueprint $table) {
                $table->id();
                $table->string('type', 20)->default('core');
                $table->string('name', 100);
                $table->string('provider', 100)->nullable();
                $table->string('area_name', 100)->nullable();
                $table->string('target_ip', 45)->nullable();
                $table->string('source_type', 40)->default('central_ping');
                $table->string('probe_source', 50)->nullable();
                $table->string('status', 20)->default('unknown');
                $table->decimal('last_latency_ms', 10, 2)->nullable();
                $table->decimal('last_packet_loss', 6, 2)->nullable();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('monitoring_probe_results')) {
            Schema::create('monitoring_probe_results', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('monitoring_target_id');
                $table->string('status', 20);
                $table->decimal('latency_ms', 10, 2)->nullable();
                $table->decimal('packet_loss', 6, 2)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('checked_at')->nullable();
                $table->timestamps();

                $table->index(['monitoring_target_id', 'checked_at']);
                $table->foreign('monitoring_target_id')
                    ->references('id')->on('monitoring_targets')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('router_uplink_mappings')) {
            Schema::create('router_uplink_mappings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('router_id')->index();
                $table->string('grafana_router_label', 96)->nullable();
                $table->string('uplink_interface', 96);
                $table->string('role', 20)->default('primary');
                $table->string('provider', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }
};
