<?php

namespace App\Contracts;

use App\Exceptions\PsbPasswordPolicyException;
use App\Models\Area;
use App\Models\PsbDraft;

/**
 * Decides the PPPoE password for a new PSB secret.
 *
 * The implementation is chosen by config('site.psb_password_policy') and bound
 * in AppServiceProvider.
 */
interface PsbPppoePasswordPolicy
{
    /**
     * Password for the secret created from a PSB draft (WhatsApp flow).
     *
     * @throws PsbPasswordPolicyException when no password can be produced
     */
    public function passwordForDraft(PsbDraft $draft): string;

    /**
     * Password for a manual activation in the given area.
     *
     * @throws PsbPasswordPolicyException when no password can be produced
     */
    public function passwordForArea(Area $area): string;
}
