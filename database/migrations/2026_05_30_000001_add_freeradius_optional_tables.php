<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('radgroupcheck')) {
            Schema::create('radgroupcheck', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->index();
                $table->string('attribute', 64);
                $table->char('op', 2)->default('==');
                $table->string('value', 253);
                $table->index(['groupname', 'attribute']);
            });
        }

        if (! Schema::hasTable('radgroupreply')) {
            Schema::create('radgroupreply', function (Blueprint $table) {
                $table->id();
                $table->string('groupname', 64)->index();
                $table->string('attribute', 64);
                $table->char('op', 2)->default('=');
                $table->string('value', 253);
                $table->index(['groupname', 'attribute']);
            });
        }

        if (! Schema::hasTable('radusergroup')) {
            Schema::create('radusergroup', function (Blueprint $table) {
                $table->string('username', 64)->index();
                $table->string('groupname', 64);
                $table->integer('priority')->default(1);
                $table->primary(['username', 'groupname']);
            });
        }

        if (! Schema::hasTable('radpostauth')) {
            Schema::create('radpostauth', function (Blueprint $table) {
                $table->id();
                $table->string('username', 64)->nullable();
                $table->string('pass', 64)->nullable();
                $table->string('reply', 32)->nullable();
                $table->timestamp('authdate')->useCurrent();
                $table->index('username');
            });
        }
    }

    public function down(): void
    {
        foreach (['radpostauth', 'radusergroup', 'radgroupreply', 'radgroupcheck'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
