<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnosis learn & measure (roadmap v2 section 2.2): what the stored data says really fixed an incident
 * (RX jump, serial swap, unisolate, co-recovery, power only), so the diagnosis can be scored beyond the few
 * technician labels. Ids only, evidence <= 1 KB. Additive and guarded; down() drops only this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outage_outcome_labels')) {
            return;
        }

        Schema::create('outage_outcome_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 12); // ticket | signal
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->string('onu_ref', 40)->nullable();
            $table->string('outcome', 32); // cause code
            $table->string('family', 32);
            $table->string('signature', 16); // rx_jump | serial_swap | unisolate | co_recovery | power_only
            $table->boolean('independent')->default(true); // false = circular (power_only): shown, never scored
            $table->json('evidence')->nullable();
            $table->unsignedBigInteger('diagnosis_id')->nullable(); // the prediction it scores
            $table->dateTime('window_from')->nullable();
            $table->dateTime('window_to')->nullable();
            $table->dateTime('labelled_at');
            $table->boolean('final')->default(false); // true once the +26 h pass has run
            $table->unique(['subject_type', 'subject_id', 'signature'], 'outage_outcome_labels_subject_sig_unique');
            $table->index(['outcome', 'labelled_at'], 'outage_outcome_labels_outcome_idx');
            $table->index('customer_id', 'outage_outcome_labels_customer_idx');
            $table->index(['final', 'labelled_at'], 'outage_outcome_labels_final_idx'); // the Monday calibration and the card
            $table->index('diagnosis_id', 'outage_outcome_labels_diagnosis_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_outcome_labels');
    }
};
