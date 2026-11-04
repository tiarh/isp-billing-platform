<?php

namespace App\Services\Routers;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;

/**
 * Pelanggan yang usernamenya "mirip" dengan username sebuah secret (awalan sama, dan RT+RW sama
 * atau akhiran sama) (salah ketik RT/RW atau akhiran ODP diganti). Sama dengan aturan
 * `customers:reconcile-mikrotik-missing` dan `PppoeManualRenameReconcileService`,
 * jadi secret seperti ini bisa jadi secret lama milik pelanggan yang sudah ada.
 */
class UnlinkedSecretLookalike
{
    /** @return array{0: string, 1: string, 2: string, 3: string}|null  [awalan, RT, RW, akhiran] huruf besar */
    public static function parts(string $username): ?array
    {
        if (! preg_match('/^(.+?)_RT0*(\d+)_RW0*(\d+)(.*)$/i', trim($username), $m)) {
            return null;
        }

        return [strtoupper($m[1]), (string) ((int) $m[2]), (string) ((int) $m[3]), strtoupper($m[4])];
    }

    /** Pelanggan pertama yang mirip (termasuk yang sudah dihapus dan IMP-), atau null. Username persis sama tidak dihitung. */
    public function find(string $username): ?Customer
    {
        return $this->findAll($username)->first();
    }

    /**
     * Semua pelanggan yang mirip (paling banyak 10), lengkap dengan nama router.
     *
     * @return Collection<int, Customer>
     */
    public function findAll(string $username): Collection
    {
        $mine = self::parts($username);
        if ($mine === null) {
            return new Collection;
        }

        // LIKE hanya menyaring kasar ("_" boleh cocok apa saja); keputusan akhir di PHP.
        $candidates = Customer::withTrashed()
            ->with('router:id,name')
            ->where('pppoe_user', 'like', $mine[0].'_RT%')
            ->orderBy('id')
            ->get(['id', 'pppoe_user', 'router_id']);

        return $candidates->filter(function (Customer $customer) use ($mine, $username): bool {
            $theirs = self::parts((string) $customer->pppoe_user);
            if ($theirs === null || strcasecmp((string) $customer->pppoe_user, $username) === 0 || $theirs[0] !== $mine[0]) {
                return false;
            }

            return ($theirs[1] === $mine[1] && $theirs[2] === $mine[2]) || $theirs[3] === $mine[3];
        })->take(10)->values();
    }

    /**
     * Untuk dialog: satu baris per pelanggan yang mirip.
     *
     * @return list<array{id: int, username: string, router: string, text: string}>
     */
    public function lines(string $username): array
    {
        return $this->findAll($username)->map(function (Customer $customer): array {
            $router = $customer->router?->name ?? '-';

            return [
                'id' => (int) $customer->id,
                'username' => (string) $customer->pppoe_user,
                'router' => $router,
                'text' => "Mirip pelanggan #{$customer->id} ({$router})",
            ];
        })->all();
    }

    public function hint(string $username): ?string
    {
        $customer = $this->find($username);

        return $customer ? "Mirip pelanggan #{$customer->id} ({$customer->pppoe_user}). Cek dulu, mungkin secret lama pelanggan itu." : null;
    }
}
