<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Package;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Support\OpsTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Record manual (Auto PPPoE / FiberHome UI) activations into psb_drafts
 * so they share the same KPI path as PSB WA.
 */
class PsbActivationRecorder
{
    public function __construct(
        protected PsbSiteRegistry $sites,
    ) {}

    /**
     * After manual Auto-PPPoE success (secret on MT).
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordManualPppoe(
        Customer $customer,
        Router $router,
        ?Package $package = null,
        array $extra = [],
    ): ?PsbDraft {
        if (! Schema::hasTable('psb_drafts')) {
            return null;
        }

        try {
            $site = $this->sites->byRouterId((int) $router->id);
            $siteKey = $site['key'] ?? 'manual';
            $mode = (string) ($site['mode'] ?? (
                ($site && ($site['olt_id'] ?? null)) ? PsbDraft::MODE_FULL_OLT : PsbDraft::MODE_PPPOE_ONLY
            ));

            // full_olt manual Autopp usually secret-only first; FiberHome may follow
            $status = PsbDraft::STATUS_SECRET_OK;
            if (! empty($extra['status'])) {
                $status = (string) $extra['status'];
            }

            $meta = [
                'source' => 'manual',
                'manual_path' => $extra['manual_path'] ?? 'auto_pppoe',
                'recorded_at' => now()->toIso8601String(),
                'site_key' => $siteKey,
            ];
            if (! empty($extra['meta']) && is_array($extra['meta'])) {
                $meta = array_merge($meta, $extra['meta']);
            }

            // Dedupe: same customer + same day manual already recorded
            $existing = PsbDraft::query()
                ->where('customer_id', $customer->id)
                ->where('pppoe_user', $customer->pppoe_user)
                ->whereBetween('created_at', OpsTime::dayBounds())
                ->where(function ($q) {
                    $q->where('meta', 'like', '%"source":"manual"%')
                        ->orWhere('meta', 'like', '%manual%');
                })
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                $existing->fill(array_filter([
                    'status' => $status,
                    'router_id' => $router->id,
                    'olt_id' => $extra['olt_id'] ?? $customer->olt_id ?? $existing->olt_id,
                    'package_id' => $package?->id ?? $customer->package_id,
                    'mikrotik_profile' => $package?->mikrotik_profile ?? $customer->mikrotik_profile,
                    'area_id' => $customer->area_id,
                    'sn' => $extra['sn'] ?? $existing->sn,
                    'pon_port' => $extra['pon_port'] ?? $existing->pon_port,
                    'vlan' => $extra['vlan'] ?? $existing->vlan,
                    'site_profile_key' => $siteKey,
                    'activation_mode' => $mode,
                    'secret_at' => $existing->secret_at ?: now(),
                    'olt_at' => $extra['olt_at'] ?? $existing->olt_at,
                    'pppoe_local_address' => $customer->pppoe_local_address,
                    'pppoe_remote_address' => $customer->pppoe_remote_address,
                ], fn ($v) => $v !== null && $v !== ''));
                $m = is_array($existing->meta) ? $existing->meta : [];
                $existing->meta = array_merge($m, $meta);
                $existing->save();

                return $existing;
            }

            $draft = new PsbDraft([
                'status' => $status,
                'activation_mode' => $mode,
                'site_profile_key' => $siteKey,
                'group_jid' => 'manual@ebilling',
                'sender_jid' => $extra['sender_jid'] ?? null,
                'sender_name' => $extra['sender_name'] ?? 'manual',
                'message_id' => 'manual-'.($customer->id).'-'.now()->format('YmdHis'),
                'raw_text' => '[manual auto-pppoe] '.$customer->name,
                'full_name' => $customer->name,
                'rt' => $extra['rt'] ?? null,
                'rw' => $extra['rw'] ?? null,
                'odp' => $extra['odp'] ?? $customer->odp_name ?? null,
                'phone' => $customer->phone,
                'daerah' => $extra['daerah'] ?? optional($customer->area)->name,
                'area_id' => $customer->area_id,
                'sn' => $extra['sn'] ?? $customer->onu_serial,
                'pon_port' => $extra['pon_port'] ?? $customer->olt_port_label,
                'vlan' => $extra['vlan'] ?? null,
                'pppoe_user' => $customer->pppoe_user,
                'pppoe_local_address' => $customer->pppoe_local_address,
                'pppoe_remote_address' => $customer->pppoe_remote_address,
                'mikrotik_profile' => $package?->mikrotik_profile ?? $customer->mikrotik_profile,
                'router_id' => $router->id,
                'olt_id' => $extra['olt_id'] ?? $customer->olt_id,
                'package_id' => $package?->id ?? $customer->package_id,
                'customer_id' => $customer->id,
                'ktp_photo_path' => $customer->ktp_photo_url,
                'secret_at' => now(),
                'olt_at' => $extra['olt_at'] ?? null,
                'meta' => $meta,
            ]);
            $draft->save();

            Log::info('[PSB KPI] manual activation recorded', [
                'draft_id' => $draft->id,
                'customer_id' => $customer->id,
                'site' => $siteKey,
                'router_id' => $router->id,
            ]);

            return $draft;
        } catch (\Throwable $e) {
            Log::warning('[PSB KPI] recordManualPppoe failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * After FiberHome provision success — upgrade draft to olt_ok or create one.
     *
     * @param  array<string, mixed>  $payload
     */
    public function recordFiberhomeProvision(array $payload): ?PsbDraft
    {
        if (! Schema::hasTable('psb_drafts')) {
            return null;
        }

        try {
            $pppoe = trim((string) ($payload['name'] ?? $payload['pppoe_user'] ?? ''));
            $sn = strtoupper(trim((string) ($payload['sn'] ?? '')));
            $oltId = (int) ($payload['olt_id'] ?? 0);
            $routerId = (int) ($payload['router_id'] ?? 0);
            $site = $routerId > 0
                ? $this->sites->byRouterId($routerId)
                : ($oltId > 0 ? $this->sites->byOltId($oltId) : null);
            $siteKey = $site['key'] ?? 'manual';

            $draft = null;
            if ($pppoe !== '') {
                $draft = PsbDraft::query()
                    ->where('pppoe_user', $pppoe)
                    ->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])
                    ->orderByDesc('id')
                    ->first();
            }
            if (! $draft && $sn !== '') {
                // A device returned via a `received` dismantle recovery case
                // is being reused: never hijack the old owner's PSB draft.
                $recovery = app(DeviceRecoveryService::class);
                $releasedIds = $recovery->releasedCustomerIdsForSerial($sn);
                $releasedPppoe = $recovery->releasedPppoeUsersForSerial($sn);
                $draft = PsbDraft::query()
                    ->where('sn', $sn)
                    ->when($releasedIds !== [], fn ($query) => $query->where(fn ($owner) => $owner
                        ->whereNull('customer_id')
                        ->orWhereNotIn('customer_id', $releasedIds)))
                    ->when($releasedPppoe !== [], fn ($query) => $query->where(fn ($owner) => $owner
                        ->whereNull('pppoe_user')
                        ->orWhereRaw(
                            'LOWER(pppoe_user) NOT IN ('.implode(',', array_fill(0, count($releasedPppoe), '?')).')',
                            $releasedPppoe
                        )))
                    ->whereNotIn('status', [PsbDraft::STATUS_CANCELLED])
                    ->orderByDesc('id')
                    ->first();
            }

            if ($draft) {
                $draft->status = PsbDraft::STATUS_OLT_OK;
                $draft->olt_at = now();
                $draft->activation_mode = PsbDraft::MODE_FULL_OLT;
                $draft->site_profile_key = $siteKey;
                if ($oltId > 0) {
                    $draft->olt_id = $oltId;
                }
                if ($routerId > 0) {
                    $draft->router_id = $routerId;
                }
                if ($sn !== '') {
                    $draft->sn = $sn;
                }
                if (! empty($payload['port'])) {
                    $draft->pon_port = $payload['port'];
                }
                if (! empty($payload['onu_id'])) {
                    $draft->onu_id = (int) $payload['onu_id'];
                }
                if (! empty($payload['vlan'])) {
                    $draft->vlan = (int) $payload['vlan'];
                }
                if (! empty($payload['tr069_vlan'])) {
                    $draft->tr069_vlan = (int) $payload['tr069_vlan'];
                }
                $meta = is_array($draft->meta) ? $draft->meta : [];
                $meta['fiberhome_manual'] = true;
                $meta['olt_provisioned_at'] = now()->toIso8601String();
                $draft->meta = $meta;
                $draft->save();

                return $draft;
            }

            // No draft yet (pure manual FH without Autopp record) — create KPI row
            $customer = null;
            if ($pppoe !== '') {
                $customer = Customer::query()->where('pppoe_user', $pppoe)->first();
            }

            $draft = new PsbDraft([
                'status' => PsbDraft::STATUS_OLT_OK,
                'activation_mode' => PsbDraft::MODE_FULL_OLT,
                'site_profile_key' => $siteKey,
                'group_jid' => 'manual@ebilling',
                'sender_name' => 'manual-fiberhome',
                'message_id' => 'manual-fh-'.md5($sn.$pppoe.now()->timestamp),
                'raw_text' => '[manual fiberhome] '.$pppoe,
                'full_name' => $customer?->name ?? $pppoe,
                'sn' => $sn ?: null,
                'pon_port' => $payload['port'] ?? null,
                'onu_id' => isset($payload['onu_id']) ? (int) $payload['onu_id'] : null,
                'vlan' => isset($payload['vlan']) ? (int) $payload['vlan'] : null,
                'tr069_vlan' => isset($payload['tr069_vlan']) ? (int) $payload['tr069_vlan'] : 69,
                'pppoe_user' => $pppoe ?: null,
                'router_id' => $routerId ?: ($site['router_id'] ?? null),
                'olt_id' => $oltId ?: ($site['olt_id'] ?? null),
                'customer_id' => $customer?->id,
                'area_id' => $customer?->area_id,
                'package_id' => $customer?->package_id,
                'secret_at' => $customer ? now() : null,
                'olt_at' => now(),
                'meta' => [
                    'source' => 'manual',
                    'manual_path' => 'fiberhome',
                    'site_key' => $siteKey,
                    'olt_provisioned_at' => now()->toIso8601String(),
                ],
            ]);
            $draft->save();

            return $draft;
        } catch (\Throwable $e) {
            Log::warning('[PSB KPI] recordFiberhomeProvision failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
