<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                $table->string('email')->nullable();
                $table->string('phone');
                $table->text('address');
                $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
                $table->foreignId('package_id')->constrained('packages')->restrictOnDelete();
                $table->string('pppoe_user')->unique();
                $table->text('pppoe_password')->nullable(); // encrypted
                $table->enum('status', ['active', 'suspended', 'inactive', 'isolated', 'terminated', 'pending_installation'])->default('active');
                $table->string('nik')->nullable();
                $table->string('ktp_photo_url')->nullable();
                $table->string('ktp_external_url')->nullable();
                $table->decimal('geo_lat', 10, 8)->nullable();
                $table->decimal('geo_long', 11, 8)->nullable();
                $table->date('join_date')->nullable();
                $table->boolean('is_online')->default(false);
                $table->integer('due_day')->default(20);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
