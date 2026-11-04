<?php

namespace App\Services\Monitoring;

use App\Models\Customer;

/**
 * Maps a PPPoE username on a router to customers.id. Shared by the daily usage
 * rollup (M-4c-1) and the router detail read model (M-4c-2).
 *
 * Exact match first, then lowercase; ambiguous lowercase matches stay unlinked.
 * Customers with a NULL router_id are late-bound and match any router (mirrors
 * TrafficPppoeKeyService). The index is built once per instance.
 */
class PppoeCustomerMatcher
{
    /** @var array<int, array{exact: array<string, int>, lower: array<string, int|null>}>|null */
    private ?array $customerIndex = null;

    public function resolve(int $routerId, string $user): ?int
    {
        $index = $this->customerIndex();
        $lower = mb_strtolower(trim($user));

        foreach ([$routerId, 0] as $scope) {
            $hit = $index[$scope]['exact'][$user] ?? null;
            if ($hit !== null) {
                return $hit;
            }
        }
        foreach ([$routerId, 0] as $scope) {
            if (array_key_exists($lower, $index[$scope]['lower'] ?? [])) {
                $hit = $index[$scope]['lower'][$lower];
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * Active / isolated customers bound to this router that have a PPPoE user.
     *
     * @return list<array{id: int, name: string, code: string, pppoe_user: string, status: string}>
     */
    public function customersForRouter(int $routerId): array
    {
        return Customer::query()
            ->where('router_id', $routerId)
            ->whereIn('status', ['active', 'isolated'])
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '<>', '')
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'pppoe_user', 'status'])
            ->map(static fn (Customer $c): array => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'code' => (string) $c->code,
                'pppoe_user' => (string) $c->pppoe_user,
                'status' => (string) $c->status,
            ])
            ->all();
    }

    /** @return array<int, array{exact: array<string, int>, lower: array<string, int|null>}> scope 0 = customers without a router */
    private function customerIndex(): array
    {
        if ($this->customerIndex !== null) {
            return $this->customerIndex;
        }

        $index = [];
        Customer::query()
            ->whereIn('status', ['active', 'isolated'])
            ->whereNotNull('pppoe_user')
            ->where('pppoe_user', '<>', '')
            ->orderBy('id')
            ->get(['id', 'router_id', 'pppoe_user'])
            ->each(function (Customer $c) use (&$index): void {
                $scope = $c->router_id ? (int) $c->router_id : 0;
                $name = (string) $c->pppoe_user;
                $lower = mb_strtolower(trim($name));
                $index[$scope]['exact'][$name] ??= (int) $c->id;
                if (array_key_exists($lower, $index[$scope]['lower'] ?? []) && $index[$scope]['lower'][$lower] !== (int) $c->id) {
                    $index[$scope]['lower'][$lower] = null; // ambiguous
                } else {
                    $index[$scope]['lower'][$lower] = (int) $c->id;
                }
            });

        return $this->customerIndex = $index;
    }
}
