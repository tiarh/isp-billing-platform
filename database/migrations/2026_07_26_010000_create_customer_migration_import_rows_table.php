<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_migration_import_rows')) {
            return;
        }

        Schema::create('customer_migration_import_rows', function (Blueprint $table) {
            $table->id();
            $table->string('batch_key', 80)->index();
            $table->string('source_label', 160)->nullable();
            $table->unsignedInteger('row_number')->default(0);
            $table->string('classification', 32)->default('needs_review')->index();
            $table->string('classification_source', 32)->default('excel_rule')->index();
            $table->string('project', 80)->nullable()->index();
            $table->string('area', 80)->nullable()->index();
            $table->string('sub_area', 120)->nullable();
            $table->string('rw', 20)->nullable()->index();
            $table->boolean('rw_needs_review')->default(false)->index();
            $table->string('full_name', 160)->nullable();
            $table->string('pppoe_user', 160)->nullable()->index();
            $table->string('sn_lama', 64)->nullable()->index();
            $table->string('sn_baru', 64)->nullable()->index();
            $table->string('sn_baru_source', 32)->nullable();
            $table->string('old_onu_ref', 80)->nullable();
            $table->string('status', 32)->default('staged')->index();
            $table->string('match_status', 40)->default('needs_review')->index();
            $table->text('match_notes')->nullable();
            $table->json('raw_data')->nullable();
            $table->json('meta')->nullable();
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('imported_by')->nullable()->index();
            $table->timestamp('imported_at')->nullable();
            $table->unsignedBigInteger('corrected_by')->nullable()->index();
            $table->timestamp('corrected_at')->nullable();
            $table->string('applied_target_type', 40)->nullable()->index();
            $table->unsignedBigInteger('applied_target_id')->nullable()->index();
            $table->text('applied_error')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable()->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['batch_key', 'classification']);
            $table->index(['batch_key', 'match_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_migration_import_rows');
    }
};
