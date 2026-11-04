<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saleskit: calon pelanggan (prospek) sebelum dipasang. Terpisah dari PSB WA
 * (yang menangani eksekusi pemasangan) — ini murni pendataan prospek per
 * daerah + RT/RW untuk admin/teknisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_leads')) {
            return;
        }

        Schema::create('sales_leads', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->nullable()->unique();
            $table->string('name', 150);
            $table->string('phone', 32)->nullable();
            $table->text('address')->nullable();

            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('daerah', 64)->index();
            $table->string('rt', 8)->nullable();
            $table->string('rw', 8)->nullable();
            $table->string('odp_name', 128)->nullable();

            $table->string('source', 32)->default('manual');
            $table->string('status', 16)->default('calon')->index();
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_note', 255)->nullable();
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('converted_customer_id')->nullable()->index();
            $table->timestamp('installed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            $table->index(['daerah', 'rw', 'rt']);
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_leads');
    }
};
