<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn () => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                    // Must match EnsureGlobalAdmin / User::isGlobalAdmin (noc + unscoped admin).
                    // Do NOT default everyone (e.g. teknisi) to global_admin — that shows menus → 403.
                    'scope' => $request->user()->isSuperAdmin()
                        ? 'superadmin'
                        : ($request->user()->isGlobalAdmin()
                            ? 'global_admin'
                            : ($request->user()->hasAreaScope() ? 'scoped_admin' : 'user')),
                    'area_ids' => $request->user()->hasAreaScope()
                        ? $request->user()->accessibleAreaIds()->all()
                        : [],
                ] : null,
            ],
            'csrf_token' => fn () => csrf_token(),
            // Kontrak flash BE->FE: controller memakai
            // ->with('success', ...) / ->with('warning', ...) / ->with('error', ...).
            // Kunci session 'status'
            // TIDAK di-share karena dipakai Breeze auth untuk machine key
            // ('verification-link-sent', dll), bukan pesan operator.
            // Validation errors ('errors' prop, mis. errors.migration_action) sudah
            // di-share otomatis oleh parent Middleware::share() (inertia-laravel v2).
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'warning' => fn () => $request->session()->get('warning'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'settings' => [
                'payment_channels' => fn () => $request->user()?->isGlobalAdmin()
                    ? Setting::get('payment_channels', [])
                    : [],
            ],
        ];
    }
}
