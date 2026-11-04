<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add new columns to support_tickets
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('customer_name')->nullable()->after('customer_id');
            $table->string('customer_phone')->nullable()->after('customer_name');
            $table->string('customer_coords')->nullable()->after('customer_phone');
            $table->string('report_id')->nullable()->after('code');
            $table->text('gangguan')->nullable()->after('description');
            $table->text('keterangan')->nullable()->after('gangguan');
            $table->text('kendala')->nullable()->after('keterangan');
            $table->text('action_taken')->nullable()->after('kendala');
            $table->json('photos')->nullable()->after('action_taken');
            $table->timestamp('assigned_at')->nullable()->after('due_at');
            $table->unsignedBigInteger('created_by')->nullable()->after('assigned_to');

            // Update status enum to include 'pending'
            // MySQL doesn't support ALTER ENUM directly, so we modify the column
            $table->string('status', 30)->default('open')->change();
        });

        // Add 'teknisi' role to allowed roles (just update existing users if needed)
        // The role column is already a string, so 'teknisi' just works
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'customer_name', 'customer_phone', 'customer_coords',
                'report_id', 'gangguan', 'keterangan', 'kendala',
                'action_taken', 'photos', 'assigned_at', 'created_by',
            ]);
        });
    }
};
