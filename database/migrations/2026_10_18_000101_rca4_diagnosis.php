<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RCA-4: stored diagnoses (one row per classification: ticket_open / auto_episode / auto_refresh / manual_check),
 * the diagnosis columns on outage_signals and the weekly precision report. All additive and guarded; down() keeps data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('outage_diagnoses')) {
            Schema::create('outage_diagnoses', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('ticket_id')->nullable();
                $table->unsignedBigInteger('signal_id')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->string('trigger', 16); // ticket_open | auto_episode | auto_refresh | manual_check
                $table->string('state', 8)->default('done'); // running | done | failed
                $table->string('cause_code', 32)->nullable();
                $table->string('confidence', 8)->nullable(); // tinggi | sedang | rendah
                $table->string('layer', 12)->nullable();
                $table->json('secondary')->nullable();
                $table->json('evidence')->nullable();
                $table->json('probes')->nullable();
                $table->string('rule_id', 48)->nullable();
                $table->string('rules_version', 16)->nullable();
                $table->string('suggested_action', 24)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->timestamps();

                $table->index('customer_id', 'outage_diagnoses_customer_idx');
                $table->index('ticket_id', 'outage_diagnoses_ticket_idx');
                $table->index(['cause_code', 'created_at'], 'outage_diagnoses_cause_created_idx');
                $table->index(['customer_id', 'created_at'], 'outage_diagnoses_customer_created_idx');
            });
        }

        if (Schema::hasTable('outage_signals')) {
            Schema::table('outage_signals', function (Blueprint $table): void {
                if (! Schema::hasColumn('outage_signals', 'diagnosed_cause')) {
                    $table->string('diagnosed_cause', 32)->nullable();
                }
                if (! Schema::hasColumn('outage_signals', 'diagnosis_id')) {
                    $table->unsignedBigInteger('diagnosis_id')->nullable();
                }
            });
        }

        if (! Schema::hasTable('outage_diagnosis_reports')) {
            Schema::create('outage_diagnosis_reports', function (Blueprint $table): void {
                $table->id();
                $table->date('week_start')->unique();
                $table->json('payload');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Intentionally additive: diagnoses feed the precision history, so nothing is dropped.
    }
};
