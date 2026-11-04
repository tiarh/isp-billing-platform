<?php

namespace App\Services;

class LegacyAreaCoordinateService
{
    /**
     * @return array{lat: float, lng: float}|null
     */
    public function parse(?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '0' || str_starts_with(strtolower($value), 'http')) {
            return null;
        }

        $value = strtoupper($value);
        $value = str_replace(['S.', 'E.', 'S', 'E', 'N'], '', $value);
        $value = preg_replace('/\s+/', '', $value) ?: $value;

        if (preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $value, $matches) === 1) {
            return $this->validCoordinate((float) $matches[1], (float) $matches[2]);
        }

        if (preg_match('/^(-?\d+),(\d+),(-?\d+(?:\.\d+)?)$/', $value, $matches) === 1) {
            return $this->validCoordinate((float) ($matches[1].'.'.$matches[2]), (float) $matches[3]);
        }

        return null;
    }

    /**
     * @param  array<string, array<int, array{lat: float, lng: float}>>  $pointsByArea
     * @return array<string, array{lat: float, lng: float, count: int}>
     */
    public function centroids(array $pointsByArea, int $minimumPoints = 2): array
    {
        $centroids = [];
        foreach ($pointsByArea as $area => $points) {
            if (count($points) < $minimumPoints) {
                continue;
            }

            $centroids[$area] = [
                'lat' => array_sum(array_column($points, 'lat')) / count($points),
                'lng' => array_sum(array_column($points, 'lng')) / count($points),
                'count' => count($points),
            ];
        }

        return $centroids;
    }

    /**
     * @param  array{lat: float, lng: float}  $point
     * @param  array<string, array{lat: float, lng: float, count: int}>  $centroids
     * @return array{area: string, distance_km: float}|null
     */
    public function nearest(array $point, array $centroids): ?array
    {
        $nearest = null;
        foreach ($centroids as $area => $centroid) {
            $distance = $this->distanceKm($point['lat'], $point['lng'], $centroid['lat'], $centroid['lng']);
            if (! $nearest || $distance < $nearest['distance_km']) {
                $nearest = [
                    'area' => $area,
                    'distance_km' => $distance,
                ];
            }
        }

        return $nearest;
    }

    public function distanceKm(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $earthRadiusKm = 6371;
        $latDelta = deg2rad($latB - $latA);
        $lngDelta = deg2rad($lngB - $lngA);

        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function validCoordinate(float $lat, float $lng): ?array
    {
        if ($lat > 0) {
            $lat = -$lat;
        }

        if (($lat === 0.0 && $lng === 0.0) || $lat < -9 || $lat > -6 || $lng < 110 || $lng > 115) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
        ];
    }
}
