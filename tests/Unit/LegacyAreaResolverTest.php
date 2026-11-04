<?php

namespace Tests\Unit;

use App\Services\LegacyAreaResolver;
use PHPUnit\Framework\TestCase;

class LegacyAreaResolverTest extends TestCase
{
    public function test_bedali_code_prefix_maps_to_bedali(): void
    {
        $result = (new LegacyAreaResolver)->resolve(['id' => 'BDL001']);

        $this->assertSame('SKYNET-BEDALI', $result['area']);
        $this->assertSame('prefix', $result['reason']);
    }

    public function test_arjosari_code_prefix_maps_to_arjosari(): void
    {
        $result = (new LegacyAreaResolver)->resolve(['id' => 'ARJ1017']);

        $this->assertSame('SKYNET-ARJOSARI', $result['area']);
        $this->assertSame('prefix', $result['reason']);
    }

    public function test_gkw_code_prefix_maps_to_jambuwer(): void
    {
        $result = (new LegacyAreaResolver)->resolve(['id' => 'GKW0001']);

        $this->assertSame('SKYNET-JAMBUWER', $result['area']);
        $this->assertSame('prefix', $result['reason']);
    }

    public function test_high_confidence_corrected_prefixes_map_to_legacy_location_truth(): void
    {
        $resolver = new LegacyAreaResolver;

        $this->assertSame('SKYNET-KARANGPLOSO', $resolver->resolve(['id' => 'LSS001'])['area']);
        $this->assertSame('SKYNET-KARANGPLOSO', $resolver->resolve(['id' => 'REST001'])['area']);
        $this->assertSame('SKYNET-COMBORAN', $resolver->resolve(['id' => 'SKHJ001'])['area']);
        $this->assertSame('SKYNET-LAWANG', $resolver->resolve(['id' => 'SBWN001'])['area']);
        $this->assertSame('SKYNET-SONGSONG', $resolver->resolve(['id' => 'SNG001'])['area']);
        $this->assertSame('SKYNET-LAWANG', $resolver->resolve(['id' => 'INKL001'])['area']);
        $this->assertSame('SKYNET-LAWANG', $resolver->resolve(['id' => 'BMS001'])['area']);
    }

    public function test_transaction_only_stub_evidence_maps_to_real_areas(): void
    {
        $resolver = new LegacyAreaResolver;

        $this->assertSame('SKYNET-NONGKOJAJAR', $resolver->resolve(['id_pelanggan' => 'NKJ009'])['area']);
        $this->assertSame('SKYNET-BEDALI', $resolver->resolve(['id_pelanggan' => 'SRV1227', 'alamat' => 'PERUM BDL INDAH'])['area']);
        $this->assertSame('SKYNET-KRIAN', $resolver->resolve(['id_pelanggan' => '2402212924', 'alamat' => 'Jl. Sidorono gg pedukuhan'])['area']);
    }

    public function test_api_area_object_wins_over_conflicting_fallback_evidence(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'RDG001',
            'area' => ['name' => 'SUBNET-WAJAK'],
            'package' => ['name' => 'Paket Randuagung'],
            'address' => 'Randuagung',
        ]);

        $this->assertSame('SUBNET-WAJAK', $result['area']);
        $this->assertSame('api_area', $result['reason']);
        $this->assertSame('SUBNET-WAJAK', $result['source_value']);
    }

    public function test_blank_api_area_falls_back_to_prefix_mapping(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'RDG001',
            'area' => ['name' => ''],
        ]);

        $this->assertSame('SKYNET-RANDUAGUNG', $result['area']);
        $this->assertSame('prefix', $result['reason']);
    }

    public function test_invalid_api_area_does_not_block_valid_fallback_mapping(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'BDL001',
            'area' => ['name' => 'SKYNET-GENERAL'],
        ]);

        $this->assertSame('SKYNET-BEDALI', $result['area']);
        $this->assertSame('prefix', $result['reason']);
    }

    public function test_package_keyword_maps_to_wajak(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => '2210174501',
            'package' => ['name' => 'Paket up to 5Mb WAJAK'],
        ]);

        $this->assertSame('SKYNET-WAJAK', $result['area']);
        $this->assertSame('package_keyword', $result['reason']);
    }

    public function test_legacy_location_variant_maps_to_bedali(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id_pelanggan' => 'CUSTOM001',
            'nama_lokasi' => 'SKYNET -  BEDALI',
        ]);

        $this->assertSame('SKYNET-BEDALI', $result['area']);
        $this->assertSame('legacy_location', $result['reason']);
    }

    public function test_gdg_prefix_and_gadang_api_area_map_to_gadang(): void
    {
        $resolver = new LegacyAreaResolver;

        $byPrefix = $resolver->resolve(['id' => 'GDG001']);
        $this->assertSame('SKYNET-GADANG', $byPrefix['area']);
        $this->assertSame('prefix', $byPrefix['reason']);

        $byApi = $resolver->resolve([
            'id' => 'X001',
            'area' => ['name' => 'SKYNET-GADANG'],
        ]);
        $this->assertSame('SKYNET-GADANG', $byApi['area']);
        $this->assertSame('api_area', $byApi['reason']);
    }

    public function test_dua_satu_network_router_maps_to_gadang(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'X999',
            'router_name' => 'DUASATUNETWORK',
        ]);

        $this->assertSame('SKYNET-GADANG', $result['area']);
        $this->assertSame('router_name', $result['reason']);
    }

    public function test_historical_glagaharum_address_maps_to_jambuwer(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'NUMERIC001',
            'address' => 'Dusun Glagaharum',
        ]);

        $this->assertSame('SKYNET-JAMBUWER', $result['area']);
        $this->assertSame('address_keyword', $result['reason']);
    }

    public function test_historical_cakruan_klopo_kuning_address_maps_to_jambuwer(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'NUMERIC002',
            'address' => 'Cakruan Klopo Kuning',
        ]);

        $this->assertSame('SKYNET-JAMBUWER', $result['area']);
        $this->assertSame('address_keyword', $result['reason']);
    }

    public function test_unknown_customer_returns_unmapped_and_never_general(): void
    {
        $result = (new LegacyAreaResolver)->resolve([
            'id' => 'UNKNOWN001',
            'name' => 'Unknown Customer',
            'address' => 'Unknown Address',
        ]);

        $this->assertNull($result['area']);
        $this->assertSame('unmapped', $result['reason']);
        $this->assertNotSame('SKYNET-GENERAL', $result['area']);
    }
}
