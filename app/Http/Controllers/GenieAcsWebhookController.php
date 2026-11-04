<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessGenieAcsInformJob;
use App\Services\GenieAcsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Public webhook for GenieACS extension / external inform notifier.
 * Auth: X-GenieACS-Token or Authorization Bearer = GENIEACS_WEBHOOK_TOKEN
 */
class GenieAcsWebhookController extends Controller
{
    public function device(Request $request, GenieAcsService $genieacs): JsonResponse
    {
        if (! $this->tokenValid($request)) {
            Log::warning('[GenieACS webhook] unauthorized', ['ip' => $request->ip()]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->validate([
            'device_id' => ['nullable', 'string', 'max:255'],
            '_id' => ['nullable', 'string', 'max:255'],
            'serial' => ['nullable', 'string', 'max:64'],
            'serial_number' => ['nullable', 'string', 'max:64'],
            'tag' => ['nullable', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:128'],
            'event' => ['nullable', 'string', 'max:64'],
            'async' => ['nullable', 'boolean'],
        ]);

        $serial = $payload['serial'] ?? $payload['serial_number'] ?? null;
        $deviceId = $payload['device_id'] ?? $payload['_id'] ?? null;
        if (empty($serial) && empty($deviceId)) {
            return response()->json(['error' => 'device_id or serial required'], 422);
        }

        $normalized = [
            'device_id' => $deviceId,
            'serial' => $serial,
            'tag' => $payload['tag'] ?? $payload['name'] ?? null,
            'event' => $payload['event'] ?? 'webhook',
        ];

        // Default async — keep ACS ext HTTP fast
        $async = $request->boolean('async', true);
        if ($async) {
            ProcessGenieAcsInformJob::dispatch($normalized);

            return response()->json([
                'accepted' => true,
                'mode' => 'async',
                'message' => 'Queued for ingest + auto-tag',
            ]);
        }

        $result = $genieacs->ingestDeviceInform($normalized);

        return response()->json($result, ($result['ok'] ?? false) ? 200 : 422);
    }

    protected function tokenValid(Request $request): bool
    {
        $token = (string) config('services.genieacs.webhook_token', '');
        if ($token === '') {
            // Misconfigured — refuse (do not open unauthenticated write)
            return false;
        }

        $provided = (string) (
            $request->header('X-GenieACS-Token')
            ?: $request->header('X-Webhook-Token')
            ?: $request->bearerToken()
            ?: $request->input('token')
            ?: ''
        );

        return $provided !== '' && hash_equals($token, $provided);
    }
}
