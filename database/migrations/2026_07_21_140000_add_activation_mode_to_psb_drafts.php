<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('psb_drafts')) {
            return;
        }
        Schema::table('psb_drafts', function (Blueprint $table) {
            if (! Schema::hasColumn('psb_drafts', 'activation_mode')) {
                $table->string('activation_mode', 32)->nullable()->after('status')->index();
                // full_olt | pppoe_only | pppoe_vlan
            }
            if (! Schema::hasColumn('psb_drafts', 'site_profile_key')) {
                $table->string('site_profile_key', 64)->nullable()->after('activation_mode');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('psb_drafts')) {
            return;
        }
        Schema::table('psb_drafts', function (Blueprint $table) {
            if (Schema::hasColumn('psb_drafts', 'site_profile_key')) {
                $table->dropColumn('site_profile_key');
            }
            if (Schema::hasColumn('psb_drafts', 'activation_mode')) {
                $table->dropColumn('activation_mode');
            }
        });
    }
};
