<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Services\Routers\UnlinkedSecretDeleteGuard;
use App\Services\Routers\UnlinkedSecretQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Daftar secret PPPoE yang ada di router tetapi belum terhubung ke pelanggan eBilling.
 * Hanya baca dari database; tidak menyentuh MikroTik.
 */
class RouterUnlinkedSecretController extends Controller
{
    public function index(Request $request, Router $router, UnlinkedSecretQuery $query, UnlinkedSecretDeleteGuard $deleteGuard): JsonResponse
    {
        $request->validate(['search' => ['nullable', 'string', 'max:'.UnlinkedSecretQuery::MAX_SEARCH]]);

        $page = $query->paginate($router, $request->input('search'));

        return response()->json($page->toArray() + [
            'meta' => [
                'router_active' => (bool) $router->is_active,
                'last_scanned_at' => $router->last_scanned_at?->toIso8601String(),
                'can_add' => (bool) $router->is_active,
                // Tombol Hapus muncul hanya untuk superadmin/NOC, flag menyala, mode aman mati, router aktif.
                'can_delete' => $deleteGuard->canShow($router, $request->user()),
            ],
        ]);
    }
}
