<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'sender_jid')) {
                $table->string('sender_jid', 120)->nullable()->after('sender_number')->index();
            }
            if (! Schema::hasColumn('attendances', 'technician_id')) {
                $table->foreignId('technician_id')->nullable()->after('sender_name')->constrained('technician_members')->nullOnDelete();
            }
            if (! Schema::hasColumn('attendances', 'evidence_message_id')) {
                $table->string('evidence_message_id', 128)->nullable()->after('message_id')->index();
            }
            if (! Schema::hasColumn('attendances', 'review_status')) {
                $table->string('review_status', 32)->default('accepted')->after('evidence_message_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'technician_id')) {
                $table->dropForeign(['technician_id']);
            }

            foreach (['review_status', 'evidence_message_id', 'technician_id', 'sender_jid'] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
