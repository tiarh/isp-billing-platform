<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'legacy_id')) {
                $table->string('legacy_id')->nullable()->after('id')->index();
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'legacy_id')) {
                $table->string('legacy_id')->nullable()->after('id')->index();
            }

            if (! Schema::hasColumn('invoices', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->after('generated_at');
            }
        });

        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'legacy_id')) {
                $table->string('legacy_id')->nullable()->after('id')->index();
            }

            if (! Schema::hasColumn('transactions', 'legacy_customer_code')) {
                $table->string('legacy_customer_code')->nullable()->after('legacy_id')->index();
            }

            if (! Schema::hasColumn('transactions', 'legacy_period')) {
                $table->date('legacy_period')->nullable()->after('legacy_customer_code')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach (['legacy_period', 'legacy_customer_code', 'legacy_id'] as $column) {
                if (Schema::hasColumn('transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('invoices', function (Blueprint $table) {
            foreach (['last_synced_at', 'legacy_id'] as $column) {
                if (Schema::hasColumn('invoices', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'legacy_id')) {
                $table->dropColumn('legacy_id');
            }
        });
    }
};
