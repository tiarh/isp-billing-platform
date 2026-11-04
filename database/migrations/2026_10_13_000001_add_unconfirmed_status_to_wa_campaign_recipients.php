<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR-WA: a broadcast send whose outcome is unknown (Evolution read timeout) is
 * recorded as recipient status `unconfirmed` ("mungkin terkirim") instead of
 * `failed`, so "Ulang Gagal" never resends it. Additive + idempotent.
 */
return new class extends Migration
{
    private const REQUIRED = ['pending', 'sent', 'failed', 'unconfirmed'];

    public function up(): void
    {
        if (Schema::hasTable('wa_campaigns') && ! Schema::hasColumn('wa_campaigns', 'unconfirmed_count')) {
            Schema::table('wa_campaigns', function (Blueprint $table): void {
                $table->unsignedInteger('unconfirmed_count')->default(0);
            });
        }

        if (! Schema::hasTable('wa_campaign_recipients') || ! Schema::hasColumn('wa_campaign_recipients', 'status')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $column = DB::selectOne(
                'SELECT COLUMN_TYPE AS column_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                ['wa_campaign_recipients', 'status'],
            );
            $columnType = strtolower((string) ($column->column_type ?? ''));
            if (! str_starts_with($columnType, 'enum(')) {
                return;
            }
            preg_match_all("/'([^']*)'/", $columnType, $matches);
            $existing = $matches[1] ?? [];
            if (in_array('unconfirmed', $existing, true)) {
                return;
            }
            $values = array_values(array_unique(array_merge($existing, self::REQUIRED)));
            $list = implode(',', array_map(fn (string $value): string => DB::getPdo()->quote($value), $values));
            DB::statement("ALTER TABLE wa_campaign_recipients MODIFY COLUMN `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");

            return;
        }

        Schema::table('wa_campaign_recipients', function (Blueprint $table): void {
            $table->enum('status', self::REQUIRED)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Intentionally additive: shrinking the enum could reject existing rows.
    }
};
