<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proxy for files on the public disk (S3/MinIO), served from the app domain.
 *
 * - Sensitive folders are never served here (staff use the signed media routes).
 * - Anonymous visitors only get the folders in ANONYMOUS_PREFIXES.
 * - A logged-in staff session gets every other folder.
 * - Anything else answers 404, so a guesser learns nothing.
 */
class StorageProxyController extends Controller
{
    /** Never served through this route, for anyone. */
    public const PROTECTED_PREFIXES = [
        'ktp/',
        'odp/',
        'psb/',
        'migrasi/',
        'payment-proofs/',
        'tickets/',
    ];

    /** Folders that may be opened without a login (customer-facing media). */
    public const ANONYMOUS_PREFIXES = [
        'campaigns/',
    ];

    public function show(Request $request, string $path): Response
    {
        $path = str_replace('\\', '/', rawurldecode($path));
        $segments = explode('/', trim($path, '/'));

        if ($path === '' || str_contains($path, "\0") || array_intersect($segments, ['.', '..'])) {
            abort(404);
        }

        $path = implode('/', $segments);
        $lower = strtolower($path);

        if (Str::startsWith($lower, self::PROTECTED_PREFIXES)) {
            abort(404);
        }

        if ($request->user() === null && ! Str::startsWith($lower, self::ANONYMOUS_PREFIXES)) {
            abort(404);
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
