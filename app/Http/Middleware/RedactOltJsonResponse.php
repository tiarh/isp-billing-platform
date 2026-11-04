<?php

namespace App\Http\Middleware;

use App\Support\OltResponseSanitizer;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedactOltJsonResponse
{
    public function __construct(private readonly OltResponseSanitizer $sanitizer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);
            if (is_array($data)) {
                $response->setData($this->sanitizer->sanitize($data));
            }
        }

        return $response;
    }
}
