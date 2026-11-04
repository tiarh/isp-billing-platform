<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AllowOltC300Api
{
    /**
     * Handle OLT C300 API requests.
     * - OCR endpoint: enforce its rate limit; route middleware still requires auth
     * - Other OLT API endpoints: require auth (handled by route middleware)
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('api/olt-c300/ocr')) {
            // Rate limit OCR: 10 requests per minute per IP
            $key = 'olt-ocr:'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 10)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'error' => 'Too many requests.',
                    'retry_after_seconds' => $seconds,
                ], 429);
            }
            RateLimiter::hit($key, 60);

            // Validate image payload
            if (! $request->hasFile('image') && ! $request->input('image_base64')) {
                return response()->json(['error' => 'Image required.'], 422);
            }
        }

        // Rate limit other OLT API endpoints: 30 per minute per user
        if ($request->is('api/olt-c300/*') && ! $request->is('api/olt-c300/ocr')) {
            $userId = $request->user()?->id ?? $request->ip();
            $key = 'olt-api:'.$userId;
            if (RateLimiter::tooManyAttempts($key, 30)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'error' => 'Too many requests.',
                    'retry_after_seconds' => $seconds,
                ], 429);
            }
            RateLimiter::hit($key, 60);
        }

        return $next($request);
    }
}
