<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            if (! Schema::hasColumn('routers', 'vpn_enabled')) {
                $table->boolean('vpn_enabled')->default(false)->after('last_sync_stats');
            }

            if (! Schema::hasColumn('routers', 'vpn_interface')) {
                $table->string('vpn_interface')->default('wg-ebilling')->after('vpn_enabled');
            }

            if (! Schema::hasColumn('routers', 'vpn_address')) {
                $table->string('vpn_address')->nullable()->after('vpn_interface');
            }

            if (! Schema::hasColumn('routers', 'vpn_server_address')) {
                $table->string('vpn_server_address')->default('10.99.0.1')->after('vpn_address');
            }

            if (! Schema::hasColumn('routers', 'vpn_server_public_key')) {
                $table->text('vpn_server_public_key')->nullable()->after('vpn_server_address');
            }

            if (! Schema::hasColumn('routers', 'vpn_server_endpoint')) {
                $table->string('vpn_server_endpoint')->nullable()->after('vpn_server_public_key');
            }

            if (! Schema::hasColumn('routers', 'vpn_server_port')) {
                $table->unsignedInteger('vpn_server_port')->default(443)->after('vpn_server_endpoint');
            }

            if (! Schema::hasColumn('routers', 'vpn_allowed_ips')) {
                $table->string('vpn_allowed_ips')->default('10.99.0.0/24')->after('vpn_server_port');
            }

            if (! Schema::hasColumn('routers', 'vpn_client_private_key')) {
                $table->text('vpn_client_private_key')->nullable()->after('vpn_allowed_ips');
            }

            if (! Schema::hasColumn('routers', 'vpn_client_public_key')) {
                $table->text('vpn_client_public_key')->nullable()->after('vpn_client_private_key');
            }

            if (! Schema::hasColumn('routers', 'vpn_preshared_key')) {
                $table->text('vpn_preshared_key')->nullable()->after('vpn_client_public_key');
            }

            if (! Schema::hasColumn('routers', 'radius_enabled')) {
                $table->boolean('radius_enabled')->default(false)->after('vpn_preshared_key');
            }

            if (! Schema::hasColumn('routers', 'radius_secret')) {
                $table->text('radius_secret')->nullable()->after('radius_enabled');
            }

            if (! Schema::hasColumn('routers', 'radius_auth_port')) {
                $table->unsignedInteger('radius_auth_port')->default(1812)->after('radius_secret');
            }

            if (! Schema::hasColumn('routers', 'radius_acct_port')) {
                $table->unsignedInteger('radius_acct_port')->default(1813)->after('radius_auth_port');
            }

            if (! Schema::hasColumn('routers', 'last_radius_synced_at')) {
                $table->timestamp('last_radius_synced_at')->nullable()->after('radius_acct_port');
            }
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            foreach ([
                'last_radius_synced_at',
                'radius_acct_port',
                'radius_auth_port',
                'radius_secret',
                'radius_enabled',
                'vpn_preshared_key',
                'vpn_client_public_key',
                'vpn_client_private_key',
                'vpn_allowed_ips',
                'vpn_server_port',
                'vpn_server_endpoint',
                'vpn_server_public_key',
                'vpn_server_address',
                'vpn_address',
                'vpn_interface',
                'vpn_enabled',
            ] as $column) {
                if (Schema::hasColumn('routers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
