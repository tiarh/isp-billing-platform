<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\PsbDraft;
use Illuminate\Support\Facades\Schema;

class PsbDraftCustomerSyncService
{
    /**
     * @return array{status: string, updated: array<int, string>, skipped: array<int, string>}
     */
    public function sync(PsbDraft $draft): array
    {
        $updated = [];
        $skipped = [];

        if (! $draft->customer_id) {
            return $this->mark($draft, 'draft_only', [], ['customer_id kosong']);
        }

        $customer = Customer::query()->find($draft->customer_id);
        if (! $customer) {
            return $this->mark($draft, 'failed', [], ['customer tidak ditemukan']);
        }

        $data = [];
        $this->copyIfSafe($data, $updated, $skipped, $customer, 'ktp_photo_url', $this->safeKtpPath($draft));
        $this->copyIfSafe($data, $updated, $skipped, $customer, 'odp_photo_url', $this->safeSnPhotoPath($draft));

        if (Schema::hasColumn('customers', 'onu_serial') && $draft->sn) {
            $sn = strtoupper(preg_replace('/\s+/', '', (string) $draft->sn) ?? (string) $draft->sn);
            if ((string) ($customer->onu_serial ?? '') !== $sn) {
                $data['onu_serial'] = $sn;
                $updated[] = 'onu_serial';
            }
        }
        if (Schema::hasColumn('customers', 'odp_name') && $draft->odp && (string) ($customer->odp_name ?? '') === '') {
            $data['odp_name'] = $draft->odp;
            $updated[] = 'odp_name';
        }
        if (Schema::hasColumn('customers', 'nik') && $draft->nik && (string) ($customer->nik ?? '') === '') {
            $data['nik'] = $draft->nik;
            $updated[] = 'nik';
        }

        if ($data !== []) {
            $customer->forceFill($data)->save();
        }

        return $this->mark($draft, $updated === [] ? 'synced' : 'synced', $updated, $skipped);
    }

    private function copyIfSafe(array &$data, array &$updated, array &$skipped, Customer $customer, string $column, ?string $path): void
    {
        if (! Schema::hasColumn('customers', $column) || ! $path) {
            return;
        }

        $current = (string) ($customer->{$column} ?? '');
        if ($current !== '' && ! str_contains($current, 'psb/') && ! str_contains($current, '/psb/')) {
            $skipped[] = $column.'_manual';

            return;
        }

        if ($current !== $path) {
            $data[$column] = $path;
            $updated[] = $column;
        }
    }

    private function safeKtpPath(PsbDraft $draft): ?string
    {
        $path = trim((string) ($draft->ktp_photo_path ?? ''));
        if ($path === '' || preg_match('#/(sn_|router_)#i', $path)) {
            return null;
        }

        $meta = is_array($draft->meta) ? $draft->meta : [];
        $cls = $meta['photo_class'] ?? [];
        if (is_array($cls) && array_key_exists('ktp_score', $cls) && $cls['ktp_score'] !== null && (int) $cls['ktp_score'] < 25) {
            return null;
        }

        return $path;
    }

    private function safeSnPhotoPath(PsbDraft $draft): ?string
    {
        $path = trim((string) ($draft->router_photo_path ?? ''));
        if ($path === '') {
            return null;
        }
        if (preg_match('#/ktp_#i', $path) && ! preg_match('#/(sn_|router_)#i', $path)) {
            return null;
        }

        return $path;
    }

    /**
     * @param  array<int, string>  $updated
     * @param  array<int, string>  $skipped
     * @return array{status: string, updated: array<int, string>, skipped: array<int, string>}
     */
    private function mark(PsbDraft $draft, string $status, array $updated, array $skipped): array
    {
        $meta = is_array($draft->meta) ? $draft->meta : [];
        $meta['psb_customer_sync'] = [
            'status' => $status,
            'updated' => $updated,
            'skipped' => $skipped,
            'checked_at' => now()->toIso8601String(),
        ];
        $draft->meta = $meta;
        $draft->save();

        return $meta['psb_customer_sync'];
    }
}
