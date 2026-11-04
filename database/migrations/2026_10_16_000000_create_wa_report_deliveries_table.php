<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skynet Report delivery ledger: one row per (report, WIB day, group). The unique key plus a
 * conditional UPDATE guarantees "at most once per day per group" even when the cache or Redis
 * is flushed. Reusable by later report types (report_key). No message text is stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_report_deliveries')) {
            return;
        }

        Schema::create('wa_report_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('report_key', 64);
            $table->date('period_date');
            $table->string('group_jid', 80);
            $table->string('channel', 16)->default('ops');
            // pending|sending|sent|failed|unknown|skipped
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('message_hash', 40)->nullable();
            $table->string('provider_message_id', 120)->nullable();
            $table->string('error', 160)->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['report_key', 'period_date', 'group_jid'], 'wa_report_deliveries_unique');
            $table->index('status', 'wa_report_deliveries_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_report_deliveries');
    }
};
