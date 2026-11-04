<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roadmap 2.4 PR-1: one row per ONU, overwritten in place by the (future) outage detector.
 * Rebuildable cache, so down() simply drops it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('outage_onu_tracker')) {
            return;
        }

        Schema::create('outage_onu_tracker', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('olt_id');
            $table->string('onu_ref', 64);
            $table->unsignedBigInteger('customer_id')->nullable();
            // online|los|dying_gasp|offline|unknown
            $table->string('phase', 16)->default('unknown');
            $table->dateTime('phase_since_at')->nullable();
            $table->dateTime('last_state_collected_at')->nullable();
            // last 6 distinct raw samples [{at,dbm}]
            $table->json('rx_samples')->nullable();
            // one median per WIB day [{date,dbm,n}] for the "3 consecutive days" weak RX rule
            $table->json('rx_daily')->nullable();
            // last known "ONU distance" from the collector (fiber-cut segment estimate)
            $table->unsignedInteger('last_onu_distance_m')->nullable();
            $table->dateTime('pppoe_down_since')->nullable();
            $table->timestamps();

            $table->unique(['olt_id', 'onu_ref'], 'outage_onu_tracker_olt_onu_unique');
            $table->index('customer_id', 'outage_onu_tracker_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outage_onu_tracker');
    }
};
