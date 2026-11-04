<?php

namespace App\Services\Routers;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Models\User;
use App\Services\Migration\MigrationNormalizer;

/**
 * Semua alasan menolak "Hapus secret" yang cukup dibaca dari database (tanpa menyentuh router).
 * Tiap penolakan = ['status' => http, 'code' => kode, 'message' => teks untuk admin].
 */
class UnlinkedSecretDeleteGuard
{
    public const FLAG = 'router_secret_delete_enabled';

    /** Status baris secret selama penghapusan berjalan (klaim); `UnlinkedSecretPrefill::isOpen` menolaknya. */
    public const CLAIMED = 'deleting';

    /** Hanya superadmin dan NOC (jawaban owner 2026-11-02), bukan semua admin penuh. */
    public function actorAllowed(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->isNoc());
    }

    public function flagEnabled(): bool
    {
        return MigrationNormalizer::boolSetting(self::FLAG, false);
    }

    public function readOnly(): bool
    {
        return MigrationNormalizer::boolSetting('control_plane.device_read_only_mode', true);
    }

    /** Tombol "Hapus secret" tampil hanya kalau semua ini terpenuhi. */
    public function canShow(Router $router, ?User $user): bool
    {
        return $this->actorAllowed($user) && $this->gate($router) === null;
    }

    /** @return array{status: int, code: string, message: string}|null */
    public function gate(Router $router): ?array
    {
        if ($this->readOnly()) {
            return $this->refuse(423, 'read_only', 'Mode aman perangkat aktif. Tidak ada yang dihapus.');
        }
        if (! $this->flagEnabled()) {
            return $this->refuse(423, 'flag_off', 'Hapus secret belum diaktifkan.');
        }
        if (! $router->is_active) {
            return $this->refuse(409, 'router_archived', 'Router ini sudah diarsipkan.');
        }
        $hasVpn = $router->vpn_enabled && trim((string) $router->vpn_address) !== '';
        if (trim((string) $router->ip_address) === '' && ! $hasVpn) {
            return $this->refuse(409, 'unreachable', 'Router tidak bisa dihubungi. Tidak ada yang dihapus.');
        }

        return null;
    }

    /**
     * Mirip pelanggan (RT/RW atau akhiran) TIDAK menolak di sini; itu peringatan, lihat lookalikeRefusal().
     *
     * @param  string|null  $typed  username yang diketik admin; null = belum diketik (cek dialog)
     * @param  bool  $claimed  baris sudah kita tandai "deleting" (klaim di UnlinkedSecretDeleter); itu status yang diharapkan
     * @return array{status: int, code: string, message: string}|null
     */
    public function refusal(Router $router, RouterStagedCustomer $row, ?string $typed, bool $claimed = false): ?array
    {
        $refusal = $this->gate($router);
        if ($refusal !== null) {
            return $refusal;
        }

        $expectedStatus = $claimed ? self::CLAIMED : 'unmatched';
        if ((int) $row->router_id !== (int) $router->id || $row->status !== $expectedStatus || $row->matched_customer_id !== null) {
            return $this->refuse(409, 'not_unmatched', UnlinkedSecretPrefill::GONE_MESSAGE);
        }
        if ($typed !== null && $typed !== $row->pppoe_user) {
            return $this->refuse(422, 'confirm_mismatch', 'Username yang diketik tidak sama. Tidak ada yang dihapus.');
        }

        return $this->identityRefusal((string) $row->pppoe_user);
    }

    /** @return array{status: int, code: string, message: string}|null */
    private function identityRefusal(string $username): ?array
    {
        $upper = strtoupper($username);

        // Pelanggan mana pun (router lain, sudah dihapus, IMP-) dengan username yang sama.
        $customer = Customer::withTrashed()->with('router:id,name')->whereRaw('UPPER(pppoe_user) = ?', [$upper])->first();
        if ($customer !== null) {
            $routerName = $customer->router?->name ?? '-';

            return $this->refuse(409, 'customer_exists', "Username ini dipakai pelanggan #{$customer->id} ({$routerName}). Tidak bisa dihapus dari sini.");
        }

        $draft = PsbDraft::query()->whereRaw('UPPER(pppoe_user) = ?', [$upper])->where('status', '!=', PsbDraft::STATUS_CANCELLED)->first();
        if ($draft !== null) {
            return $this->refuse(409, 'psb_draft', "Username ini sedang dipakai PSB #{$draft->id}.");
        }

        $migration = CustomerMigration::query()->whereRaw('UPPER(pppoe_user) = ?', [$upper])
            ->whereNotIn('status', CustomerMigration::CLOSED_STATUSES)->first();
        if ($migration !== null) {
            return $this->refuse(409, 'open_migration', "Username ini sedang dipakai migrasi #{$migration->id}.");
        }

        return null;
    }

    /**
     * Pelanggan yang mirip = peringatan. Hapus hanya boleh kalau admin sudah mencentang
     * "Saya sudah cek, ini bukan pelanggan itu".
     *
     * @param  list<array{id: int, text: string}>  $lookalikes
     * @return array{status: int, code: string, message: string}|null
     */
    public function lookalikeRefusal(array $lookalikes, bool $acknowledged): ?array
    {
        if ($lookalikes === [] || $acknowledged) {
            return null;
        }

        return $this->refuse(409, 'lookalike_unconfirmed', 'Username ini mirip pelanggan lain ('.implode(', ', array_map(fn (array $l): string => '#'.$l['id'], $lookalikes)).'). Centang dulu bahwa sudah dicek.');
    }

    /** @return array{status: int, code: string, message: string} */
    public function refuse(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'code' => $code, 'message' => $message];
    }
}
