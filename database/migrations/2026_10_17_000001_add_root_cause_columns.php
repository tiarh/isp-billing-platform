<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outage RCA-1: "Penyebab sebenarnya" label.
 * - support_tickets.root_cause: cause chosen when an incident ticket is closed (App\Enums\OutageRootCause).
 * - outage_signals.cause_code: optional cause attached to a NOC label of a shadow episode.
 * Additive and guarded (hasTable / hasColumn), nullable, no backfill: existing tickets stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_tickets') && ! Schema::hasColumn('support_tickets', 'root_cause')) {
            Schema::table('support_tickets', function (Blueprint $table): void {
                $table->string('root_cause', 32)->nullable();
            });
        }

        if (Schema::hasTable('outage_signals') && ! Schema::hasColumn('outage_signals', 'cause_code')) {
            Schema::table('outage_signals', function (Blueprint $table): void {
                $table->string('cause_code', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally additive: keep recorded causes.
    }
};
