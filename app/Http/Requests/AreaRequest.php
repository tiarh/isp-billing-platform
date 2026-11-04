<?php

namespace App\Http\Requests;

use App\Models\AreaAlias;
use App\Services\AreaLocalityResolver;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AreaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $areaId = $this->route('area')?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('areas', 'name')->ignore($areaId), $this->notAnotherAreasAlias($areaId, true)],
            'code' => ['required', 'string', 'max:255', Rule::unique('areas', 'code')->ignore($areaId), $this->notAnotherAreasAlias($areaId, false)],
            'aliases' => ['nullable', 'string', 'max:1000', function (string $attribute, mixed $value, Closure $fail) use ($areaId): void {
                $input = is_string($value) ? $value : null;
                foreach (AreaAlias::rawList($input) as $raw) {
                    if (mb_strlen($raw) > 255) {
                        $fail('Alias terlalu panjang (maks 255 karakter).');
                    } elseif (AreaLocalityResolver::normalize($raw) === '') {
                        $fail("Alias \"{$raw}\" tidak valid (tidak ada kata yang bisa dicocokkan).");
                    }
                }
                foreach (AreaAlias::parseList($input) as $key => $alias) {
                    if (strlen($key) > 255) {
                        $fail("Alias \"{$alias}\" terlalu panjang.");

                        continue;
                    }
                    if (($reason = AreaAlias::conflictFor($key, $areaId ? (int) $areaId : null)) !== null) {
                        $fail("Alias \"{$alias}\" tidak bisa dipakai: {$reason}.");
                    }
                }
            }],
        ];
    }

    /**
     * An area name/code must not normalize to a key another area already owns as
     * an alias (it would make the locality resolver ambiguous).
     */
    private function notAnotherAreasAlias(?int $areaId, bool $isName): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($areaId, $isName): void {
            if (! is_string($value)) {
                return;
            }
            $key = $isName ? AreaLocalityResolver::nameKey($value) : AreaLocalityResolver::normalize($value);
            if ($key === '') {
                return;
            }
            $other = AreaAlias::query()->where('alias_key', $key)
                ->when($areaId, fn ($q) => $q->where('area_id', '!=', $areaId))
                ->with('area:id,name')->first();
            if ($other !== null) {
                $fail('Nama/kode ini sama dengan alias area '.($other->area?->name ?? '#'.$other->area_id).'.');
            }
        };
    }
}
