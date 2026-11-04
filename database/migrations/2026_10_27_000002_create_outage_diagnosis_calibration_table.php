<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis learn & measure (roadmap v2 section 2.3): per class and week, how often the prediction was right against
 * tickets and independent outcome labels, and the resulting state (pasti | kemungkinan | belum_tahu). Filled by the
 * Monday precision report. Additive and guarded; down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outage_diagnosis_calibration')) {
            return;
        }

        Schema::create('outage_diagnosis_calibration', function (Blueprint $table): void {
            $table->id();
            $table->date('week_start');
            $table->string('cause_code', 32);
            $table->string('family', 32);
            $table->unsignedInteger('n_ticket')->default(0);
            $table->unsignedInteger('n_independent')->default(0);
            $table->unsignedInteger('n_circular')->default(0);
            $table->unsignedInteger('correct')->default(0);
            $table->decimal('precision_rate', 5, 4)->nullable();
            $table->decimal('wilson_low', 5, 4)->nullable();
            $table->string('state', 16); // pasti | kemungkinan | belum_tahu
            $table->timestamps();
            $table->unique(['week_start', 'cause_code'], 'outage_diagnosis_calibration_week_cause_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_diagnosis_calibration');
    }
};
