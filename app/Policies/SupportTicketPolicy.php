<?php

namespace App\Policies;

use App\Models\SupportTicket;
use App\Models\User;

class SupportTicketPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        if ($user->isTeknisi() && (int) $ticket->assigned_to !== (int) $user->id) {
            return false;
        }

        return $this->inScope($user, $ticket);
    }

    public function update(User $user, SupportTicket $ticket): bool
    {
        return $this->view($user, $ticket);
    }

    public function delete(User $user, SupportTicket $ticket): bool
    {
        return $user->isAdminLike() && $this->inScope($user, $ticket);
    }

    private function inScope(User $user, SupportTicket $ticket): bool
    {
        if (! $user->hasAreaScope()) {
            return true;
        }

        $ticket->loadMissing(['customer' => fn ($q) => $q->withTrashed()]);

        return $ticket->customer?->area_id
            && $user->accessibleAreaIds()->contains($ticket->customer->area_id);
    }
}
