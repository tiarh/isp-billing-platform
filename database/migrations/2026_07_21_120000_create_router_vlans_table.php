<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VLAN service per MikroTik (BNG) — fondasi PSB HTB / pppoe_vlan.
 * Beda dari olt_vlan_mappings (per PON port di OLT).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('router_vlans')) {
            return;
        }

        Schema::create('router_vlans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->unsignedSmallInteger('vlan'); // 1–4094
            $table->string('label', 120)->nullable();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->string('mikrotik_profile', 64)->nullable(); // PPP profile hint
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();

            $table->unique(['router_id', 'vlan']);
            $table->index(['router_id', 'is_active']);
            $table->index(['area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_vlans');
    }
};
