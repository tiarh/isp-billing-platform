<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-08 (Gadang online status): customers.is_online had no
 * timestamp that is stamped for BOTH outcomes of an authoritative PPPoE
 * session sync. mikrotik_sync_checked_at is only touched for customers that
 * were seen online (and by unrelated provisioning paths), so the read model
 * could not tell a fresh "offline" from a frozen "online".
 *
 * pppoe_online_checked_at = when is_online was last confirmed against the
 * router's /ppp/active list. Nullable; null means "never confirmed" (stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customers') || Schema::hasColumn('customers', 'pppoe_online_checked_at')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('pppoe_online_checked_at')->nullable()->after('is_online');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customers') || ! Schema::hasColumn('customers', 'pppoe_online_checked_at')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('pppoe_online_checked_at');
        });
    }
};
