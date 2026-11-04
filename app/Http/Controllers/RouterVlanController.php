<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Router;
use App\Models\RouterVlan;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD VLAN service per MikroTik — dipakai Aktivasi tab + PSB HTB (Sprint 2).
 * Tidak menyentuh OLT C300 FiberHome / PSB WA orchestrator.
 */
class RouterVlanController extends Controller
{
    public function index(Request $request)
    {
        $routerId = (int) $request->query('router_id', 0);

        $query = RouterVlan::query()
            ->with([
                'router:id,name',
                'area:id,name',
            ])
            ->orderBy('router_id')
            ->orderByDesc('is_default')
            ->orderBy('vlan');

        if ($routerId > 0) {
            $query->where('router_id', $routerId);
        }

        $routers = Router::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address']);

        $areas = Area::query()->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'data' => $query->get(),
            'routers' => $routers,
            'areas' => $areas,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'router_id' => ['required', 'integer', 'exists:routers,id'],
            'vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'label' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'mikrotik_profile' => ['nullable', 'string', 'max:64'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $validated['is_default'] = (bool) ($validated['is_default'] ?? false);
        $validated['is_active'] = array_key_exists('is_active', $validated)
            ? (bool) $validated['is_active']
            : true;

        // Only one default per router
        if ($validated['is_default']) {
            RouterVlan::query()
                ->where('router_id', $validated['router_id'])
                ->update(['is_default' => false]);
        }

        $row = RouterVlan::query()->updateOrCreate(
            [
                'router_id' => $validated['router_id'],
                'vlan' => $validated['vlan'],
            ],
            $validated,
        );

        return response()->json([
            'success' => true,
            'data' => $row->load(['router:id,name', 'area:id,name']),
        ], 201);
    }

    public function update(Request $request, RouterVlan $routerVlan)
    {
        $validated = $request->validate([
            'vlan' => [
                'sometimes',
                'integer',
                'min:1',
                'max:4094',
                Rule::unique('router_vlans', 'vlan')
                    ->where(fn ($q) => $q->where('router_id', $routerVlan->router_id))
                    ->ignore($routerVlan->id),
            ],
            'label' => ['nullable', 'string', 'max:120'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'mikrotik_profile' => ['nullable', 'string', 'max:64'],
            'is_default' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        if (! empty($validated['is_default'])) {
            RouterVlan::query()
                ->where('router_id', $routerVlan->router_id)
                ->where('id', '!=', $routerVlan->id)
                ->update(['is_default' => false]);
        }

        $routerVlan->update($validated);

        return response()->json([
            'success' => true,
            'data' => $routerVlan->fresh()->load(['router:id,name', 'area:id,name']),
        ]);
    }

    public function destroy(RouterVlan $routerVlan)
    {
        $routerVlan->update(['is_active' => false]);

        return response()->json(['success' => true]);
    }
}
