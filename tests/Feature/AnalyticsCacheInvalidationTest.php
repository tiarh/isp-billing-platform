<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class AnalyticsCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_store_flushes_analytics_cache_for_user(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'A001',
            'name' => 'Customer A',
            'phone' => '0811111111',
            'address' => 'Addr',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'a001',
            'status' => 'active',
        ]);

        Cache::put('analytics.mrr.superadmin', ['current_mrr' => 999], now()->addHour());

        $this->actingAs($user)->post(route('invoices.store'), [
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 100000,
            'due_date' => now()->addDays(7)->format('Y-m-d'),
        ])->assertRedirect(route('invoices.index'));

        $this->assertNull(Cache::get('analytics.mrr.superadmin'));
    }

    public function test_invoice_void_flushes_analytics_cache_for_user(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);
        $package = Package::create(['code' => 'BASIC', 'name' => 'Basic', 'price' => 100000]);
        $customer = Customer::create([
            'code' => 'A001',
            'name' => 'Customer A',
            'phone' => '0811111111',
            'address' => 'Addr',
            'area_id' => $area->id,
            'package_id' => $package->id,
            'pppoe_user' => 'a001',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->format('Y-m-d'),
            'amount' => 100000,
            'due_date' => now()->addDays(7),
            'status' => 'unpaid',
            'generated_at' => now(),
        ]);

        Cache::put('analytics.collection_rate.superadmin', ['collection_rate' => 50], now()->addHour());

        $this->actingAs($user)
            ->post(route('invoices.void', $invoice), ['reason' => 'test'])
            ->assertRedirect();

        $this->assertNull(Cache::get('analytics.collection_rate.superadmin'));
    }
}
