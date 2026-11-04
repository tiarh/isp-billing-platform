<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: why a Dismantle ticket was filed (stable code + optional note).
 * Nullable, no default backfill here — existing rows are classified by
 * `php artisan tickets:backfill-dismantle-reason` (dry-run by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('support_tickets')) {
            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            if (! Schema::hasColumn('support_tickets', 'dismantle_reason')) {
                $table->string('dismantle_reason', 32)->nullable();
            }
            if (! Schema::hasColumn('support_tickets', 'dismantle_reason_note')) {
                $table->text('dismantle_reason_note')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Intentionally additive: keep recorded reasons.
    }
};
