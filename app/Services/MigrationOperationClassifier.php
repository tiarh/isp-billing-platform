<?php

namespace App\Services;

final class MigrationOperationClassifier
{
    public const MIGRATION = 'MIGRATION';

    public const HARDWARE_REPLACEMENT = 'HARDWARE_REPLACEMENT';

    public const DUPLICATE_MIGRATION = 'DUPLICATE_MIGRATION';

    public const PENDING_TECHNICIAN_CONFIRMATION = 'PENDING_TECHNICIAN_CONFIRMATION';

    public const CONFLICT = 'CONFLICT';

    public const NOT_FOUND = 'NOT_FOUND';

    /**
     * @param  array<string, mixed>  $input
     * @return array{operation_type: string, reason_code: string, operation_key: string, operation_reference: string, requires_confirmation: bool, warnings: list<string>}
     */
    public function classify(array $input): array
    {
        $customerId = (int) ($input['customer_id'] ?? 0);
        $currentRouterId = (int) ($input['current_router_id'] ?? 0);
        $targetRouterId = (int) ($input['target_router_id'] ?? 0);
        $pppoe = trim((string) ($input['pppoe_user'] ?? ''));
        $customerPppoe = trim((string) ($input['customer_pppoe_user'] ?? ''));
        $newSn = $this->normalizeSerial($input['new_sn'] ?? null);
        $currentSn = $this->normalizeSerial($input['current_sn'] ?? null);
        $warnings = [];

        $operationKey = hash('sha256', implode('|', [
            'migration-operation-v1',
            $customerId > 0 ? (string) $customerId : 'unknown-customer',
            $targetRouterId > 0 ? (string) $targetRouterId : 'unknown-router',
            $pppoe,
            $newSn,
        ]));
        $base = [
            'operation_key' => $operationKey,
            'operation_reference' => 'MIG-'.strtoupper(substr($operationKey, 0, 12)),
            'requires_confirmation' => false,
            'warnings' => $warnings,
        ];

        if ($customerId <= 0 || $targetRouterId <= 0 || $pppoe === '') {
            return array_merge($base, [
                'operation_type' => self::NOT_FOUND,
                'reason_code' => 'NOT_FOUND',
            ]);
        }

        if ($customerPppoe !== '' && $pppoe !== $customerPppoe) {
            return array_merge($base, [
                'operation_type' => self::CONFLICT,
                'reason_code' => 'PPPOE_CONFLICT',
            ]);
        }

        $oldRouterId = (int) ($input['old_router_id'] ?? 0);
        if ($oldRouterId > 0 && $currentRouterId > 0 && $oldRouterId !== $currentRouterId) {
            $warnings[] = 'OLD_ROUTER_MISMATCH_ONLY';
        }
        $base['warnings'] = $warnings;

        if ($currentRouterId <= 0) {
            return array_merge($base, [
                'operation_type' => self::NOT_FOUND,
                'reason_code' => 'NOT_FOUND',
                'warnings' => $warnings,
            ]);
        }

        if ($targetRouterId !== $currentRouterId) {
            if ($newSn !== '' && $newSn === $currentSn) {
                return array_merge($base, [
                    'operation_type' => self::CONFLICT,
                    'reason_code' => 'TARGET_CONFLICT',
                    'warnings' => $warnings,
                ]);
            }

            return array_merge($base, [
                'operation_type' => self::MIGRATION,
                'reason_code' => 'TARGET_ROUTER_CHANGED',
                'warnings' => $warnings,
            ]);
        }

        if ($newSn === '' || ($currentSn !== '' && $newSn === $currentSn) || (bool) ($input['already_processed'] ?? false)) {
            return array_merge($base, [
                'operation_type' => self::DUPLICATE_MIGRATION,
                'reason_code' => 'SAME_TARGET_SAME_SN_ALREADY_DONE',
                'warnings' => $warnings,
            ]);
        }

        $replacementConfirmed = (bool) ($input['technician_confirmed'] ?? false);
        $replacementReason = (bool) ($input['replacement_reason_present'] ?? false);
        if ($replacementReason || $replacementConfirmed) {
            return array_merge($base, [
                'operation_type' => self::HARDWARE_REPLACEMENT,
                'reason_code' => 'SAME_TARGET_NEW_SN_DEVICE_REPLACED',
                'requires_confirmation' => true,
                'warnings' => $warnings,
            ]);
        }

        return array_merge($base, [
            'operation_type' => self::PENDING_TECHNICIAN_CONFIRMATION,
            'reason_code' => 'SAME_TARGET_NEW_SN_NO_CONFIRMATION',
            'requires_confirmation' => true,
            'warnings' => $warnings,
        ]);
    }

    public function hasReplacementReason(?string $text): bool
    {
        return $text !== null && (bool) preg_match(
            '/\b(?:rusak|mati|replacement|ganti|diganti|perangkat baru|ont baru|onu baru|fhtt baru|fiberhome baru)\b/iu',
            $text
        );
    }

    private function normalizeSerial(mixed $value): string
    {
        $serial = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string) $value)) ?? '');

        return $serial === 'TIDAKTERBACA' ? '' : $serial;
    }
}
