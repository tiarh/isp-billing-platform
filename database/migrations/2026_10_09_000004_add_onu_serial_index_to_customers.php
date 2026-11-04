<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-10-09: index customers.onu_serial (exact-match SN lookups by the
 * pickup reuse closer, PSB and reused-ONU takeover). Additive and online on
 * MySQL 8 InnoDB (ALGORITHM=INPLACE, LOCK=NONE; ~4.6k rows in production).
 */
return new class extends Migration
{
    private const INDEX = 'customers_onu_serial_index';

    public function up(): void
    {
        if (! Schema::hasTable('customers')
            || ! Schema::hasColumn('customers', 'onu_serial')
            || Schema::hasIndex('customers', self::INDEX)) {
            return;
        }

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE customers ADD INDEX '.self::INDEX.' (onu_serial), ALGORITHM=INPLACE, LOCK=NONE');

            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->index('onu_serial', self::INDEX);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('customers') && Schema::hasIndex('customers', self::INDEX)) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }
    }
};
