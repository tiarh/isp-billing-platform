<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class TotpAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_enabled_superadmin_must_complete_challenge_after_password_login(): void
    {
        $user = $this->superadminWithTotp();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'canary-pw',
        ]);

        $response->assertRedirect(route('totp.challenge', absolute: false));
        $this->assertGuest();
        $this->assertSame($user->id, session('auth.totp_pending_user_id'));
    }

    public function test_superadmin_can_self_enroll_and_confirmation_is_audited(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->get(route('totp.enroll'))
            ->assertOk()
            ->assertDontSee('otpauth://');
        $begin = $this->actingAs($user)->postJson(route('totp.enroll.begin'));
        $begin->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_uri', 'recovery_codes']);
        $this->assertStringContainsString('no-store', (string) $begin->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', (string) $begin->headers->get('Cache-Control'));

        $secret = (string) $begin->json('secret');
        $this->actingAs($user)->post(route('totp.enroll.confirm'), [
            'code' => app(TotpService::class)->codeForSecret($secret),
        ])->assertRedirect(route('totp.enroll', absolute: false));

        $this->assertTrue($user->fresh()->totpIsEnabled());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'totp.enrolled',
            'subject_id' => $user->id,
        ]);
    }

    public function test_superadmin_can_reset_another_users_totp_only_after_password_reauth(): void
    {
        $actor = User::factory()->create(['role' => 'superadmin', 'password' => 'canary-pw']);
        $target = $this->superadminWithTotp('target@example.test');

        $this->actingAs($actor)->post(route('users.totp-reset', $target), [
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');
        $this->assertTrue($target->fresh()->totpIsEnabled());

        $this->actingAs($actor)->post(route('users.totp-reset', $target), [
            'password' => 'canary-pw',
        ])->assertRedirect();

        $this->assertFalse($target->fresh()->totpIsEnabled());
        $this->assertDatabaseHas('activity_log', [
            'description' => 'totp.reset',
            'subject_id' => $target->id,
            'causer_id' => $actor->id,
        ]);
    }

    public function test_control_plane_mutation_requires_one_time_totp_step_up(): void
    {
        Route::middleware(['web', 'auth', 'superadmin', 'control-plane-totp'])
            ->post('/__test/control-plane-mutation', static fn () => response('mutated'))
            ->name('test.control-plane.mutation');

        $user = $this->superadminWithTotp();
        $this->actingAs($user)
            ->post('/__test/control-plane-mutation')
            ->assertRedirect(route('totp.step-up', absolute: false));

        $this->actingAs($user)
            ->post(route('totp.step-up'), [
                'code' => app(TotpService::class)->codeForSecret((string) $user->fresh()->totp_secret),
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post('/__test/control-plane-mutation')
            ->assertOk()
            ->assertSee('mutated');
    }

    public function test_resubmitting_the_mutation_without_a_code_does_not_satisfy_step_up(): void
    {
        Route::middleware(['web', 'auth', 'superadmin', 'control-plane-totp'])
            ->post('/__test/control-plane-replay', static fn () => response('mutated'))
            ->name('test.control-plane.replay');

        $user = $this->superadminWithTotp();

        // First attempt is redirected to the step-up page and records the
        // pending route in the session.
        $this->actingAs($user)
            ->post('/__test/control-plane-replay')
            ->assertRedirect(route('totp.step-up', absolute: false));

        // Regression: previously the pending-route marker doubled as the grant,
        // so simply repeating the request (no TOTP code at all) went through.
        $this->actingAs($user)
            ->post('/__test/control-plane-replay')
            ->assertRedirect(route('totp.step-up', absolute: false));

        $this->assertNull(session('auth.totp_step_up_verified'));
    }

    public function test_step_up_grant_is_single_use_and_bound_to_the_pending_route(): void
    {
        Route::middleware(['web', 'auth', 'superadmin', 'control-plane-totp'])
            ->post('/__test/control-plane-a', static fn () => response('a'))
            ->name('test.control-plane.a');
        Route::middleware(['web', 'auth', 'superadmin', 'control-plane-totp'])
            ->post('/__test/control-plane-b', static fn () => response('b'))
            ->name('test.control-plane.b');

        $user = $this->superadminWithTotp();
        $this->actingAs($user)->post('/__test/control-plane-a')->assertRedirect();
        $this->actingAs($user)
            ->post(route('totp.step-up'), [
                'code' => app(TotpService::class)->codeForSecret((string) $user->fresh()->totp_secret),
            ])
            ->assertRedirect();

        // The grant was issued for route "a"; route "b" must still step up.
        $this->actingAs($user)
            ->post('/__test/control-plane-b')
            ->assertRedirect(route('totp.step-up', absolute: false));
    }

    public function test_step_up_return_url_must_be_same_origin(): void
    {
        $user = $this->superadminWithTotp();

        $this->actingAs($user)
            ->withSession([
                'auth.totp_step_up_route' => 'monitoring.settings.connection.update',
                'auth.totp_step_up_return_url' => url('/').'.evil.example/phish',
            ])
            ->post(route('totp.step-up'), [
                'code' => app(TotpService::class)->codeForSecret((string) $user->fresh()->totp_secret),
            ])
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_read_only_control_plane_view_does_not_require_step_up(): void
    {
        $user = $this->superadminWithTotp();

        $this->actingAs($user)
            ->get(route('production-control-plane.index'))
            ->assertOk();
    }

    public function test_monitoring_settings_mutations_are_bound_to_control_plane_step_up(): void
    {
        foreach (['monitoring.settings.connection.update', 'monitoring.settings.upstream-links.update', 'monitoring.settings.router-identities.update'] as $routeName) {
            $this->assertContains('control-plane-totp', app('router')->getRoutes()->getByName($routeName)->gatherMiddleware());
        }
    }

    public function test_global_admin_without_superadmin_role_keeps_existing_route_access(): void
    {
        Route::middleware(['web', 'auth', 'global-admin', 'control-plane-totp'])
            ->post('/__test/global-admin-control-plane', static fn () => response('allowed'))
            ->name('test.global-admin-control-plane');

        $user = User::factory()->create(['role' => 'noc']);

        $this->actingAs($user)
            ->post('/__test/global-admin-control-plane')
            ->assertOk()
            ->assertSee('allowed');
    }

    public function test_artisan_reset_clears_totp_without_external_side_effects(): void
    {
        $user = $this->superadminWithTotp();

        $this->artisan('totp:reset', ['user' => $user->email])
            ->expectsConfirmation('Reset TOTP untuk akun ini?', 'yes')
            ->expectsOutput('TOTP berhasil di-reset.')
            ->assertSuccessful();

        $this->assertFalse($user->fresh()->totpIsEnabled());
    }

    public function test_totp_and_recovery_codes_are_one_use(): void
    {
        $user = $this->superadminWithTotp();
        $totp = app(TotpService::class);
        $code = $totp->codeForSecret((string) $user->totp_secret);

        $this->assertTrue($totp->verifyForUser($user, $code));
        $this->assertFalse($totp->verifyForUser($user->fresh(), $code));

        $recoveryUser = $this->superadminWithTotp('recovery@example.test');
        $this->assertTrue($totp->verifyForUser($recoveryUser, 'recovery-code-1234'));
        $this->assertFalse($totp->verifyForUser($recoveryUser->fresh(), 'recovery-code-1234'));
    }

    private function superadminWithTotp(string $email = 'admin@example.test'): User
    {
        $user = User::factory()->create([
            'email' => $email,
            'role' => 'superadmin',
            'password' => 'canary-pw',
        ]);

        app(TotpService::class)->enable($user, 'JBSWY3DPEHPK3PXP', ['recovery-code-1234']);

        return $user->fresh();
    }
}
