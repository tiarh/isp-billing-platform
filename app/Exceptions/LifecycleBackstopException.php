<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Roadmap 1.3 A10 (enforce mode): an unguarded write tried to terminate or
 * archive a customer that is provably in use. Never thrown in log mode.
 */
class LifecycleBackstopException extends RuntimeException
{
    /** @param  list<string>  $reasons */
    public function __construct(public readonly int $customerId, string $customerCode, public readonly array $reasons)
    {
        parent::__construct(sprintf(
            'Pagar terminate: pelanggan %s masih terdeteksi dipakai (%s); perubahan ke terminated/arsip ditolak. Gunakan menu Pelanggan (dengan pagar) atau minta superadmin.',
            $customerCode,
            implode(', ', array_map(static fn (string $r): string => match ($r) {
                'pppoe_in_use' => 'sesi PPPoE masih aktif',
                'onu_in_use' => 'ONU masih online',
                default => $r,
            }, $reasons)),
        ));
    }

    /** Show the T9 text instead of a generic 500 (web/Inertia: back with error; JSON: 422). */
    public function render(Request $request): RedirectResponse|JsonResponse
    {
        // N6: api/* is JSON even without an Accept header (matches bootstrap/app.php).
        if (($request->expectsJson() || $request->is('api/*')) && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $this->getMessage()], 422);
        }

        return back()->withErrors(['lifecycle_guard' => $this->getMessage()])->with('error', $this->getMessage());
    }
}
