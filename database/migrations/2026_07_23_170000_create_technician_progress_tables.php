<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('technician_members')) {
            Schema::create('technician_members', function (Blueprint $table) {
                $table->id();
                $table->string('name', 80)->unique();
                $table->string('role', 40)->nullable()->index();
                $table->boolean('is_active')->default(true)->index();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('technician_teams')) {
            Schema::create('technician_teams', function (Blueprint $table) {
                $table->id();
                $table->date('work_date')->index();
                $table->unsignedSmallInteger('team_no')->nullable();
                $table->string('label', 80)->nullable();
                $table->string('site', 120)->nullable();
                $table->string('status', 40)->default('draft')->index();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique(['work_date', 'team_no'], 'technician_teams_work_date_team_unique');
            });
        }

        if (! Schema::hasTable('technician_team_members')) {
            Schema::create('technician_team_members', function (Blueprint $table) {
                $table->id();
                $table->foreignId('team_id')->constrained('technician_teams')->cascadeOnDelete();
                $table->foreignId('member_id')->nullable()->constrained('technician_members')->nullOnDelete();
                $table->string('name', 80);
                $table->timestamps();
                $table->unique(['team_id', 'name'], 'technician_team_members_team_name_unique');
            });
        }

        if (! Schema::hasTable('technician_daily_schedules')) {
            Schema::create('technician_daily_schedules', function (Blueprint $table) {
                $table->id();
                $table->date('schedule_date')->unique();
                $table->string('status', 40)->default('draft')->index();
                $table->timestamp('previewed_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('sent_message_id', 128)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('technician_daily_tasks')) {
            Schema::create('technician_daily_tasks', function (Blueprint $table) {
                $table->id();
                $table->foreignId('schedule_id')->nullable()->constrained('technician_daily_schedules')->nullOnDelete();
                $table->date('work_date')->index();
                $table->unsignedSmallInteger('team_no')->nullable()->index();
                $table->string('team_label', 120)->nullable();
                $table->json('technicians')->nullable();
                $table->string('site', 160)->nullable()->index();
                $table->string('task_type', 40)->default('manual')->index();
                $table->text('task');
                $table->string('progress', 160)->nullable();
                $table->string('status', 40)->default('draft')->index();
                $table->string('priority', 40)->default('normal')->index();
                $table->string('source', 40)->default('manual_wa')->index();
                $table->string('source_ref', 128)->nullable()->index();
                $table->text('raw_text')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['work_date', 'team_no', 'status'], 'technician_tasks_day_team_status_idx');
            });
        }

        if (! Schema::hasTable('technician_progress_reports')) {
            Schema::create('technician_progress_reports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('task_id')->nullable()->constrained('technician_daily_tasks')->nullOnDelete();
                $table->date('work_date')->index();
                $table->unsignedSmallInteger('team_no')->nullable()->index();
                $table->string('team_label', 120)->nullable();
                $table->string('group_jid', 255)->nullable()->index();
                $table->string('sender_jid', 255)->nullable()->index();
                $table->string('sender_name', 120)->nullable();
                $table->string('message_id', 128)->nullable()->unique();
                $table->json('technicians')->nullable();
                $table->json('progress_items')->nullable();
                $table->json('sites')->nullable();
                $table->json('actions')->nullable();
                $table->json('photo_status')->nullable();
                $table->string('status', 60)->nullable()->index();
                $table->json('constraints')->nullable();
                $table->json('materials')->nullable();
                $table->text('note')->nullable();
                $table->text('raw_text')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['work_date', 'team_no', 'created_at'], 'technician_reports_day_team_created_idx');
            });
        }

        if (! Schema::hasTable('technician_wa_command_logs')) {
            Schema::create('technician_wa_command_logs', function (Blueprint $table) {
                $table->id();
                $table->date('work_date')->nullable()->index();
                $table->string('group_jid', 255)->nullable()->index();
                $table->string('sender_jid', 255)->nullable()->index();
                $table->string('sender_name', 120)->nullable();
                $table->string('message_id', 128)->nullable()->unique();
                $table->string('command', 80)->nullable()->index();
                $table->string('action', 80)->nullable()->index();
                $table->boolean('is_dry_run')->default(true)->index();
                $table->boolean('handled')->default(false)->index();
                $table->text('response_preview')->nullable();
                $table->json('payload')->nullable();
                $table->text('raw_text')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('technician_wa_command_logs');
        Schema::dropIfExists('technician_progress_reports');
        Schema::dropIfExists('technician_daily_tasks');
        Schema::dropIfExists('technician_daily_schedules');
        Schema::dropIfExists('technician_team_members');
        Schema::dropIfExists('technician_teams');
        Schema::dropIfExists('technician_members');
    }
};
