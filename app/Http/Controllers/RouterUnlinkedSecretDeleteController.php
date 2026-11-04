<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\RouterStagedCustomer;
use App\Services\Routers\UnlinkedSecretDeleteGuard;
use App\Services\Routers\UnlinkedSecretDeleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Hapus secret" untuk secret yang belum terhubung. Hanya superadmin dan NOC.
 * Semua aturan ada di UnlinkedSecretDeleteGuard dan UnlinkedSecretDeleter; ini hanya pintu masuknya.
 */
class RouterUnlinkedSecretDeleteController extends Controller
{
    public function check(Request $request, Router $router, RouterStagedCustomer $stagedCustomer, UnlinkedSecretDeleteGuard $guard, UnlinkedSecretDeleter $deleter): JsonResponse
    {
        abort_unless($guard->actorAllowed($request->user()), 403);

        return $this->respond($deleter->check($router, $stagedCustomer));
    }

    public function destroy(Request $request, Router $router, RouterStagedCustomer $stagedCustomer, UnlinkedSecretDeleteGuard $guard, UnlinkedSecretDeleter $deleter): JsonResponse
    {
        abort_unless($guard->actorAllowed($request->user()), 403);

        $input = $request->validate([
            'confirm_username' => ['required', 'string', 'max:128'],
            'disconnect_online' => ['nullable', 'boolean'],
            'lookalike_ack' => ['nullable', 'boolean'],
            'expected_profile' => ['nullable', 'string', 'max:128'],
            'expected_disabled' => ['nullable', 'boolean'],
            'request_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
        ]);

        return $this->respond($deleter->delete($router, $stagedCustomer, $request->user(), $input));
    }

    /** @param  array<string, mixed>  $result */
    private function respond(array $result): JsonResponse
    {
        return response()->json($result, (int) ($result['status'] ?? 200));
    }
}
