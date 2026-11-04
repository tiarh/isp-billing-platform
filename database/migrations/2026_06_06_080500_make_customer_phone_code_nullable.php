<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 255)->nullable()->default(null)->change();
            $table->string('code', 255)->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone', 255)->nullable(false)->default(null)->change();
            $table->string('code', 255)->nullable(false)->default(null)->change();
        });
    }
};
