<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit 2026-10-06 (P8): the hottest list/dashboard filters had no supporting
 * index, so MySQL filesorted the whole table on every page:
 *
 * - customers: `status` (dashboard, analytics, index filter) and the index
 *   default sort `join_date` under the `deleted_at IS NULL` global scope.
 * - invoices: `(period, status)` (dashboard "missing invoices", index filter)
 *   and `(status, due_date)` (overdue / aging).
 * - transactions: `(status, paid_at)` (dashboard revenue, recent payments).
 * - support_tickets: `(status, created_at)` (ticket index `latest()`).
 *
 * Tables are small (~1.5k customers) so these are instant online DDL; the
 * gain is CPU (no filesort / full scan per request), not I/O.
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => index name => columns */
    private const INDEXES = [
        'customers' => [
            'customers_status_area_idx' => ['status', 'area_id'],
            'customers_deleted_join_idx' => ['deleted_at', 'join_date'],
        ],
        'invoices' => [
            'invoices_period_status_idx' => ['period', 'status'],
            'invoices_status_due_idx' => ['status', 'due_date'],
        ],
        'transactions' => [
            'transactions_status_paid_idx' => ['status', 'paid_at'],
        ],
        'support_tickets' => [
            'support_tickets_status_created_idx' => ['status', 'created_at'],
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($indexes as $name => $columns) {
                if (! Schema::hasColumns($table, $columns) || Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($columns, $name): void {
                    $blueprint->index($columns, $name);
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (array_keys($indexes) as $name) {
                if (! Schema::hasIndex($table, $name)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($name): void {
                    $blueprint->dropIndex($name);
                });
            }
        }
    }
};
