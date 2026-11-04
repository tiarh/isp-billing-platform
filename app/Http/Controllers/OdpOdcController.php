<?php

namespace App\Http\Controllers;

use App\Models\OdpOdc;
use App\Support\AreaScope;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ODP/ODC asset CRUD (FTTH topology). Writes are admin-level and area-scoped.
 */
class OdpOdcController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'area_id' => ['required', 'exists:areas,id'],
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'type' => ['required', Rule::in(['ODP', 'ODC', 'POP'])],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'parent_id' => [
                'nullable', 'integer', 'exists:odp_odc,id',
                // An ODP may have a parent ODC/POP; ODC/POP must not have a parent.
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (in_array($request->input('type'), ['ODC', 'POP'], true)) {
                        $fail('ODC/POP tidak boleh punya parent.');
                    }
                },
            ],
            'olt_port' => ['nullable', 'string', 'max:50'],
            'total_ports' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        AreaScope::authorizeAreaId((int) $validated['area_id'], $request->user());
        $validated['parent_id'] = $validated['parent_id'] ?? null;

        OdpOdc::create($validated);

        return back()->with('success', 'ODP/ODC dibuat.');
    }

    public function update(Request $request, OdpOdc $odpOdc)
    {
        $validated = $request->validate([
            'area_id' => ['sometimes', 'integer', 'exists:areas,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:64'],
            'type' => ['sometimes', Rule::in(['ODP', 'ODC', 'POP'])],
            'lat' => ['sometimes', 'numeric', 'between:-90,90'],
            'lng' => ['sometimes', 'numeric', 'between:-180,180'],
            'parent_id' => ['nullable', 'integer', 'exists:odp_odc,id', 'not_in:'.$odpOdc->id],
            'olt_port' => ['nullable', 'string', 'max:50'],
            'total_ports' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        if (isset($validated['area_id'])) {
            AreaScope::authorizeAreaId((int) $validated['area_id'], $request->user());
        }

        $odpOdc->update($validated);

        return back()->with('success', 'ODP/ODC diperbarui.');
    }

    public function destroy(OdpOdc $odpOdc)
    {
        // Detach links so we never orphan customers silently.
        $odpOdc->children()->update(['parent_id' => null]);
        $odpOdc->customers()->update(['odp_odc_id' => null]);
        $odpOdc->delete();

        return back()->with('success', 'ODP/ODC dihapus.');
    }
}
