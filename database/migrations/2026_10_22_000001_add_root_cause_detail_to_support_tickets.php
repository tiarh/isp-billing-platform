<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Root cause detail of an incident ticket (plan ticket-root-cause-required):
 * - root_cause_note: short text for "Lainnya" (optional for other causes);
 * - root_cause_suggested: the diagnosis suggestion that was shown at the moment of close;
 * - root_cause_source: who filled the cause (technician | admin | api | system).
 * Additive, nullable, guarded, no backfill. Columns are written out one by one so static analysis sees them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        if (! Schema::hasColumn('support_tickets', 'root_cause_note')) {
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->string('root_cause_note', 255)->nullable();
            });
        }
        if (! Schema::hasColumn('support_tickets', 'root_cause_suggested')) {
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->string('root_cause_suggested', 32)->nullable();
            });
        }
        if (! Schema::hasColumn('support_tickets', 'root_cause_source')) {
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->string('root_cause_source', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        foreach (['root_cause_note', 'root_cause_suggested', 'root_cause_source'] as $column) {
            if (Schema::hasColumn('support_tickets', $column)) {
                Schema::table('support_tickets', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
