<?php

namespace Tests\Feature;

use RuntimeException;
use Tests\TestCase;

class ErrorSurfaceSecurityTest extends TestCase
{
    public function test_custom_error_views_exist_and_do_not_render_exception_details(): void
    {
        $statuses = [403, 404, 419, 429, 500, 503];
        $secret = 'SENSITIVE-EXCEPTION-DETAIL';

        foreach ($statuses as $status) {
            $viewPath = base_path("resources/views/errors/{$status}.blade.php");

            $this->assertFileExists($viewPath, "Missing custom error view for HTTP {$status}");
            $this->assertStringContainsString("@extends('errors.layout')", file_get_contents($viewPath));

            $rendered = view("errors.{$status}", [
                'exception' => new RuntimeException($secret),
            ])->render();

            $this->assertStringContainsString((string) $status, $rendered);
            $this->assertStringNotContainsString($secret, $rendered);
        }

        $layout = file_get_contents(base_path('resources/views/errors/layout.blade.php'));

        $this->assertStringContainsString('Terjadi kendala', $layout);
        $this->assertStringNotContainsString('$exception', $layout);
        $this->assertStringNotContainsString('getMessage', $layout);
        $this->assertStringNotContainsString('getTrace', $layout);
    }
}
