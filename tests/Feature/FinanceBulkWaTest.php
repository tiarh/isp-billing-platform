<?php

namespace Tests\Feature;

use App\Contracts\WaProvider;
use App\Jobs\SendFinanceBulkWaJob;
use App\Models\Area;
use App\Models\BillingWaLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Setting;
use App\Models\User;
use App\Services\FinanceBulkWaService;
use App\Services\IndonesianPhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-billing')]
class FinanceBulkWaTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_is_disabled_and_dry_run_preview_never_queues_or_calls_provider(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldNotReceive('send');
        $this->app->instance(WaProvider::class, $provider);
        Queue::fake();

        $response = $this->actingAs($admin)->getJson(route('api.finance.per-area.bulk-wa.preview', [
            'period' => '2026-09',
        ]));

        $response->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('dry_run', true)
            ->assertJsonPath('can_execute', false)
            ->assertJsonPath('max_recipients', 100)
            ->assertJsonPath('rate_limit_per_minute', 10);
        Queue::assertNothingPushed();
    }

    public function test_live_execute_is_idempotent_and_message_has_no_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('BULK-001');
        $invoice = $this->invoice($customer);
        Setting::set('finance_bulk_wa_enabled', '1', 'boolean', 'finance_bulk_wa');
        Setting::set('finance_bulk_wa_dry_run', '0', 'boolean', 'finance_bulk_wa');
        Queue::fake();

        $payload = [
            'period' => '2026-09',
            'confirmation' => 'KIRIM WA TAGIHAN',
            'idempotency_key' => 'bulk-test-001',
        ];
        $first = $this->actingAs($admin)->postJson(route('api.finance.per-area.bulk-wa.execute'), $payload);
        $first->assertOk()->assertJsonPath('created', 1)->assertJsonPath('existing', 0);

        $second = $this->actingAs($admin)->postJson(route('api.finance.per-area.bulk-wa.execute'), $payload);
        $second->assertOk()->assertJsonPath('created', 0)->assertJsonPath('existing', 1);

        $this->assertDatabaseCount('billing_wa_logs', 1);
        $log = BillingWaLog::query()->firstOrFail();
        $this->assertSame($invoice->id, $log->invoice_id);
        $this->assertStringNotContainsString('pppoe', strtolower((string) $log->message));
        $this->assertStringNotContainsString('password', strtolower((string) $log->message));
        Queue::assertPushed(SendFinanceBulkWaJob::class, 1);
    }

    public function test_execute_requires_confirmation_and_does_not_send_while_dry_run(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin']);
        $customer = $this->customer('BULK-002');
        $this->invoice($customer);
        Setting::set('finance_bulk_wa_enabled', '1', 'boolean', 'finance_bulk_wa');
        Queue::fake();

        $this->actingAs($admin)->postJson(route('api.finance.per-area.bulk-wa.execute'), [
            'period' => '2026-09',
            'confirmation' => 'WRONG',
            'idempotency_key' => 'bulk-test-002',
        ])->assertUnprocessable();

        $this->actingAs($admin)->postJson(route('api.finance.per-area.bulk-wa.execute'), [
            'period' => '2026-09',
            'confirmation' => 'KIRIM WA TAGIHAN',
            'idempotency_key' => 'bulk-test-002',
        ])->assertStatus(409);

        $this->assertDatabaseCount('billing_wa_logs', 0);
        Queue::assertNothingPushed();
    }

    public function test_job_rechecks_live_gate_and_uses_fake_provider_only(): void
    {
        Setting::set('finance_bulk_wa_enabled', '1', 'boolean', 'finance_bulk_wa');
        Setting::set('finance_bulk_wa_dry_run', '0', 'boolean', 'finance_bulk_wa');
        $customer = $this->customer('BULK-JOB');
        $invoice = $this->invoice($customer);
        $log = BillingWaLog::create([
            'event' => 'finance_bulk_reminder',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'phone' => '08******90',
            'message' => 'Tagihan demo',
            'status' => 'pending',
            'dedupe_key' => 'finance-bulk:job-test',
        ]);
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldReceive('isConfigured')->once()->andReturn(true);
        $provider->shouldReceive('connectionState')->once()->andReturn('open');
        $provider->shouldReceive('send')->once()->with('6281234567890', 'Tagihan demo')->andReturn(['success' => true, 'message_id' => 'fake-1']);

        (new SendFinanceBulkWaJob($log->id, '081234567890', 'Tagihan demo'))
            ->handle($provider, app(IndonesianPhoneNormalizer::class), app(FinanceBulkWaService::class));

        $this->assertDatabaseHas('billing_wa_logs', ['id' => $log->id, 'status' => 'sent', 'provider_message_id' => 'fake-1']);
    }

    public function test_job_skips_invoice_that_was_paid_after_queueing(): void
    {
        Setting::set('finance_bulk_wa_enabled', '1', 'boolean', 'finance_bulk_wa');
        Setting::set('finance_bulk_wa_dry_run', '0', 'boolean', 'finance_bulk_wa');
        $customer = $this->customer('BULK-PAID');
        $invoice = $this->invoice($customer);
        $invoice->update(['status' => 'paid']);
        $invoice->transactions()->create([
            'reference' => 'BULK-PAID-REFERENCE',
            'amount' => 100000,
            'channel' => 'manual',
            'status' => 'paid',
            'method' => 'cash',
            'paid_at' => now(),
        ]);
        $log = BillingWaLog::create([
            'event' => 'finance_bulk_reminder',
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'phone' => '08******90',
            'message' => 'Tagihan demo',
            'status' => 'pending',
            'dedupe_key' => 'finance-bulk:job-paid',
        ]);
        $provider = Mockery::mock(WaProvider::class);
        $provider->shouldNotReceive('isConfigured');
        $provider->shouldNotReceive('send');

        (new SendFinanceBulkWaJob($log->id, '081234567890', 'Tagihan demo'))
            ->handle($provider, app(IndonesianPhoneNormalizer::class), app(FinanceBulkWaService::class));

        $this->assertDatabaseHas('billing_wa_logs', ['id' => $log->id, 'status' => 'skipped']);
    }

    public function test_scoped_admin_cannot_preview_foreign_area(): void
    {
        $allowed = Area::create(['code' => 'BULK-A', 'name' => 'Gadang']);
        $blocked = Area::create(['code' => 'BULK-B', 'name' => 'Krian']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($allowed);

        $this->actingAs($admin)->getJson(route('api.finance.per-area.bulk-wa.preview', [
            'period' => '2026-09',
            'area' => $blocked->id,
        ]))->assertForbidden();
    }

    private function customer(string $code): Customer
    {
        $area = Area::firstOrCreate(['code' => 'BULK-AREA'], ['name' => 'Gadang']);
        $package = Package::create(['code' => $code.'-PKG', 'name' => 'Bulk Package', 'price' => 100000]);

        return Customer::create([
            'code' => $code,
            'name' => 'Bulk Customer',
            'phone' => '081234567890',
            'address' => 'Bulk address',
            'pppoe_user' => 'secret-pppoe-value',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $customer): Invoice
    {
        return Invoice::create([
            'code' => 'INV-'.$customer->code,
            'customer_id' => $customer->id,
            'period' => '2026-09-01',
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => '2026-09-20',
            'generated_at' => '2026-09-01 00:00:00',
        ]);
    }
}
