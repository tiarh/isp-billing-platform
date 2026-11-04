<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PG-1: payment gateway foundation.
 *  - payment_gateway_events keeps what is needed to credit a held payment
 *    later (kill switch OFF → held_kill_switch → released when ON).
 *  - transactions.channel gains 'gateway'; method gains online channels so
 *    gateway money is no longer reported as channel=manual/method=qris.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_events', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_gateway_events', 'credit_key')) {
                $table->string('credit_key', 128)->nullable()->after('reference');
            }
            if (! Schema::hasColumn('payment_gateway_events', 'credit_amount')) {
                $table->decimal('credit_amount', 12, 2)->nullable()->after('amount');
            }
            if (! Schema::hasColumn('payment_gateway_events', 'credit_method')) {
                $table->string('credit_method', 20)->nullable()->after('credit_amount');
            }
            if (! Schema::hasColumn('payment_gateway_events', 'held_at')) {
                $table->timestamp('held_at')->nullable()->after('processed_at');
            }
            if (! Schema::hasColumn('payment_gateway_events', 'released_at')) {
                $table->timestamp('released_at')->nullable()->after('held_at');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE transactions MODIFY channel ENUM('whatsapp', 'manual', 'gateway') NOT NULL DEFAULT 'manual'");
            DB::statement("ALTER TABLE transactions MODIFY method ENUM('cash', 'transfer', 'qris', 'other', 'va', 'ewallet', 'retail', 'card') NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('transactions')->where('channel', 'gateway')->update(['channel' => 'manual']);
            DB::table('transactions')->whereIn('method', ['va', 'ewallet', 'retail', 'card'])->update(['method' => 'other']);
            DB::statement("ALTER TABLE transactions MODIFY channel ENUM('whatsapp', 'manual') NOT NULL DEFAULT 'manual'");
            DB::statement("ALTER TABLE transactions MODIFY method ENUM('cash', 'transfer', 'qris', 'other') NULL");
        }

        Schema::table('payment_gateway_events', function (Blueprint $table) {
            foreach (['credit_key', 'credit_amount', 'credit_method', 'held_at', 'released_at'] as $column) {
                if (Schema::hasColumn('payment_gateway_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
