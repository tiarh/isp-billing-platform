<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('routers')) {
            if (! Schema::hasColumn('routers', 'grafana_identity')) {
                Schema::table('routers', function (Blueprint $table) {
                    $table->string('grafana_identity', 96)->nullable()->index();
                });
            }
            if (! Schema::hasColumn('routers', 'monitoring_enabled')) {
                Schema::table('routers', function (Blueprint $table) {
                    $table->boolean('monitoring_enabled')->default(false);
                });
            }
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
        } else {
            $missing = fn (string $c) => ! Schema::hasColumn('router_uplink_mappings', $c);
            Schema::table('router_uplink_mappings', function (Blueprint $table) use ($missing) {
                if ($missing('router_id')) {
                    $table->unsignedBigInteger('router_id')->nullable()->index();
                }
                if ($missing('grafana_router_label')) {
                    $table->string('grafana_router_label', 96)->nullable();
                }
                if ($missing('uplink_interface')) {
                    $table->string('uplink_interface', 96)->default('');
                }
                if ($missing('role')) {
                    $table->string('role', 20)->default('primary');
                }
                if ($missing('provider')) {
                    $table->string('provider', 100)->nullable();
                }
                if ($missing('is_active')) {
                    $table->boolean('is_active')->default(true);
                }
                if ($missing('created_at')) {
                    $table->timestamp('created_at')->nullable();
                }
                if ($missing('updated_at')) {
                    $table->timestamp('updated_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('monitoring_upstream_links')) {
            Schema::create('monitoring_upstream_links', function (Blueprint $table) {
                $table->id();
                $table->string('upstream_key', 32)->unique();
                $table->string('display_name', 64);
                $table->string('kind', 16); // transit|ix|aggregate
                $table->string('router_label', 96)->default('Jakarta-Edge');
                $table->string('interface_name', 128);
                $table->unsignedInteger('capacity_mbps')->nullable();
                $table->smallInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Intentionally left in place: legacy router_uplink_mappings (may hold prod data)
        // and routers.monitoring_enabled (may predate this migration).
        Schema::dropIfExists('monitoring_upstream_links');

        if (Schema::hasTable('routers') && Schema::hasColumn('routers', 'grafana_identity')) {
            // Index may be missing if the column pre-existed; ignore that case.
            try {
                Schema::table('routers', function (Blueprint $table) {
                    $table->dropIndex(['grafana_identity']);
                });
            } catch (Throwable) {
            }
            Schema::table('routers', function (Blueprint $table) {
                $table->dropColumn('grafana_identity');
            });
        }
    }
};
