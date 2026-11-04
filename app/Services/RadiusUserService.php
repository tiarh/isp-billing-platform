<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RadiusUserService
{
    public function tablesReady(): bool
    {
        return Schema::hasTable('radcheck') && Schema::hasTable('radreply');
    }

    public function syncCustomer(Customer $customer): array
    {
        if (! $this->tablesReady()) {
            return ['synced' => false, 'reason' => 'missing_radius_tables'];
        }

        $customer->loadMissing(['package', 'router']);

        if (! $customer->pppoe_user) {
            return ['synced' => false, 'reason' => 'missing_pppoe_user'];
        }

        if (! $customer->router?->radius_enabled) {
            return ['synced' => false, 'reason' => 'radius_disabled'];
        }

        return DB::transaction(function () use ($customer) {
            $username = $customer->pppoe_user;

            DB::table('radcheck')
                ->where('username', $username)
                ->whereIn('attribute', ['Cleartext-Password', 'Auth-Type'])
                ->delete();

            DB::table('radreply')
                ->where('username', $username)
                ->whereIn('attribute', ['Mikrotik-Group', 'Mikrotik-Rate-Limit', 'Service-Type', 'Framed-Protocol', 'Framed-IP-Address'])
                ->delete();

            if (in_array($customer->status, ['inactive', 'terminated'], true)) {
                DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => 'Reject',
                ]);

                return ['synced' => true, 'mode' => 'reject'];
            }

            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $customer->pppoe_password ?: '',
            ]);

            $profile = $this->profileName($customer);
            $replies = [
                ['attribute' => 'Service-Type', 'op' => ':=', 'value' => 'Framed-User'],
                ['attribute' => 'Framed-Protocol', 'op' => ':=', 'value' => 'PPP'],
                ['attribute' => 'Mikrotik-Group', 'op' => ':=', 'value' => $profile],
            ];

            $rateLimit = $customer->status === 'isolated' ? null : $customer->package?->rate_limit;
            if ($rateLimit) {
                $replies[] = ['attribute' => 'Mikrotik-Rate-Limit', 'op' => ':=', 'value' => $rateLimit];
            }

            // Assign static IP via RADIUS if pppoe_remote_address is set
            if ($customer->pppoe_remote_address) {
                $replies[] = ['attribute' => 'Framed-IP-Address', 'op' => ':=', 'value' => $customer->pppoe_remote_address];
            }

            foreach ($replies as $reply) {
                DB::table('radreply')->insert([
                    'username' => $username,
                    'attribute' => $reply['attribute'],
                    'op' => $reply['op'],
                    'value' => $reply['value'],
                ]);
            }

            return ['synced' => true, 'mode' => 'accept', 'profile' => $profile];
        });
    }

    public function syncRouter(Router $router): array
    {
        $customers = $router->customers()->ebilling()->with(['package', 'router'])->get();
        $results = $customers->map(fn (Customer $customer) => $this->syncCustomer($customer));

        $router->update(['last_radius_synced_at' => now()]);

        return [
            'total' => $customers->count(),
            'synced' => $results->where('synced', true)->count(),
            'skipped' => $results->where('synced', false)->count(),
            'reasons' => $this->reasonSummary($results),
        ];
    }

    public function upsertNas(Router $router): void
    {
        if (! Schema::hasTable('nas') || ! $router->radius_secret) {
            return;
        }

        DB::table('nas')->updateOrInsert(
            ['nasname' => $this->nasAddress($router)],
            [
                'shortname' => $router->name,
                'type' => 'other',
                'secret' => $router->radius_secret,
                'description' => "Skynet E-Billing router {$router->name}",
            ]
        );
    }

    private function profileName(Customer $customer): string
    {
        if ($customer->status === 'isolated') {
            return $customer->router?->isolation_profile ?: 'isolirebilling';
        }

        return $customer->package?->mikrotik_profile
            ?: $customer->mikrotik_profile
            ?: 'default';
    }

    private function nasAddress(Router $router): string
    {
        if ($router->vpn_enabled && $router->vpn_address) {
            return str_contains($router->vpn_address, '/')
                ? str($router->vpn_address)->before('/')->toString()
                : $router->vpn_address;
        }

        return $router->ip_address;
    }

    private function reasonSummary(Collection $results): array
    {
        return $results
            ->where('synced', false)
            ->pluck('reason')
            ->filter()
            ->countBy()
            ->all();
    }
}
