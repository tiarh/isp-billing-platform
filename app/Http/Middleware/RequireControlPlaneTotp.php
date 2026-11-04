<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireControlPlaneTotp
{
    /** Session key written ONLY by TotpController::stepUp after a verified code. */
    public const VERIFIED_KEY = 'auth.totp_step_up_verified';

    /** Session key describing which mutation is waiting for a code (never grants access). */
    public const PENDING_ROUTE_KEY = 'auth.totp_step_up_route';

    public const RETURN_URL_KEY = 'auth.totp_step_up_return_url';

    /** A verified step-up is only usable for this long and only once. */
    public const VERIFIED_TTL_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->guest(route('login'));
        }

        // global_admin roles keep their existing authorization contract. The
        // step-up requirement is mandatory only for superadmin actors.
        if (! $request->user()?->isSuperAdmin()) {
            return $next($request);
        }

        if (! $request->user()->totpIsEnabled()) {
            return redirect()->route('totp.enroll')->with('warning', 'Aktifkan TOTP sebelum mutasi control plane.');
        }

        $routeName = (string) ($request->route()?->getName() ?? '');

        // The grant is consumed (pull) so it is one-time, and it must have
        // been issued by a successful code verification for this exact route
        // and user. The middleware itself never writes this key: previously the
        // pending route marker doubled as the grant, so simply resubmitting the
        // mutation after the redirect satisfied the "step-up".
        $grant = $request->session()->pull(self::VERIFIED_KEY);
        if ($routeName !== '' && self::grantIsValid($grant, $routeName, (int) $request->user()->getAuthIdentifier())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'totp_step_up_required',
                'message' => 'Verifikasi TOTP diperlukan sebelum mutasi control plane.',
            ], 428);
        }

        $request->session()->put([
            self::PENDING_ROUTE_KEY => $routeName,
            self::RETURN_URL_KEY => url()->previous(),
        ]);

        return redirect()->route('totp.step-up');
    }

    /**
     * @param  mixed  $grant
     */
    public static function grantIsValid($grant, string $routeName, int $userId): bool
    {
        if (! is_array($grant)) {
            return false;
        }

        return ($grant['route'] ?? null) === $routeName
            && (int) ($grant['user_id'] ?? 0) === $userId
            && (int) ($grant['expires_at'] ?? 0) >= time();
    }
}
