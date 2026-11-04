<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isolation portal (STEP 1):
 * - areas.portal_enabled : which areas may show the isolation portal
 * - customers.static_ip / last_learned_ip : IP lookup order for STATIC users
 *   (pppoe_remote_address stays as the last fallback)
 *
 * No owner_isp/auth_type yet: ownership is distinguished by area only.
 * No MikroTik NAT/whitelist here; CIDR rules are applied manually on the router.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('areas') && ! Schema::hasColumn('areas', 'portal_enabled')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->boolean('portal_enabled')->default(false)->after('name');
            });
        }

        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                if (! Schema::hasColumn('customers', 'static_ip')) {
                    $table->string('static_ip', 45)->nullable()->after('pppoe_remote_address')->index();
                }
                if (! Schema::hasColumn('customers', 'last_learned_ip')) {
                    $table->string('last_learned_ip', 45)->nullable()->after('static_ip')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table): void {
                foreach (['static_ip', 'last_learned_ip'] as $column) {
                    if (Schema::hasColumn('customers', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('areas') && Schema::hasColumn('areas', 'portal_enabled')) {
            Schema::table('areas', function (Blueprint $table): void {
                $table->dropColumn('portal_enabled');
            });
        }
    }
};
