<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('technician_progress_photos')) {
            return;
        }

        Schema::create('technician_progress_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->nullable()->constrained('technician_progress_reports')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('technician_daily_tasks')->nullOnDelete();
            $table->date('work_date')->nullable()->index();
            $table->string('group_jid', 255)->nullable()->index();
            $table->string('sender_jid', 255)->nullable()->index();
            $table->string('sender_name', 120)->nullable();
            $table->string('message_id', 128)->nullable()->unique();
            $table->string('storage_disk', 40)->default('local');
            $table->string('path');
            $table->string('original_name', 180)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->string('kind', 60)->nullable()->index();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['work_date', 'group_jid', 'created_at'], 'technician_photos_day_group_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_progress_photos');
    }
};
