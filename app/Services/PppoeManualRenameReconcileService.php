<?php

namespace App\Services;

use App\Exceptions\LockBusyException;
use App\Models\Customer;
use App\Models\PsbDraft;
use App\Models\RouterStagedCustomer;
use App\Support\CacheLockWait;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PppoeManualRenameReconcileService
{
    public function __construct(private readonly MikrotikService $mikrotik) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(Customer $customer, ?string $requestedCandidate = null, bool $live = false): array
    {
        $old = strtoupper(trim((string) $customer->pppoe_user));
        $identity = $this->identity($old);
        $candidateRows = collect();
        if ($identity && $customer->router_id) {
            $candidateRows = RouterStagedCustomer::query()
                ->where('router_id', $customer->router_id)
                ->where('last_seen_at', '>=', now()->subDays(45))
                ->where('pppoe_user', 'like', $identity['prefix'].'_RT%')
                ->get()
                ->filter(fn (RouterStagedCustomer $row): bool => $this->sameSubscriberWithChangedSuffix(
                    $identity,
                    strtoupper(trim((string) $row->pppoe_user))
                ))
                ->when(
                    trim((string) $requestedCandidate) !== '',
                    fn ($rows) => $rows->filter(
                        fn (RouterStagedCustomer $row): bool => strcasecmp($row->pppoe_user, (string) $requestedCandidate) === 0
                    )
                )
                ->unique(fn (RouterStagedCustomer $row) => strtoupper($row->pppoe_user))
                ->values();
        }

        $candidate = $candidateRows->count() === 1 ? $candidateRows->first() : null;
        $new = strtoupper(trim((string) ($candidate?->pppoe_user ?? $requestedCandidate ?? '')));
        $newIdentity = $this->identity($new);
        $draft = $this->matchingDraft($customer, $newIdentity['suffix'] ?? null);
        $ownedByOther = $new !== '' && Customer::withTrashed()
            ->whereRaw('UPPER(pppoe_user) = ?', [$new])
            ->whereKeyNot($customer->id)
            ->exists();

        $result = [
            'eligible' => $candidate !== null && $draft !== null && ! $ownedByOther,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'router_id' => $customer->router_id,
            'old_pppoe_user' => $old,
            'new_pppoe_user' => $new ?: null,
            'candidate_count' => $candidateRows->count(),
            'snapshot_id' => $candidate?->id,
            'snapshot_at' => $candidate?->last_seen_at?->toIso8601String(),
            'draft_id' => $draft?->id,
            'draft_odp' => $draft?->odp,
            'owned_by_other_customer' => $ownedByOther,
            'live_checked' => false,
            'old_secret_live' => null,
            'new_secret_live' => null,
            'active_connection' => null,
            'live_profile' => null,
            'live_error' => null,
            'device_write' => false,
        ];

        if ($live && $result['eligible']) {
            $result = array_merge($result, $this->liveEvidence($customer, $old, $new));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(Customer $customer, string $candidate, string $reason, ?int $actorId = null): array
    {
        if (mb_strlen(trim($reason)) < 8) {
            throw new \InvalidArgumentException('Alasan approval minimal 8 karakter.');
        }

        $candidate = strtoupper(trim($candidate));
        if (strcasecmp((string) $customer->pppoe_user, $candidate) === 0) {
            return ['applied' => false, 'idempotent' => true, 'customer_id' => $customer->id, 'device_write' => false];
        }

        $preview = $this->preview($customer, $candidate, true);
        if (! $preview['eligible']) {
            throw new \RuntimeException('Preflight gagal: kandidat snapshot/draft tidak unik atau username sudah dipakai.');
        }
        if (! $preview['live_checked'] || ! $preview['new_secret_live'] || $preview['old_secret_live']) {
            throw new \RuntimeException('Preflight live gagal: secret baru wajib ada dan secret lama wajib sudah tidak ada.');
        }

        $lock = Cache::lock('pppoe-manual-rename-customer-'.$customer->id, 60);
        if (! CacheLockWait::acquire($lock, 10)) {
            throw new LockBusyException('Customer sedang direconcile proses lain.');
        }

        try {
            return DB::transaction(function () use ($customer, $candidate, $reason, $actorId, $preview): array {
                $locked = Customer::withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if (strcasecmp((string) $locked->pppoe_user, $candidate) === 0) {
                    return ['applied' => false, 'idempotent' => true, 'customer_id' => $locked->id, 'device_write' => false];
                }
                if (strcasecmp((string) $locked->pppoe_user, (string) $preview['old_pppoe_user']) !== 0) {
                    throw new \RuntimeException('PPPoE DB berubah setelah preflight; ulangi dry-run.');
                }

                $before = [
                    'pppoe_user' => $locked->pppoe_user,
                    'mikrotik_sync_status' => $locked->mikrotik_sync_status,
                ];
                $after = [
                    'pppoe_user' => $candidate,
                    'mikrotik_sync_status' => 'synced',
                    'mikrotik_profile' => $preview['live_profile'] ?: $locked->mikrotik_profile,
                ];
                $key = hash('sha256', implode('|', [$locked->id, $before['pppoe_user'], $candidate]));

                $locked->forceFill([
                    ...$after,
                    'mikrotik_synced_at' => now(),
                    'mikrotik_sync_checked_at' => now(),
                ])->save();

                $draft = PsbDraft::query()->whereKey($preview['draft_id'])->lockForUpdate()->first();
                if ($draft) {
                    $meta = is_array($draft->meta) ? $draft->meta : [];
                    $meta['pppoe_manual_rename_reconcile'] = [
                        'idempotency_key' => $key,
                        'before' => $before,
                        'after' => $after,
                        'reason' => $reason,
                        'actor_id' => $actorId,
                        'at' => now()->toIso8601String(),
                        'snapshot_id' => $preview['snapshot_id'],
                        'live_verified' => true,
                        'device_write' => false,
                        'rollback_hint' => "Restore customers.pppoe_user ke {$before['pppoe_user']} setelah verifikasi secret live.",
                    ];
                    $draft->forceFill([
                        'pppoe_user' => $candidate,
                        'mikrotik_profile' => $after['mikrotik_profile'],
                        'meta' => $meta,
                    ])->save();
                }

                return [
                    'applied' => true,
                    'idempotent' => false,
                    'customer_id' => $locked->id,
                    'draft_id' => $draft?->id,
                    'before' => $before,
                    'after' => $after,
                    'idempotency_key' => $key,
                    'device_write' => false,
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function liveEvidence(Customer $customer, string $old, string $new): array
    {
        try {
            $this->mikrotik->connect($customer->router, ['timeout' => 15, 'attempts' => 1]);
            $oldSecret = $this->mikrotik->getPPPSecret($old);
            $newSecret = $this->mikrotik->getPPPSecret($new);
            $active = collect($this->mikrotik->getActiveConnections(false))
                ->contains(fn (array $row): bool => strcasecmp((string) ($row['name'] ?? ''), $new) === 0);

            return [
                'live_checked' => true,
                'old_secret_live' => $oldSecret !== null,
                'new_secret_live' => $newSecret !== null,
                'active_connection' => $active,
                'live_profile' => $newSecret['profile'] ?? null,
                'live_error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'live_checked' => false,
                'old_secret_live' => null,
                'new_secret_live' => null,
                'active_connection' => null,
                'live_profile' => null,
                'live_error' => $e->getMessage(),
            ];
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    private function matchingDraft(Customer $customer, ?string $newSuffix): ?PsbDraft
    {
        if (! $newSuffix) {
            return null;
        }

        return PsbDraft::query()
            ->where('router_id', $customer->router_id)
            ->where(function ($query) use ($customer): void {
                $query->where('customer_id', $customer->id)
                    ->orWhereRaw('UPPER(full_name) = ?', [strtoupper($customer->name)]);
            })
            ->latest('id')
            ->get()
            ->first(fn (PsbDraft $draft): bool => strcasecmp($this->odpSuffix((string) $draft->odp), $newSuffix) === 0);
    }

    /**
     * @return array{prefix: string, rt: string, rw: string, suffix: string}|null
     */
    private function identity(string $username): ?array
    {
        if (! preg_match('/^(.+?)_RT0*(\d+)_RW0*(\d+)_(.+)$/i', $username, $matches)) {
            return null;
        }

        return [
            'prefix' => strtoupper($matches[1]),
            'rt' => (string) ((int) $matches[2]),
            'rw' => (string) ((int) $matches[3]),
            'suffix' => strtoupper($matches[4]),
        ];
    }

    /**
     * @param  array{prefix: string, rt: string, rw: string, suffix: string}  $old
     */
    private function sameSubscriberWithChangedSuffix(array $old, string $candidate): bool
    {
        $new = $this->identity($candidate);

        return $new !== null
            && $new['prefix'] === $old['prefix']
            && $new['rt'] === $old['rt']
            && $new['rw'] === $old['rw']
            && $new['suffix'] !== $old['suffix'];
    }

    private function odpSuffix(string $odp): string
    {
        return preg_match('/([A-Z0-9]+(?:\.[A-Z0-9]+)*)$/i', trim($odp), $matches)
            ? strtoupper($matches[1])
            : '';
    }
}
