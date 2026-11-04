<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'message_id')) {
                $table->string('message_id')->nullable()->after('photo_url');
                $table->unique('message_id');
            }

            if (! $this->indexExists('attendances', 'attendances_created_at_index')) {
                $table->index('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'message_id')) {
                $table->dropUnique(['message_id']);
                $table->dropColumn('message_id');
            }

            if ($this->indexExists('attendances', 'attendances_created_at_index')) {
                $table->dropIndex(['created_at']);
            }
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $indexes = Schema::getIndexes($table);

        foreach ($indexes as $meta) {
            if (($meta['name'] ?? '') === $index) {
                return true;
            }
        }

        return false;
    }
};
