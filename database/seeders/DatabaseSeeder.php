<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo data only. Everything here is fictional.
 *
 * The demo customers and the demo admin login come from FaqScreenshotSeeder.
 * It refuses to run unless APP_ENV=testing and E2E_PASSWORD is set, so a
 * normal local install only gets the billing settings.
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call(BillingSettingsSeeder::class);

        if (app()->environment('testing') && (string) env('E2E_PASSWORD') !== '') {
            $this->call(FaqScreenshotSeeder::class);
        }
    }
}
