<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

class TrafficPppoeKeyService
{
    private const MAP_KEY = 'monitoring.traffic.pppoe_hash_map.v1';

    public function hash(?string $pppoe): ?string
    {
        $pppoe = mb_strtolower(trim((string) $pppoe));
        $secret = (string) config('services.monitoring.traffic.hash_secret', '');

        if ($pppoe === '' || $secret === '') {
            return null;
        }

        return hash_hmac('sha256', $pppoe, $secret);
    }

    /** @return array<string, array{customer_id:int,router_id:int|null}> */
    public function mapping(): array
    {
        return Cache::remember(self::MAP_KEY, now()->addMinutes(10), function (): array {
            $map = [];
            $conflicts = [];

            Customer::query()
                ->whereIn('status', ['active', 'isolated'])
                ->whereNotNull('pppoe_user')
                ->where('pppoe_user', '<>', '')
                ->orderBy('id')
                ->get(['id', 'router_id', 'pppoe_user'])
                ->each(function (Customer $customer) use (&$map, &$conflicts): void {
                    $key = $this->hash($customer->pppoe_user);
                    if ($key === null || isset($conflicts[$key])) {
                        return;
                    }

                    if (isset($map[$key]) && $map[$key]['customer_id'] !== (int) $customer->id) {
                        unset($map[$key]);
                        $conflicts[$key] = true;

                        return;
                    }

                    $map[$key] = [
                        'customer_id' => (int) $customer->id,
                        'router_id' => $customer->router_id === null ? null : (int) $customer->router_id,
                    ];
                });

            return $map;
        });
    }

    /** @return array{customer_id:int,router_id:int|null}|null */
    public function resolve(?string $hash): ?array
    {
        $hash = trim((string) $hash);
        if ($hash === '' || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return null;
        }

        return $this->mapping()[$hash] ?? null;
    }

    /**
     * Resolve a unique PPPoE identity against the router that supplied it.
     * A NULL customer router is deliberately late-bound for read-only traffic;
     * a known router assignment must still match exactly.
     *
     * @return array{customer_id:int,router_id:int}|null
     */
    public function resolveForRouter(?string $hash, int $routerId): ?array
    {
        if ($routerId < 1) {
            return null;
        }

        $owner = $this->resolve($hash);
        if ($owner === null || ($owner['router_id'] !== null && $owner['router_id'] !== $routerId)) {
            return null;
        }

        return [
            'customer_id' => $owner['customer_id'],
            'router_id' => $routerId,
        ];
    }
}
