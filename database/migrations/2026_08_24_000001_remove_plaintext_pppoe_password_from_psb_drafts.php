<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('psb_drafts') && Schema::hasColumn('psb_drafts', 'pppoe_password')) {
            Schema::table('psb_drafts', function (Blueprint $table): void {
                $table->dropColumn('pppoe_password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('psb_drafts') && ! Schema::hasColumn('psb_drafts', 'pppoe_password')) {
            Schema::table('psb_drafts', function (Blueprint $table): void {
                // Intentionally re-added empty on rollback; the backup contains
                // the pre-migration schema if restoration is explicitly needed.
                $table->string('pppoe_password', 64)->nullable();
            });
        }
    }
};
