<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('billing_wa_logs') && ! Schema::hasColumn('billing_wa_logs', 'provider_message_id')) {
            Schema::table('billing_wa_logs', function (Blueprint $table) {
                $table->string('provider_message_id', 191)->nullable()->index()->after('sent_at');
            });
        }
        if (Schema::hasTable('wa_campaign_recipients') && ! Schema::hasColumn('wa_campaign_recipients', 'provider_message_id')) {
            Schema::table('wa_campaign_recipients', function (Blueprint $table) {
                $table->string('provider_message_id', 191)->nullable()->index()->after('sent_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('billing_wa_logs') && Schema::hasColumn('billing_wa_logs', 'provider_message_id')) {
            Schema::table('billing_wa_logs', fn (Blueprint $table) => $table->dropColumn('provider_message_id'));
        }
        if (Schema::hasTable('wa_campaign_recipients') && Schema::hasColumn('wa_campaign_recipients', 'provider_message_id')) {
            Schema::table('wa_campaign_recipients', fn (Blueprint $table) => $table->dropColumn('provider_message_id'));
        }
    }
};
