<?php

namespace App\Services;

class LegacyAreaResolver
{
    private const GENERAL_AREA = 'SKYNET-GENERAL';

    /**
     * @var array<string, string>
     */
    private array $prefixMap = [
        'ARJ' => 'SKYNET-ARJOSARI',
        'BDL' => 'SKYNET-BEDALI',
        'BMS' => 'SKYNET-LAWANG',
        'BMY' => 'SKYNET-BUMIAYU',
        'BTR' => 'SKYNET-BANTARAN',
        'DKR' => 'SKYNET-PURWODADI',
        'FREE' => 'SKYNET-LAWANG',
        'GDG' => 'SKYNET-GADANG',
        'DSN' => 'SKYNET-GADANG',
        'GJH' => 'SKYNET-GAJAHREJO',
        'GJHJ' => 'SKYNET-GAJAHREJO',
        'GKW' => 'SKYNET-JAMBUWER',
        'INKL' => 'SKYNET-LAWANG',
        'JTSR' => 'SKYNET-JATISARI',
        'KLLW' => 'SKYNET-LAWANG',
        'KNDT' => 'SKYNET-KENDIT',
        'KPBR' => 'SKYNET-KAMPUNG BIRU',
        'KRL' => 'SKYNET-KARANGPLOSO',
        'KRN' => 'SKYNET-KRIAN',
        'KTS' => 'SKYNET-KERTOSARI',
        'LSS' => 'SKYNET-KARANGPLOSO',
        'MLG' => 'SKYNET-BARENG MALANG',
        'MRGS' => 'SKYNET-MERGOSONO',
        'MRL' => 'SKYNET-MULYOARJO',
        'MTPR' => 'SKYNET-MARTOPURO',
        'NKJ' => 'SKYNET-NONGKOJAJAR',
        'NKJJ' => 'SKYNET-NONGKOJAJAR',
        'PLSN' => 'SKYNET-PLAOSAN',
        'PRD' => 'SKYNET-PURWODADI',
        'PRTG' => 'SKYNET-PROTONG',
        'PSRN' => 'SKYNET-PASURUAN',
        'PSRP' => 'SKYNET-PASREPAN',
        'PWD' => 'SKYNET-PURWODADI',
        'RDG' => 'SKYNET-RANDUAGUNG',
        'REST' => 'SKYNET-KARANGPLOSO',
        'RGD' => 'SKYNET-RANDUAGUNG',
        'RRDG' => 'SKYNET-RANDUAGUNG',
        'SBWN' => 'SKYNET-LAWANG',
        'SDDG' => 'SKYNET-SIDOADI GARUM',
        'SKHJ' => 'SKYNET-COMBORAN',
        'SLT' => 'SKYNET-SENTUL',
        'SMPL' => 'SKYNET-PASURUAN',
        'SNG' => 'SKYNET-SONGSONG',
        'SPDW' => 'SUBNET-PANDOWO',
        'SRGD' => 'SKYNET-SRIGADING',
        'STL' => 'SKYNET-SENTUL',
        'SUB' => 'SKYNET-SUBNET',
        'TTR' => 'SKYNET-TUTUR',
        'WNS' => 'SKYNET-WAJAK',
    ];

    /**
     * Ordered from most specific to least specific to avoid broad matches
     * stealing records from more precise locations.
     *
     * @var array<string, string>
     */
    private array $keywordMap = [
        'BEDALI INDAH' => 'SKYNET-BEDALI',
        'BEDALIINDAH' => 'SKYNET-BEDALI',
        'BDL INDAH' => 'SKYNET-BEDALI',
        'BEDALI' => 'SKYNET-BEDALI',
        'CAKRUAN' => 'SKYNET-JAMBUWER',
        'GLAGAHARUM' => 'SKYNET-JAMBUWER',
        'JAMBUWER' => 'SKYNET-JAMBUWER',
        'KAMPUNG BARU' => 'SKYNET-JAMBUWER',
        'KLOPO KUNING' => 'SKYNET-JAMBUWER',
        'REKASAN' => 'SKYNET-JAMBUWER',
        'REKESAN' => 'SKYNET-JAMBUWER',
        'SIDOMULYO' => 'SKYNET-JAMBUWER',
        'SIDOREJO' => 'SKYNET-JAMBUWER',
        'SIDORONO' => 'SKYNET-KRIAN',
        'ARJOSARI' => 'SKYNET-ARJOSARI',
        'KRIAN' => 'SKYNET-KRIAN',
        'WAJAK' => 'SKYNET-WAJAK',
        'RANDUAGUNG' => 'SKYNET-RANDUAGUNG',
        'RANDU AGUNG' => 'SKYNET-RANDUAGUNG',
        'SRIGADING' => 'SKYNET-SRIGADING',
        'KENDIT' => 'SKYNET-KENDIT',
        'BUMIAYU' => 'SKYNET-BUMIAYU',
        'BUMI AYU' => 'SKYNET-BUMIAYU',
        'PANDOWO' => 'SUBNET-PANDOWO',
        'SENTUL' => 'SKYNET-SENTUL',
        'TUTUR' => 'SKYNET-TUTUR',
        'MARTOPURO' => 'SKYNET-MARTOPURO',
        'JATISARI' => 'SKYNET-JATISARI',
        'KAMPUNG BIRU' => 'SKYNET-KAMPUNG BIRU',
        'SIDOADI GARUM' => 'SKYNET-SIDOADI GARUM',
        'SIDOADI' => 'SKYNET-SIDOADI GARUM',
        'PURWODADI' => 'SKYNET-PURWODADI',
        'KERTOSARI' => 'SKYNET-KERTOSARI',
        'PROTONG' => 'SKYNET-PROTONG',
        'PASREPAN' => 'SKYNET-PASREPAN',
        'BANTARAN' => 'SKYNET-BANTARAN',
        'MALANG' => 'SKYNET-MALANG',
        'PLAOSAN' => 'SKYNET-PLAOSAN',
        'NONGKOJAJAR' => 'SKYNET-NONGKOJAJAR',
        'GAJAHREJO' => 'SKYNET-GAJAHREJO',
        'SONGSONG' => 'SKYNET-SONGSONG',
        'LAWANG' => 'SKYNET-LAWANG',
        'KUNCI' => 'SKYNET-KUNCI',
        'KARANGPLOSO' => 'SKYNET-KARANGPLOSO',
        'SINGOSARI' => 'SKYNET-SINGOSARI',
        'MERGOSONO' => 'SKYNET-MERGOSONO',
        'MULYOARJO' => 'SKYNET-MULYOARJO',
        'PASURUAN' => 'SKYNET-PASURUAN',
        'PAKIS' => 'SKYNET-PAKIS',
        'BLITAR' => 'SKYNET-BLITAR',
        'COMBORAN' => 'SKYNET-COMBORAN',
        'PURWOSARI' => 'SKYNET-PURWOSARI',
        'PUROWOSARI' => 'SKYNET-PURWOSARI',
        'ALAM HIJAU' => 'SKYNET-ALAMHIJAU',
        'ALAMHIJAU' => 'SKYNET-ALAMHIJAU',
        'YONKAV' => 'SKYNET-YONKAV',
        // Gadang + brand MikroTik "Dua Satu Network" (same OLT/router cluster)
        'DUA SATU NETWORK' => 'SKYNET-GADANG',
        'DUASATUNETWORK' => 'SKYNET-GADANG',
        'DUA SATU' => 'SKYNET-GADANG',
        'DUASATU' => 'SKYNET-GADANG',
        'GADANG' => 'SKYNET-GADANG',
        'SKYNET GADANG' => 'SKYNET-GADANG',
    ];

    /**
     * @var array<int, string>
     */
    private array $approvedAreas = [
        'SKYNET-ALAMHIJAU',
        'SKYNET-ARJOSARI',
        'SKYNET-BANTARAN',
        'SKYNET-BARENG MALANG',
        'SKYNET-BEDALI',
        'SKYNET-BLITAR',
        'SKYNET-BUKIT-SENTUL',
        'SKYNET-BUMIAYU',
        'SKYNET-COMBORAN',
        'SKYNET-GAJAHREJO',
        'SKYNET-GADANG',
        'SKYNET-JAMBUWER',
        'SKYNET-JATISARI',
        'SKYNET-KAMPUNG BIRU',
        'SKYNET-KARANGPLOSO',
        'SKYNET-KENDIT',
        'SKYNET-KERTOSARI',
        'SKYNET-KRIAN',
        'SKYNET-KUNCI',
        'SKYNET-LAWANG',
        'SKYNET-MALANG',
        'SKYNET-MARTOPURO',
        'SKYNET-MERGOSONO',
        'SKYNET-MULYOARJO',
        'SKYNET-NONGKOJAJAR',
        'SKYNET-PAKIS',
        'SKYNET-PASREPAN',
        'SKYNET-PASURUAN',
        'SKYNET-PLAOSAN',
        'SKYNET-PROTONG',
        'SKYNET-PURWODADI',
        'SKYNET-PURWOSARI',
        'SKYNET-RANDUAGUNG',
        'SKYNET-SENTUL',
        'SKYNET-SIDOADI GARUM',
        'SKYNET-SINGOSARI',
        'SKYNET-SONGSONG',
        'SKYNET-SRIGADING',
        'SKYNET-SUBNET',
        'SKYNET-TUTUR',
        'SKYNET-WAJAK',
        'SKYNET-YONKAV',
        'SUBNET-PAKIS',
        'SUBNET-PANDOWO',
        'SUBNET-WAJAK',
    ];

    /**
     * @return array{area: ?string, reason: string, source_value: ?string, valid: bool}
     */
    public function resolve(array $customer): array
    {
        $apiArea = $this->extractApiArea($customer);
        if ($apiArea) {
            $area = $this->normalizeAreaName($apiArea);
            if ($this->isApprovedArea($area)) {
                return $this->mapped($area, 'api_area', $apiArea);
            }
        }

        $legacyLocation = $this->stringValue($customer['nama_lokasi'] ?? null);
        if ($legacyLocation) {
            $area = $this->areaFromKeywords($legacyLocation) ?? $this->normalizeAreaName($legacyLocation);
            if ($this->isApprovedArea($area)) {
                return $this->mapped($area, 'legacy_location', $legacyLocation);
            }
        }

        $code = $this->extractCode($customer);
        if ($code) {
            $prefix = $this->extractPrefix($code);
            if ($prefix && isset($this->prefixMap[$prefix])) {
                return $this->mapped($this->prefixMap[$prefix], 'prefix', $prefix);
            }
        }

        $router = $this->extractRouterName($customer);
        if ($router) {
            $area = $this->areaFromKeywords($router) ?? $this->normalizeAreaName($router);
            if ($this->isApprovedArea($area)) {
                return $this->mapped($area, 'router_name', $router);
            }
        }

        $package = $this->extractPackageName($customer);
        if ($package) {
            $area = $this->areaFromKeywords($package);
            if ($area) {
                return $this->mapped($area, 'package_keyword', $package);
            }
        }

        $address = $this->stringValue($customer['address'] ?? $customer['alamat'] ?? null);
        if ($address) {
            $area = $this->areaFromKeywords($address);
            if ($area) {
                return $this->mapped($area, 'address_keyword', $address);
            }
        }

        return [
            'area' => null,
            'reason' => 'unmapped',
            'source_value' => null,
            'valid' => false,
        ];
    }

    public function normalizeAreaName(string $name): string
    {
        $name = strtoupper(trim($name));
        $name = str_replace('_', ' ', $name);
        $name = preg_replace('/\s+/', ' ', $name) ?: $name;
        $name = preg_replace('/\s*-\s*/', '-', $name) ?: $name;
        $name = str_replace('SKYNET ', 'SKYNET-', $name);

        $aliases = [
            'SKYNET-BEDALI INDAH' => 'SKYNET-BEDALI',
            'SKYNET-BEDALIINDAH' => 'SKYNET-BEDALI',
            'SKYNET-RANDU AGUNG' => 'SKYNET-RANDUAGUNG',
            'SKYNET-ALAM HIJAU' => 'SKYNET-ALAMHIJAU',
            'SKYNET-BUKIT SENTUL' => 'SKYNET-SENTUL',
            'SKYNET-BUKIT-SENTUL' => 'SKYNET-SENTUL',
            'SKYNET-PUROWOSARI' => 'SKYNET-PURWOSARI',
            'SKYNET-GADANG' => 'SKYNET-GADANG',
            'SKYNET-DUA SATU NETWORK' => 'SKYNET-GADANG',
            'SKYNET-DUASATUNETWORK' => 'SKYNET-GADANG',
            'SKYNET-DUA SATU' => 'SKYNET-GADANG',
            'SKYNET-DUASATU' => 'SKYNET-GADANG',
            'DUA SATU NETWORK' => 'SKYNET-GADANG',
            'DUASATUNETWORK' => 'SKYNET-GADANG',
        ];

        if (! str_starts_with($name, 'SKYNET-') && ! str_starts_with($name, 'SUBNET-')) {
            $name = 'SKYNET-'.$name;
        }

        return $aliases[$name] ?? $name;
    }

    public function isApprovedArea(?string $area): bool
    {
        if (! $area || $area === self::GENERAL_AREA) {
            return false;
        }

        return in_array($area, $this->approvedAreas, true);
    }

    /**
     * @return array<int, string>
     */
    public function approvedAreas(): array
    {
        return $this->approvedAreas;
    }

    /**
     * @return array<string, string>
     */
    public function prefixMap(): array
    {
        return $this->prefixMap;
    }

    private function extractApiArea(array $customer): ?string
    {
        if (isset($customer['area']) && is_array($customer['area'])) {
            return $this->stringValue($customer['area']['name'] ?? null);
        }

        return $this->stringValue($customer['area_name'] ?? null);
    }

    private function extractPackageName(array $customer): ?string
    {
        if (isset($customer['package']) && is_array($customer['package'])) {
            return $this->stringValue($customer['package']['name'] ?? null);
        }

        return $this->stringValue($customer['package'] ?? $customer['paket'] ?? null);
    }

    private function extractRouterName(array $customer): ?string
    {
        return $this->stringValue(
            $customer['router_name']
                ?? $customer['nama_router']
                ?? $customer['router']
                ?? null
        );
    }

    private function extractCode(array $customer): ?string
    {
        return $this->stringValue(
            $customer['code']
                ?? $customer['id']
                ?? $customer['id_pelanggan']
                ?? $customer['customer_id']
                ?? null
        );
    }

    private function extractPrefix(string $code): ?string
    {
        if (preg_match('/^[A-Z]+/i', trim($code), $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[0]);
    }

    private function areaFromKeywords(string $text): ?string
    {
        $haystack = strtoupper($text);
        $haystack = str_replace(['_', '-'], ' ', $haystack);
        $haystack = preg_replace('/\s+/', ' ', $haystack) ?: $haystack;

        foreach ($this->keywordMap as $keyword => $area) {
            if (str_contains($haystack, $keyword)) {
                return $area;
            }
        }

        return null;
    }

    /**
     * @return array{area: string, reason: string, source_value: string, valid: bool}
     */
    private function mapped(string $area, string $reason, string $sourceValue): array
    {
        return [
            'area' => $area,
            'reason' => $reason,
            'source_value' => $sourceValue,
            'valid' => true,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
