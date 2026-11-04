<?php

namespace App\Http\Controllers;

use App\Models\OutageSignal;
use App\Services\Outage\OutageManualTicketException;
use App\Services\Outage\OutageManualTicketService;
use App\Services\Outage\OutageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manual "Buat tiket" of the Deteksi Gangguan tab. Global-admin route; inside: setting on AND role allowed.
 * Separate from OutageShadowController, which stays read-only. No customer WA, no ticket-form store path.
 */
class OutageManualTicketController extends Controller
{
    public function preview(Request $request, OutageSignal $signal, OutageManualTicketService $service): JsonResponse
    {
        if (! $this->allowed($request)) {
            return response()->json(['message' => 'Anda tidak punya akses membuat tiket.'], 403);
        }
        try {
            return response()->json(['data' => $service->preview($signal)]);
        } catch (OutageManualTicketException $e) {
            return response()->json(['message' => $e->getMessage(), 'ticket_id' => $e->ticketId], $e->status);
        }
    }

    public function store(Request $request, OutageSignal $signal, OutageManualTicketService $service): JsonResponse
    {
        if (! $this->allowed($request)) {
            return response()->json(['message' => 'Anda tidak punya akses membuat tiket.'], 403);
        }
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', Rule::in(['low', 'normal', 'high', 'urgent'])],
        ]);

        try {
            $result = $service->create($signal, $request->user(), $data['note'] ?? null, $data['priority'] ?? null);
        } catch (OutageManualTicketException $e) {
            return response()->json(['message' => $e->getMessage(), 'ticket_id' => $e->ticketId], $e->status);
        }

        return response()->json([
            'created' => $result['created'],
            'ticket' => $service->ticketInfo($result['ticket']),
            'message' => $result['created']
                ? 'Tiket #'.$result['ticket']->id.' dibuat.'
                : 'Tiket sudah ada: #'.$result['ticket']->id.'.',
        ], $result['created'] ? 201 : 200);
    }

    private function allowed(Request $request): bool
    {
        return OutageSettings::manualTicketAllowedTo($request->user()?->role);
    }
}
