<?php

namespace Tests\Feature;

use Tests\TestCase;

class SecurityDiscoveryFilesTest extends TestCase
{
    public function test_robots_disallows_crawling_internal_application_paths(): void
    {
        $robots = file_get_contents(base_path('public/robots.txt'));

        $this->assertStringContainsString('User-agent: *', $robots);
        $this->assertStringContainsString('Disallow: /', $robots);
        $this->assertStringNotContainsString('Disallow:', str_replace('Disallow: /', '', $robots));
    }

    public function test_security_txt_has_contact_and_canonical_directives(): void
    {
        $securityTxt = file_get_contents(base_path('public/.well-known/security.txt'));

        $this->assertMatchesRegularExpression('/^Contact:\s*mailto:[^\s]+$/mi', $securityTxt);
        $this->assertStringContainsString('Preferred-Languages: id, en', $securityTxt);
        $this->assertMatchesRegularExpression('/^Canonical:\s*https:\/\/[^\s]+\/\.well-known\/security\.txt$/mi', $securityTxt);
        $this->assertMatchesRegularExpression('/^Expires:\s*\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/mi', $securityTxt);
    }
}
