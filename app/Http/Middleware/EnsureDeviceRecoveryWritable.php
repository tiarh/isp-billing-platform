<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDeviceRecoveryWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('device-recovery.mode') !== 'write') {
            return response()->json([
                'success' => false,
                'error_code' => 'device_recovery_read_only',
                'message' => 'Pengambilan perangkat sedang dalam mode read-only. Tidak ada perubahan yang disimpan.',
            ], 423);
        }

        return $next($request);
    }
}
