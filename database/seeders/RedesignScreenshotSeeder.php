<?php

namespace Database\Seeders;

use App\Models\SalesLead;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Rich, entirely fictitious sample data for the redesign screenshot run
 * (e2e/redesign-screenshots, workflow "Redesign Screenshots").
 *
 * - Names, phone numbers, addresses and serial numbers are invented.
 * - Every IP address is from the RFC 5737 documentation ranges
 *   (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24); no real hostnames.
 * - Only runs on APP_ENV=testing (CI sandbox). Never for production.
 *
 * Runs after RecoveryE2ESeeder + FaqScreenshotSeeder and adds to their data.
 * At the end it writes the ids of a few records the screenshot spec needs
 * (customer/invoice/ticket/recovery detail pages) to a JSON file.
 */
class RedesignScreenshotSeeder extends Seeder
{
    private const PREFIX = 'RDS';

    /** @var array<string, array<int, string>> */
    private array $columns = [];

    private CarbonImmutable $now;

    private string $passwordHash;

    /** @var array<string, int> */
    private array $users = [];

    /** @var array<string, int> */
    private array $areas = [];

    /** @var array<string, string> */
    private array $areaNames = [];

    /** @var array<int, int> */
    private array $routers = [];

    /** @var array<int, int> */
    private array $olts = [];

    /** @var array<string, int> */
    private array $packages = [];

    /** @var array<int, array<string, mixed>> */
    private array $customers = [];

    /** @var array<string, int> */
    private array $ids = [];

    private int $seq = 0;

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('RedesignScreenshotSeeder hanya boleh dijalankan pada APP_ENV=testing.');
        }

        $password = (string) env('E2E_PASSWORD');
        if ($password === '') {
            throw new RuntimeException('E2E_PASSWORD wajib tersedia untuk fixture screenshot redesign.');
        }

        if (DB::table('customers')->where('code', 'like', self::PREFIX.'-%')->exists()) {
            // Fixture is meant for a fresh sandbox DB; seeding twice would only
            // fight foreign keys. Keep the existing data and refresh the ids file.
            $this->command?->warn('RedesignScreenshotSeeder: data contoh sudah ada, dilewati.');

            return;
        }

        mt_srand(20261010);
        $this->now = CarbonImmutable::now();
        $this->passwordHash = Hash::make($password);

        // DDL implicitly commits on MySQL, so do it before the transaction.
        $this->ensurePsbImportCandidatesTable();

        DB::transaction(function (): void {
            $this->seedSettings();
            $this->seedUsers();
            $this->seedAreas();
            $this->seedRouters();
            $this->seedOlts();
            $this->seedPackages();
            $this->seedInventory();
            $this->seedCustomers();
            $this->seedBilling();
            $this->seedTickets();
            $this->seedDeviceRecovery();
            $this->seedAttendance();
            $this->seedMonitoring();
            $this->seedWhatsApp();
            $this->seedPsb();
            $this->seedMigrations();
            $this->seedSales();
            $this->seedReseller();
            $this->seedGenieAcs();
            $this->seedHotspot();
            $this->seedTechnicianProgress();
        });

        $this->writeIds();
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /** Insert only the columns that exist in this schema (robust to drift). */
    private function insert(string $table, array $row): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $this->columns[$table] ??= Schema::getColumnListing($table);
        $filtered = array_intersect_key($row, array_flip($this->columns[$table]));
        foreach ($filtered as $key => $value) {
            if (is_array($value)) {
                $filtered[$key] = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }
        }
        if (in_array('id', $this->columns[$table], true)) {
            return (int) DB::table($table)->insertGetId($filtered);
        }
        DB::table($table)->insert($filtered);

        return 0;
    }

    private function ts(): array
    {
        return ['created_at' => $this->now, 'updated_at' => $this->now];
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function phone(int $n): string
    {
        return '0812555'.str_pad((string) (1000 + $n), 5, '0', STR_PAD_LEFT);
    }

    private function upsertUser(string $email, string $name, string $role, ?string $phone = null): int
    {
        DB::table('users')->updateOrInsert(
            ['email' => $email],
            array_intersect_key([
                'name' => $name,
                'password' => $this->passwordHash,
                'role' => $role,
                'phone' => $phone,
                'email_verified_at' => $this->now,
                'created_at' => $this->now->subMonths(mt_rand(2, 18)),
                'updated_at' => $this->now,
            ], array_flip(Schema::getColumnListing('users'))),
        );

        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    // ------------------------------------------------------------------
    // reference data
    // ------------------------------------------------------------------

    private function seedSettings(): void
    {
        Setting::set('company_name', 'Skynet Network', 'text', 'billing');
        Setting::set('company_address', 'Jl. Contoh Raya No. 1, Kota Fiktif', 'text', 'billing');
        Setting::set('company_phone', '0812-5550-0000', 'text', 'billing');
        Setting::set('payment_channels', [
            ['bank' => 'BCA', 'account_number' => '0000000001', 'account_name' => 'PT Contoh Jaringan'],
            ['bank' => 'Mandiri', 'account_number' => '0000000002', 'account_name' => 'PT Contoh Jaringan'],
        ], 'json', 'billing');
        Setting::set('attendance_group_label', 'Grup Absensi Teknisi (contoh)', 'text', 'attendance');
        Setting::set('attendance_group_id', 'grup-absensi-contoh@g.us', 'text', 'attendance');
        Setting::set('psb_wa_group_ids', ['grup-psb-contoh@g.us'], 'json', 'psb', 'PSB WA group JIDs');

        // Public isolir portal (/isolir, /isolated) is off by default; enable it in the sandbox.
        Setting::set('isolation_portal_enabled', 'true', 'boolean', 'isolation');
        Setting::set('isolation_portal_wa_admin', '0812-5550-0000', 'text', 'isolation');
        Setting::set('isolation_portal_office_address', 'Jl. Contoh Raya No. 1, Kota Fiktif', 'text', 'isolation');
        Setting::set('isolation_portal_office_hours', 'Senin-Sabtu 08.00-16.00', 'text', 'isolation');
    }

    private function seedUsers(): void
    {
        $this->users['superadmin'] = $this->upsertUser('admin@local', 'E2E Admin', 'superadmin');
        $this->users['technician'] = $this->upsertUser('e2e-technician@example.test', 'E2E Technician', 'teknisi', $this->phone(1));

        $people = [
            ['dewi.lestari', 'Dewi Lestari', 'superadmin'],
            ['rudi.hartono', 'Rudi Hartono', 'admin'],
            ['maya.sari', 'Maya Sari', 'admin'],
            ['fajar.nugroho', 'Fajar Nugroho', 'noc'],
            ['intan.permata', 'Intan Permata', 'noc'],
            ['bayu.saputra', 'Bayu Saputra', 'teknisi'],
            ['eko.wibowo', 'Eko Wibowo', 'teknisi'],
            ['hendra.gunawan', 'Hendra Gunawan', 'teknisi'],
            ['yusuf.maulana', 'Yusuf Maulana', 'teknisi'],
            ['rina.kusuma', 'Rina Kusuma', 'admin'],
        ];
        foreach ($people as $i => [$slug, $name, $role]) {
            $this->users[$slug] = $this->upsertUser($slug.'@example.test', $name, $role, $this->phone(10 + $i));
        }
    }

    private function seedAreas(): void
    {
        $areas = [
            ['SKM', 'Sukamaju', true],
            ['MKS', 'Mekarsari', true],
            ['CMP', 'Cempaka Baru', false],
            ['TJA', 'Tanjung Asri', true],
            ['BKI', 'Bukit Indah', false],
        ];
        foreach ($areas as [$code, $name, $portal]) {
            DB::table('areas')->updateOrInsert(['code' => $code], array_intersect_key([
                'name' => $name,
                'portal_enabled' => $portal,
                'description' => 'Area layanan '.$name.' (data contoh)',
                'created_at' => $this->now->subYear(),
                'updated_at' => $this->now,
            ], array_flip(Schema::getColumnListing('areas'))));
            $this->areas[$code] = (int) DB::table('areas')->where('code', $code)->value('id');
            $this->areaNames[$code] = $name;
        }

        // Scoped admins: Rudi → Sukamaju + Mekarsari, Rina → Tanjung Asri.
        foreach ([['rudi.hartono', 'SKM'], ['rudi.hartono', 'MKS'], ['rina.kusuma', 'TJA']] as [$user, $area]) {
            DB::table('area_user')->updateOrInsert(
                ['area_id' => $this->areas[$area], 'user_id' => $this->users[$user]],
                $this->ts(),
            );
        }
    }

    private function seedRouters(): void
    {
        $routers = [
            ['RTR-SUKAMAJU-01', '192.0.2.10', 'online', 'CCR2004-1G-12S+2XS', 412, 37, 'SKM'],
            ['RTR-MEKARSARI-01', '192.0.2.11', 'online', 'CCR1036-8G-2S+', 286, 22, 'MKS'],
            ['RTR-CEMPAKA-01', '192.0.2.12', 'online', 'RB4011iGS+', 154, 18, 'CMP'],
            ['RTR-TANJUNG-01', '192.0.2.13', 'offline', 'hEX S', 0, null, 'TJA'],
        ];
        foreach ($routers as $i => [$name, $ip, $status, $board, $online, $cpu, $area]) {
            DB::table('routers')->where('name', $name)->delete();
            $id = $this->insert('routers', [
                'name' => $name,
                'ip_address' => $ip,
                'port' => 8728,
                'username' => 'api-readonly',
                'password' => Crypt::encryptString('contoh-bukan-rahasia'),
                'is_active' => true,
                'connection_status' => $status,
                'isolation_profile' => 'isolir',
                'sync_status' => 'idle',
                'sync_finished_at' => $this->now->subMinutes(12 + $i),
                'sync_message' => 'Sinkron selesai (data contoh)',
                'last_sync_stats' => ['matched' => $online, 'unmatched' => mt_rand(0, 6), 'created' => 0],
                'vpn_enabled' => false,
                'vpn_server_address' => '192.0.2.1',
                'vpn_allowed_ips' => '192.0.2.0/24',
                'last_scanned_at' => $this->now->subMinutes(30),
                'last_health_check_at' => $status === 'online' ? $this->now->subMinutes(2) : $this->now->subHours(5),
                'last_scan_customers_count' => $online + mt_rand(5, 40),
                'current_online_count' => $status === 'online' ? $online : 0,
                'total_pppoe_count' => $online + mt_rand(10, 60),
                'cpu_load' => $cpu,
                'uptime' => $status === 'online' ? mt_rand(3, 60).'d'.mt_rand(1, 23).'h'.mt_rand(1, 59).'m' : null,
                'version' => '7.15.3 (stable)',
                'board_name' => $board,
                'created_at' => $this->now->subYear(),
                'updated_at' => $this->now,
            ]);
            $this->routers[] = $id;
            if ($i === 0) {
                $this->ids['router'] = $id; // monitoring router detail page screenshots
            }

            foreach ([['paket-10M', '10M/10M'], ['paket-20M', '20M/20M'], ['paket-50M', '50M/50M'], ['paket-100M', '100M/100M'], ['isolir', '1M/1M']] as [$profile, $rate]) {
                $this->insert('router_profiles', [
                    'router_id' => $id,
                    'name' => $profile,
                    'rate_limit' => $rate,
                    'bandwidth' => $rate,
                    'local_address' => '198.51.100.1',
                    'remote_address' => 'pool-'.$profile,
                    'only_one' => 'yes',
                ] + $this->ts());
            }

            $this->insert('router_vlans', [
                'router_id' => $id, 'vlan' => 100 + $i, 'label' => 'VLAN PPPoE '.$name,
                'area_id' => $this->areas[$area], 'mikrotik_profile' => 'paket-20M', 'is_default' => true, 'is_active' => true,
            ] + $this->ts());

            $this->insert('pppoe_address_pool_blocks', [
                'router_id' => $id,
                'site_profile_key' => strtolower($area),
                'pppoe_profile' => 'paket-20M',
                'customer_area' => $area,
                'block_name' => 'Blok '.$area.'-A',
                'block_index' => 0,
                'address_format' => 'full_ipv4',
                'local_address' => '198.51.100.1',
                'remote_start' => '198.51.100.10',
                'remote_end' => '198.51.100.250',
                'last_remote_address' => '198.51.100.'.(40 + $i * 20),
                'last_remote_source' => 'router',
                'last_remote_synced_at' => $this->now->subHour(),
                'priority' => 100,
                'is_active' => true,
                'capacity' => 241,
                'used_count' => 30 + $i * 20,
                'available_count' => 211 - $i * 20,
                'warning_threshold' => 10,
                'last_synced_at' => $this->now->subHour(),
            ] + $this->ts());

            for ($k = 0; $k < 24; $k++) {
                $this->insert('router_mt_snapshots', [
                    'router_id' => $id,
                    'online_count' => $status === 'online' ? max(0, $online - mt_rand(0, 25)) : 0,
                    'secret_count' => $online + 40,
                    'tier' => 't0',
                    'source' => 'exporter',
                    'quality' => $status === 'online' ? 'good' : 'stale',
                    'scrape_ms' => mt_rand(80, 400),
                    'collected_at' => $this->now->subHours(23 - $k),
                ] + $this->ts());
            }
            $this->insert('router_mt_secret_states', [
                'router_id' => $id, 'secrets_hash' => hash('sha256', $name), 'secret_count' => $online + 40,
                'mapped_count' => $online + 30, 'last_checked_at' => $this->now->subMinutes(5),
                'last_full_sync_at' => $this->now->subHours(2), 'last_status' => 'ok', 'last_message' => 'OK',
            ] + $this->ts());

            foreach (['ppp-unmatched-'.$i.'a', 'ppp-unmatched-'.$i.'b'] as $user) {
                $this->insert('router_staged_customers', [
                    'router_id' => $id, 'pppoe_user' => $user, 'profile' => 'paket-20M',
                    'comment' => 'Belum terhubung ke pelanggan', 'disabled' => false, 'status' => 'unmatched',
                    'first_seen_at' => $this->now->subDays(3), 'last_seen_at' => $this->now->subHour(),
                ] + $this->ts());
            }
        }
    }

    private function seedOlts(): void
    {
        $olts = [
            ['OLT-SUKAMAJU-C300', 'OLT-SKM', 'zte_c300', 'SKM', '198.51.100.20', 0],
            ['OLT-MEKARSARI-C300', 'OLT-MKS', 'zte_c300', 'MKS', '198.51.100.21', 1],
            ['OLT-CEMPAKA-MA5608T', 'OLT-CMP', 'huawei_ma5608t', 'CMP', '198.51.100.22', 2],
        ];
        foreach ($olts as [$name, $code, $vendor, $area, $ip, $routerIdx]) {
            DB::table('olts')->where('code', $code)->delete();
            $onus = [];
            for ($n = 1; $n <= 16; $n++) {
                $port = '1/'.(1 + intdiv($n - 1, 8)).'/'.(1 + ($n % 4));
                $online = $n % 7 !== 0;
                $onus[] = [
                    'onu_ref' => 'gpon-onu_'.$port.':'.$n,
                    'pon_port' => $port,
                    'state' => $online ? 'working' : 'LOS',
                    'phase_state' => $online ? 'working' : 'LOS',
                    'serial_number' => strtoupper(substr($vendor, 0, 4)).'C0DE'.str_pad((string) ($n + 100 * $routerIdx), 4, '0', STR_PAD_LEFT),
                    'onu_name' => 'pelanggan-'.strtolower($area).'-'.$n,
                    'rx_power_dbm' => $online ? -1 * (17 + mt_rand(0, 120) / 10) : null,
                    'distance_m' => mt_rand(250, 4200),
                ];
            }
            $id = $this->insert('olts', [
                'name' => $name,
                'code' => $code,
                'vendor' => $vendor,
                'area_id' => $this->areas[$area],
                'router_id' => $this->routers[$routerIdx],
                'management_ip' => $ip,
                'management_protocol' => 'telnet',
                'management_port' => 23,
                'username' => 'readonly',
                'password' => Crypt::encryptString('contoh-bukan-rahasia'),
                'snmp_community' => 'canary-community',
                'location' => 'POP '.$area.' (fiktif)',
                'notes' => 'Data contoh untuk screenshot redesign',
                'last_gpon_snapshot' => ['onus' => $onus, 'collected_at' => $this->now->subMinutes(20)->toISOString()],
                'last_gpon_synced_at' => $this->now->subMinutes(20),
                'sync_status' => 'idle',
                'created_at' => $this->now->subYear(),
                'updated_at' => $this->now,
            ]);
            $this->olts[] = $id;
            foreach ($onus as $onu) {
                $this->insert('olt_onus', array_merge($onu, [
                    'olt_id' => $id,
                    'snmp_index' => (string) mt_rand(268500000, 268600000),
                    'rx_source' => 'snmp',
                    'serial_source' => 'snmp',
                    'name_source' => 'snmp',
                    'state_collected_at' => $this->now->subMinutes(10),
                    'rx_collected_at' => $this->now->subMinutes(10),
                    'inventory_collected_at' => $this->now->subHours(2),
                ], $this->ts()));
            }
            foreach (['sync_inventory', 'provision', 'reboot_onu'] as $k => $op) {
                $this->insert('olt_operation_logs', [
                    'olt_id' => $id, 'user_id' => $this->users['superadmin'], 'operation' => $op,
                    'onu_ref' => $onus[$k]['onu_ref'], 'status' => $k === 2 ? 'failed' : 'success',
                    'payload' => ['dry_run' => true], 'result' => ['message' => 'OK (contoh)'],
                    'error' => $k === 2 ? 'Timeout (contoh)' : null,
                    'created_at' => $this->now->subHours(6 - $k), 'updated_at' => $this->now->subHours(6 - $k),
                ]);
            }
        }
    }

    private function seedPackages(): void
    {
        $packages = [
            ['RDS-10M', 'Home 10 Mbps', 10, 110000, 0],
            ['RDS-20M', 'Home 20 Mbps', 20, 150000, 0],
            ['RDS-30M', 'Home 30 Mbps', 30, 185000, 1],
            ['RDS-50M', 'Family 50 Mbps', 50, 250000, 1],
            ['RDS-100M', 'Family 100 Mbps', 100, 125000, 2],
            ['RDS-200M', 'Premium 200 Mbps', 200, 150000, 2],
            ['RDS-BIZ', 'Bisnis 100 Mbps Dedicated', 100, 750000, 0],
        ];
        foreach ($packages as [$code, $name, $speed, $price, $routerIdx]) {
            DB::table('packages')->updateOrInsert(['code' => $code], array_intersect_key([
                'router_id' => $this->routers[$routerIdx],
                'name' => $name,
                'description' => 'Paket internet '.$speed.' Mbps (data contoh)',
                'price' => $price,
                'speed_mbps' => $speed,
                'mikrotik_profile' => 'paket-'.$speed.'M',
                'rate_limit' => $speed.'M/'.$speed.'M',
                'is_active' => $code !== 'RDS-BIZ',
                'created_at' => $this->now->subYear(),
                'updated_at' => $this->now,
            ], array_flip(Schema::getColumnListing('packages'))));
            $this->packages[$code] = (int) DB::table('packages')->where('code', $code)->value('id');
        }
    }

    private function seedInventory(): void
    {
        $base = ['SKM' => [-6.2001, 110.4001], 'MKS' => [-6.2101, 110.4151], 'CMP' => [-6.1951, 110.4251], 'TJA' => [-6.2201, 110.3951], 'BKI' => [-6.1851, 110.4101]];
        foreach ($this->areas as $code => $areaId) {
            DB::table('odp_odc')->where('area_id', $areaId)->where('name', 'like', 'ODC-'.$code.'%')->delete();
            DB::table('odp_odc')->where('area_id', $areaId)->where('name', 'like', 'ODP-'.$code.'%')->delete();
            [$lat, $lng] = $base[$code];
            $odc = $this->insert('odp_odc', [
                'area_id' => $areaId, 'name' => 'ODC-'.$code.'-01', 'code' => 'ODC-'.$code.'-01', 'type' => 'ODC',
                'olt_port' => '1/1/1', 'total_ports' => 48, 'description' => 'ODC utama '.$code, 'status' => 'active',
                'lat' => $lat, 'lng' => $lng,
            ] + $this->ts());
            for ($i = 1; $i <= 4; $i++) {
                $this->insert('odp_odc', [
                    'area_id' => $areaId, 'parent_id' => $odc, 'name' => 'ODP-'.$code.'-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'code' => 'ODP-'.$code.'-'.$i, 'type' => 'ODP', 'total_ports' => $i === 4 ? 16 : 8,
                    'description' => 'Tiang depan gang '.$i, 'status' => $i === 3 ? 'maintenance' : 'active',
                    'lat' => $lat + $i * 0.0012, 'lng' => $lng + $i * 0.0009,
                ] + $this->ts());
            }
            $this->insert('kabel', [
                'area_id' => $areaId, 'name' => 'Feeder '.$code.' 24 core', 'type' => 'feeder', 'length_km' => 2.4 + mt_rand(0, 30) / 10,
                'status' => 'active', 'description' => 'Kabel feeder dari OLT ke ODC',
            ] + $this->ts());
            $this->insert('kabel', [
                'area_id' => $areaId, 'name' => 'Distribusi '.$code.' 12 core', 'type' => 'distribution', 'length_km' => 1.1 + mt_rand(0, 20) / 10,
                'status' => 'active', 'description' => 'Kabel distribusi ODC ke ODP',
            ] + $this->ts());
            $this->insert('splitter', [
                'area_id' => $areaId, 'name' => 'Splitter 1:8 '.$code, 'type' => '1:8', 'total_ports' => 8, 'used_ports' => mt_rand(3, 8),
                'status' => 'active', 'parent_odp_odc_id' => $odc, 'description' => 'Splitter di ODC',
            ] + $this->ts());
        }
    }

    // ------------------------------------------------------------------
    // customers & billing
    // ------------------------------------------------------------------

    private function seedCustomers(): void
    {
        $first = ['Andi', 'Bambang', 'Citra', 'Dian', 'Endah', 'Fikri', 'Galih', 'Hesti', 'Irfan', 'Joko', 'Kartika', 'Lukman', 'Mega', 'Nanda', 'Oki', 'Putri', 'Rizky', 'Sari', 'Taufik', 'Umi', 'Vina', 'Wahyu', 'Yanti', 'Zainal'];
        $last = ['Pratama', 'Wijaya', 'Kurniawan', 'Setiawan', 'Lestari', 'Hidayat', 'Saputra', 'Rahmawati', 'Susanto', 'Permana', 'Utami', 'Nugraha'];
        $streets = ['Jl. Anggrek', 'Jl. Flamboyan', 'Jl. Cendana', 'Jl. Teratai', 'Jl. Kamboja', 'Jl. Dahlia', 'Gg. Mangga', 'Perum Griya Asri Blok'];
        $statuses = array_merge(array_fill(0, 26, 'active'), ['isolated', 'isolated', 'isolated', 'isolated', 'suspended', 'suspended', 'terminated', 'terminated', 'pending_installation', 'pending_installation', 'inactive']);
        $areaCodes = array_keys($this->areas);
        $packageCodes = ['RDS-10M', 'RDS-20M', 'RDS-20M', 'RDS-30M', 'RDS-50M', 'RDS-100M', 'RDS-200M'];
        $base = ['SKM' => [-6.2001, 110.4001], 'MKS' => [-6.2101, 110.4151], 'CMP' => [-6.1951, 110.4251], 'TJA' => [-6.2201, 110.3951], 'BKI' => [-6.1851, 110.4101]];

        DB::table('customers')->where('code', 'like', self::PREFIX.'-%')->delete();

        foreach ($statuses as $i => $status) {
            $n = $i + 1;
            $name = $first[$i % count($first)].' '.$last[($i * 5) % count($last)];
            $area = $areaCodes[$i % count($areaCodes)];
            $routerIdx = $i % 3;
            $oltIdx = $i % 3;
            $package = $packageCodes[$i % count($packageCodes)];
            $code = self::PREFIX.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $online = $status === 'active' && $n % 6 !== 0;
            $rx = $status === 'pending_installation' ? null : -1 * (17 + ($n * 7 % 110) / 10);
            [$lat, $lng] = $base[$area];
            $pppoe = Str::slug($first[$i % count($first)]).'-'.strtolower($area).'-'.$n;

            $id = $this->insert('customers', [
                'code' => $code,
                'name' => $name,
                'email' => $pppoe.'@example.test',
                'phone' => $this->phone(100 + $n),
                'address' => $this->pick($streets).' No. '.mt_rand(1, 120).', RT 0'.mt_rand(1, 9).'/RW 0'.mt_rand(1, 5).', '.$this->areaNames[$area],
                'area_id' => $this->areas[$area],
                'router_id' => $this->routers[$routerIdx],
                'olt_id' => $status === 'pending_installation' ? null : $this->olts[$oltIdx],
                'olt_port_label' => $status === 'pending_installation' ? null : '1/1/'.(1 + $n % 4).':'.$n,
                'onu_serial' => $status === 'pending_installation' ? null : 'ZTEGC0DE'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                'olt_status' => $status === 'pending_installation' ? null : ($online ? 'working' : ($status === 'terminated' ? 'offline' : 'LOS')),
                'onu_rx_power_dbm' => $rx,
                'onu_tx_power_dbm' => $rx === null ? null : 2.1,
                'fiber_distance_m' => $rx === null ? null : mt_rand(300, 3800),
                'olt_last_synced_at' => $this->now->subMinutes(15),
                'mikrotik_profile' => $status === 'isolated' ? 'isolir' : 'paket-'.preg_replace('/\D/', '', $package).'M',
                'previous_profile' => $status === 'isolated' ? 'paket-20M' : null,
                'mikrotik_sync_status' => $n % 9 === 0 ? 'drift' : 'synced',
                'mikrotik_synced_at' => $this->now->subHours(1),
                'mikrotik_sync_checked_at' => $this->now->subMinutes(30),
                'package_id' => $this->packages[$package],
                'pppoe_user' => $pppoe,
                'pppoe_remote_address' => '198.51.100.'.(10 + $n),
                'last_learned_ip' => $online ? '198.51.100.'.(10 + $n) : null,
                'status' => $status,
                'odp_name' => 'ODP-'.$area.'-0'.(1 + $n % 4),
                'geo_lat' => $lat + ($n % 7) * 0.0011 - 0.003,
                'geo_long' => $lng + ($n % 5) * 0.0013 - 0.003,
                'join_date' => $this->now->subDays(30 + $n * 17)->toDateString(),
                'is_online' => $online,
                'pppoe_online_checked_at' => $this->now->subMinutes(3),
                'service_online' => $online,
                'service_online_at' => $online ? $this->now->subMinutes(3) : null,
                'due_day' => $n % 3 === 0 ? 25 : 20,
                'created_at' => $this->now->subDays(30 + $n * 17),
                'updated_at' => $this->now->subDays($n % 10),
            ]);

            $this->customers[] = compact('id', 'code', 'name', 'status', 'area', 'package', 'pppoe', 'online', 'n') + [
                'phone' => $this->phone(100 + $n),
                'area_id' => $this->areas[$area],
                'price' => (int) DB::table('packages')->where('id', $this->packages[$package])->value('price'),
            ];
        }

        $this->ids['customer'] = $this->customers[0]['id'];
        $this->ids['customer_isolated'] = collect($this->customers)->firstWhere('status', 'isolated')['id'];

        // One isolated customer next to the Sukamaju customers, so the Peta
        // customer layer shows an Isolir (amber) pin on the first screen.
        $movedKey = collect($this->customers)->search(fn ($c) => $c['id'] === $this->ids['customer_isolated']);
        $oldAreaName = $this->areaNames[$this->customers[$movedKey]['area']];
        $newAddress = str_replace(
            $oldAreaName,
            $this->areaNames['SKM'],
            (string) DB::table('customers')->where('id', $this->ids['customer_isolated'])->value('address'),
        );
        DB::table('customers')->where('id', $this->ids['customer_isolated'])->update([
            'area_id' => $this->areas['SKM'],
            'address' => $newAddress,
            'geo_lat' => $base['SKM'][0] + 0.0005,
            'geo_long' => $base['SKM'][1] + 0.0005,
        ]);
        // Package detail shot (RDS-20M): make sure the customer list shows Berhenti and Menunggu Pasang too,
        // not only the statuses that happen to fall on this package. DB and in-memory rows stay in step.
        foreach ([32, 34] as $idx) {
            if (! isset($this->customers[$idx]) || $this->customers[$idx]['package'] === 'RDS-20M') {
                continue;
            }
            DB::table('customers')->where('id', $this->customers[$idx]['id'])->update(['package_id' => $this->packages['RDS-20M']]);
            $this->customers[$idx]['package'] = 'RDS-20M';
            $this->customers[$idx]['price'] = (int) DB::table('packages')->where('id', $this->packages['RDS-20M'])->value('price');
        }

        // Keep the in-memory row in step with the DB (area filters, WA recipients, areas of later seeders).
        $this->customers[$movedKey]['area'] = 'SKM';
        $this->customers[$movedKey]['area_id'] = $this->areas['SKM'];
    }

    private function seedBilling(): void
    {
        DB::table('invoices')->where('code', 'like', 'INV-'.self::PREFIX.'-%')->delete();
        $methods = ['cash', 'transfer', 'qris', 'transfer', 'va', 'ewallet'];
        $invoiceSeq = 0;

        foreach ($this->customers as $c) {
            if ($c['status'] === 'pending_installation') {
                continue;
            }
            for ($m = 3; $m >= 0; $m--) {
                $period = $this->now->startOfMonth()->subMonths($m);
                $due = $period->setDay(min(28, $c['n'] % 3 === 0 ? 25 : 20));

                if ($m >= 2) {
                    $status = ($c['status'] === 'terminated' && $m === 2) ? 'void' : 'paid';
                } elseif ($m === 1) {
                    $status = in_array($c['status'], ['isolated', 'suspended'], true) ? 'overdue' : ($c['status'] === 'terminated' ? 'void' : 'paid');
                } else {
                    $status = match (true) {
                        in_array($c['status'], ['isolated', 'suspended'], true) => 'unpaid',
                        $c['status'] === 'terminated' => 'void',
                        $c['n'] % 3 === 0 => 'paid',
                        default => 'unpaid',
                    };
                }

                $invoiceSeq++;
                $code = 'INV-'.self::PREFIX.'-'.$period->format('Ym').'-'.str_pad((string) $c['n'], 4, '0', STR_PAD_LEFT);
                $invoiceId = $this->insert('invoices', [
                    'uuid' => (string) Str::uuid(),
                    'code' => $code,
                    'customer_id' => $c['id'],
                    'period' => $period->toDateString(),
                    'amount' => $c['price'],
                    'status' => $status,
                    'due_date' => $due->toDateString(),
                    'generated_at' => $period->subDays(5),
                    'created_at' => $period->subDays(5),
                    'updated_at' => $this->now->subDays($m * 10),
                ]);

                if (! isset($this->ids['invoice_unpaid']) && $status === 'unpaid') {
                    $this->ids['invoice_unpaid'] = $invoiceId;
                    $this->ids['invoice_unpaid_uuid_row'] = $invoiceId;
                }
                if (! isset($this->ids['invoice_overdue']) && $status === 'overdue') {
                    $this->ids['invoice_overdue'] = $invoiceId;
                }
                if (! isset($this->ids['invoice_void']) && $status === 'void') {
                    $this->ids['invoice_void'] = $invoiceId;
                }

                if ($status === 'paid') {
                    $method = $methods[$invoiceSeq % count($methods)];
                    $gateway = in_array($method, ['qris', 'va', 'ewallet'], true);
                    $paidAt = $due->subDays(mt_rand(0, 8))->setTime(mt_rand(8, 20), mt_rand(0, 59));
                    $trxId = $this->insert('transactions', [
                        'reference' => 'TRX-'.self::PREFIX.'-'.str_pad((string) $invoiceSeq, 5, '0', STR_PAD_LEFT),
                        'invoice_id' => $invoiceId,
                        'admin_id' => $gateway ? null : $this->users['maya.sari'],
                        'amount' => $c['price'],
                        'channel' => $gateway ? 'gateway' : 'manual',
                        'method' => $method,
                        'status' => $gateway ? 'paid' : 'verified',
                        'paid_at' => $paidAt,
                        'notes' => $gateway ? 'Dibayar via payment gateway (contoh)' : 'Pembayaran diterima di kantor (contoh)',
                        'created_at' => $paidAt,
                        'updated_at' => $paidAt,
                    ]);
                    if (! isset($this->ids['invoice_paid'])) {
                        $this->ids['invoice_paid'] = $invoiceId;
                    }
                    if ($gateway) {
                        $this->insert('payment_gateway_events', [
                            'provider' => 'xendit',
                            'external_id' => 'evt_contoh_'.$invoiceSeq,
                            'reference' => $code,
                            'invoice_id' => $invoiceId,
                            'amount' => $c['price'],
                            'credit_amount' => $c['price'],
                            'credit_method' => $method,
                            'status' => 'processed',
                            'payload' => ['note' => 'payload contoh', 'external_id' => $code],
                            'processed_at' => $paidAt,
                            'created_at' => $paidAt,
                            'updated_at' => $paidAt,
                        ]);
                    }
                    if ($m === 0 && $trxId) {
                        $this->insert('invoice_broadcasts', [
                            'invoice_id' => $invoiceId, 'type' => 'payment_received', 'channel' => 'whatsapp', 'status' => 'sent',
                            'message' => 'Terima kasih, pembayaran '.$code.' sudah kami terima.', 'sent_at' => $paidAt,
                            'created_at' => $paidAt, 'updated_at' => $paidAt,
                        ]);
                    }
                }

                if ($m === 0) {
                    $this->insert('invoice_broadcasts', [
                        'invoice_id' => $invoiceId, 'type' => 'invoice_created', 'channel' => 'whatsapp',
                        'status' => $c['n'] % 11 === 0 ? 'failed' : 'sent',
                        'message' => 'Tagihan internet periode '.$period->translatedFormat('F Y').' sudah terbit.',
                        'error_message' => $c['n'] % 11 === 0 ? 'Nomor tidak terdaftar di WhatsApp (contoh)' : null,
                        'sent_at' => $period->subDays(5), 'created_at' => $period->subDays(5), 'updated_at' => $period->subDays(5),
                    ]);
                }
            }
        }

        foreach (['pending', 'failed', 'held'] as $k => $status) {
            $this->insert('payment_gateway_events', [
                'provider' => 'xendit', 'external_id' => 'evt_contoh_x'.$k, 'reference' => 'INV-CONTOH-'.$k,
                'amount' => 150000, 'status' => $status, 'payload' => ['note' => 'payload contoh'],
                'held_at' => $status === 'held' ? $this->now->subHours(3) : null,
                'created_at' => $this->now->subHours(5 - $k), 'updated_at' => $this->now->subHours(5 - $k),
            ]);
        }

        // Billing enforcement trail (isolir / reconnect).
        foreach (collect($this->customers)->whereIn('status', ['isolated', 'active'])->take(6) as $k => $c) {
            $this->insert('billing_enforcement_actions', [
                'idempotency_key' => 'rds-enf-'.$c['id'].'-'.$k,
                'customer_id' => $c['id'],
                'action' => $c['status'] === 'isolated' ? 'isolate' : 'reconnect',
                'policy_version' => 'v2',
                'status' => 'completed',
                'metadata' => ['source' => 'scheduler'],
                'started_at' => $this->now->subDays($k + 1),
                'completed_at' => $this->now->subDays($k + 1)->addMinute(),
                'created_at' => $this->now->subDays($k + 1),
                'updated_at' => $this->now->subDays($k + 1),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // tickets & device recovery
    // ------------------------------------------------------------------

    private function seedTickets(): void
    {
        DB::table('support_tickets')->where('code', 'like', 'TCK-'.self::PREFIX.'-%')->pluck('id')->each(function ($id): void {
            DB::table('ticket_comments')->where('ticket_id', $id)->delete();
            DB::table('ticket_activities')->where('ticket_id', $id)->delete();
        });
        DB::table('support_tickets')->where('code', 'like', 'TCK-'.self::PREFIX.'-%')->delete();

        $techs = [$this->users['technician'], $this->users['bayu.saputra'], $this->users['eko.wibowo'], $this->users['hendra.gunawan']];
        $tickets = [
            ['incident', 'urgent', 'open', null, 'Internet mati total sejak pagi', 'Lampu LOS merah menyala, pelanggan sudah restart ONU.'],
            ['incident', 'high', 'assigned', 0, 'Koneksi putus-putus malam hari', 'Putus setiap 10-15 menit antara pukul 19.00-22.00.'],
            ['incident', 'normal', 'in_progress', 0, 'Kecepatan tidak sesuai paket', 'Speedtest hanya 4 Mbps dari paket 20 Mbps.'],
            ['incident', 'normal', 'pending', 1, 'Redaman tinggi di ODP', 'RX -29 dBm, perlu cek konektor di ODP.'],
            ['incident', 'low', 'resolved', 2, 'WiFi tidak muncul', 'SSID hilang setelah listrik padam; reset konfigurasi ONU.'],
            ['incident', 'normal', 'closed', 3, 'Kabel drop putus tertabrak truk', 'Kabel drop diganti 80 meter.'],
            ['installation', 'normal', 'assigned', 0, 'Pasang baru paket 20 Mbps', 'Calon pelanggan di Jl. Anggrek, ODP-SKM-02.'],
            ['installation', 'high', 'open', null, 'Pasang baru paket 50 Mbps', 'Rumah dua lantai, butuh kabel tambahan.'],
            ['psb', 'normal', 'in_progress', 1, 'PSB dari WA: Galih Saputra', 'Draft PSB masuk dari grup WA, menunggu SN ONU.'],
            ['billing', 'normal', 'open', null, 'Tanya rincian tagihan bulan ini', 'Pelanggan minta rincian dikirim via WA.'],
            ['billing', 'low', 'resolved', null, 'Sudah bayar tapi masih terisolir', 'Pembayaran transfer dicocokkan manual, layanan dibuka.'],
            ['request', 'normal', 'assigned', 2, 'Ganti password WiFi', 'Pelanggan lupa password WiFi.'],
            ['request', 'low', 'cancelled', null, 'Pindah titik router dalam rumah', 'Dibatalkan pelanggan.'],
            ['maintenance', 'high', 'in_progress', 3, 'Perapian kabel tiang ODC Mekarsari', 'Jadwal maintenance terencana, 2 jam.'],
            ['maintenance', 'normal', 'closed', 1, 'Ganti baterai UPS POP Sukamaju', 'Baterai UPS diganti, uji beban OK.'],
            ['other', 'low', 'open', null, 'Permintaan survey lokasi baru', 'Perumahan baru 40 unit, minta survey jaringan.'],
            ['dismantle', 'normal', 'assigned', 0, 'Dismantle: pindah rumah', 'Pelanggan pindah ke luar kota, ambil ONU dan adaptor.'],
            ['dismantle', 'normal', 'in_progress', 1, 'Dismantle: tunggakan 3 bulan', 'Ambil perangkat setelah 3 bulan tidak bayar.'],
            ['dismantle', 'normal', 'closed', 2, 'Dismantle: pindah provider', 'Perangkat sudah diambil dan diterima gudang.'],
            ['dismantle', 'low', 'open', null, 'Dismantle: tidak lanjut berlangganan', 'Menunggu jadwal teknisi.'],
        ];
        $reasons = ['moving_house', 'arrears', 'switch_provider', 'not_continuing'];
        $dismantleIdx = 0;
        $comments = [
            'Sudah dihubungi via WA, pelanggan ada di rumah setelah jam 13.00.',
            'Cek di OLT: ONU status LOS sejak 06:12.',
            'Teknisi berangkat dari POP.',
            'Konektor di ODP dibersihkan, RX naik ke -21 dBm.',
        ];

        foreach ($tickets as $i => [$type, $priority, $status, $techIdx, $subject, $description]) {
            $c = $this->customers[($i * 3) % count($this->customers)];
            if ($type === 'dismantle') {
                $c = collect($this->customers)->where('status', 'terminated')->values()->get($dismantleIdx % 2) ?? $c;
            }
            $code = 'TCK-'.self::PREFIX.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT);
            $created = $this->now->subHours(2 + $i * 7);
            $assignee = $techIdx === null ? null : $techs[$techIdx];
            $ticketId = $this->insert('support_tickets', [
                'code' => $code,
                'report_id' => 'RPT-'.self::PREFIX.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'customer_id' => $c['id'],
                'area_id' => $c['area_id'],
                'customer_name' => $c['name'],
                'customer_phone' => $c['phone'],
                'customer_coords' => '-6.2'.mt_rand(10, 99).', 110.4'.mt_rand(10, 99),
                'assigned_to' => $assignee,
                'created_by' => $this->users[$i % 2 ? 'maya.sari' : 'superadmin'],
                'type' => $type,
                'priority' => $priority,
                'status' => $status,
                'subject' => $subject,
                'description' => $description,
                'gangguan' => $type === 'incident' ? $subject : null,
                'keterangan' => 'Data contoh untuk screenshot redesign.',
                'kendala' => $status === 'pending' ? 'Menunggu material patch cord.' : null,
                'action_taken' => in_array($status, ['resolved', 'closed'], true) ? 'Perbaikan selesai, pelanggan konfirmasi normal.' : null,
                'due_at' => $created->addDay(),
                'assigned_at' => $assignee ? $created->addMinutes(20) : null,
                'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $created->addHours(5) : null,
                'dismantle_reason' => $type === 'dismantle' ? $reasons[$dismantleIdx % count($reasons)] : null,
                'created_at' => $created,
                'updated_at' => $created->addHours(1),
            ]);

            $this->insert('ticket_activities', [
                'ticket_id' => $ticketId, 'user_id' => $this->users['superadmin'], 'action' => 'created',
                'old_value' => [], 'new_value' => ['status' => 'open'], 'description' => 'Tiket dibuat',
                'created_at' => $created, 'updated_at' => $created,
            ]);
            if ($assignee) {
                $this->insert('ticket_activities', [
                    'ticket_id' => $ticketId, 'user_id' => $this->users['superadmin'], 'action' => 'assigned',
                    'old_value' => ['assigned_to' => null], 'new_value' => ['assigned_to' => $assignee], 'description' => 'Tiket ditugaskan ke teknisi',
                    'created_at' => $created->addMinutes(20), 'updated_at' => $created->addMinutes(20),
                ]);
            }
            if ($type === 'dismantle' && $status !== 'open') {
                $this->insert('ticket_activities', [
                    'ticket_id' => $ticketId, 'user_id' => $this->users['superadmin'], 'action' => 'customer_lifecycle_applied',
                    'old_value' => [], 'new_value' => ['lifecycle_action' => 'dismantle'], 'description' => 'Dismantle disetujui',
                    'created_at' => $created->addMinutes(30), 'updated_at' => $created->addMinutes(30),
                ]);
            }
            $commentCount = in_array($status, ['open', 'cancelled'], true) ? 0 : 1 + $i % 3;
            for ($k = 0; $k < $commentCount; $k++) {
                $this->insert('ticket_comments', [
                    'ticket_id' => $ticketId,
                    'user_id' => $k % 2 === 0 ? ($assignee ?? $this->users['superadmin']) : $this->users['maya.sari'],
                    'body' => $comments[($i + $k) % count($comments)],
                    'created_at' => $created->addMinutes(40 + $k * 25),
                    'updated_at' => $created->addMinutes(40 + $k * 25),
                ]);
            }

            if ($type === 'dismantle') {
                $this->ids['dismantle_ticket_'.$dismantleIdx] = $ticketId;
                $dismantleIdx++;
            }
            if ($i === 1) {
                $this->ids['ticket'] = $ticketId; // incident, assigned to E2E technician
            }
            if ($i === 2) {
                $this->ids['ticket_in_progress'] = $ticketId;
            }
        }
        $this->ids['ticket_dismantle'] = $this->ids['dismantle_ticket_0'];
    }

    private function seedDeviceRecovery(): void
    {
        $terminated = collect($this->customers)->whereIn('status', ['terminated', 'suspended', 'inactive'])->values();
        $techs = [$this->users['technician'], $this->users['bayu.saputra'], $this->users['eko.wibowo']];
        $cases = [
            ['waiting_assignment', null, 'pending', 'unknown'],
            ['assigned', 0, 'pending', 'unknown'],
            ['scheduled', 0, 'pending', 'unknown'],
            ['pickup_started', 1, 'pending', 'unknown'],
            ['carried', 0, 'carried', 'good'],
            ['received', 2, 'received', 'good'],
            ['exception', 1, 'exception', 'lost'],
            ['exception_accepted', 2, 'exception', 'damaged'],
            ['cancelled', null, 'pending', 'unknown'],
            ['reused', 1, 'pending', 'good'],
        ];

        foreach ($cases as $i => [$status, $techIdx, $itemStatus, $condition]) {
            $c = $terminated[$i % $terminated->count()];
            $assignee = $techIdx === null ? null : $techs[$techIdx];
            $created = $this->now->subDays(10 - $i)->setTime(9, 0);
            $code = 'TCK-'.self::PREFIX.'-DR'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $ticketStatus = match ($status) {
                'received', 'exception_accepted', 'reused' => 'closed',
                'cancelled' => 'cancelled',
                'waiting_assignment' => 'open',
                default => 'assigned',
            };
            $ticketId = $this->insert('support_tickets', [
                'code' => $code,
                'report_id' => $code,
                'customer_id' => $c['id'],
                'area_id' => $c['area_id'],
                'customer_name' => $c['name'],
                'customer_phone' => $c['phone'],
                'assigned_to' => $assignee,
                'created_by' => $this->users['superadmin'],
                'type' => 'dismantle',
                'priority' => 'normal',
                'status' => $ticketStatus,
                'subject' => 'Ambil perangkat: '.$c['name'],
                'description' => 'Pengambilan ONU dan adaptor setelah berhenti berlangganan (contoh).',
                'dismantle_reason' => ['not_continuing', 'moving_house', 'arrears', 'switch_provider'][$i % 4],
                'due_at' => $created->addDays(2),
                'assigned_at' => $assignee ? $created->addMinutes(15) : null,
                'created_at' => $created,
                'updated_at' => $created->addHour(),
            ]);
            $this->insert('ticket_activities', [
                'ticket_id' => $ticketId, 'user_id' => $this->users['superadmin'], 'action' => 'customer_lifecycle_applied',
                'old_value' => [], 'new_value' => ['lifecycle_action' => 'dismantle'], 'description' => 'Dismantle disetujui',
                'created_at' => $created, 'updated_at' => $created,
            ]);

            $caseId = $this->insert('device_recovery_cases', [
                'ticket_id' => $ticketId,
                'customer_id' => $c['id'],
                'area_id' => $c['area_id'],
                'status' => $status,
                'keep_customer_active' => false,
                'assigned_to' => $assignee,
                'created_by' => $this->users['superadmin'],
                'updated_by' => $this->users['superadmin'],
                'scheduled_at' => in_array($status, ['waiting_assignment', 'assigned', 'cancelled'], true) ? null : $created->addDay()->setTime(10, 0),
                'started_at' => in_array($status, ['pickup_started', 'carried', 'received', 'exception', 'exception_accepted'], true) ? $created->addDay()->setTime(10, 30) : null,
                'received_at' => $status === 'received' ? $created->addDays(2)->setTime(15, 0) : null,
                'exception_reason' => str_starts_with($status, 'exception') ? ($condition === 'lost' ? 'Pelanggan menyatakan ONU hilang.' : 'ONU rusak terkena petir.') : null,
                'version' => 3,
                'meta' => ['source' => 'approved_dismantle'],
                'created_at' => $created,
                'updated_at' => $created->addDay(),
            ]);

            foreach ([['onu', 'ZTEGC0DE'.str_pad((string) $c['n'], 4, '0', STR_PAD_LEFT), 'customer'], ['adaptor', null, 'standard']] as $k => [$type, $serial, $source]) {
                $this->insert('device_recovery_items', [
                    'recovery_case_id' => $caseId,
                    'device_type' => $type,
                    'expected_serial_number' => $serial,
                    'captured_serial_number' => in_array($itemStatus, ['carried', 'received'], true) ? $serial : null,
                    'expected_source' => $source,
                    'status' => $itemStatus,
                    'condition' => $condition,
                    'captured_by' => in_array($itemStatus, ['carried', 'received'], true) ? $assignee : null,
                    'captured_at' => in_array($itemStatus, ['carried', 'received'], true) ? $created->addDay()->setTime(11, 0) : null,
                    'verified_by' => $itemStatus === 'received' ? $this->users['maya.sari'] : null,
                    'verified_at' => $itemStatus === 'received' ? $created->addDays(2)->setTime(15, 0) : null,
                    'notes' => $k === 0 ? 'Kondisi fisik baik, lengkap dengan kabel patch.' : null,
                    'created_at' => $created,
                    'updated_at' => $created->addDay(),
                ]);
            }

            $trail = [['created', null, 'waiting_assignment']];
            if ($assignee) {
                $trail[] = ['assigned', 'waiting_assignment', 'assigned'];
            }
            if ($status !== 'waiting_assignment' && $status !== 'assigned') {
                $trail[] = ['status_changed', 'assigned', $status];
            }
            foreach ($trail as $k => [$event, $from, $to]) {
                $this->insert('device_recovery_events', [
                    'recovery_case_id' => $caseId,
                    'actor_id' => $this->users['superadmin'],
                    'event_type' => $event,
                    'from_status' => $from,
                    'to_status' => $to,
                    'reason' => $k === 0 ? 'Dibuat dari dismantle yang disetujui' : null,
                    'source' => 'web',
                    'idempotency_key' => 'rds-dr-'.$caseId.'-'.$k,
                    'created_at' => $created->addHours($k * 3),
                ]);
            }

            if ($status === 'assigned' && ! isset($this->ids['recovery_case'])) {
                $this->ids['recovery_case'] = $caseId;
            }
            if ($status === 'received') {
                $this->ids['recovery_case_received'] = $caseId;
            }
        }
    }

    // ------------------------------------------------------------------
    // operations: attendance, monitoring, WA, PSB, migrasi, sales, mitra
    // ------------------------------------------------------------------

    private function seedAttendance(): void
    {
        DB::table('attendances')->where('message_id', 'like', 'rds-%')->delete();
        $techs = [];
        foreach ([['E2E Technician', 1], ['Bayu Saputra', 15], ['Eko Wibowo', 16], ['Hendra Gunawan', 17]] as [$name, $p]) {
            DB::table('technician_members')->where('name', $name)->delete();
            $memberId = $this->insert('technician_members', [
                'name' => $name, 'role' => 'teknisi', 'is_active' => true, 'meta' => ['phone' => $this->phone($p)],
            ] + $this->ts());
            $techs[] = [$name, $this->phone($p), $memberId ?: null];
        }
        $captions = ['Absen masuk, standby POP Sukamaju', 'Masuk, lanjut tiket gangguan Mekarsari', 'Hadir — tim pasang baru', 'Absen pulang, semua tiket selesai'];
        for ($d = 0; $d < 5; $d++) {
            foreach ($techs as $k => [$name, $phone, $userId]) {
                $at = $this->now->subDays($d)->setTime(7, 30 + $k * 6);
                $this->insert('attendances', [
                    'sender_number' => $phone,
                    'sender_jid' => $phone.'@s.whatsapp.net',
                    'sender_name' => $name,
                    'technician_id' => $userId,
                    'caption' => $captions[($d + $k) % count($captions)],
                    'photo_url' => 'attendance/contoh-'.($k + 1).'.jpg',
                    'message_id' => 'rds-att-'.$d.'-'.$k,
                    'review_status' => ($d + $k) % 9 === 0 ? 'rejected' : 'accepted',
                    'created_at' => $at,
                    'updated_at' => $at,
                ]);
            }
        }
    }

    private function seedMonitoring(): void
    {
        $alerts = [
            ['router', 'RTR-TANJUNG-01', 'router_offline', 'critical', 'active'],
            ['olt', 'OLT-CEMPAKA-MA5608T', 'pon_los_spike', 'warning', 'active'],
            ['router', 'POP Cempaka (contoh)', 'high_latency', 'warning', 'active'],
            ['customer', 'Pelanggan RDS-0006', 'rx_power_low', 'warning', 'recovered'],
            ['router', 'RTR-MEKARSARI-01', 'cpu_high', 'warning', 'recovered'],
        ];
        foreach ($alerts as $i => [$type, $name, $issue, $severity, $status]) {
            $this->insert('monitoring_alert_events', [
                'source' => 'sandbox', 'entity_type' => $type, 'entity_id' => (string) ($i + 1), 'entity_name' => $name,
                'issue' => $issue, 'severity' => $severity, 'status' => $status, 'dedupe_key' => 'rds-alert-'.$i,
                'payload' => ['note' => 'contoh'], 'first_seen_at' => $this->now->subHours(3 + $i),
                'last_seen_at' => $this->now->subMinutes(5 + $i), 'last_sent_at' => $this->now->subMinutes(30),
                'recovered_at' => $status === 'recovered' ? $this->now->subMinutes(40) : null,
            ] + $this->ts());
        }

        // Ticketing candidates drawer (source connectivity_scan): 6 "PON offline" (4 share one ODP/PON = one massal group)
        // and 4 "Redaman buruk". Fictitious customers only; last_seen is fresh so nothing is flagged stale.
        $ticketed = DB::table('support_tickets')->whereNotNull('customer_id')->pluck('customer_id')->all();
        $active = collect($this->customers)->where('status', 'active')
            ->reject(fn (array $c) => in_array($c['id'], $ticketed, true))->values()->take(10);
        foreach ($active as $i => $c) {
            $pon = $i < 6;
            $massal = $i < 4;
            $this->insert('monitoring_alert_events', [
                'source' => 'connectivity_scan', 'entity_type' => 'customer', 'entity_id' => (string) $c['id'],
                'entity_name' => $c['name'].' ('.$c['code'].')',
                'issue' => $pon ? 'pon_pppoe_mismatch' : 'bad_signal_online',
                'severity' => $pon ? 'warning' : 'critical',
                'status' => 'active',
                'dedupe_key' => 'customer:'.$c['id'].':'.($pon ? 'pon_pppoe_mismatch' : 'bad_signal_online'),
                'payload' => [
                    'customer_name' => $c['name'], 'customer_code' => $c['code'], 'area' => $this->areaNames[$c['area']] ?? null,
                    'odp' => $massal ? 'ODP-SKM-01' : 'ODP-MKS-0'.(2 + $i % 3),
                    'pon_port' => $massal ? '1/1/2' : '1/2/'.(1 + $i % 4),
                    'olt_status' => $pon ? 'offline' : 'working', 'pppoe_online' => true,
                    'onu_rx_power_dbm' => $pon ? -23.4 : -(27.5 + $i / 10),
                ],
                'first_seen_at' => $this->now->subMinutes(25), 'last_seen_at' => $this->now->subMinutes(2 + $i),
            ] + $this->ts());
        }

        // Traffic samples for the customer traffic panel.
        foreach (array_slice($this->customers, 0, 12) as $c) {
            $routerId = DB::table('customers')->where('id', $c['id'])->value('router_id');
            $this->insert('customer_traffic_current', [
                'customer_id' => $c['id'], 'router_id' => $routerId, 'pppoe_key_hash' => hash('sha256', $c['pppoe']),
                'online' => $c['online'], 'download_bps' => $c['online'] ? mt_rand(2, 80) * 1000000 : 0,
                'upload_bps' => $c['online'] ? mt_rand(1, 20) * 1000000 : 0, 'quality' => 'good', 'source' => 'mikrotik_pppoe',
                'sampled_at' => $this->now->subMinutes(1),
            ] + $this->ts());
            for ($d = 1; $d <= 7; $d++) {
                $this->insert('customer_traffic_daily', [
                    'traffic_date_wib' => $this->now->subDays($d)->toDateString(), 'customer_id' => $c['id'], 'router_id' => $routerId,
                    'download_bytes' => mt_rand(3, 40) * 1000000000, 'upload_bytes' => mt_rand(1, 8) * 1000000000,
                    'sample_count' => 280, 'expected_sample_count' => 288, 'coverage_ratio' => 0.97, 'quality' => 'good',
                    'first_sampled_at' => $this->now->subDays($d)->startOfDay(), 'last_sampled_at' => $this->now->subDays($d)->endOfDay(),
                ] + $this->ts());
            }
        }
    }

    private function seedWhatsApp(): void
    {
        DB::table('wa_campaigns')->where('name', 'like', '%(contoh)%')->pluck('id')->each(fn ($id) => DB::table('wa_campaign_recipients')->where('wa_campaign_id', $id)->delete());
        DB::table('wa_campaigns')->where('name', 'like', '%(contoh)%')->delete();

        $campaigns = [
            ['Pengingat jatuh tempo Oktober (contoh)', 'completed', 'all', null, 'Halo {nama}, tagihan internet Anda Rp{tagihan} jatuh tempo {jatuh_tempo}. Abaikan jika sudah bayar.'],
            ['Info maintenance Mekarsari (contoh)', 'completed', 'area', 'MKS', 'Pelanggan Mekarsari yth, akan ada maintenance jaringan Sabtu 01.00-03.00 WIB.'],
            ['Promo upgrade 100 Mbps (contoh)', 'processing', 'all', null, 'Upgrade ke 100 Mbps hanya Rp125.000/bulan. Balas YA untuk info.'],
            ['Gangguan massal Cempaka (contoh)', 'failed', 'area', 'CMP', 'Mohon maaf, terjadi gangguan di area Cempaka Baru. Tim sedang menangani.'],
            ['Ucapan hari raya (contoh)', 'draft', 'all', null, 'Selamat hari raya dari seluruh tim. Mohon maaf lahir dan batin.'],
            ['Survey kepuasan (contoh)', 'paused', 'area', 'SKM', 'Bantu kami dengan mengisi survey singkat berikut.'],
        ];
        foreach ($campaigns as $i => [$name, $status, $target, $area, $template]) {
            $recipients = collect($this->customers)->when($area, fn ($c) => $c->where('area', $area))->values();
            $total = $recipients->count();
            $sent = match ($status) {
                'completed' => $total - 1, 'processing' => intdiv($total, 2), 'failed' => 2, 'paused' => 3, default => 0
            };
            $failed = match ($status) {
                'completed' => 1, 'failed' => $total - 2, default => 0
            };
            $campaignId = $this->insert('wa_campaigns', [
                'name' => $name, 'message_template' => $template, 'status' => $status, 'target_type' => $target,
                'target_area_id' => $area ? $this->areas[$area] : null,
                'scheduled_at' => $this->now->subDays(6 - $i), 'total_recipients' => $total, 'sent_count' => $sent, 'failed_count' => $failed,
                'created_at' => $this->now->subDays(7 - $i), 'updated_at' => $this->now->subDays(6 - $i),
            ]);
            foreach ($recipients as $k => $c) {
                $rs = $k < $sent ? 'sent' : ($k < $sent + $failed ? 'failed' : 'pending');
                $this->insert('wa_campaign_recipients', [
                    'wa_campaign_id' => $campaignId, 'customer_id' => $c['id'], 'phone_number' => $c['phone'], 'status' => $rs,
                    'error_message' => $rs === 'failed' ? 'Nomor tidak aktif (contoh)' : null,
                    'sent_at' => $rs === 'sent' ? $this->now->subDays(6 - $i)->addMinutes($k) : null,
                ] + $this->ts());
            }
            if ($i === 0) {
                $this->ids['broadcast'] = $campaignId;
            }
        }

        $events = ['invoice_created', 'due_reminder', 'payment_received', 'isolation_notice', 'reconnected'];
        foreach (array_slice($this->customers, 0, 20) as $k => $c) {
            $this->insert('billing_wa_logs', [
                'event' => $events[$k % count($events)], 'customer_id' => $c['id'], 'area_id' => $c['area_id'],
                'phone' => $c['phone'], 'message' => 'Pesan otomatis billing (contoh) untuk '.$c['name'],
                'status' => $k % 7 === 0 ? 'failed' : ($k % 5 === 0 ? 'pending' : 'sent'),
                'error' => $k % 7 === 0 ? 'Gateway WA tidak terhubung (contoh)' : null,
                'dedupe_key' => 'rds-wa-'.$k, 'sent_at' => $k % 5 === 0 ? null : $this->now->subHours($k),
                'created_at' => $this->now->subHours($k), 'updated_at' => $this->now->subHours($k),
            ]);
        }

        foreach (['psb', 'gangguan', 'absensi', 'migrasi'] as $k => $domain) {
            $this->insert('wa_chat_signals', [
                'channel' => 'whatsapp', 'domain' => $domain, 'intent' => $domain.'_report', 'severity' => $k === 1 ? 'high' : 'normal',
                'group_jid' => 'grup-contoh-'.$k.'@g.us', 'group_label' => 'Grup '.ucfirst($domain).' (contoh)',
                'sender_name' => 'Teknisi Contoh', 'message_id' => 'rds-sig-'.$k, 'summary' => 'Ringkasan pesan '.$domain.' (contoh)',
                'redacted_text' => 'Isi pesan disamarkan (contoh)', 'occurred_at' => $this->now->subHours($k + 1),
            ] + $this->ts());
        }
    }

    /**
     * psb_import_candidates has no migration (created out-of-band on prod), so a
     * fresh sandbox DB lacks it and /psb-imports (PsbImportController::index)
     * returned 500. Create the same shape e2e-sandbox.yml uses (testing only).
     */
    private function ensurePsbImportCandidatesTable(): void
    {
        if (Schema::hasTable('psb_import_candidates')) {
            return;
        }

        Schema::create('psb_import_candidates', function ($table) {
            $table->id();
            $table->string('source_file');
            $table->string('customer_code');
            $table->string('pppoe_user')->nullable();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone')->nullable();
            $table->string('area_name')->nullable();
            $table->string('package_name')->nullable();
            $table->string('speed')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->string('status')->default('staged');
            $table->string('match_status')->nullable();
            $table->text('match_notes')->nullable();
            $table->string('router_profile')->nullable();
            $table->unsignedBigInteger('matched_customer_id')->nullable();
            $table->unsignedBigInteger('resolved_area_id')->nullable();
            $table->unsignedBigInteger('resolved_package_id')->nullable();
            $table->unsignedBigInteger('resolved_router_id')->nullable();
            $table->unsignedBigInteger('matched_router_staged_customer_id')->nullable();
            $table->unsignedBigInteger('applied_customer_id')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('raw_payload')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    private function seedPsb(): void
    {
        DB::table('psb_drafts')->where('message_id', 'like', 'rds-%')->delete();
        $statuses = ['received', 'waiting_sn', 'pending_vlan', 'secret_ok', 'olt_ok', 'applied', 'applied', 'applied', 'correction_pending_review', 'failed', 'timeout', 'cancelled'];
        $names = ['Galih Saputra', 'Tia Ananta', 'Rizal Fahmi', 'Siska Amelia', 'Teguh Prakoso', 'Wulan Sari', 'Arif Budiman', 'Lina Marlina', 'Dodi Kurnia', 'Fitri Handayani', 'Hadi Susilo', 'Ratna Dewi'];
        $areaCodes = array_keys($this->areas);
        foreach ($statuses as $i => $status) {
            $area = $areaCodes[$i % 3];
            $created = $this->now->subHours(3 + $i * 9);
            $customerId = $status === 'applied' ? $this->customers[$i]['id'] : null;
            $this->insert('psb_drafts', [
                'status' => $status,
                'activation_mode' => $i % 2 ? 'auto' : 'manual',
                'site_profile_key' => $i % 4 === 3 ? 'krian' : 'ra',
                'group_jid' => 'grup-psb-contoh@g.us',
                'sender_jid' => $this->phone(15 + $i % 3).'@s.whatsapp.net',
                'sender_name' => ['Bayu Saputra', 'Eko Wibowo', 'Hendra Gunawan'][$i % 3],
                'message_id' => 'rds-psb-'.$i,
                'raw_text' => "PSB\nNama: {$names[$i]}\nRT/RW: 0".($i % 9 + 1)."/02\nODP: ODP-{$area}-0".($i % 4 + 1)."\nHP: ".$this->phone(300 + $i),
                'full_name' => $names[$i],
                'rt' => '0'.($i % 9 + 1),
                'rw' => '02',
                'odp' => 'ODP-'.$area.'-0'.($i % 4 + 1),
                'phone' => $this->phone(300 + $i),
                'tikor' => '-6.2'.mt_rand(10, 99).',110.4'.mt_rand(10, 99),
                'daerah' => $this->areaNames[$area],
                'area_id' => $this->areas[$area],
                'sn' => in_array($status, ['received', 'waiting_sn'], true) ? null : 'ZTEGC0FE'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'pon_port' => in_array($status, ['received', 'waiting_sn'], true) ? null : '1/1/'.($i % 4 + 1),
                'onu_id' => in_array($status, ['received', 'waiting_sn'], true) ? null : 20 + $i,
                'vlan' => in_array($status, ['received', 'waiting_sn', 'pending_vlan'], true) ? null : 100 + $i % 3,
                'pppoe_user' => Str::slug($names[$i]).'-'.strtolower($area),
                'pppoe_remote_address' => in_array($status, ['secret_ok', 'olt_ok', 'applied'], true) ? '198.51.100.'.(150 + $i) : null,
                'mikrotik_profile' => 'paket-20M',
                'router_id' => $this->routers[$i % 3],
                'olt_id' => $this->olts[$i % 3],
                'package_id' => $this->packages['RDS-20M'],
                'customer_id' => $customerId,
                'last_error' => in_array($status, ['failed', 'timeout'], true) ? 'OLT tidak merespons dalam 30 detik (contoh)' : null,
                'meta' => ['source' => 'contoh'],
                'secret_at' => in_array($status, ['secret_ok', 'olt_ok', 'applied'], true) ? $created->addMinutes(5) : null,
                'olt_at' => in_array($status, ['olt_ok', 'applied'], true) ? $created->addMinutes(9) : null,
                'applied_at' => $status === 'applied' ? $created->addMinutes(12) : null,
                'created_at' => $created,
                'updated_at' => $created->addMinutes(12),
            ]);
        }
        for ($d = 13; $d >= 0; $d--) {
            foreach (['skm', 'mks'] as $k => $site) {
                $input = mt_rand(1, 7);
                $success = max(0, $input - mt_rand(0, 2));
                $this->insert('psb_kpi_daily', [
                    'work_date' => $this->now->subDays($d)->toDateString(), 'site_key' => $site, 'area_id' => array_values($this->areas)[$k],
                    'input_count' => $input, 'success_count' => $success, 'pending_count' => max(0, $input - $success - 1),
                    'failed_count' => $input - $success > 0 ? 1 : 0,
                ] + $this->ts());
            }
        }
        foreach (array_slice($names, 0, 6) as $k => $name) {
            $this->insert('psb_starlite_entries', [
                'installed_on' => $this->now->subDays($k * 2)->toDateString(), 'rw' => '0'.($k + 1), 'full_name' => $name,
                'pppoe_user' => Str::slug($name).'-sl', 'sn' => 'ZTEGC0AB'.str_pad((string) $k, 4, '0', STR_PAD_LEFT),
                'service_type' => 'PSB', 'install_status' => $k % 3 === 0 ? 'pending' : 'terpasang', 'odp' => 'ODP-SKM-0'.($k % 4 + 1),
                'kendala' => $k % 3 === 0 ? 'Menunggu tarikan kabel' : null, 'pic' => 'Bayu', 'source' => 'excel', 'source_key' => 'rds-sl-'.$k,
            ] + $this->ts());
        }
    }

    private function seedMigrations(): void
    {
        DB::table('customer_migrations')->where('message_id', 'like', 'rds-%')->delete();
        $statuses = ['received', 'waiting_sn', 'olt_ok', 'synced', 'synced', 'failed', 'needs_review', 'cancelled', 'partially_executed'];
        foreach ($statuses as $i => $status) {
            $c = $this->customers[$i + 2];
            $created = $this->now->subHours(5 + $i * 11);
            $this->insert('customer_migrations', [
                'status' => $status,
                'source' => $i % 3 === 2 ? 'import' : 'wa',
                'group_jid' => 'grup-migrasi-contoh@g.us',
                'sender_name' => ['Bayu Saputra', 'Eko Wibowo'][$i % 2],
                'message_id' => 'rds-mig-'.$i,
                'raw_text' => "MIGRASI\nUser: {$c['pppoe']}\nSN baru: ZTEGC0BB".str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'customer_id' => $c['id'],
                'pppoe_user' => $c['pppoe'],
                'full_name' => $c['name'],
                'phone' => $c['phone'],
                'rt' => '03', 'rw' => '01',
                'odp' => 'ODP-'.$c['area'].'-02',
                'daerah' => $this->areaNames[$c['area']],
                'area_id' => $c['area_id'],
                'old_router_id' => $this->routers[3],
                'old_olt_id' => $this->olts[2],
                'old_onu_ref' => 'gpon-onu_1/1/2:'.($i + 5),
                'old_sn' => 'HWTCC0DE'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'router_id' => $this->routers[0],
                'new_olt_id' => $this->olts[0],
                'new_onu_ref' => in_array($status, ['olt_ok', 'synced', 'partially_executed'], true) ? 'gpon-onu_1/1/3:'.(30 + $i) : null,
                'new_pon_port' => '1/1/3',
                'new_onu_id' => 30 + $i,
                'new_sn' => $status === 'received' ? null : 'ZTEGC0BB'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'vlan' => 100,
                'tr069_vlan' => 69,
                'last_error' => $status === 'failed' ? 'SN tidak ditemukan di daftar unconfigured (contoh)' : null,
                'meta' => ['source' => 'contoh'],
                'olt_at' => in_array($status, ['olt_ok', 'synced'], true) ? $created->addMinutes(8) : null,
                'synced_at' => $status === 'synced' ? $created->addMinutes(15) : null,
                'created_at' => $created,
                'updated_at' => $created->addMinutes(15),
            ]);
        }
    }

    private function seedSales(): void
    {
        DB::table('sales_leads')->where('code', 'like', 'LEAD-'.self::PREFIX.'-%')->delete();
        $leads = [
            ['Agung Firmansyah', 'calon', null], ['Bella Oktaviani', 'calon', null], ['Candra Wijaya', 'terpasang', null],
            ['Desi Ratnasari', 'terpasang', null], ['Erwin Saputra', 'batal', 'price'], ['Fani Rahmadani', 'calon', null],
            ['Gilang Ramadhan', 'batal', 'coverage'], ['Hana Safitri', 'calon', null], ['Ilham Akbar', 'terpasang', null],
        ];
        $reasonCodes = array_keys((array) (defined(SalesLead::class.'::REASONS') ? SalesLead::REASONS : []));
        $areaCodes = array_keys($this->areas);
        foreach ($leads as $i => [$name, $status, $reason]) {
            $area = $areaCodes[$i % count($areaCodes)];
            $this->insert('sales_leads', [
                'code' => 'LEAD-'.self::PREFIX.'-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'name' => $name,
                'phone' => $this->phone(500 + $i),
                'address' => 'Jl. Contoh No. '.($i + 3),
                'area_id' => $this->areas[$area],
                'daerah' => $this->areaNames[$area],
                'rt' => '0'.($i % 7 + 1), 'rw' => '0'.($i % 3 + 1),
                'odp_name' => 'ODP-'.$area.'-0'.($i % 4 + 1),
                'source' => $i % 2 ? 'referral' : 'manual',
                'status' => $status,
                'reason_code' => $status === 'batal' ? ($reasonCodes[$i % max(1, count($reasonCodes))] ?? $reason) : null,
                'reason_note' => $status === 'batal' ? 'Catatan alasan batal (contoh)' : null,
                'notes' => 'Prospek dari kunjungan door-to-door (contoh)',
                'converted_customer_id' => $status === 'terpasang' ? $this->customers[$i]['id'] : null,
                'installed_at' => $status === 'terpasang' ? $this->now->subDays($i + 1) : null,
                'created_by' => $this->users['rudi.hartono'],
                'created_at' => $this->now->subDays(12 - $i),
                'updated_at' => $this->now->subDays(max(0, 10 - $i)),
            ]);
        }
    }

    private function seedReseller(): void
    {
        $resellers = [$this->users['rudi.hartono'], $this->users['rina.kusuma']];
        $statuses = ['pending', 'approved', 'paid', 'paid', 'void', 'pending'];
        foreach (array_slice($this->customers, 0, 12) as $k => $c) {
            $invoiceId = DB::table('invoices')->where('customer_id', $c['id'])->orderByDesc('period')->value('id');
            $status = $statuses[$k % count($statuses)];
            $this->insert('reseller_commissions', [
                'reseller_id' => $resellers[$k % 2],
                'customer_id' => $c['id'],
                'invoice_id' => $invoiceId,
                'period' => $this->now->subMonths($k % 2)->format('Y-m'),
                'base_amount' => $c['price'],
                'commission_amount' => round($c['price'] * 0.1),
                'status' => $status,
                'paid_at' => $status === 'paid' ? $this->now->subDays(3) : null,
                'meta' => ['rate' => 0.1],
            ] + $this->ts());
        }
    }

    private function seedGenieAcs(): void
    {
        DB::table('genieacs_devices')->where('device_id', 'like', 'RDS-%')->delete();
        foreach (array_slice($this->customers, 0, 14) as $k => $c) {
            $serial = 'ZTEGC0DE'.str_pad((string) $c['n'], 4, '0', STR_PAD_LEFT);
            $this->insert('genieacs_devices', [
                'device_id' => 'RDS-'.$serial,
                'customer_id' => $c['id'],
                'match_source' => 'pppoe_user',
                'snapshot_status' => 'active',
                'last_seen_sync_at' => $this->now->subMinutes(10),
                'serial_number' => $serial,
                'oui' => '00AABB',
                'product_class' => $k % 3 === 0 ? 'F670L' : 'F609',
                'manufacturer' => 'ZTE',
                'model' => $k % 3 === 0 ? 'ZXHN F670L' : 'ZXHN F609',
                'software_version' => 'V9.0.11P1N2',
                'hardware_version' => 'V9.0',
                'ip_address' => '198.51.100.'.(10 + $c['n']),
                'ip_tr069' => '203.0.113.'.(10 + $c['n']),
                'mac_address' => sprintf('02:00:5E:10:%02X:%02X', intdiv($c['n'], 256), $c['n'] % 256),
                'ssid' => 'WIFI-'.strtoupper(substr($c['pppoe'], 0, 6)),
                'ssid_5g' => $k % 3 === 0 ? 'WIFI-'.strtoupper(substr($c['pppoe'], 0, 6)).'-5G' : null,
                'tags' => ['area:'.$c['area']],
                'pelanggan' => $c['name'],
                'pppoe_user' => $c['pppoe'],
                'wan_status' => $c['online'] ? 'Connected' : 'Disconnected',
                'pon_mode' => 'GPON',
                'wifi_clients' => $c['online'] ? mt_rand(1, 12) : 0,
                'uptime_seconds' => $c['online'] ? mt_rand(3600, 2000000) : null,
                'uptime_text' => $c['online'] ? mt_rand(1, 20).'d '.mt_rand(1, 23).'h' : null,
                'wlan_enabled' => true,
                'wlan_channel' => (string) mt_rand(1, 11),
                'last_inform_at' => $c['online'] ? $this->now->subMinutes(mt_rand(1, 30)) : $this->now->subDays(2),
                'registered_at' => $this->now->subMonths(3),
            ] + $this->cpeLayerColumns($k, (bool) $c['online']) + $this->ts());
        }
    }

    /**
     * RCA-3: fresh WLAN data for most online devices; every third one has only stale data (columns NULL), so
     * the screenshot shows both "Nyala/Mati + klien" and "— (data lama)".
     *
     * @return array<string,mixed>
     */
    private function cpeLayerColumns(int $k, bool $online): array
    {
        if (! Schema::hasColumn('genieacs_devices', 'wlan_values_at')) {
            return [];
        }
        $fresh = $online && $k % 3 !== 0;

        return [
            'last_boot_at' => $online ? $this->now->subHours(mt_rand(2, 400)) : null,
            'wlan24_status' => $fresh ? 'Up' : null,
            'wlan5_status' => $fresh ? ($k % 4 === 1 ? 'Disabled' : 'Up') : null,
            'wlan24_enabled' => $fresh ? 1 : null,
            'wlan5_enabled' => $fresh ? ($k % 4 === 1 ? 0 : 1) : null,
            'wlan24_assoc' => $fresh ? mt_rand(0, 6) : null,
            'wlan5_assoc' => $fresh ? mt_rand(0, 6) : null,
            'wlan_values_at' => $fresh ? $this->now->subMinutes(mt_rand(1, 50)) : null,
        ];
    }

    private function seedHotspot(): void
    {
        DB::table('hotspot_vouchers')->where('batch_code', 'like', 'RDS-%')->delete();
        $statuses = ['unused', 'unused', 'unused', 'active', 'used', 'expired', 'disabled'];
        for ($i = 0; $i < 14; $i++) {
            $status = $statuses[$i % count($statuses)];
            $this->insert('hotspot_vouchers', [
                'router_id' => $this->routers[0], 'package_id' => null, 'batch_code' => 'RDS-'.($i < 7 ? 'A' : 'B'),
                'username' => 'vc'.str_pad((string) (4100 + $i), 6, '0', STR_PAD_LEFT), 'password' => (string) (8800 + $i),
                'profile' => 'hotspot-1hari', 'rate_limit' => '5M/5M', 'price' => 5000, 'duration_minutes' => 1440,
                'status' => $status, 'activated_at' => in_array($status, ['active', 'used', 'expired'], true) ? $this->now->subHours(20) : null,
                'expires_at' => in_array($status, ['active', 'used', 'expired'], true) ? $this->now->addHours(4) : null,
            ] + $this->ts());
        }
    }

    private function seedTechnicianProgress(): void
    {
        $scheduleId = $this->insert('technician_daily_schedules', [
            'schedule_date' => $this->now->toDateString(), 'status' => 'approved', 'approved_at' => $this->now->setTime(7, 0),
        ] + $this->ts());
        $tasks = [
            [1, 'Tim 1', ['Bayu', 'Eko'], 'Sukamaju', 'psb', 'Pasang baru 3 pelanggan'],
            [2, 'Tim 2', ['Hendra'], 'Mekarsari', 'incident', 'Perbaikan redaman ODP-MKS-03'],
            [3, 'Tim 3', ['Yusuf'], 'Cempaka Baru', 'dismantle', 'Ambil perangkat 2 pelanggan'],
        ];
        foreach ($tasks as [$no, $label, $techs, $site, $type, $task]) {
            $this->insert('technician_daily_tasks', [
                'schedule_id' => $scheduleId, 'work_date' => $this->now->toDateString(), 'team_no' => $no, 'team_label' => $label,
                'technicians' => $techs, 'site' => $site, 'task_type' => $type, 'task' => $task, 'progress' => $no === 1 ? '2/3 selesai' : null,
                'status' => $no === 1 ? 'in_progress' : 'planned', 'priority' => 'normal', 'source' => 'manual_wa',
            ] + $this->ts());
        }
    }

    private function writeIds(): void
    {
        $invoiceUuid = DB::table('invoices')->where('id', $this->ids['invoice_unpaid'] ?? 0)->value('uuid');
        $payload = [
            'customer' => $this->ids['customer'] ?? null,
            'customer_isolated' => $this->ids['customer_isolated'] ?? null,
            'invoice' => $this->ids['invoice_unpaid'] ?? null,
            'invoice_paid' => $this->ids['invoice_paid'] ?? null,
            'invoice_overdue' => $this->ids['invoice_overdue'] ?? null,
            'invoice_void' => $this->ids['invoice_void'] ?? null,
            'invoice_uuid' => $invoiceUuid,
            'invoice_paid_uuid' => DB::table('invoices')->where('id', $this->ids['invoice_paid'] ?? 0)->value('uuid'),
            'invoice_void_uuid' => DB::table('invoices')->where('id', $this->ids['invoice_void'] ?? 0)->value('uuid'),
            'ticket' => $this->ids['ticket'] ?? null,
            'ticket_dismantle' => $this->ids['ticket_dismantle'] ?? null,
            'recovery_case' => $this->ids['recovery_case'] ?? null,
            'recovery_case_received' => $this->ids['recovery_case_received'] ?? null,
            'broadcast' => $this->ids['broadcast'] ?? null,
            'router' => $this->routers[0] ?? null,
            'olt' => $this->olts[0] ?? null,
            'area' => $this->areas['SKM'] ?? null,
            'package' => $this->packages['RDS-20M'] ?? null,
            'user' => $this->users['rudi.hartono'] ?? null,
        ];

        $target = (string) (env('REDESIGN_SHOTS_IDS_FILE') ?: storage_path('app/redesign-screenshots-ids.json'));
        File::ensureDirectoryExists(dirname($target));
        File::put($target, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->command?->info('Redesign screenshot ids written to '.$target);
    }
}
