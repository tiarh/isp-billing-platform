<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pppoe_address_pool_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained('routers')->cascadeOnDelete();
            $table->string('site_profile_key', 80)->nullable()->index();
            $table->string('pppoe_profile', 80)->nullable()->index();
            $table->string('customer_area', 120)->nullable()->index();
            $table->string('block_name', 120)->nullable();
            $table->unsignedInteger('block_index')->default(0);
            $table->string('address_format', 24)->default('full_ipv4');
            $table->string('prefix_template', 80)->nullable();
            $table->string('local_address', 45);
            $table->string('remote_start', 45);
            $table->string('remote_end', 45);
            $table->unsignedInteger('next_block_index')->nullable();
            $table->string('last_remote_address', 45)->nullable();
            $table->string('last_remote_source', 40)->nullable();
            $table->timestamp('last_remote_synced_at')->nullable();
            $table->string('allocator_mode', 40)->default('last_remote_pointer');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('capacity')->default(0);
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('available_count')->default(0);
            $table->unsignedInteger('warning_threshold')->default(10);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['router_id', 'is_active', 'priority', 'block_index'], 'pppoe_pool_router_active_order_idx');
            $table->index(['router_id', 'site_profile_key', 'pppoe_profile'], 'pppoe_pool_router_site_profile_idx');
            $table->index(['router_id', 'customer_area'], 'pppoe_pool_router_area_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pppoe_address_pool_blocks');
    }
};
