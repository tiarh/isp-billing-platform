<?php

namespace Tests\Concerns;

use App\Http\Middleware\RequireControlPlaneTotp;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentGateway\PaymentWriteGate;
use Tests\Support\Canary;

/**
 * Shared fixtures for payment gateway tests. Only synthetic data:
 * documentation-range IPs (RFC 5737), fake names, no real credentials.
 */
trait GatewayFixtures
{
    protected function setPaymentWrites(bool $enabled): void
    {
        Setting::set(PaymentWriteGate::SETTING_KEY, $enabled ? '1' : '0', 'boolean', 'control_plane', 'Payment write');
    }

    protected function gatewayRouter(): Router
    {
        return Router::create([
            'name' => 'PG Router '.uniqid(),
            'ip_address' => '192.0.2.'.rand(1, 254),
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'port' => 8728,
            'is_active' => true,
        ]);
    }

    protected function gatewayCustomer(array $overrides = []): Customer
    {
        $package = Package::first() ?: Package::create([
            'code' => 'PKG-PG',
            'name' => 'PG Package',
            'price' => 100000,
            'mikrotik_profile' => 'PACKAGE_PROFILE',
        ]);

        return Customer::create(array_merge([
            'code' => 'PG-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'Gateway Customer',
            'phone' => '081200000000',
            'address' => 'Jl. Contoh No. 1',
            'pppoe_user' => 'pg.'.substr(uniqid(), -6),
            'package_id' => $package->id,
            'status' => 'active',
        ], $overrides));
    }

    protected function gatewayInvoice(Customer $customer, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'customer_id' => $customer->id,
            'period' => now()->startOfMonth()->toDateString(),
            'amount' => 100000,
            'status' => 'unpaid',
            'due_date' => now()->addDays(7)->toDateString(),
            'generated_at' => now(),
        ], $overrides));
    }

    protected function gatewayUser(string $role = 'superadmin', bool $totp = true): User
    {
        $user = User::factory()->create(['role' => $role]);
        if ($totp) {
            $user->forceFill(['totp_secret' => 'FAKETOTPSECRET234567', 'totp_confirmed_at' => now()])->save();
        }

        return $user->fresh();
    }

    /**
     * Session grant normally written by TotpController::stepUp after a code.
     *
     * @return array<string, mixed>
     */
    protected function totpGrant(User $user, string $routeName): array
    {
        return [RequireControlPlaneTotp::VERIFIED_KEY => [
            'route' => $routeName,
            'user_id' => $user->id,
            'expires_at' => time() + 300,
        ]];
    }
}
