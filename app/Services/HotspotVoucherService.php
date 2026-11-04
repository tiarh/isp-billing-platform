<?php

namespace App\Services;

use App\Models\HotspotVoucher;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HotspotVoucherService
{
    public function generateBatch(array $payload): Collection
    {
        $router = ! empty($payload['router_id']) ? Router::find($payload['router_id']) : null;
        $package = ! empty($payload['package_id']) ? Package::find($payload['package_id']) : null;
        $batchCode = $payload['batch_code'] ?? 'VCR-'.now()->format('Ymd-His');
        $count = (int) ($payload['count'] ?? 1);

        return DB::transaction(function () use ($payload, $router, $package, $batchCode, $count) {
            return collect(range(1, $count))->map(function () use ($payload, $router, $package, $batchCode) {
                $username = $this->uniqueVoucherUsername($payload['prefix'] ?? 'HS');
                $voucher = HotspotVoucher::create([
                    'router_id' => $router?->id,
                    'package_id' => $package?->id,
                    'batch_code' => $batchCode,
                    'username' => $username,
                    'password' => $payload['password_same_as_username'] ?? true
                        ? $username
                        : strtoupper(Str::random((int) ($payload['password_length'] ?? 6))),
                    'profile' => $payload['profile'] ?? $package?->mikrotik_profile,
                    'rate_limit' => $payload['rate_limit'] ?? $package?->rate_limit,
                    'price' => (int) ($payload['price'] ?? $package?->price ?? 0),
                    'duration_minutes' => $payload['duration_minutes'] ?? null,
                    'quota_bytes' => $payload['quota_bytes'] ?? null,
                    'meta' => [
                        'generated_by' => auth()->id(),
                    ],
                ]);

                $this->syncToRadius($voucher);

                return $voucher;
            });
        });
    }

    public function syncToRadius(HotspotVoucher $voucher): array
    {
        if (! Schema::hasTable('radcheck') || ! Schema::hasTable('radreply')) {
            return ['synced' => false, 'reason' => 'missing_radius_tables'];
        }

        DB::transaction(function () use ($voucher) {
            DB::table('radcheck')->where('username', $voucher->username)->delete();
            DB::table('radreply')->where('username', $voucher->username)->delete();

            if ($voucher->status === 'disabled') {
                DB::table('radcheck')->insert([
                    'username' => $voucher->username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => 'Reject',
                ]);

                return;
            }

            DB::table('radcheck')->insert([
                'username' => $voucher->username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $voucher->password,
            ]);

            $replies = [
                ['Service-Type', ':=', 'Login-User'],
            ];

            if ($voucher->profile) {
                $replies[] = ['Mikrotik-Group', ':=', $voucher->profile];
            }

            if ($voucher->rate_limit) {
                $replies[] = ['Mikrotik-Rate-Limit', ':=', $voucher->rate_limit];
            }

            if ($voucher->duration_minutes) {
                $replies[] = ['Session-Timeout', ':=', (string) ($voucher->duration_minutes * 60)];
            }

            if ($voucher->quota_bytes) {
                $replies[] = ['Mikrotik-Total-Limit', ':=', (string) $voucher->quota_bytes];
            }

            foreach ($replies as [$attribute, $op, $value]) {
                DB::table('radreply')->insert([
                    'username' => $voucher->username,
                    'attribute' => $attribute,
                    'op' => $op,
                    'value' => $value,
                ]);
            }
        });

        return ['synced' => true];
    }

    public function disable(HotspotVoucher $voucher): array
    {
        $voucher->update(['status' => 'disabled']);

        return $this->syncToRadius($voucher);
    }

    protected function uniqueVoucherUsername(string $prefix): string
    {
        do {
            $username = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $prefix)).strtoupper(Str::random(6));
        } while (HotspotVoucher::where('username', $username)->exists());

        return $username;
    }
}
