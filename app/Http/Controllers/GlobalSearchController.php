<?php

namespace App\Http\Controllers;

use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearchService $service): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:64', 'regex:/^[\pL\pN\s\-\._@+]+$/u'],
        ]);

        $started = microtime(true);
        $groups = $service->search($request->user(), $data['q']);

        // No caching and no logging of the term: it can be a name or phone number.
        return response()->json([
            'q' => $data['q'],
            'groups' => $groups,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
        ]);
    }
}
