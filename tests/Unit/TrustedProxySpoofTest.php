<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class TrustedProxySpoofTest extends TestCase
{
    public function test_untrusted_forwarded_for_cannot_override_client_ip(): void
    {
        Request::setTrustedProxies(['127.0.0.1/32'], Request::HEADER_X_FORWARDED_FOR);

        try {
            $request = Request::create('/up', 'GET', [], [], [], [
                'REMOTE_ADDR' => '198.51.100.44',
            ]);
            $request->headers->set('X-Forwarded-For', '198.18.2.8');

            $this->assertSame('198.51.100.44', $request->getClientIp());
        } finally {
            Request::setTrustedProxies([], 0);
        }
    }
}
