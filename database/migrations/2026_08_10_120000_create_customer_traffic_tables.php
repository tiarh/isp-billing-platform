<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_traffic_current')) {
            Schema::create('customer_traffic_current', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('router_id');
                $table->char('pppoe_key_hash', 64);
                $table->boolean('online')->default(false);
                $table->char('session_hash', 64)->nullable();
                $table->unsignedBigInteger('download_bps')->default(0);
                $table->unsignedBigInteger('upload_bps')->default(0);
                $table->unsignedBigInteger('download_bytes_total')->nullable();
                $table->unsignedBigInteger('upload_bytes_total')->nullable();
                $table->string('quality', 16)->default('first_sample');
                $table->string('source', 32)->default('mikrotik_pppoe');
                $table->timestamp('sampled_at')->nullable();
                $table->timestamps();

                $table->unique(['customer_id', 'router_id'], 'traffic_current_customer_router_unique');
                $table->index(['router_id', 'sampled_at'], 'traffic_current_router_sampled_idx');
                $table->index(['quality', 'sampled_at'], 'traffic_current_quality_sampled_idx');
            });
        }

        if (! Schema::hasTable('customer_traffic_daily')) {
            Schema::create('customer_traffic_daily', function (Blueprint $table): void {
                $table->id();
                $table->date('traffic_date_wib');
                $table->unsignedBigInteger('customer_id');
                $table->unsignedBigInteger('router_id');
                $table->unsignedBigInteger('download_bytes')->default(0);
                $table->unsignedBigInteger('upload_bytes')->default(0);
                $table->unsignedInteger('sample_count')->default(0);
                $table->unsignedInteger('expected_sample_count')->default(0);
                $table->unsignedInteger('reset_count')->default(0);
                $table->unsignedInteger('gap_count')->default(0);
                $table->decimal('coverage_ratio', 6, 4)->default(0);
                $table->string('quality', 16)->default('good');
                $table->string('source', 32)->default('api_poll');
                $table->timestamp('first_sampled_at')->nullable();
                $table->timestamp('last_sampled_at')->nullable();
                $table->timestamps();

                $table->unique(['traffic_date_wib', 'customer_id', 'router_id'], 'traffic_daily_date_customer_router_unique');
                $table->index(['customer_id', 'traffic_date_wib'], 'traffic_daily_customer_date_idx');
                $table->index(['router_id', 'traffic_date_wib'], 'traffic_daily_router_date_idx');
            });
        }

        if (! Schema::hasTable('customer_traffic_ingest_batches')) {
            Schema::create('customer_traffic_ingest_batches', function (Blueprint $table): void {
                $table->id();
                $table->string('batch_id', 128);
                $table->unsignedInteger('chunk_index');
                $table->unsignedInteger('chunk_count');
                $table->string('schema_version', 16)->default('1');
                $table->string('source', 32)->default('collector_106');
                $table->string('traffic_date_wib', 10)->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->char('body_hash', 64);
                $table->string('status', 16)->default('committed');
                $table->timestamp('committed_at')->nullable();
                $table->timestamps();

                $table->unique(['batch_id', 'chunk_index'], 'traffic_ingest_batch_chunk_unique');
                $table->unique('body_hash', 'traffic_ingest_body_hash_unique');
                $table->index(['created_at'], 'traffic_ingest_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_traffic_ingest_batches');
        Schema::dropIfExists('customer_traffic_daily');
        Schema::dropIfExists('customer_traffic_current');
    }
};
