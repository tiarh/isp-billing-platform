<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis accuracy (2026-10-26): a bounded JSON snapshot of the correlator context, stored only for `ticket_open`
 * and `manual_check` diagnoses, so a new rules version can be back-tested on past tickets before it is deployed.
 * Additive and guarded; the column is NULL for every older row. down() drops only this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outage_diagnoses') || Schema::hasColumn('outage_diagnoses', 'context')) {
            return;
        }

        Schema::table('outage_diagnoses', function (Blueprint $table): void {
            $table->json('context')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outage_diagnoses') || ! Schema::hasColumn('outage_diagnoses', 'context')) {
            return;
        }

        Schema::table('outage_diagnoses', function (Blueprint $table): void {
            $table->dropColumn('context');
        });
    }
};
