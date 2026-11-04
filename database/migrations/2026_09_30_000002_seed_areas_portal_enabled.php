<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STEP 1 portal defaults:
 * - skynet-randuagung2 (own, static)  -> portal ON
 * - skynet randuagung  (titipan)      -> portal OFF
 * Ownership is distinguished by AREA only (no owner_isp/auth_type yet).
 */
return new class extends Migration
{
    private const ON = ['SKYNET-RANDUAGUNG2'];

    private const OFF = ['SKYNET-RANDUAGUNG'];

    public function up(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasColumn('areas', 'portal_enabled')) {
            return;
        }

        foreach (self::ON as $name) {
            DB::table('areas')->where('name', $name)->update(['portal_enabled' => true]);
        }

        foreach (self::OFF as $name) {
            DB::table('areas')->where('name', $name)->update(['portal_enabled' => false]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('areas') || ! Schema::hasColumn('areas', 'portal_enabled')) {
            return;
        }

        foreach (array_merge(self::ON, self::OFF) as $name) {
            DB::table('areas')->where('name', $name)->update(['portal_enabled' => false]);
        }
    }
};
