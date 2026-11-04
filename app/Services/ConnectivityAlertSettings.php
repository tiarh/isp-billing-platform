<?php

namespace App\Services;

use App\Models\Setting;

class ConnectivityAlertSettings
{
    public function groupId(): string
    {
        return (string) (Setting::get('connectivity_alerts_group_id', '')
            ?: config('services.connectivity_alerts.group_id', ''));
    }

    public function enabled(): bool
    {
        $enabledRaw = Setting::get('connectivity_alerts_enabled');

        if ($enabledRaw !== null && $enabledRaw !== '') {
            return filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('services.connectivity_alerts.enabled', false);
    }

    public function dryRun(): bool
    {
        $dryRunRaw = Setting::get('connectivity_alerts_dry_run');

        if ($dryRunRaw !== null && $dryRunRaw !== '') {
            return filter_var($dryRunRaw, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config('services.connectivity_alerts.dry_run', true);
    }

    public function pendingMinutes(): int
    {
        return (int) config('services.connectivity_alerts.pending_minutes', 15);
    }

    public function repeatMinutes(): int
    {
        return (int) config('services.connectivity_alerts.repeat_minutes', 120);
    }

    /**
     * odp_only = digest per ODP saja (anti-spam, default)
     * both = per-pelanggan + digest ODP (bisa 2× pesan per event)
     * customer_only = per-pelanggan saja
     */
    public function notifyMode(): string
    {
        $mode = (string) config('services.connectivity_alerts.notify_mode', 'odp_only');

        return in_array($mode, ['odp_only', 'both', 'customer_only'], true) ? $mode : 'odp_only';
    }

    public function sendsCustomerAlerts(): bool
    {
        return in_array($this->notifyMode(), ['both', 'customer_only'], true);
    }

    public function sendsOdpAlerts(): bool
    {
        return in_array($this->notifyMode(), ['both', 'odp_only'], true);
    }

    /**
     * @return array{
     *   group_id: string,
     *   enabled: bool,
     *   dry_run: bool,
     *   group_label: string|null
     * }
     */
    public function payload(?callable $resolveGroupLabel = null): array
    {
        $groupId = $this->groupId();

        return [
            'group_id' => $groupId,
            'group_label' => $groupId !== '' && $resolveGroupLabel
                ? $resolveGroupLabel($groupId)
                : null,
            'enabled' => $this->enabled(),
            'dry_run' => $this->dryRun(),
        ];
    }
}
