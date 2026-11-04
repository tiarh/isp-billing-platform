<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add telegram_chat_id column to users table.
     *
     * Used by TelegramNotifyService to send individual notifications
     * to users via Telegram Bot. Users get their chat_id by starting
     * the bot (sending /start to @YourBotName).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 100)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('telegram_chat_id');
        });
    }
};
