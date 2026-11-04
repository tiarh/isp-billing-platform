<?php

namespace App\Providers;

use App\Services\Olt\Encoders\FiberHomeCommandEncoder;
use App\Services\Olt\Encoders\HiosoCommandEncoder;
use App\Services\Olt\Encoders\VendorCommandEncoder;
use App\Services\Olt\Encoders\VendorRuleMissingException;
use App\Services\Olt\Encoders\ZteC300CommandEncoder;
use Illuminate\Support\ServiceProvider;

class OltServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VendorCommandEncoder::class, function ($app, array $params = []) {
            $vendor = $params['vendor'] ?? $this->detectVendor($app);

            return match ($vendor) {
                'zte-c300' => $app->make(ZteC300CommandEncoder::class),
                'fiberhome-an5506' => $app->make(FiberHomeCommandEncoder::class),
                'hioso' => $app->make(HiosoCommandEncoder::class),
                default => throw VendorRuleMissingException::forVendor($vendor, 'VendorCommandEncoder binding'),
            };
        });
    }

    private function detectVendor($app): string
    {
        // [VENDOR-RULE-TBD] vendor detection fallback strategy.
        // Phase B default: zte-c300 (primary in current production).
        return 'zte-c300';
    }
}
