<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\GenieAcsDevice;
use App\Models\OltOnu;
use Illuminate\Support\Facades\Schema;

final class DeviceRecoveryReconciliationService
{
    public function forCase(DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $recoveryService): array
    {
        $customer = $this->customerSnapshot($recoveryCase);
        $expectedSerial = $recoveryService->normalizeSerial($customer['onu_serial'] ?? null);

        return [
            'customer_device' => [
                'available' => $customer !== null,
                'expected_serial_number' => $expectedSerial,
                'olt_id' => $customer['olt_id'] ?? null,
                'olt_port_label' => $customer['olt_port_label'] ?? null,
                'olt_status' => $customer['olt_status'] ?? null,
                'last_synced_at' => $this->isoDate($customer['olt_last_synced_at'] ?? null),
            ],
            'olt_onu' => $this->oltSnapshot($customer, $expectedSerial, $recoveryService),
            'genieacs' => $this->genieSnapshot($recoveryCase->customer_id, $expectedSerial, $recoveryService),
        ];
    }

    private function customerSnapshot(DeviceRecoveryCase $recoveryCase): ?array
    {
        if (! Schema::hasTable('customers')) {
            return null;
        }

        $columns = array_values(array_intersect([
            'id',
            'onu_serial',
            'olt_id',
            'olt_port_label',
            'olt_status',
            'olt_last_synced_at',
        ], Schema::getColumnListing('customers')));
        if (! in_array('id', $columns, true)) {
            return null;
        }

        $customer = Customer::query()->select($columns)->find($recoveryCase->customer_id);

        return $customer?->toArray();
    }

    private function oltSnapshot(?array $customer, ?string $expectedSerial, DeviceRecoveryService $recoveryService): array
    {
        if (! Schema::hasTable('olt_onus')) {
            return [
                'available' => false,
                'matched' => false,
                'candidate_count' => 0,
                'ambiguous' => false,
                'stale' => true,
                'last_synced_at' => null,
                'onu' => null,
            ];
        }

        $columns = Schema::getColumnListing('olt_onus');
        $query = OltOnu::query();
        if (($customer['olt_id'] ?? null) !== null && in_array('olt_id', $columns, true)) {
            $query->where('olt_id', (int) $customer['olt_id']);
        }

        $candidateQuery = null;
        if ($expectedSerial && in_array('serial_number', $columns, true)) {
            $candidateQuery = (clone $query)->whereRaw(
                "UPPER(REPLACE(REPLACE(COALESCE(serial_number, ''), '-', ''), ' ', '')) = ?",
                [$expectedSerial],
            );
        }

        $ref = $this->normalizeReference($customer['olt_port_label'] ?? null);
        if (! $candidateQuery || ! $candidateQuery->exists()) {
            $candidateQuery = $ref && in_array('onu_ref', $columns, true)
                ? (clone $query)->whereRaw(
                    "LOWER(REPLACE(LOWER(COALESCE(onu_ref, '')), 'gpon-onu_', '')) = ?",
                    [$ref],
                )
                : null;
        }

        $candidates = $candidateQuery ? $candidateQuery->limit(10)->get($this->oltColumns($columns)) : collect();
        $onu = $candidates->first();
        $lastSyncedAt = $onu?->inventory_collected_at ?: $onu?->state_collected_at;

        return [
            'available' => true,
            'matched' => $onu !== null,
            'candidate_count' => $candidateQuery ? (int) $candidateQuery->count() : 0,
            'ambiguous' => $candidates->count() > 1,
            'stale' => $lastSyncedAt === null || $lastSyncedAt->lt(now()->subHours(24)),
            'last_synced_at' => $lastSyncedAt?->timezone('Asia/Jakarta')->toIso8601String(),
            'onu' => $onu ? [
                'olt_id' => $onu->olt_id,
                'onu_ref' => $onu->onu_ref,
                'serial_number' => $recoveryService->normalizeSerial($onu->serial_number),
                'onu_name' => $onu->onu_name,
                'state' => $onu->state,
                'phase_state' => $onu->phase_state,
                'rx_power_dbm' => $onu->rx_power_dbm,
                'distance_m' => $onu->distance_m,
            ] : null,
        ];
    }

    private function genieSnapshot(int $customerId, ?string $expectedSerial, DeviceRecoveryService $recoveryService): array
    {
        if (! Schema::hasTable('genieacs_devices')) {
            return [
                'available' => false,
                'matched' => false,
                'candidate_count' => 0,
                'ambiguous' => false,
                'stale' => true,
                'last_synced_at' => null,
                'device' => null,
            ];
        }

        $columns = Schema::getColumnListing('genieacs_devices');
        $select = array_values(array_intersect([
            'id',
            'device_id',
            'customer_id',
            'serial_number',
            'manufacturer',
            'model',
            'last_inform_at',
            'last_seen_sync_at',
            'last_ping_at',
            'last_ping_success',
        ], $columns));
        if (! in_array('id', $select, true)) {
            return [
                'available' => true,
                'matched' => false,
                'candidate_count' => 0,
                'ambiguous' => false,
                'stale' => true,
                'last_synced_at' => null,
                'device' => null,
            ];
        }

        $query = GenieAcsDevice::query()->select($select);
        $candidateQuery = in_array('customer_id', $columns, true)
            ? (clone $query)->where('customer_id', $customerId)
            : null;
        if ($candidateQuery && ! $candidateQuery->exists() && $expectedSerial && in_array('serial_number', $columns, true)) {
            $candidateQuery = (clone $query)->whereRaw(
                "UPPER(REPLACE(REPLACE(COALESCE(serial_number, ''), '-', ''), ' ', '')) = ?",
                [$expectedSerial],
            );
        }

        $candidates = $candidateQuery ? $candidateQuery->limit(10)->get() : collect();
        $device = $candidates->first();
        $lastSyncedAt = $device?->last_seen_sync_at ?: $device?->last_inform_at;

        return [
            'available' => true,
            'matched' => $device !== null,
            'candidate_count' => $candidateQuery ? (int) $candidateQuery->count() : 0,
            'ambiguous' => $candidates->count() > 1,
            'stale' => $lastSyncedAt === null || $lastSyncedAt->lt(now()->subMinutes((int) config('services.genieacs.online_minutes', 30))),
            'last_synced_at' => $lastSyncedAt?->timezone('Asia/Jakarta')->toIso8601String(),
            'device' => $device ? [
                'device_id' => $device->device_id,
                'serial_number' => $recoveryService->normalizeSerial($device->serial_number),
                'manufacturer' => $device->manufacturer,
                'model' => $device->model,
                'last_inform_at' => $device->last_inform_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'last_ping_at' => $device->last_ping_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'last_ping_success' => $device->last_ping_success,
            ] : null,
        ];
    }

    private function oltColumns(array $columns): array
    {
        return array_values(array_intersect([
            'id',
            'olt_id',
            'onu_ref',
            'serial_number',
            'onu_name',
            'state',
            'phase_state',
            'rx_power_dbm',
            'distance_m',
            'inventory_collected_at',
            'state_collected_at',
        ], $columns));
    }

    private function normalizeReference(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/^gpon-onu_/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }

    private function isoDate(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->setTimezone(new \DateTimeZone('Asia/Jakarta'))->format(DATE_ATOM)
            : null;
    }
}
