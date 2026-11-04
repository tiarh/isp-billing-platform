<?php

namespace Tests\Unit;

use App\Services\LegacyAreaCoordinateService;
use PHPUnit\Framework\TestCase;

class LegacyAreaCoordinateServiceTest extends TestCase
{
    public function test_it_parses_normal_coordinates(): void
    {
        $coordinate = (new LegacyAreaCoordinateService)->parse('-6.858129, 111.686149');

        $this->assertSame(-6.858129, $coordinate['lat']);
        $this->assertSame(111.686149, $coordinate['lng']);
    }

    public function test_it_parses_safe_comma_decimal_coordinates(): void
    {
        $coordinate = (new LegacyAreaCoordinateService)->parse('-6,788014,111.752410');

        $this->assertSame(-6.788014, $coordinate['lat']);
        $this->assertSame(111.752410, $coordinate['lng']);
    }

    public function test_it_rejects_unusable_coordinates(): void
    {
        $service = new LegacyAreaCoordinateService;

        $this->assertNull($service->parse('0'));
        $this->assertNull($service->parse('0,0'));
        $this->assertNull($service->parse('https://maps.app.goo.gl/example'));
        $this->assertNull($service->parse('not a coordinate'));
    }

    public function test_it_finds_nearest_centroid(): void
    {
        $service = new LegacyAreaCoordinateService;
        $centroids = $service->centroids([
            'SKYNET-BEDALI' => [
                ['lat' => -6.858, 'lng' => 111.686],
                ['lat' => -6.859, 'lng' => 111.687],
            ],
            'SKYNET-KARANGPLOSO' => [
                ['lat' => -6.890, 'lng' => 111.597],
                ['lat' => -6.891, 'lng' => 111.598],
            ],
        ]);

        $nearest = $service->nearest(['lat' => -6.8585, 'lng' => 111.6865], $centroids);

        $this->assertSame('SKYNET-BEDALI', $nearest['area']);
    }
}
