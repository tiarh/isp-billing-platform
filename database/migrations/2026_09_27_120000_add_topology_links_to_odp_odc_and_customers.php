<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FTTH topology links:
 * - odp_odc.parent_id : an ODP points to its parent ODC (nullable; ODC/POP have none)
 * - odp_odc.code      : stable human code for matching (e.g. ODP-RANDUAGUNG-018)
 * - customers.odp_odc_id : direct link from a customer to their ODP/ODC asset
 *
 * Additive + nullable so existing rows keep working; the backfill command fills
 * them progressively (ODP/ODC data is still incomplete).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('odp_odc')) {
            Schema::table('odp_odc', function (Blueprint $table): void {
                if (! Schema::hasColumn('odp_odc', 'parent_id')) {
                    $table->unsignedBigInteger('parent_id')->nullable()->after('area_id')->index();
                }
                if (! Schema::hasColumn('odp_odc', 'code')) {
                    $table->string('code', 64)->nullable()->after('name')->index();
                }
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'odp_odc_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->unsignedBigInteger('odp_odc_id')->nullable()->after('odp_name')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'odp_odc_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropColumn('odp_odc_id');
            });
        }

        if (Schema::hasTable('odp_odc')) {
            Schema::table('odp_odc', function (Blueprint $table): void {
                foreach (['parent_id', 'code'] as $column) {
                    if (Schema::hasColumn('odp_odc', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
