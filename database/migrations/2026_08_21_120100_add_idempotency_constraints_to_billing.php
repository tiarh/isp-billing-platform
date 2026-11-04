<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Abort before DDL if existing data would violate the new guarantees.
        foreach ([
            ['billing_wa_logs', 'dedupe_key'],
            ['wa_campaign_recipients', 'wa_campaign_id,customer_id'],
        ] as [$table, $columns]) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $group = str_replace(',', ', ', $columns);
            $duplicate = DB::table($table)
                ->selectRaw($group)
                ->whereNotNull(str_contains($columns, ',') ? 'customer_id' : $columns)
                ->groupBy(...explode(',', $columns))
                ->havingRaw('COUNT(*) > 1')
                ->exists();
            if ($duplicate) {
                throw new RuntimeException("Cannot add idempotency constraint: duplicate {$table}.{$columns} data exists");
            }
        }

        if (Schema::hasTable('transactions') && ! Schema::hasColumn('transactions', 'idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->string('idempotency_key', 191)->nullable()->unique()->after('reference');
            });
        }

        if (Schema::hasTable('billing_wa_logs')) {
            Schema::table('billing_wa_logs', function (Blueprint $table) {
                $table->unique('dedupe_key', 'billing_wa_logs_dedupe_key_unique');
            });
        }

        if (Schema::hasTable('wa_campaign_recipients')) {
            Schema::table('wa_campaign_recipients', function (Blueprint $table) {
                $table->unique(['wa_campaign_id', 'customer_id'], 'wa_campaign_recipient_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactions') && Schema::hasColumn('transactions', 'idempotency_key')) {
            Schema::table('transactions', function (Blueprint $table) {
                $table->dropUnique(['idempotency_key']);
                $table->dropColumn('idempotency_key');
            });
        }
        if (Schema::hasTable('billing_wa_logs')) {
            Schema::table('billing_wa_logs', fn (Blueprint $table) => $table->dropUnique('billing_wa_logs_dedupe_key_unique'));
        }
        if (Schema::hasTable('wa_campaign_recipients')) {
            Schema::table('wa_campaign_recipients', fn (Blueprint $table) => $table->dropUnique('wa_campaign_recipient_unique'));
        }
    }
};
