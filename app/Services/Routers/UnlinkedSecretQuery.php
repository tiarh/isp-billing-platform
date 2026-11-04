<?php

namespace App\Services\Routers;

use App\Models\Router;
use App\Models\RouterStagedCustomer;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Secret yang ada di router, belum terhubung ke pelanggan eBilling (status `unmatched`).
 * Himpunan yang sama dengan hitungan "secret belum terhubung" di Ringkasan router.
 */
class UnlinkedSecretQuery
{
    public const PER_PAGE = 25;

    public const MAX_SEARCH = 64;

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function paginate(Router $router, ?string $search): LengthAwarePaginator
    {
        $query = RouterStagedCustomer::query()
            ->where('router_id', $router->id)
            ->where('status', 'unmatched')
            ->whereNull('matched_customer_id');

        $search = trim((string) $search);
        if ($search !== '') {
            $query->where('pppoe_user', 'like', '%'.$this->escapeLike(mb_substr($search, 0, self::MAX_SEARCH)).'%');
        }

        return $query
            ->orderBy('pppoe_user')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->through(fn (RouterStagedCustomer $row): array => $this->row($row));
    }

    /**
     * Sengaja daftar kolom eksplisit: tidak pernah raw_payload, comment, atau password.
     *
     * @return array<string, mixed>
     */
    private function row(RouterStagedCustomer $row): array
    {
        $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
        $ip = $payload['remote-address'] ?? null;

        return [
            'id' => $row->id,
            'pppoe_user' => $row->pppoe_user,
            'profile' => $row->profile,
            'disabled' => (bool) $row->disabled,
            'last_seen_at' => $row->last_seen_at?->toIso8601String(),
            'ip' => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null,
        ];
    }

    /** Username memuat `_` dan `%`, jadi harus dianggap huruf biasa di LIKE. */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
