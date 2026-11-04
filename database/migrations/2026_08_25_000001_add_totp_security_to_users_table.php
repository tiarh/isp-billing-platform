<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'totp_secret')) {
                $table->text('totp_secret')->nullable();
            }

            if (! Schema::hasColumn('users', 'totp_recovery_codes')) {
                $table->text('totp_recovery_codes')->nullable();
            }

            if (! Schema::hasColumn('users', 'totp_confirmed_at')) {
                $table->timestamp('totp_confirmed_at')->nullable();
            }

            if (! Schema::hasColumn('users', 'totp_last_used_counter')) {
                $table->unsignedBigInteger('totp_last_used_counter')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            foreach (['totp_last_used_counter', 'totp_confirmed_at', 'totp_recovery_codes', 'totp_secret'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
