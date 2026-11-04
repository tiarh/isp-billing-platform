<?php

namespace App\Providers;

use App\Support\DemoInstanceGuard;
use Illuminate\Support\ServiceProvider;

class DemoInstanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // No-op unless DEMO_INSTANCE=true (prod never sets it).
        DemoInstanceGuard::assertSafe();
    }
}
