<?php

namespace App\Models;

use App\Services\AreaLocalityResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaAlias extends Model
{
    protected $fillable = ['area_id', 'alias', 'alias_key', 'created_by'];

    /**
     * Parse a comma/newline separated alias list, de-duplicated by alias_key.
     *
     * @return array<string, string> alias_key => alias (trimmed display text)
     */
    public static function parseList(?string $input): array
    {
        $out = [];
        foreach (static::rawList($input) as $alias) {
            $key = AreaLocalityResolver::normalize($alias);
            if ($key === '') {
                // e.g. "RT 03" or "Desa": nothing left to match on.
                continue;
            }
            $out[$key] ??= $alias;
        }

        return $out;
    }

    /**
     * Trimmed, whitespace-collapsed raw aliases (no normalization, no de-dup).
     *
     * @return list<string>
     */
    public static function rawList(?string $input): array
    {
        $out = [];
        foreach (preg_split('/[,\n;]+/', (string) $input) ?: [] as $raw) {
            $alias = trim((string) preg_replace('/\s+/', ' ', $raw));
            if ($alias !== '') {
                $out[] = $alias;
            }
        }

        return $out;
    }

    /**
     * Why an alias key cannot be used for an area, or null when it is free.
     * Conflicts: another area's alias, name or code key.
     */
    public static function conflictFor(string $key, ?int $areaId): ?string
    {
        $other = static::query()->where('alias_key', $key)
            ->when($areaId, fn ($q) => $q->where('area_id', '!=', $areaId))
            ->with('area:id,name')->first();
        if ($other !== null) {
            return 'dipakai area '.($other->area?->name ?? '#'.$other->area_id);
        }

        foreach (Area::query()->when($areaId, fn ($q) => $q->where('id', '!=', $areaId))->get(['id', 'name', 'code']) as $area) {
            if (AreaLocalityResolver::nameKey($area->name) === $key || AreaLocalityResolver::normalize($area->code) === $key) {
                return 'sama dengan nama/kode area '.$area->name;
            }
        }

        return null;
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
