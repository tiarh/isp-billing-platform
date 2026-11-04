<?php

namespace App\Http\Controllers;

use App\Models\Olt;
use App\Models\OltVlanMapping;
use Illuminate\Http\Request;

class OltVlanMappingController extends Controller
{
    public function index(Olt $olt)
    {
        return response()->json([
            'data' => OltVlanMapping::query()
                ->where('olt_id', $olt->id)
                ->orderBy('pon_port')
                ->orderBy('service_type')
                ->get(['id', 'olt_id', 'pon_port', 'service_type', 'vlan', 'description', 'is_active']),
        ]);
    }

    public function store(Request $request, Olt $olt)
    {
        $validated = $request->validate([
            'pon_port' => ['required', 'string', 'max:32', 'regex:/^\d+\/\d+\/\d+$/'],
            'service_type' => ['nullable', 'string', 'max:32'],
            'vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $ponPort = preg_replace('/\s+/', '', trim((string) $validated['pon_port'])) ?? trim((string) $validated['pon_port']);
        $ponPort = preg_replace('/^gpon-olt_/i', '', $ponPort) ?? $ponPort;
        if (preg_match('/^(\d+\/\d+\/\d+)/', $ponPort, $m)) {
            $ponPort = $m[1];
        }

        $mapping = OltVlanMapping::updateOrCreate(
            [
                'olt_id' => $olt->id,
                'pon_port' => $ponPort,
                'service_type' => $validated['service_type'] ?: 'internet',
            ],
            [
                'vlan' => $validated['vlan'],
                'description' => $validated['description'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ],
        );

        return response()->json(['data' => $mapping->fresh()], 201);
    }

    public function update(Request $request, OltVlanMapping $mapping)
    {
        $validated = $request->validate([
            'vlan' => ['required', 'integer', 'min:1', 'max:4094'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $mapping->update($validated);

        return response()->json(['data' => $mapping->fresh()]);
    }

    public function destroy(OltVlanMapping $mapping)
    {
        $mapping->update(['is_active' => false]);

        return response()->noContent();
    }
}
