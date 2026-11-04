<?php

namespace App\Services\Psb;

use App\Models\Olt;
use App\Models\OltVlanMapping;
use App\Models\PsbDraft;
use Illuminate\Support\Facades\Schema;

/**
 * Pure part of PSB uncfg matching (moved verbatim from PsbOrchestratorService).
 * The controller call (listUncfg) stays in the facade's findUncfgForDraft, which tests override.
 */
final class PsbUncfgMatcher
{
    /**
     * @param  array<int, mixed>  $onus  rows of the controller's `onus` list
     * @return array{found: bool, sn?: string, port?: string, onu_id?: int|null, vlan?: int|null, message?: string}
     */
    public function match(PsbDraft $draft, Olt $olt, array $onus): array
    {
        $vlanMap = $this->vlanMapForOlt($olt);
        $wantSn = $draft->sn ? strtoupper($draft->sn) : null;

        foreach ($onus as $u) {
            $sn = strtoupper((string) ($u['sn'] ?? ''));
            if ($sn === '' || $sn === '(TIDAK TERBACA)') {
                continue;
            }
            if ($wantSn && $sn !== $wantSn && ! str_contains($sn, $wantSn) && ! str_contains($wantSn, $sn)) {
                continue;
            }
            if (! $wantSn && count($onus) !== 1) {
                // Without SN, only auto-match if exactly one uncfg
                continue;
            }
            $port = $this->normalizePonPortKey((string) ($u['port'] ?? ''));
            $vlan = $vlanMap[$port] ?? null;
            if ($vlan === null && $port !== '') {
                return [
                    'found' => false,
                    'block_reason' => 'vlan_mapping_missing',
                    'message' => "VLAN mapping belum diisi untuk PON {$port} di OLT {$olt->name}. Isi di Aktivasi → VLAN Mapping OLT (tanpa fallback).",
                    'port' => $port,
                    'sn' => $sn,
                ];
            }

            return [
                'found' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => isset($u['onu_id']) ? (int) $u['onu_id'] : null,
                'vlan' => $vlan,
            ];
        }

        // Single uncfg: unreadable SN → pakai chat SN; beda SN → P6 mismatch
        if ($wantSn && count($onus) === 1) {
            $u = $onus[0];
            $port = $this->normalizePonPortKey((string) ($u['port'] ?? ''));
            $vlan = $vlanMap[$port] ?? null;
            if ($vlan === null && $port !== '') {
                return [
                    'found' => false,
                    'block_reason' => 'vlan_mapping_missing',
                    'message' => "VLAN mapping belum diisi untuk PON {$port} di OLT {$olt->name}. Isi di Aktivasi → VLAN Mapping OLT (tanpa fallback).",
                    'port' => $port,
                ];
            }
            $sn = strtoupper((string) ($u['sn'] ?? ''));
            $onuId = isset($u['onu_id']) ? (int) $u['onu_id'] : null;

            if ($sn === '' || $sn === '(TIDAK TERBACA)') {
                return [
                    'found' => true,
                    'sn' => $wantSn,
                    'port' => $port,
                    'onu_id' => $onuId,
                    'vlan' => $vlan,
                ];
            }

            if ($sn !== $wantSn && ! str_contains($sn, $wantSn) && ! str_contains($wantSn, $sn)) {
                return [
                    'found' => false,
                    'sn_mismatch' => true,
                    'chat_sn' => $wantSn,
                    'uncfg_sn' => $sn,
                    'port' => $port,
                    'onu_id' => $onuId,
                    'vlan' => $vlan,
                    'message' => "SN uncfg {$sn} ≠ chat {$wantSn}",
                ];
            }

            return [
                'found' => true,
                'sn' => $sn,
                'port' => $port,
                'onu_id' => $onuId,
                'vlan' => $vlan,
            ];
        }

        return [
            'found' => false,
            'message' => $wantSn
                ? "SN {$wantSn} belum di uncfg (".count($onus).' ONU uncfg lain)'
                : 'SN belum di uncfg / tidak terbaca',
        ];
    }

    /**
     * VLAN map per OLT — HANYA dari DB (tanpa hardcode fallback).
     *
     * @return array<string, int>
     */
    public function vlanMapForOlt(Olt $olt): array
    {
        $map = [];
        if (! Schema::hasTable('olt_vlan_mappings')) {
            return $map;
        }
        $rows = OltVlanMapping::query()
            ->where('olt_id', $olt->id)
            ->where(function ($q) {
                $q->where('service_type', 'internet')
                    ->orWhereNull('service_type')
                    ->orWhere('service_type', '');
            })
            ->where('is_active', true)
            ->get(['pon_port', 'vlan']);
        foreach ($rows as $r) {
            $key = $this->normalizePonPortKey((string) $r->pon_port);
            if ($key !== '' && (int) $r->vlan > 0) {
                $map[$key] = (int) $r->vlan;
            }
        }

        return $map;
    }

    public function normalizePonPortKey(string $port): string
    {
        $port = trim($port);
        $port = preg_replace('/^gpon-olt_/i', '', $port) ?? $port;
        $port = preg_replace('/\s+/', '', $port) ?? $port;
        if (preg_match('/^(\d+\/\d+\/\d+)(?::\d+)?$/', $port, $m)) {
            return $m[1];
        }

        return $port;
    }
}
