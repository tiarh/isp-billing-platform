<?php

namespace App\Policies;

use App\Models\DeviceRecoveryCase;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AreaScope;

class DeviceRecoveryCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdminLike() || $user->isTeknisi();
    }

    public function view(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        if ($user->isTeknisi()) {
            return (int) $recoveryCase->assigned_to === (int) $user->id;
        }
        if (! $user->isAdminLike()) {
            return false;
        }

        return $this->inScope($user, $recoveryCase);
    }

    public function create(User $user, SupportTicket $ticket): bool
    {
        if (! $user->isAdminLike()) {
            return false;
        }

        AreaScope::authorizeTicket($ticket, $user);

        return true;
    }

    /**
     * Owner 2026-10-10: "Lapor pengambilan tanpa tiket" is for technicians
     * only; admins record an already-collected device with quick record.
     */
    public function report(User $user): bool
    {
        return $user->isTeknisi();
    }

    public function assign(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function keepCustomerActive(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function addItem(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function updateItem(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function schedule(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function start(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        if ($user->isSuperAdmin()) {
            return $this->inScope($user, $recoveryCase);
        }

        return $user->isTeknisi()
            && (int) $recoveryCase->assigned_to === (int) $user->id
            && $this->inScope($user, $recoveryCase);
    }

    public function capture(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $this->start($user, $recoveryCase);
    }

    public function receive(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function quickRecord(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    public function resolveException(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isGlobalAdmin() && $this->inScope($user, $recoveryCase);
    }

    public function cancel(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $recoveryCase);
    }

    private function inScope(User $user, DeviceRecoveryCase $recoveryCase): bool
    {
        if (! $user->hasAreaScope()) {
            return true;
        }

        return $recoveryCase->area_id
            && $user->accessibleAreaIds()->contains($recoveryCase->area_id);
    }
}
