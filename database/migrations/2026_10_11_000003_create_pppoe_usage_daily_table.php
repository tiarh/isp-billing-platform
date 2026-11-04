<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-4c-1: per-PPPoE-user daily usage (WIB days) rolled up from Prometheus
 * increase() by `monitoring:pppoe-usage-rollup`. Unlinked usernames are kept
 * (customer_id NULL). quality: ok | partial | invalid. Retention 13 months.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pppoe_usage_daily')) {
            return;
        }

        Schema::create('pppoe_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->date('usage_date_wib');
            $table->unsignedBigInteger('router_id');
            $table->string('pppoe_user', 128);
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('download_bytes')->default(0);
            $table->unsignedBigInteger('upload_bytes')->default(0);
            $table->boolean('is_final')->default(false);
            $table->string('quality', 16)->default('ok');
            $table->timestamps();

            $table->unique(['usage_date_wib', 'router_id', 'pppoe_user'], 'pppoe_usage_daily_date_router_user_unique');
            $table->index(['router_id', 'usage_date_wib'], 'pppoe_usage_daily_router_date_idx');
            $table->index(['customer_id', 'usage_date_wib'], 'pppoe_usage_daily_customer_date_idx');
            $table->index(['usage_date_wib', 'is_final'], 'pppoe_usage_daily_date_final_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_usage_daily');
    }
};
