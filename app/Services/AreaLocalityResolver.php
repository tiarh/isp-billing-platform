<?php

namespace App\Services;

use App\Models\Area;
use App\Models\AreaAlias;
use Illuminate\Support\Str;

/**
 * Resolves free-text locality labels (PSB "daerah", WA text) to an Area.
 *
 * Read-only. Priority: alias > name > code. Two different areas at the same
 * priority is "ambiguous". Levenshtein <= 1 is only ever a suggestion
 * (candidates), never an automatic match.
 *
 * (areas has no active flag, so all areas take part.)
 */
class AreaLocalityResolver
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_UNMATCHED = 'unmatched';

    /** Tokens dropped during normalization (administrative prefixes). */
    private const PREFIX_TOKENS = [
        'desa', 'ds', 'dsn', 'dusun', 'kel', 'kelurahan', 'kec', 'kecamatan',
        'dukuh', 'dk', 'perum', 'perumahan',
    ];

    private const PRIORITY = ['alias', 'name', 'code'];

    /**
     * Index cached per instance (one query pair per instance, not per resolve()).
     * Use a fresh instance or flush() after areas/aliases change.
     *
     * @var array{alias: array<string, list<int>>, name: array<string, list<int>>, code: array<string, list<int>>}|null
     */
    private ?array $index = null;

    public function flush(): void
    {
        $this->index = null;
    }

    /**
     * Normalized token list of a free-text label.
     *
     * @return list<string>
     */
    public static function tokens(?string $value): array
    {
        $value = Str::lower(Str::ascii((string) $value));
        // "RT 03", "rw.5", "RT03/RW05", "RT 03/05" -> removed together with their number(s).
        $value = (string) preg_replace('/\b(rt|rw)\s*[.:]?\s*\d+(\s*\/\s*\d+)?/', ' ', $value);
        $value = (string) preg_replace('/[^a-z0-9]+/', ' ', $value);

        $tokens = [];
        foreach (explode(' ', trim($value)) as $token) {
            if ($token === '' || $token === 'rt' || $token === 'rw' || in_array($token, self::PREFIX_TOKENS, true)) {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    /** Whole-label key: normalized tokens concatenated ([a-z0-9] only). */
    public static function normalize(?string $value): string
    {
        return implode('', self::tokens($value));
    }

    /** Key of an area name: "SKYNET-"/"SUBNET-" prefix removed first. */
    public static function nameKey(?string $name): string
    {
        $name = (string) preg_replace('/^\s*(skynet|subnet)\s*[-_ ]\s*/i', '', (string) $name);

        return self::normalize($name);
    }

    /**
     * @return array{status: string, area_id: ?int, matched_key: ?string, via: ?string, candidates: list<array{area_id: int, key: string, via: string}>}
     */
    public function resolve(?string ...$labels): array
    {
        $index = $this->index ??= $this->buildIndex();

        $wholeKeys = [];
        $tokenKeys = [];
        foreach ($labels as $label) {
            if ($label === null || trim($label) === '') {
                continue;
            }
            $tokens = self::tokens($label);
            if ($tokens === []) {
                continue;
            }
            $wholeKeys[] = implode('', $tokens);
            foreach ($tokens as $token) {
                // A digit-only token is a house/RT number, never a locality by itself
                // (it still counts inside the whole-label key: "Randuagung 2").
                if (! ctype_digit($token)) {
                    $tokenKeys[] = $token;
                }
            }
        }
        $wholeKeys = array_values(array_unique($wholeKeys));
        $tokenKeys = array_values(array_unique($tokenKeys));

        foreach ([$wholeKeys, $tokenKeys] as $probes) {
            foreach (self::PRIORITY as $via) {
                $hits = [];
                foreach ($probes as $probe) {
                    foreach ($index[$via][$probe] ?? [] as $areaId) {
                        $hits[$areaId] ??= $probe;
                    }
                }
                if (count($hits) === 1) {
                    $areaId = (int) array_key_first($hits);

                    return $this->result(self::STATUS_MATCHED, $areaId, $hits[$areaId], $via, []);
                }
                if (count($hits) > 1) {
                    $candidates = [];
                    foreach ($hits as $areaId => $key) {
                        $candidates[] = ['area_id' => (int) $areaId, 'key' => $key, 'via' => $via];
                    }

                    return $this->result(self::STATUS_AMBIGUOUS, null, null, null, $candidates);
                }
            }
        }

        return $this->result(self::STATUS_UNMATCHED, null, null, null, $this->suggest(array_merge($wholeKeys, $tokenKeys), $index));
    }

    /**
     * Index: via => key => list of area ids.
     *
     * @return array{alias: array<string, list<int>>, name: array<string, list<int>>, code: array<string, list<int>>}
     */
    private function buildIndex(): array
    {
        $index = ['alias' => [], 'name' => [], 'code' => []];

        foreach (Area::query()->get(['id', 'name', 'code']) as $area) {
            $nameKey = self::nameKey($area->name);
            if ($nameKey !== '') {
                $index['name'][$nameKey][$area->id] = $area->id;
            }
            $codeKey = self::normalize($area->code);
            if ($codeKey !== '') {
                $index['code'][$codeKey][$area->id] = $area->id;
            }
        }
        foreach (AreaAlias::query()->get(['area_id', 'alias_key']) as $alias) {
            if ($alias->alias_key !== '') {
                $index['alias'][$alias->alias_key][$alias->area_id] = $alias->area_id;
            }
        }

        foreach ($index as $via => $keys) {
            foreach ($keys as $key => $ids) {
                $index[$via][$key] = array_values($ids);
            }
        }

        return $index;
    }

    /**
     * Near-miss suggestions (levenshtein <= 1). Never an automatic match.
     *
     * @param  list<string>  $probes
     * @return list<array{area_id: int, key: string, via: string}>
     */
    private function suggest(array $probes, array $index): array
    {
        $out = [];
        $seen = [];
        foreach ($probes as $probe) {
            if (strlen($probe) < 4) {
                continue;
            }
            foreach (self::PRIORITY as $via) {
                foreach ($index[$via] as $key => $areaIds) {
                    $key = (string) $key;
                    if (abs(strlen($key) - strlen($probe)) > 1 || levenshtein($probe, $key) > 1) {
                        continue;
                    }
                    foreach ($areaIds as $areaId) {
                        if (isset($seen[$areaId])) {
                            continue;
                        }
                        $seen[$areaId] = true;
                        $out[] = ['area_id' => (int) $areaId, 'key' => $key, 'via' => $via];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<array{area_id: int, key: string, via: string}>  $candidates
     * @return array{status: string, area_id: ?int, matched_key: ?string, via: ?string, candidates: list<array{area_id: int, key: string, via: string}>}
     */
    private function result(string $status, ?int $areaId, ?string $key, ?string $via, array $candidates): array
    {
        return [
            'status' => $status,
            'area_id' => $areaId,
            'matched_key' => $key,
            'via' => $via,
            'candidates' => $candidates,
        ];
    }
}
