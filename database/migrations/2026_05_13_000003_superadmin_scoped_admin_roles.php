<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')->where('role', '')->update(['role' => 'admin']);
        DB::table('users')->where('email', 'admin@skynet.id')->update(['role' => 'superadmin']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'role')) {
            return;
        }

        DB::table('users')->where('email', 'admin@skynet.id')->update(['role' => 'admin']);
    }
};
