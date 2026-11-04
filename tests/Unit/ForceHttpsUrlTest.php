<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ForceHttpsUrlTest extends TestCase
{
    public function test_route_helper_uses_https_when_app_url_is_https(): void
    {
        config([
            'app.url' => 'https://ebilling.example.test',
            'app.force_https' => true,
        ]);

        URL::forceScheme('https');
        URL::forceRootUrl('https://ebilling.example.test');

        $this->assertStringStartsWith('https://', route('login'));
        $this->assertStringNotContainsString('http://ebilling', route('login'));
    }
}
