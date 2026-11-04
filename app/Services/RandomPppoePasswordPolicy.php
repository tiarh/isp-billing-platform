<?php

namespace App\Services;

use App\Contracts\PsbPppoePasswordPolicy;
use App\Models\Area;
use App\Models\PsbDraft;

/**
 * Random PPPoE password for each new PSB secret.
 *
 * A password is generated on every call and is not stored here. Callers
 * write it to the router and then read the verified value back from the
 * router, which is what they save on the draft/customer. If a secret has to
 * be recreated, a new password is generated for it.
 *
 * Known gap: in PsbOrchestratorService, when the router answers that the
 * secret already exists, the generated password is saved without a read-back,
 * so the stored value can differ from the router. With the area policy both
 * are the same value; with this policy a read-back is needed in that branch.
 */
class RandomPppoePasswordPolicy implements PsbPppoePasswordPolicy
{
    public const LENGTH = 16;

    private const ALPHABET = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function passwordForDraft(PsbDraft $draft): string
    {
        return $this->generate();
    }

    public function passwordForArea(Area $area): string
    {
        return $this->generate();
    }

    private function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $password = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $password .= self::ALPHABET[random_int(0, $max)];
        }

        return $password;
    }
}
