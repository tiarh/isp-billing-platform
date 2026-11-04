<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wa_chat_signals')) {
            return;
        }

        Schema::create('wa_chat_signals', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 40)->index();
            $table->string('domain', 40)->index();
            $table->string('intent', 60)->index();
            $table->string('severity', 30)->default('normal')->index();
            $table->string('command', 80)->nullable()->index();
            $table->string('group_jid', 255)->index();
            $table->string('group_label', 120)->nullable();
            $table->string('sender_jid', 255)->nullable()->index();
            $table->string('sender_name', 120)->nullable();
            $table->string('message_id', 160)->unique();
            $table->text('summary')->nullable();
            $table->text('redacted_text')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->timestamps();
            $table->index(['channel', 'created_at'], 'wa_chat_signals_channel_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_chat_signals');
    }
};
