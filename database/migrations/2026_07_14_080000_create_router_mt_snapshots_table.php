<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan C / C0: shadow T0 pulse (Online/Secret counts) — UI still Plan B.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('router_mt_snapshots')) {
            return;
        }

        Schema::create('router_mt_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->unsignedInteger('online_count')->default(0);
            $table->unsignedInteger('secret_count')->default(0);
            $table->unsignedInteger('multi_session')->nullable();
            $table->unsignedInteger('session_rows')->nullable();
            $table->string('tier', 8)->default('t0'); // t0|t1|t2
            $table->string('source', 32)->default('exporter'); // exporter|api|live
            $table->string('quality', 16)->default('good'); // good|suspect|stale|fail
            $table->unsignedInteger('scrape_ms')->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamp('collected_at')->useCurrent();
            $table->timestamps();

            $table->index(['router_id', 'collected_at']);
            $table->index(['quality', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_mt_snapshots');
    }
};
