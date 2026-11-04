<?php

namespace App\Services\Migration;

use App\Services\ProductionControlPlaneService;

/**
 * May a migration write to devices now (migration safety fix: the global read-only switch holds on every lane).
 *
 * The switch `control_plane.device_read_only_mode` (default ON when the setting row is missing) stops every
 * migration device action: the admin web lane, the WA lane of C300/FiberHome/ZTE (also with the WA-only canary)
 * and the Huawei OLT-only lane. PSB has its own switches and does not read it. Stateless, takes no lock, writes
 * nothing. A run that already passed the executing marker is never stopped by it.
 */
final class MigrationWriteGate
{
    public function globalReadOnly(): bool
    {
        return MigrationNormalizer::boolSetting('control_plane.device_read_only_mode', true);
    }

    /** @return array<string, bool> the flags of the Huawei OLT-only lane (no MikroTik flag: that lane only copies the PPPoE row) */
    public function huaweiLaneFlags(ProductionControlPlaneService $controlPlane): array
    {
        return [
            'migration_actions' => MigrationNormalizer::boolSetting('migration_device_actions_enabled', false),
            'global_read_only' => $this->globalReadOnly(),
            'olt' => $controlPlane->writeEnabled('olt'),
            'genieacs' => $controlPlane->writeEnabled('genieacs'),
            'mikrotik' => false,
        ];
    }

    /**
     * C300/FiberHome/ZTE lanes. The WA-only canary opens the lane without the per-domain flags, but never past
     * the global read-only switch.
     *
     * @param  array<string, bool>  $flags  the planner's migration write flags
     */
    public function writesAllowed(array $flags, bool $scopedWaCanary): bool
    {
        if ($flags['global_read_only'] ?? true) {
            return false;
        }
        $global = ($flags['migration_actions'] ?? false) && ($flags['olt'] ?? false) && ($flags['mikrotik'] ?? false) && ($flags['genieacs'] ?? false);

        return $global || ($scopedWaCanary && MigrationNormalizer::boolSetting('migration_wa_only_live_enabled', false));
    }
}
