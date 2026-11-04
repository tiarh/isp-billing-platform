<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_migrations')) {
            return;
        }

        Schema::create('customer_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('received')->index();
            $table->string('source', 32)->default('wa')->index();
            $table->string('group_jid', 80)->nullable()->index();
            $table->string('sender_jid', 80)->nullable();
            $table->string('sender_name', 120)->nullable();
            $table->string('message_id', 120)->nullable()->unique();
            $table->text('raw_text')->nullable();

            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->string('pppoe_user', 128)->nullable()->index();
            $table->string('full_name', 120)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('nik', 32)->nullable();
            $table->string('rt', 8)->nullable();
            $table->string('rw', 8)->nullable();
            $table->string('odp', 64)->nullable();
            $table->string('tikor', 64)->nullable();
            $table->string('daerah', 64)->nullable();
            $table->unsignedBigInteger('area_id')->nullable()->index();

            $table->unsignedBigInteger('old_router_id')->nullable()->index();
            $table->unsignedBigInteger('old_olt_id')->nullable()->index();
            $table->string('old_onu_ref', 64)->nullable();
            $table->string('old_sn', 64)->nullable()->index();

            $table->unsignedBigInteger('router_id')->nullable()->index();
            $table->unsignedBigInteger('new_olt_id')->nullable()->index();
            $table->string('new_onu_ref', 64)->nullable();
            $table->string('new_pon_port', 32)->nullable();
            $table->unsignedInteger('new_onu_id')->nullable();
            $table->string('new_sn', 64)->nullable()->index();
            $table->unsignedInteger('vlan')->nullable();
            $table->unsignedInteger('tr069_vlan')->nullable();

            $table->string('ktp_photo_path', 255)->nullable();
            $table->string('router_photo_path', 255)->nullable();
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('olt_at')->nullable();
            $table->timestamp('old_onu_deleted_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_migrations');
    }
};
