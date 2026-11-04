<?php

namespace Database\Seeders;

use App\Models\Olt;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Sandbox-only fixture for the Huawei "Lihat ONT baru" browser spec: one Huawei
 * OLT on a TEST-NET address (never reachable). Fake credentials only.
 */
class HuaweiAutofindE2ESeeder extends Seeder
{
    public const OLT_NAME = 'OLT GADANG E2E';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('HuaweiAutofindE2ESeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        Olt::query()->updateOrCreate(
            ['code' => 'OLT-GDG-E2E'],
            [
                'name' => self::OLT_NAME,
                'vendor' => 'huawei_ma5608t',
                'management_ip' => '192.0.2.10',
                'management_protocol' => 'ssh',
                'username' => 'e2e-user',
                'password' => 'canary-pw',
            ],
        );
    }
}
