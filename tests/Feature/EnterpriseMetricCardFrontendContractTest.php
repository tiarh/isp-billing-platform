<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnterpriseMetricCardFrontendContractTest extends TestCase
{
    public function test_metric_card_does_not_expose_banned_violet_tone(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Components/enterprise/EnterpriseMetricCard.tsx'));

        $this->assertStringNotContainsString('violet', $source);
    }
}
