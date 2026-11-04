<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 1.3 PR-4a: a termination the lifecycle guard refused to run now is
 * parked here ("terminate tertunda") until the guard passes or an admin
 * cancels. open_key = customer_id while pending and NULL otherwise, so MySQL
 * UNIQUE allows exactly one open row per customer. Nothing writes this table
 * in PR-4a except tests.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_pending_terminations')) {
            return;
        }

        Schema::create('customer_pending_terminations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers');
            $table->unsignedBigInteger('open_key')->nullable()->unique();
            // warehouse_receive|warehouse_quick_record|recap_receive|recap_reused|takeover
            $table->string('source', 40);
            $table->unsignedBigInteger('recovery_case_id')->nullable()->index();
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->unsignedBigInteger('takeover_customer_id')->nullable();
            // pending|terminated|cancelled|closed
            $table->string('status', 20)->default('pending');
            $table->boolean('hold_billing')->default(true);
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->dateTime('requested_at');
            $table->string('first_verdict', 10)->nullable();
            $table->string('last_verdict', 10)->nullable();
            $table->json('first_reasons')->nullable();
            $table->json('last_reasons')->nullable();
            $table->dateTime('last_checked_at')->nullable();
            $table->unsignedInteger('check_count')->default(0);
            $table->dateTime('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            // guard_passed|admin_cancelled|already_terminated|customer_archived|superseded
            $table->string('resolution', 40)->nullable();
            $table->text('resolution_note')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at'], 'cpt_status_requested_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_pending_terminations');
    }
};
