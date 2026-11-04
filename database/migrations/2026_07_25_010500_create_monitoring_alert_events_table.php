<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_alert_events')) {
            return;
        }

        Schema::create('monitoring_alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 80)->index();
            $table->string('entity_type', 80)->index();
            $table->string('entity_id', 120)->index();
            $table->string('entity_name')->nullable();
            $table->string('issue', 80)->index();
            $table->string('severity', 40)->default('warning')->index();
            $table->string('status', 40)->default('active')->index();
            $table->string('dedupe_key')->unique();
            $table->json('payload')->nullable();
            $table->timestamp('first_seen_at')->nullable()->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_sent_at')->nullable()->index();
            $table->timestamp('recovered_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_alert_events');
    }
};
