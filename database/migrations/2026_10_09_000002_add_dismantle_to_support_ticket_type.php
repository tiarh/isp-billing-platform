<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production's support_tickets.type enum already carries 'dismantle' (legacy
 * schema: + psb, maintenance) but the migrations never declared it, so fresh
 * databases reject Dismantle tickets. Additive + idempotent: production is a
 * no-op; existing values are always kept.
 */
return new class extends Migration
{
    private const REQUIRED = ['installation', 'incident', 'billing', 'request', 'other', 'psb', 'dismantle', 'maintenance'];

    public function up(): void
    {
        if (! Schema::hasTable('support_tickets') || ! Schema::hasColumn('support_tickets', 'type')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $column = DB::selectOne(
                'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['support_tickets', 'type'],
            );
            $columnType = strtolower((string) ($column->column_type ?? ''));
            if (! str_starts_with($columnType, 'enum(')) {
                return; // varchar or other free-form type already accepts 'dismantle'
            }
            preg_match_all("/'([^']*)'/", $columnType, $matches);
            $existing = $matches[1] ?? [];
            if (in_array('dismantle', $existing, true)) {
                return;
            }
            $values = array_values(array_unique(array_merge($existing, self::REQUIRED)));
            $list = implode(',', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));
            DB::statement("ALTER TABLE support_tickets MODIFY COLUMN `type` ENUM({$list}) NOT NULL DEFAULT 'incident'");

            return;
        }

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->enum('type', self::REQUIRED)->default('incident')->change();
        });
    }

    public function down(): void
    {
        // Intentionally additive: shrinking the enum could reject existing rows.
    }
};
