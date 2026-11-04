<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\Router;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Canary;
use Tests\TestCase;

class Psb4UxOwnerPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_router_show_exposes_allocator_blocks_and_package_drafts_read_only(): void
    {
        $router = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        PppoeAddressPoolBlock::create([
            'router_id' => $router->id,
            'site_profile_key' => 'ra',
            'pppoe_profile' => 'KALIREJO-NET',
            'customer_area' => 'kalirejo',
            'block_name' => 'RA-11',
            'block_index' => 11,
            'address_format' => 'full_ipv4',
            'prefix_template' => '198.18.{block}.',
            'local_address' => '198.18.11.1',
            'remote_start' => '198.18.11.2',
            'remote_end' => '198.18.11.254',
            'last_remote_address' => '198.18.11.243',
            'last_remote_source' => 'db_pointer',
            'allocator_mode' => 'last_remote_pointer',
            'is_active' => true,
            'capacity' => 253,
            'used_count' => 242,
            'available_count' => 11,
            'warning_threshold' => 20,
        ]);
        Package::create([
            'router_id' => $router->id,
            'code' => 'KALIREJO_NET_'.$router->id,
            'name' => 'Paket KALIREJO-NET',
            'mikrotik_profile' => 'KALIREJO-NET',
            'rate_limit' => '50M/50M',
            'price' => 0,
            'is_active' => false,
        ]);

        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->get(route('routers.show', $router))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Routers/Show')
                ->where('address_pool_blocks.0.block_name', 'RA-11')
                ->where('address_pool_blocks.0.last_remote_address', '198.18.11.243')
                ->where('package_drafts.0.mikrotik_profile', 'KALIREJO-NET')
                ->where('package_drafts.0.is_active', false)
            );
    }

    public function test_package_search_matches_dash_or_space_and_marks_draft(): void
    {
        $router = Router::create([
            'name' => 'SKYNET-RA-OLT',
            'ip_address' => '198.18.2.1',
            'username' => 'admin',
            'password' => Canary::of('pw'),
            'is_active' => true,
        ]);
        Package::create([
            'router_id' => $router->id,
            'code' => 'KALIREJO_NET_'.$router->id,
            'name' => 'Paket KALIREJO-NET',
            'mikrotik_profile' => 'KALIREJO-NET',
            'rate_limit' => '50M/50M',
            'price' => 0,
            'is_active' => false,
        ]);

        $user = User::factory()->create(['role' => 'superadmin']);

        $this->actingAs($user)
            ->get(route('packages.index', ['search' => 'kalirejo net']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Packages/Index')
                ->where('packages.data.0.mikrotik_profile', 'KALIREJO-NET')
                ->where('packages.data.0.router.name', 'SKYNET-RA-OLT')
                ->where('packages.data.0.is_active', false)
            );
    }
}
