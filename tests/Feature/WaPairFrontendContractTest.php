<?php

namespace Tests\Feature;

use Tests\TestCase;

class WaPairFrontendContractTest extends TestCase
{
    public function test_re_pair_uses_the_shared_destructive_confirmation(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        $this->assertStringContainsString("from '@/Components/ConfirmDestructive'", $source);
        $this->assertStringContainsString('<ConfirmDestructive', $source);
        $this->assertStringNotContainsString('confirm(', $source);
    }

    public function test_pairing_copy_has_no_mojibake_or_emoji_channel_labels(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}]/u', $source));
        $this->assertStringNotContainsString('â', $source);
        $this->assertStringContainsString('Test message dari Skynet eBilling', $source);
    }

    public function test_cards_use_server_routing_not_hardcoded_instance_labels(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        foreach (['skynet-ebilling', 'skynet-billing', 'skynet-ticket', 'Nomor 1:', 'Nomor 2:', 'Nomor 3:'] as $hardcoded) {
            $this->assertStringNotContainsString($hardcoded, $source, $hardcoded);
        }
        $this->assertStringContainsString('{item.name}', $source);
        $this->assertStringContainsString('{item.label}', $source);
        $this->assertStringContainsString('item.number_masked', $source);
        $this->assertStringContainsString('Dipakai untuk:', $source);
        $this->assertStringContainsString('item.unused_message', $source);
        $this->assertStringContainsString('also_handles', $source);
        $this->assertStringContainsString('Ticket & Progress memakai:', str_replace('&amp;', '&', $source));
        $this->assertStringContainsString('/api/wa-gateway/ticket-routing', $source);
        $this->assertStringContainsString('Ubah routing Ticket & Progress?', $source);
    }

    public function test_qr_survives_status_refresh_and_stale_replies_are_ignored(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        // The reset effect (which clears the QR) is keyed on the channel + instance name only,
        // never on the whole card object that every status refresh rebuilds.
        $this->assertStringContainsString('}, [activeChannel, activeInstanceName]);', $source);
        $this->assertStringNotContainsString('}, [activeChannel, activeInstance]);', $source);
        $this->assertSame(2, substr_count($source, 'if (channel !== activeChannelRef.current) return;'));
    }

    public function test_logout_confirmation_names_instance_and_requires_typing_it(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        $this->assertStringContainsString('typedConfirmation={activeInstance?.name', $source);
        $this->assertStringContainsString('Dipakai untuk: ${', $source);
        $this->assertStringContainsString('PSB WA, migrasi, /pppoe, alert OLT, absensi akan berhenti sampai dipairing ulang', $source);
        $this->assertStringContainsString('handlesOps', $source);
    }

    public function test_pairing_page_keeps_api_key_masked_and_post_contracts(): void
    {
        $source = (string) file_get_contents(base_path('resources/js/Pages/Settings/WaPair.tsx'));

        $this->assertStringContainsString('value={config.api_key_masked}', $source);
        $this->assertStringNotContainsString('value={config.api_key}', $source);
        $this->assertStringContainsString('/api/wa-gateway/create-instance?channel=', $source);
        $this->assertStringContainsString('/api/wa-gateway/re-pair?channel=', $source);
        $this->assertStringContainsString("'/api/wa-gateway/test'", $source);
        $this->assertStringContainsString('${instQuery}', $source);
    }
}
