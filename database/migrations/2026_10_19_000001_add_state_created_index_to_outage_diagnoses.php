<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index for the cleanup of abandoned "Cek sekarang" checks (state = running, created_at older than 10 minutes).
 * Additive and guarded; down() drops only this index.
 */
return new class extends Migration
{
    private const INDEX = 'outage_diagnoses_state_created_idx';

    public function up(): void
    {
        if (! Schema::hasTable('outage_diagnoses')
            || ! Schema::hasColumns('outage_diagnoses', ['state', 'created_at'])
            || Schema::hasIndex('outage_diagnoses', self::INDEX)) {
            return;
        }

        Schema::table('outage_diagnoses', function (Blueprint $table): void {
            $table->index(['state', 'created_at'], self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('outage_diagnoses') || ! Schema::hasIndex('outage_diagnoses', self::INDEX)) {
            return;
        }

        Schema::table('outage_diagnoses', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
        });
    }
};
