<?php

namespace Tests\Feature;

use App\Http\Controllers\StorageProxyController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class StorageProxyAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function lockedPrefixes(): array
    {
        return [
            'imports' => ['imports/migrasi-preview/url-20260101000000.xlsx'],
            'attendance' => ['attendance/2026/01/01/Teknisi_Satu_070000.jpg'],
            'technician-progress' => ['technician-progress/2026/01/01/foto.jpg'],
            'maps' => ['maps/area-x/doc.kml'],
            'device-recovery' => ['device-recovery/abc.jpg'],
            'root file' => ['readme.txt'],
        ];
    }

    private function seedFiles(): void
    {
        Storage::fake('public');
        foreach (array_merge(array_column(self::lockedPrefixes(), 0), [
            'campaigns/2026/01/banner.jpg',
            'ktp/2026/01/a.jpg',
            'odp/2026/01/a.jpg',
            'psb/2026/01/a.jpg',
            'migrasi/2026/01/a.jpg',
            'payment-proofs/a.jpg',
            'tickets/a.jpg',
        ]) as $path) {
            Storage::disk('public')->put($path, 'x');
        }
    }

    public function test_anonymous_allowlisted_folder_is_served(): void
    {
        $this->seedFiles();

        $this->get('/storage/campaigns/2026/01/banner.jpg')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    #[DataProvider('lockedPrefixes')]
    public function test_anonymous_other_folders_are_not_found(string $path): void
    {
        $this->seedFiles();

        $this->get('/storage/'.$path)->assertNotFound();
    }

    public function test_anonymous_guess_does_not_touch_the_disk(): void
    {
        Storage::shouldReceive('disk')->never();

        $this->get('/storage/imports/migrasi-preview/url-20260101000000.xlsx')->assertNotFound();
        $this->get('/storage/attendance/2026/01/01/x.jpg')->assertNotFound();
    }

    #[DataProvider('lockedPrefixes')]
    public function test_staff_session_gets_other_non_protected_folders(string $path): void
    {
        $this->seedFiles();

        $this->actingAs(User::factory()->create(['role' => 'teknisi']))
            ->get('/storage/'.$path)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_protected_prefixes_are_not_found_for_staff_and_anonymous(): void
    {
        $this->seedFiles();
        $staff = User::factory()->create(['role' => 'superadmin']);

        foreach (StorageProxyController::PROTECTED_PREFIXES as $prefix) {
            $path = '/storage/'.$prefix.'2026/01/a.jpg';
            $this->get($path)->assertNotFound();
            $this->actingAs($staff)->get($path)->assertNotFound();
            $this->get('/storage/'.strtoupper($prefix).'a.jpg')->assertNotFound();
            $this->actingAs($staff)->get('/storage/'.strtoupper($prefix).'a.jpg')->assertNotFound();
        }

        $this->actingAs($staff)->get('/storage/ktp/2026/01/a.jpg')->assertNotFound();
        $this->actingAs($staff)->get('/storage/tickets/a.jpg')->assertNotFound();
    }

    public function test_traversal_and_nul_paths_are_not_found_for_staff(): void
    {
        $this->seedFiles();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));

        $this->get('/storage/campaigns/../imports/migrasi-preview/x.xlsx')->assertNotFound();
        $this->get('/storage/imports/%2E%2E/.env')->assertNotFound();
        $this->get('/storage/imports/%00/x')->assertNotFound();
    }

    public function test_anonymous_requests_are_throttled(): void
    {
        $this->seedFiles();

        for ($i = 0; $i < 60; $i++) {
            $this->get('/storage/imports/guess-'.$i.'.xlsx')->assertNotFound();
        }

        $this->get('/storage/imports/guess-final.xlsx')->assertStatus(429);
    }

    public function test_staff_get_a_higher_limit_than_anonymous(): void
    {
        $this->seedFiles();
        $this->actingAs(User::factory()->create(['role' => 'teknisi']));

        for ($i = 0; $i < 70; $i++) {
            $this->get('/storage/technician-progress/2026/01/01/foto.jpg')->assertOk();
        }
    }

    public function test_route_name_url_and_throttle_are_unchanged(): void
    {
        $route = Route::getRoutes()->getByName('storage.public');

        $this->assertNotNull($route);
        $this->assertSame('/storage/check.txt', route('storage.public', ['path' => 'check.txt'], false));
        $this->assertContains('throttle:storage-proxy', $route->gatherMiddleware());
    }
}
