<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageProxyRouteTest extends TestCase
{
    public function test_framework_local_storage_route_does_not_shadow_s3_proxy(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('storage.local'));
        $this->assertSame('/storage/check.txt', route('storage.public', ['path' => 'check.txt'], false));
    }

    public function test_existing_object_is_served_with_private_cache_headers(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('campaigns/2026/07/banner-test.jpg', base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q=='
        ));

        $this->get('/storage/campaigns/2026/07/banner-test.jpg')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_missing_and_traversal_paths_return_not_found(): void
    {
        Storage::fake('public');

        $this->get('/storage/psb/missing.jpg')->assertNotFound();
        $this->get('/storage/psb/../.env')->assertNotFound();
        $this->get('/storage/psb/%2E%2E/.env')->assertNotFound();
    }

    public function test_customer_accessor_uses_the_storage_proxy_url(): void
    {
        config([
            'filesystems.default' => 'public',
            'filesystems.disks.public' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/public'),
                'url' => 'https://ebilling.example.test/storage',
                'visibility' => 'public',
                'throw' => false,
            ],
        ]);

        $customer = new Customer(['ktp_photo_url' => 'psb/2026/07/ktp-test.jpg']);

        $this->assertSame(
            'https://ebilling.example.test/storage/psb/2026/07/ktp-test.jpg',
            $customer->ktp_photo_url
        );
    }
}
