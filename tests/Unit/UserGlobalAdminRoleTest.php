<?php

namespace Tests\Unit;

use App\Models\Area;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserGlobalAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_noc_is_global_admin(): void
    {
        $noc = User::factory()->create(['role' => 'noc']);
        $this->assertTrue($noc->isNoc());
        $this->assertTrue($noc->isAdminLike());
        $this->assertTrue($noc->isGlobalAdmin());
    }

    public function test_unscoped_admin_is_global_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertTrue($admin->isGlobalAdmin());
        $this->assertFalse($admin->hasAreaScope());
    }

    public function test_scoped_admin_is_not_global_admin(): void
    {
        $area = Area::create(['name' => 'Test Area', 'code' => 'test-area']);
        $admin = User::factory()->create(['role' => 'admin']);
        $admin->areas()->attach($area);
        $this->assertTrue($admin->hasAreaScope());
        $this->assertFalse($admin->isGlobalAdmin());
    }

    public function test_teknisi_is_not_global_admin(): void
    {
        $tek = User::factory()->create(['role' => 'teknisi']);
        $this->assertFalse($tek->isGlobalAdmin());
        $this->assertFalse($tek->isAdminLike());
    }
}
