<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->where('channel', 'tripay')
            ->update(['channel' => 'manual']);

        DB::statement("ALTER TABLE transactions MODIFY channel ENUM('whatsapp', 'manual') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE transactions MODIFY channel ENUM('whatsapp', 'manual', 'tripay') NOT NULL DEFAULT 'manual'");
    }
};
