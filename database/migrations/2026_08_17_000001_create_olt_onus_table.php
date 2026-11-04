<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Production already has this table (created outside migrations). This
        // migration is idempotent: it only creates the table for fresh/test
        // databases so the schema is consistent and tests stop building ad-hoc
        // copies that drop columns like phase_state.
        if (! Schema::hasTable('olt_onus')) {
            Schema::create('olt_onus', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('olt_id')->nullable()->index();
                $table->string('onu_ref', 64)->nullable();
                $table->string('snmp_index', 64)->nullable();
                $table->string('pon_port', 32)->nullable();
                $table->string('serial_number', 64)->nullable()->index();
                $table->string('onu_name', 128)->nullable();
                $table->string('state', 32)->nullable();
                $table->string('phase_state', 32)->nullable();
                $table->decimal('rx_power_dbm', 8, 2)->nullable();
                $table->integer('distance_m')->nullable();
                $table->string('rx_source', 32)->nullable();
                $table->string('serial_source', 32)->nullable();
                $table->string('name_source', 32)->nullable();
                $table->timestamp('state_collected_at')->nullable();
                $table->timestamp('rx_collected_at')->nullable();
                $table->timestamp('inventory_collected_at')->nullable();
                $table->timestamps();

                $table->index(['olt_id', 'serial_number']);
                $table->index(['olt_id', 'onu_ref']);
            });
        }

        // Backfill phase_state for environments that have the table without it.
        if (Schema::hasTable('olt_onus') && ! Schema::hasColumn('olt_onus', 'phase_state')) {
            Schema::table('olt_onus', function (Blueprint $table) {
                $table->string('phase_state', 32)->nullable()->after('state');
            });
        }
    }

    public function down(): void
    {
        // Never drop in production. Only fresh test databases created this
        // table via this migration; leave pre-existing tables untouched.
        if (! Schema::hasTable('olt_onus')) {
            return;
        }
    }
};
