<?php

namespace App\Services\Cpe;

/**
 * RCA-3: per-model parameter mapping (config/cpe_models.php). Pure, no I/O.
 *
 * An unknown product_class or a TR-181 root gives the `generic` entry, which has no WLAN paths at all.
 */
class CpeModelMap
{
    public const BAND_24 = 'band24';

    public const BAND_5 = 'band5';

    /** Product classes look like HG6045F3; normalise case / whitespace only for such plain tokens. */
    public static function normalizeModel(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^[A-Za-z0-9\-_]+$/', $value) === 1) {
            return strtoupper($value);
        }

        return mb_substr($value, 0, 128);
    }

    /**
     * @param  array<string,mixed>  $device  NBI device document (projection)
     * @return array<string,mixed> model entry (see config/cpe_models.php) plus `product_class`
     */
    public function forDevice(array $device): array
    {
        $class = self::normalizeModel(is_scalar($device['_deviceId']['_ProductClass'] ?? null) ? (string) $device['_deviceId']['_ProductClass'] : null);
        // TR-181 documents carry a top-level `Device` tree and no InternetGatewayDevice tree.
        $tr181 = isset($device['Device']) && ! isset($device['InternetGatewayDevice']);

        return $this->forProductClass($tr181 ? null : $class) + ['product_class' => $class];
    }

    /** @return array<string,mixed> */
    public function forProductClass(?string $productClass): array
    {
        $class = self::normalizeModel($productClass);
        $models = (array) config('cpe_models.models', []);
        if ($class !== null && isset($models[$class])) {
            return $models[$class];
        }

        return (array) config('cpe_models.generic', []);
    }

    /**
     * Absolute leaf path of one WLAN instance field, or null when the model has none.
     *
     * @param  array<string,mixed>  $entry
     */
    public function wlanPath(array $entry, int $instance, string $field): ?string
    {
        $rel = $entry['paths']['wlan'] ?? null;
        $leaf = $entry['paths'][$field] ?? null;
        if (! ($entry['supported'] ?? false) || $rel === null || $leaf === null) {
            return null;
        }

        return $this->abs($entry, str_replace('{i}', (string) $instance, $rel).'.'.$leaf);
    }

    /** @param array<string,mixed> $entry */
    public function pppPath(array $entry, string $field): ?string
    {
        $base = $entry['paths']['ppp'] ?? null;
        $leaf = $entry['paths'][$field] ?? null;
        if (! ($entry['supported'] ?? false) || $base === null || $leaf === null) {
            return null;
        }

        return $this->abs($entry, $base.'.'.$leaf);
    }

    /** @param array<string,mixed> $entry */
    public function uptimePath(array $entry): ?string
    {
        return isset($entry['paths']['uptime']) ? $this->abs($entry, $entry['paths']['uptime']) : null;
    }

    /** @param array<string,mixed> $entry */
    public function abs(array $entry, string $relative): string
    {
        return ($entry['root'] ?? 'InternetGatewayDevice').'.'.$relative;
    }

    /**
     * Primary instances per band, e.g. ['band24' => 1, 'band5' => 9] (null when the model has none).
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,int|null>
     */
    public function primaries(array $entry): array
    {
        return [
            self::BAND_24 => isset($entry['wlan']['band24'][0]) ? (int) $entry['wlan']['band24'][0] : null,
            self::BAND_5 => isset($entry['wlan']['band5'][0]) ? (int) $entry['wlan']['band5'][0] : null,
        ];
    }

    /**
     * Leaf paths the sync projection / summon read for a model: UpTime plus, for each primary instance,
     * Status, Enable, RadioEnabled, TotalAssociations, Channel; PPP status and last error; ModelName.
     *
     * @param  array<string,mixed>  $entry
     * @return list<string>
     */
    public function fixedLeaves(array $entry): array
    {
        if (! ($entry['supported'] ?? false)) {
            return array_values(array_filter([$this->uptimePath($entry)]));
        }
        $out = [];
        foreach ($this->primaries($entry) as $instance) {
            if ($instance === null) {
                continue;
            }
            foreach (['wlan_status', 'wlan_enable', 'wlan_radio', 'wlan_assoc', 'wlan_channel'] as $field) {
                $out[] = $this->wlanPath($entry, $instance, $field);
            }
        }
        $out[] = $this->pppPath($entry, 'ppp_status');
        $out[] = $this->pppPath($entry, 'ppp_error');
        $out[] = $this->pppPath($entry, 'ppp_uptime');
        $out[] = $this->abs($entry, $entry['paths']['model_name']);
        $out[] = $this->uptimePath($entry);

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Union of the fixed leaves of every known model; used by the sync projection (one request, all models).
     *
     * @return list<string>
     */
    public function projectionLeaves(): array
    {
        $out = [];
        foreach ((array) config('cpe_models.models', []) as $entry) {
            foreach ($this->fixedLeaves($entry) as $path) {
                $out[$path] = true;
            }
        }

        return array_keys($out);
    }

    /** Whether the WLAN band mapping of this model was confirmed by the R3 rollout check. */
    public function bandVerified(array $entry): bool
    {
        return (bool) ($entry['band_verified'] ?? false);
    }
}
