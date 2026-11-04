<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seven nullable columns that exist on production but were never in a tracked migration. Each is guarded by
 * hasTable/hasColumn, so on production this is a no-op. Types mirror the prod snapshot
 * (tests/Fixtures/schema/prod-columns.json).
 */
return new class extends Migration
{
    /** @return array<string, array<string, callable(Blueprint): mixed>> */
    private function columns(): array
    {
        return [
            'customers' => [
                'service_online_reason' => fn (Blueprint $t) => $t->string('service_online_reason', 64)->nullable(),
            ],
            'support_tickets' => [
                'site' => fn (Blueprint $t) => $t->string('site', 255)->nullable(),
                'additional_technicians' => fn (Blueprint $t) => $t->json('additional_technicians')->nullable(),
                'material' => fn (Blueprint $t) => $t->json('material')->nullable(),
            ],
            'wa_campaign_recipients' => [
                'customer_name' => fn (Blueprint $t) => $t->string('customer_name', 255)->nullable(),
            ],
            'wa_campaigns' => [
                'image_path' => fn (Blueprint $t) => $t->string('image_path', 255)->nullable(),
                'custom_recipients' => fn (Blueprint $t) => $t->json('custom_recipients')->nullable(),
            ],
        ];
    }

    public function up(): void
    {
        foreach ($this->columns() as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }
            foreach ($columns as $name => $define) {
                if (Schema::hasColumn($tableName, $name)) {
                    continue;
                }
                Schema::table($tableName, function (Blueprint $table) use ($define) {
                    $define($table);
                });
            }
        }
    }

    public function down(): void
    {
        // Never drop: on production these columns hold real data and are not owned by this migration.
    }
};
