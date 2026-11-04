<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('blocking-core')]
class MonitoringSettingsFrontendContractTest extends TestCase
{
    private function source(): string
    {
        return (string) file_get_contents(base_path('resources/js/Pages/Monitoring/Settings.tsx'));
    }

    public function test_page_saves_through_inertia_routes(): void
    {
        $source = $this->source();

        $this->assertStringContainsString("route('monitoring.settings.connection.update')", $source);
        $this->assertStringContainsString("route('monitoring.settings.upstream-links.update')", $source);
        $this->assertStringContainsString("route('monitoring.settings.router-identities.update')", $source);
        $this->assertStringContainsString('router.put(', $source);
        $this->assertStringContainsString('Saran otomatis', $source);
        $this->assertStringContainsString('Tes koneksi', $source);
    }

    public function test_token_input_is_password_and_no_token_value_is_rendered(): void
    {
        $source = $this->source();

        $this->assertMatchesRegularExpression('/id="grafana-token"\s+type="password"/', $source);
        $this->assertStringContainsString('•••• tersimpan', $source);
        $this->assertStringContainsString('connection.token_set', $source);
        $this->assertStringNotContainsString('connection.token}', $source);
        $this->assertStringNotContainsString('connection.token ', $source);
        $this->assertStringNotContainsString('defaultValue={connection', $source);
    }

    public function test_page_uses_semantic_tokens_only(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('violet-', $source);
        $this->assertStringNotContainsString('bg-gradient-', $source);
        $this->assertStringNotContainsString('backdrop-blur', $source);
        $this->assertDoesNotMatchRegularExpression('/\b(?:bg|text|border)-(?:red|green|blue|amber|slate|gray)-\d{2,3}\b/', $source);
    }
}
