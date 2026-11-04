<?php

namespace App\Services\Routers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tambah pelanggan dari secret yang belum terhubung. Hanya database:
 * tidak ada perintah ke MikroTik, password tidak disentuh.
 */
class UnlinkedSecretPrefill
{
    public const GONE_MESSAGE = 'Secret ini sudah terhubung atau sudah hilang dari router.';

    public const CHANGED_MESSAGE = 'Secret sudah berubah. Buka lagi dari halaman router.';

    /**
     * Dipakai CustomerController::create.
     * null = tidak ada `?staged=` (atau bukan admin penuh), array = isi awal form.
     * Secret sudah tidak bisa dipakai = kembali ke halaman router dengan pesan.
     *
     * @return array<string, mixed>|null
     */
    public function fromRequest(Request $request): ?array
    {
        if (! $request->filled('staged') || ! $request->user()?->isGlobalAdmin()) {
            return null;
        }

        $row = RouterStagedCustomer::query()->with('router')->find((int) $request->input('staged'));
        $prefill = $row ? $this->forCreate($row) : null;
        if ($prefill !== null) {
            return $prefill;
        }

        $back = $row?->router_id ? route('routers.show', $row->router_id) : route('routers.index');

        throw new HttpResponseException(redirect($back)->with('error', self::GONE_MESSAGE));
    }

    public function redirectAfterLink(Customer $customer): RedirectResponse
    {
        return redirect()
            ->route('routers.show', ['router' => $customer->router_id, 'tab' => 'customers', 'section' => 'unlinked'])
            ->with('success', "Pelanggan dibuat dan terhubung ke secret {$customer->pppoe_user}.");
    }

    /** @return array<string, mixed>|null */
    public function forCreate(RouterStagedCustomer $row): ?array
    {
        /** @var Router|null $router */
        $router = $row->router;
        if (! $router || ! $router->is_active || ! $this->isOpen($row)) {
            return null;
        }

        $profile = (string) $row->profile;
        [$packageId, $hint] = $this->packageFor($router, $profile);
        $isolir = $profile !== '' && $profile === (string) $router->isolation_profile;
        if ($isolir) {
            $packageId = null;
            $hint = 'Secret sedang di profil isolir, pilih paket aslinya.';
        }

        return [
            'router_staged_customer_id' => $row->id,
            'router_id' => $router->id,
            'router_name' => $router->name,
            'pppoe_user' => $row->pppoe_user,
            'profile' => $profile,
            'package_id' => $packageId,
            'package_hint' => $hint,
            // Saran saja (tidak memblokir): mungkin ini secret lama pelanggan yang sudah ada.
            'lookalike_hint' => app(UnlinkedSecretLookalike::class)->hint((string) $row->pppoe_user),
            // Aktif hanya kalau secret menyala dan tidak di profil isolir.
            'status' => (! $row->disabled && ! $isolir) ? 'active' : 'pending_installation',
        ];
    }

    /**
     * Buat pelanggan dan hubungkan ke baris secret dalam satu transaksi.
     *
     * @param  Closure(): Customer  $create
     */
    public function createLinked(int $stagedId, Closure $create): Customer
    {
        return DB::transaction(function () use ($stagedId, $create): Customer {
            $row = RouterStagedCustomer::query()->whereKey($stagedId)->lockForUpdate()->first();
            $refusal = $row ? $this->saveRefusal($row) : self::CHANGED_MESSAGE;
            if ($refusal !== null) {
                throw ValidationException::withMessages(['router_staged_customer_id' => $refusal]);
            }

            $customer = $create();

            // Sama dengan RouterSyncService::markStagedSecretMatched.
            $row->forceFill(['matched_customer_id' => $customer->id, 'status' => 'matched'])->save();

            return $customer;
        });
    }

    /** Alasan baris tidak boleh dipakai saat simpan (null = boleh). */
    public function saveRefusal(RouterStagedCustomer $row): ?string
    {
        if (! $this->isOpen($row)) {
            return self::CHANGED_MESSAGE;
        }

        /** @var Router|null $router */
        $router = $row->router;

        return $router?->is_active ? null : 'Router ini sudah diarsipkan.';
    }

    /** Baris masih "belum terhubung". */
    public function isOpen(RouterStagedCustomer $row): bool
    {
        return $row->status === 'unmatched' && $row->matched_customer_id === null;
    }

    /** @return array{0: int|null, 1: string|null} */
    private function packageFor(Router $router, string $profile): array
    {
        $packages = Package::query()
            ->where('router_id', $router->id)
            ->where('is_active', true)
            ->where('price', '>', 0)
            ->get(['id', 'mikrotik_profile'])
            ->filter(fn (Package $p): bool => $profile !== '' && (string) $p->mikrotik_profile === $profile)
            ->values();

        return match ($packages->count()) {
            1 => [(int) $packages[0]->id, null],
            0 => [null, "Belum ada paket untuk profil {$profile}."],
            default => [null, "Profil {$profile} dipakai {$packages->count()} paket, pilih paket."],
        };
    }
}
