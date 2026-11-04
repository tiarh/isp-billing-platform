<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireControlPlaneTotp;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TotpController extends Controller
{
    public function enrollment(Request $request): Response|RedirectResponse
    {
        $user = $this->superadmin($request);
        if (! $user) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/Totp', [
            'enabled' => $user->totpIsEnabled(),
            'issuer' => config('totp.issuer'),
            'digits' => config('totp.digits'),
            'period' => config('totp.period'),
            'recovery_codes' => config('totp.recovery_codes'),
        ]);
    }

    public function beginEnrollment(Request $request, TotpService $totp): JsonResponse
    {
        $user = $this->superadmin($request);
        abort_unless($user, 403);
        abort_if($user->totpIsEnabled(), 409, 'TOTP sudah aktif.');

        $secret = $totp->generateSecret();
        $recoveryCodes = $totp->generateRecoveryCodes();
        $request->session()->put('auth.totp_enrollment', [
            'user_id' => $user->id,
            'secret' => $secret,
            'recovery_codes' => $recoveryCodes,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return response()->json([
            'issuer' => config('totp.issuer'),
            'account' => $user->email,
            'secret' => $secret,
            'otpauth_uri' => $totp->provisioningUri($user->email, $secret),
            'recovery_codes' => $recoveryCodes,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function confirmEnrollment(Request $request, TotpService $totp): RedirectResponse
    {
        $user = $this->superadmin($request);
        abort_unless($user, 403);
        $request->validate(['code' => ['required', 'digits:6']]);
        $enrollment = $request->session()->get('auth.totp_enrollment');

        if (! is_array($enrollment)
            || (int) ($enrollment['user_id'] ?? 0) !== $user->id
            || (int) ($enrollment['expires_at'] ?? 0) < now()->getTimestamp()) {
            throw ValidationException::withMessages(['code' => 'Sesi enrollment TOTP sudah kedaluwarsa.']);
        }

        if ($totp->verifyCode((string) $enrollment['secret'], (string) $request->string('code')) === null) {
            throw ValidationException::withMessages(['code' => 'Kode TOTP tidak valid.']);
        }

        $totp->enable($user, (string) $enrollment['secret'], (array) $enrollment['recovery_codes']);
        $request->session()->forget('auth.totp_enrollment');
        activity('totp.enrolled')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['policy_version' => config('totp.policy_version')])
            ->log('totp.enrolled');

        return redirect()->route('totp.enroll')->with('success', 'TOTP berhasil diaktifkan.');
    }

    public function challengePage(Request $request): Response|RedirectResponse
    {
        if (! $request->session()->has('auth.totp_pending_user_id')) {
            return redirect()->route('login');
        }

        return Inertia::render('Auth/TotpChallenge');
    }

    public function challenge(Request $request, TotpService $totp): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:32']]);
        $userId = (int) $request->session()->get('auth.totp_pending_user_id');
        $user = User::query()->find($userId);

        if (! $user || ! $user->totpIsEnabled() || ! $totp->verifyForUser($user, (string) $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'Kode TOTP atau recovery code tidak valid.']);
        }

        $remember = (bool) $request->session()->pull('auth.totp_pending_remember', false);
        Auth::guard('web')->login($user, $remember);
        $request->session()->forget('auth.totp_pending_user_id');
        $request->session()->regenerate();
        $request->session()->put('auth.totp_verified_at', now()->getTimestamp());

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function stepUpPage(Request $request): Response|RedirectResponse
    {
        if (! $request->user()?->isSuperAdmin() || ! $request->session()->has('auth.totp_step_up_route')) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/TotpStepUp');
    }

    public function stepUp(Request $request, TotpService $totp): RedirectResponse
    {
        $user = $this->superadmin($request);
        abort_unless($user, 403);
        $request->validate(['code' => ['required', 'string', 'max:32']]);

        if (! $user->totpIsEnabled() || ! $totp->verifyForUser($user, (string) $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'Kode TOTP atau recovery code tidak valid.']);
        }

        // Only a verified code produces a grant, bound to the pending route,
        // the user and a short expiry; RequireControlPlaneTotp consumes it once.
        $pendingRoute = (string) $request->session()->pull(RequireControlPlaneTotp::PENDING_ROUTE_KEY, '');
        if ($pendingRoute !== '') {
            $request->session()->put(RequireControlPlaneTotp::VERIFIED_KEY, [
                'route' => $pendingRoute,
                'user_id' => (int) $user->getAuthIdentifier(),
                'expires_at' => time() + RequireControlPlaneTotp::VERIFIED_TTL_SECONDS,
            ]);
        }

        $returnUrl = (string) $request->session()->pull(RequireControlPlaneTotp::RETURN_URL_KEY, route('dashboard', absolute: false));
        // Same-origin only: compare against "<origin>/" so "https://host.evil.tld"
        // cannot pass a bare prefix check against "https://host".
        $origin = rtrim(url('/'), '/');
        if ($returnUrl !== $origin && ! str_starts_with($returnUrl, $origin.'/')) {
            $returnUrl = route('dashboard', absolute: false);
        }

        return redirect()->to($returnUrl);
    }

    public function resetUser(Request $request, User $user): RedirectResponse
    {
        $actor = $this->superadmin($request);
        abort_unless($actor, 403);
        $request->validate(['password' => ['required', 'string']]);

        if (! Auth::guard('web')->validate([
            'email' => $actor->email,
            'password' => (string) $request->string('password'),
        ])) {
            throw ValidationException::withMessages(['password' => __('auth.password')]);
        }

        app(TotpService::class)->reset($user);
        activity('totp.reset')
            ->causedBy($actor)
            ->performedOn($user)
            ->withProperties([
                'policy_version' => config('totp.policy_version'),
                'target_user_id' => $user->id,
                'reset_source' => 'superadmin_ui_reauth',
            ])
            ->log('totp.reset');

        return back()->with('success', 'TOTP pengguna berhasil di-reset.');
    }

    private function superadmin(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && $user->isSuperAdmin() ? $user : null;
    }
}
