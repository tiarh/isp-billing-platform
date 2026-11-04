<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F0 PR-D: read-only router <-> DB isolation reconciliation report.
 *
 * One row per finding of a `billing:reconcile-network --store` run. Rows are
 * report data only (never read back by enforcement) and pruned after
 * services.network_reconcile.retention_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('enforcement_drift')) {
            return;
        }

        Schema::create('enforcement_drift', function (Blueprint $table) {
            $table->id();
            $table->uuid('run_id');
            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('pppoe_user')->nullable();
            $table->string('kind', 64);
            $table->string('severity', 16);
            $table->string('db_status', 32)->nullable();
            $table->string('router_profile')->nullable();
            $table->boolean('router_disabled')->nullable();
            $table->boolean('online')->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('detected_at');

            $table->index('run_id');
            $table->index(['detected_at', 'severity']);
            $table->index(['customer_id', 'detected_at']);
            $table->index(['router_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enforcement_drift');
    }
};
