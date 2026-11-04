<?php

namespace Tests\Support\PsbGolden;

use App\Models\Customer;
use App\Services\RadiusUserService;

/** Golden-test recording fake: the RADIUS sync after a PSB secret is written. */
final class PsbGoldenRadiusUserService extends RadiusUserService
{
    public function __construct(private PsbGoldenRecorder $rec) {}

    public function syncCustomer(Customer $customer): array
    {
        $this->rec->add('radius.syncCustomer', ['pppoe_user' => (string) $customer->pppoe_user]);

        return ['synced' => true];
    }
}
