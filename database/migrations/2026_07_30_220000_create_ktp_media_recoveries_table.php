<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ktp_media_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('psb_draft_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_migration_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->index();
            $table->string('idempotency_key', 64)->unique();
            $table->string('source_type', 40);
            $table->text('source_locator');
            $table->string('target_path')->nullable();
            $table->unsignedBigInteger('source_size')->nullable();
            $table->string('source_mime', 64)->nullable();
            $table->string('source_checksum', 64)->nullable()->index();
            $table->unsignedTinyInteger('confidence')->default(0);
            $table->json('evidence')->nullable();
            $table->text('before_snapshot')->nullable();
            $table->string('requested_by', 120);
            $table->text('reason');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ktp_media_recoveries');
    }
};
