<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class CustomerStatusSavingGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function make(string $status): Customer
    {
        $n = ++$this->seq;
        $package = Package::firstOrCreate(['code' => 'SG-PKG'], ['name' => 'Guard', 'price' => 100000]);

        return Customer::create([
            'code' => 'SG-'.$n, 'name' => 'Guard '.$n, 'phone' => '0816'.$n, 'address' => 'RW 1',
            'package_id' => $package->id, 'status' => $status, 'pppoe_user' => 'u-sg'.$n,
        ]);
    }

    public function test_official_values_write_silently(): void
    {
        Log::spy();

        foreach (CustomerStatus::writable() as $status) {
            $this->make($status);
        }

        Log::shouldNotHaveReceived('warning', ['customer_status.non_official_write', \Mockery::any()]);
    }

    public function test_log_mode_logs_and_saves_non_official_value(): void
    {
        Log::spy();

        $customer = $this->make('inactive');

        $this->assertSame('inactive', $customer->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => $message === 'customer_status.non_official_write'
                && $context['value'] === 'inactive'
                && array_key_exists('id', $context)
                && array_key_exists('source', $context)
                && str_starts_with((string) $context['source'], 'CustomerStatusSavingGuardTest.php:'))
            ->once();
    }

    public function test_log_mode_logs_on_update_to_non_official_value(): void
    {
        $customer = $this->make('active');
        Log::spy();

        $customer->update(['status' => 'inactive']);

        $this->assertSame('inactive', $customer->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => $message === 'customer_status.non_official_write')
            ->once();
    }

    public function test_unrelated_update_on_legacy_status_row_does_not_log(): void
    {
        $customer = $this->make('inactive');
        Log::spy();

        $customer->update(['name' => 'Renamed']);

        Log::shouldNotHaveReceived('warning', ['customer_status.non_official_write', \Mockery::any()]);
    }

    public function test_strict_mode_throws_and_does_not_save(): void
    {
        Setting::set('customer_status_strict', '1', 'boolean');
        $customer = $this->make('active');

        try {
            $customer->update(['status' => 'inactive']);
            $this->fail('Strict mode must throw on non-official status.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame('active', $customer->fresh()->status);
    }

    public function test_strict_mode_still_allows_official_values(): void
    {
        Setting::set('customer_status_strict', '1', 'boolean');

        $customer = $this->make('active');
        $customer->update(['status' => 'terminated']);

        $this->assertSame('terminated', $customer->fresh()->status);
    }
}
