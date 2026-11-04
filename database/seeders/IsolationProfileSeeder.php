<?php

namespace Database\Seeders;

use App\Models\Router;
use Illuminate\Database\Seeder;

class IsolationProfileSeeder extends Seeder
{
    public function run(): void
    {
        $profiles = [
            'Skynet-Blitar' => 'ISOLIREBILLING',
            'Skynet-Bumiayu-Malang' => 'ISOLIREBILLING',
            'Skynet-KarangKunci' => 'ISOLIREBILLING',
            'Skynet-Kasin' => 'ISOLIREBILLING',
            'Skynet-Kendit' => 'ISOLIREBILLING',
            'Skynet-Krian' => 'ISOLIREBILLING',
            'Skynet-Metro' => 'isolirebilling',
            'Skynet-PPPoE Randuagung' => 'ISOLIREBILLING',
        ];

        foreach ($profiles as $routerName => $profileName) {
            Router::where('name', $routerName)->update(['isolation_profile' => $profileName]);
            $this->command->info("Updated {$routerName} with isolation profile: {$profileName}");
        }
    }
}
