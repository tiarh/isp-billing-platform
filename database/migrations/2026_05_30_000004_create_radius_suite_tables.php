<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotspot_vouchers')) {
            Schema::create('hotspot_vouchers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('router_id')->nullable()->constrained('routers')->nullOnDelete();
                $table->foreignId('package_id')->nullable()->constrained('packages')->nullOnDelete();
                $table->string('batch_code', 40)->index();
                $table->string('username', 64)->unique();
                $table->string('password', 64);
                $table->string('profile')->nullable();
                $table->string('rate_limit')->nullable();
                $table->unsignedInteger('price')->default(0);
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->unsignedBigInteger('quota_bytes')->nullable();
                $table->enum('status', ['unused', 'active', 'used', 'expired', 'disabled'])->default('unused')->index();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_gateway_events')) {
            Schema::create('payment_gateway_events', function (Blueprint $table) {
                $table->id();
                $table->string('provider', 40)->index();
                $table->string('external_id', 128)->nullable()->index();
                $table->string('reference', 128)->nullable()->index();
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->decimal('amount', 12, 2)->nullable();
                $table->string('status', 40)->index();
                $table->json('payload');
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'external_id']);
            });
        }

        if (! Schema::hasTable('support_tickets')) {
            Schema::create('support_tickets', function (Blueprint $table) {
                $table->id();
                $table->string('code', 40)->unique();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->enum('type', ['installation', 'incident', 'billing', 'request', 'other'])->default('incident')->index();
                $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal')->index();
                $table->enum('status', ['open', 'assigned', 'in_progress', 'resolved', 'closed', 'cancelled'])->default('open')->index();
                $table->string('subject');
                $table->text('description')->nullable();
                $table->timestamp('due_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reseller_commissions')) {
            Schema::create('reseller_commissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('reseller_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->string('period', 7)->index();
                $table->decimal('base_amount', 12, 2)->default(0);
                $table->decimal('commission_amount', 12, 2)->default(0);
                $table->enum('status', ['pending', 'approved', 'paid', 'void'])->default('pending')->index();
                $table->timestamp('paid_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('genieacs_devices')) {
            Schema::create('genieacs_devices', function (Blueprint $table) {
                $table->id();
                $table->string('device_id')->unique();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('serial_number', 128)->nullable()->index();
                $table->string('oui', 32)->nullable();
                $table->string('product_class', 128)->nullable();
                $table->string('software_version', 128)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('ssid')->nullable();
                $table->timestamp('last_inform_at')->nullable();
                $table->json('parameters')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('genieacs_devices');
        Schema::dropIfExists('reseller_commissions');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('payment_gateway_events');
        Schema::dropIfExists('hotspot_vouchers');
    }
};
