<?php

namespace Tests\Feature;

use Tests\TestCase;

class OltC300AuthenticationTest extends TestCase
{
    public function test_guest_cannot_reach_olt_ocr_endpoint(): void
    {
        $response = $this->postJson('/api/olt-c300/ocr', [
            'image_base64' => 'data:image/png;base64,AAAA',
        ]);

        $response->assertUnauthorized();
    }
}
