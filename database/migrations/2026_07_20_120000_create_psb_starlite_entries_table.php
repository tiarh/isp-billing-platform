<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historical STARLITE / Excel Daily Tracker rows (before bot WA era).
 * Read-only for dashboard — does not drive MT/OLT activation.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('psb_starlite_entries')) {
            return;
        }

        Schema::create('psb_starlite_entries', function (Blueprint $table) {
            $table->id();
            $table->date('installed_on')->nullable()->index();
            $table->string('rw', 20)->nullable()->index();
            $table->string('full_name', 160)->nullable();
            $table->string('pppoe_user', 160)->nullable()->index();
            $table->string('sn', 64)->nullable()->index();
            $table->string('service_type', 40)->nullable(); // PSB / Migrasi
            $table->string('install_status', 40)->nullable()->index(); // Success / Pending
            $table->string('tikor', 80)->nullable();
            $table->string('odp', 80)->nullable();
            $table->string('kendala', 255)->nullable();
            $table->string('pic', 80)->nullable();
            $table->string('source', 40)->default('excel'); // excel | sheet | manual
            $table->string('source_key', 190)->nullable()->unique(); // dedupe
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psb_starlite_entries');
    }
};
