<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Canary;
use Tests\TestCase;

class SuperadminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_manage_users_and_assign_area_scope(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $area = Area::create(['code' => 'A', 'name' => 'Area A']);

        $this->actingAs($superadmin)->get(route('users.index'))->assertOk();
        $this->actingAs($superadmin)->post(route('users.store'), [
            'name' => 'Scoped Admin',
            'email' => 'scoped@example.com',
            'password' => Canary::of('pw123'),
            'password_confirmation' => Canary::of('pw123'),
            'role' => 'admin',
            'area_ids' => [$area->id],
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'scoped@example.com')->firstOrFail();
        $this->assertSame('admin', $user->role);
        $this->assertTrue($user->areas()->whereKey($area->id)->exists());
    }

    public function test_admin_cannot_manage_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('users.index'))->assertForbidden();
    }

    public function test_last_superadmin_cannot_be_demoted_or_deleted(): void
    {
        $superadmin = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($superadmin)->patch(route('users.update', $superadmin), [
            'name' => $superadmin->name,
            'email' => $superadmin->email,
            'role' => 'admin',
            'area_ids' => [],
        ])->assertSessionHas('error');

        $this->actingAs($superadmin)->delete(route('users.destroy', $superadmin))->assertSessionHas('error');

        $this->assertSame('superadmin', $superadmin->fresh()->role);
    }

    public function test_public_registration_is_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
    }
}
