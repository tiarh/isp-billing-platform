<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wa_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('message_template');
            $table->enum('status', ['draft', 'processing', 'completed', 'failed', 'paused'])->default('draft');
            $table->enum('target_type', ['all', 'isolated', 'area', 'custom']);
            $table->foreignId('target_area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wa_campaigns');
    }
};
