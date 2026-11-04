<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first rollup runs stored the raw exporter label (`<pppoe-NAME>`) as
 * pppoe_user, so no row linked to a customer. The rollup now normalizes the
 * label; drop the old bracketed rows so they are rebuilt (no duplicates) by the
 * next run / `--backfill-from --replace`. Derived data only (re-computable from
 * Prometheus); no customer or billing data is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pppoe_usage_daily')) {
            return;
        }

        DB::table('pppoe_usage_daily')->where('pppoe_user', 'like', '<%')->delete();
    }

    public function down(): void
    {
        // Irreversible: derived rollup rows are rebuilt from Prometheus.
    }
};
