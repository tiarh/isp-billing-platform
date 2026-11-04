<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/**
 * Application-level RLS for customers (area-scoped admins).
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->inScope($user, $customer);
    }

    public function create(User $user): bool
    {
        return $user->isAdminLike() || $user->isSuperAdmin();
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $customer);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $customer);
    }

    public function isolate(User $user, Customer $customer): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $customer);
    }

    public function reconnect(User $user, Customer $customer): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $customer);
    }

    /** Roadmap 1.3 PR-4a: cancel a guard-deferred termination (admin + superadmin only, not NOC). */
    public function cancelPendingTermination(User $user, Customer $customer): bool
    {
        return ($user->isAdmin() || $user->isSuperAdmin()) && $this->inScope($user, $customer);
    }

    private function inScope(User $user, Customer $customer): bool
    {
        if (! $user->hasAreaScope()) {
            return true;
        }

        return $customer->area_id
            && $user->accessibleAreaIds()->contains($customer->area_id);
    }
}
