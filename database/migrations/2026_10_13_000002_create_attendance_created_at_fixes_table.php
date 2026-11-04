<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backup/audit table for `attendance:fix-wib-created-at`. Creates the (empty)
 * table only; the data move itself is NOT a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_created_at_fixes')) {
            return;
        }

        Schema::create('attendance_created_at_fixes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id')->unique();
            $table->string('bucket', 8);
            // DATETIME (not TIMESTAMP): values are stored verbatim, UTC wall clock.
            $table->dateTime('old_created_at');
            $table->dateTime('new_created_at');
            $table->dateTime('applied_at');
            $table->dateTime('restored_at')->nullable();
        });
    }

    public function down(): void
    {
        // Never drop recorded backups: only drop when nothing was ever recorded.
        if (Schema::hasTable('attendance_created_at_fixes')
            && DB::table('attendance_created_at_fixes')->count() === 0) {
            Schema::drop('attendance_created_at_fixes');
        }
    }
};
