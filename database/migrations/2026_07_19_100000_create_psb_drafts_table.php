<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('psb_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('status', 32)->default('received')->index();
            // received | waiting_sn | pending_vlan | secret_ok | olt_ok | applied | failed | timeout
            $table->string('group_jid', 80)->nullable()->index();
            $table->string('sender_jid', 80)->nullable();
            $table->string('sender_name', 120)->nullable();
            $table->string('message_id', 120)->nullable()->unique();
            $table->text('raw_text')->nullable();

            $table->string('full_name', 120)->nullable();
            $table->string('rt', 8)->nullable();
            $table->string('rw', 8)->nullable();
            $table->string('odp', 64)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('tikor', 64)->nullable();
            $table->string('daerah', 64)->nullable();
            $table->unsignedBigInteger('area_id')->nullable()->index();
            $table->string('nik', 32)->nullable();

            $table->string('sn', 64)->nullable()->index();
            $table->string('pon_port', 32)->nullable();
            $table->unsignedInteger('onu_id')->nullable();
            $table->unsignedInteger('vlan')->nullable();
            $table->unsignedInteger('tr069_vlan')->default(69);

            $table->string('pppoe_user', 128)->nullable()->index();
            $table->string('pppoe_password', 64)->nullable();
            $table->string('pppoe_local_address', 45)->nullable();
            $table->string('pppoe_remote_address', 45)->nullable();
            $table->string('mikrotik_profile', 64)->nullable();

            $table->unsignedBigInteger('router_id')->nullable();
            $table->unsignedBigInteger('olt_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable()->index();

            $table->string('ktp_photo_path', 255)->nullable();
            $table->string('router_photo_path', 255)->nullable();

            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('sn_wait_until')->nullable();
            $table->timestamp('secret_at')->nullable();
            $table->timestamp('olt_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('psb_drafts');
    }
};
