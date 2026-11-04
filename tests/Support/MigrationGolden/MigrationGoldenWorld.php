<?php

namespace Tests\Support\MigrationGolden;

use App\Models\Customer;
use App\Models\CustomerMigration;
use App\Models\CustomerMigrationImportRow;
use App\Models\DeviceActionLog;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OltVlanMapping;
use App\Models\Package;
use App\Models\PppoeAddressPoolBlock;
use App\Models\PsbDraft;
use App\Models\Router;
use App\Models\RouterProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\GuidedMigrationLookupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;

/**
 * Fake world of the migration flow goldens (migration refactor M0): routers, OLTs, packages,
 * customers with old ONUs, settings and payload builders. Every name, phone, SN and PPPoE
 * user is invented; the router login values are built at run time.
 */
trait MigrationGoldenWorld
{
    protected const GROUP = '120363000000000101@g.us';

    protected const OTHER_GROUP = '120363000000000199@g.us';

    protected const TECH = '6281200000101@s.whatsapp.net';

    protected const NOC = '6281200000102@s.whatsapp.net';

    protected const STRANGER = '6281200000199@s.whatsapp.net';

    protected const OTHER_SENDER = '6281200000198@s.whatsapp.net';

    /** The technician's WhatsApp "lid" alias (a second id of the same person). */
    protected const TECH_LID = '210000000000101@lid';

    protected const KRIAN_OLD = 15;

    protected const KRIAN_NEW = 514;

    protected const RA_ROUTER = 513;

    protected const GADANG_ROUTER = 511;

    protected const OLT_KRIAN = 9;

    protected const OLT_KRIAN_OLD = 10;

    protected const OLT_RA = 1;

    protected const OLT_GADANG = 11;

    /** @var array<string, int> label => customer id */
    protected array $people = [];

    protected function seedWorld(): void
    {
        $this->burnIds();
        $secretValue = $this->canaryValue();

        foreach ([
            GuidedMigrationLookupService::FEATURE_FLAG => '1',
            GuidedMigrationLookupService::ALLOWED_GROUP_JIDS => self::GROUP,
            GuidedMigrationLookupService::ALLOWED_SENDER_JIDS => self::NOC,
            'migration_wa_only_live_enabled' => '1',
            'migration_device_actions_enabled' => '1',
            'control_plane.device_read_only_mode' => '0',
            'control_plane.olt_writes_enabled' => '1',
            'control_plane.genieacs_writes_enabled' => '1',
            'control_plane.mikrotik_writes_enabled' => '1',
            'psb_wa_group_ids' => [self::GROUP],
            'psb_acs_url' => 'http://acs.test:7547',
            'genieacs_acs_url' => 'http://acs.test',
        ] as $key => $value) {
            Setting::set($key, $value);
        }

        $routers = [
            [self::KRIAN_OLD, 'Krian', '198.19.40.15'],
            [self::KRIAN_NEW, 'Skynet-Krian-OLT', '198.19.40.14'],
            [self::RA_ROUTER, 'SKYNET-RA-OLT', '198.19.40.13'],
            [self::GADANG_ROUTER, 'SKYNET-GADANG', '198.19.40.16'],
        ];
        foreach ($routers as [$id, $name, $ip]) {
            $router = new Router(['name' => $name, 'ip_address' => $ip, 'is_active' => true]);
            $router->username = implode('-', ['mt', 'api']);
            $router->password = $secretValue;
            $router->id = $id;
            $router->save();
        }

        foreach ([
            [self::OLT_RA, 'OLT RA', 'OLT-1', 'zte_c300', '198.19.41.2', self::RA_ROUTER],
            [self::OLT_KRIAN, 'OLT KRIAN', 'OLT-KRIAN', 'zte_c300', '198.19.41.9', self::KRIAN_NEW],
            [self::OLT_KRIAN_OLD, 'OLT KRIAN HTB', 'OLT-KRIAN-HTB', 'zte_c300', '198.19.41.10', self::KRIAN_OLD],
            [self::OLT_GADANG, 'OLT GADANG 3', 'OLT-GADANG-3', 'huawei_ma5608t', '198.19.41.11', self::GADANG_ROUTER],
        ] as [$id, $name, $code, $vendor, $ip, $routerId]) {
            $olt = new Olt(['name' => $name, 'code' => $code, 'vendor' => $vendor, 'management_ip' => $ip, 'router_id' => $routerId, 'area_id' => null]);
            $olt->id = $id;
            $olt->save();
        }
        OltVlanMapping::create(['olt_id' => self::OLT_KRIAN, 'pon_port' => '1/2/2', 'service_type' => 'internet', 'vlan' => 200, 'is_active' => true]);
        OltVlanMapping::create(['olt_id' => self::OLT_RA, 'pon_port' => '1/1/1', 'service_type' => 'internet', 'vlan' => 100, 'is_active' => true]);

        foreach ([[self::KRIAN_OLD, 'KRIAN-10', '10MB'], [self::KRIAN_NEW, 'KRIAN-10', '10MB'], [self::RA_ROUTER, 'RA-200M', '200M'], [self::GADANG_ROUTER, 'GADANG-200M', 'GADANG-200M']] as [$routerId, $code, $profile]) {
            Package::create([
                'router_id' => $routerId, 'code' => $routerId === self::KRIAN_NEW ? 'KRIAN-10-514' : $code, 'name' => $code, 'mikrotik_profile' => $profile,
                'rate_limit' => $profile.'/'.$profile, 'price' => 150000, 'is_active' => true,
            ]);
            RouterProfile::create(['router_id' => $routerId, 'name' => $profile, 'rate_limit' => $profile.'/'.$profile]);
        }
        $this->mt->sourceRouterIds = [self::KRIAN_OLD];
        $this->mt->profiles[self::KRIAN_OLD] = ['10MB'];
        $this->mt->profiles[self::KRIAN_NEW] = ['10MB', '20MB'];
        $this->mt->profiles[self::RA_ROUTER] = ['200M'];
        $this->mt->profiles[self::GADANG_ROUTER] = ['GADANG-200M'];

        PppoeAddressPoolBlock::create([
            'router_id' => self::KRIAN_NEW, 'block_name' => 'block-1', 'block_index' => 1, 'address_format' => 'full_ipv4',
            'prefix_template' => '198.19.44', 'local_address' => '198.19.44.1', 'remote_start' => '198.19.44.2',
            'remote_end' => '198.19.44.254', 'next_block_index' => null, 'allocator_mode' => 'last_remote_pointer',
            'priority' => 1, 'is_active' => true, 'warning_threshold' => 10,
        ]);

        // Six customers on the old Krian router, each with an old ONU on OLT 10.
        foreach ([
            'budi' => ['DRAFTE SANTOSO', 'USERBUDI_RT01_RW02', '081200000011', 'ZTEGA0000011', '1/1/1:5'],
            'siti' => ['USERSI AMINAH', 'USERSITI_RT01_RW02', '081200000012', 'ZTEGA0000012', '1/1/1:6'],
            'dewi' => ['DRAFTG LESTARI', 'USERDEWI_RT01_RW02', '081200000013', 'ZTEGA0000013', '1/1/1:7'],
            'agus' => ['DRAFTA PRASETYO', 'USERAGUS_RT01_RW02', '081200000014', 'ZTEGA0000014', '1/1/1:8'],
            'andi' => ['DRAFTC WIJAYA', 'USERANDI_RT01_RW02', '081200000015', 'ZTEGA0000015', '1/1/1:9'],
            'toni' => ['USERTO HARTONO', 'USERTONI_RT01_RW02', '081200000016', 'ZTEGA0000016', '1/1/1:10'],
        ] as $label => [$name, $user, $phone, $oldSn, $oldRef]) {
            $customer = Customer::create([
                'code' => 'KRN-'.strtoupper($label),
                'name' => $name,
                'phone' => $phone,
                'address' => 'RT 1 RW 2 Krian',
                'package_id' => (int) Package::query()->where('code', 'KRIAN-10')->value('id'),
                'pppoe_user' => $user,
                'mikrotik_profile' => '10MB',
                'onu_serial' => $oldSn,
                'olt_port_label' => $oldRef,
                'odp_name' => 'ODP-KRN-01',
                'ktp_photo_url' => 'customers/ktp/'.$label.'.jpg',
                'router_id' => self::KRIAN_OLD,
                'olt_id' => self::OLT_KRIAN_OLD,
                'status' => 'active',
            ]);
            $this->people[$label] = (int) $customer->id;
            OltOnu::create([
                'olt_id' => self::OLT_KRIAN_OLD, 'onu_ref' => $oldRef, 'pon_port' => preg_replace('/:\d+$/', '', $oldRef),
                'serial_number' => $oldSn, 'onu_name' => $user, 'state' => 'online', 'phase_state' => 'working',
            ]);
            $this->fakeRouterRow($customer, self::KRIAN_OLD);
        }
    }

    /** The shared fake router value, built from pieces so no literal pair exists in the source. */
    protected function canaryValue(): string
    {
        return implode('-', ['canary', 'pw']);
    }

    /** Puts the customer's PPP row on a router (the user comes from the model). */
    protected function fakeRouterRow(Customer $customer, int $routerId, string $profile = '10MB', bool $disabled = false): void
    {
        $this->mt->putRow($routerId, (string) $customer->pppoe_user, $this->canaryValue(), $profile, '198.19.44.1', '198.19.44.'.(10 + count($this->mt->tables[$routerId] ?? [])), $disabled);
    }

    protected function person(string $label): Customer
    {
        return Customer::query()->findOrFail($this->people[$label]);
    }

    /** Shift auto-increment ids so a golden that depends on a row id fails here and not only in another test order. */
    protected function burnIds(): void
    {
        for ($i = random_int(3, 25); $i > 0; $i--) {
            $migration = CustomerMigration::create(['status' => CustomerMigration::STATUS_CANCELLED, 'message_id' => 'BURN-M-'.$i]);
            $migration->delete();
            $package = Package::create(['code' => 'BURN-'.$i, 'name' => 'BURN', 'price' => 1, 'mikrotik_profile' => 'burn']);
            $customer = Customer::create([
                'code' => 'BURN-'.$i, 'name' => 'BURN', 'phone' => '081200000000', 'address' => '-', 'pppoe_user' => 'burn'.$i,
                'status' => 'active', 'join_date' => now(), 'due_day' => 1, 'package_id' => $package->id,
            ]);
            DB::table('customers')->where('id', $customer->id)->delete();
            DB::table('packages')->where('id', $package->id)->delete();
        }
        for ($i = random_int(30, 55); $i > 0; $i--) {
            $draft = PsbDraft::create(['status' => PsbDraft::STATUS_CANCELLED, 'message_id' => 'BURN-P-'.$i]);
            $draft->delete();
        }
        for ($i = random_int(3, 20); $i > 0; $i--) {
            $row = CustomerMigrationImportRow::create(['batch_key' => 'burn', 'row_number' => $i, 'status' => 'staged']);
            $row->delete();
        }
        Schema::disableForeignKeyConstraints();
        for ($i = random_int(3, 20); $i > 0; $i--) {
            $onu = OltOnu::create(['olt_id' => 1, 'onu_ref' => '9/9/9:'.$i, 'serial_number' => 'BURN'.$i]);
            $onu->delete();
        }
        Schema::enableForeignKeyConstraints();
        for ($i = random_int(2, 9); $i > 0; $i--) {
            $log = DeviceActionLog::create(['domain' => 'burn', 'target_type' => 'burn', 'action' => 'burn', 'status' => 'cancelled', 'payload_hash' => 'burn', 'idempotency_key' => 'burn-'.$i.'-'.random_int(1, 99999)]);
            $log->delete();
        }
        for ($i = random_int(1, 6); $i > 0; $i--) {
            $user = User::factory()->create(['role' => 'admin']);
            $user->delete();
        }
    }

    // ───────────────────────── scenario helpers ─────────────────────────

    protected ?User $adminUser = null;

    protected function admin(): User
    {
        return $this->adminUser ??= User::factory()->create(['role' => 'admin']);
    }

    /** One WA command as the technician (or another sender/group) through the real job. */
    protected function say(string $label, string $text, ?string $sender = null, ?string $group = null): void
    {
        $this->inbound($label, $this->commandPayload($text, $sender, $group));
    }

    /** Real id of the newest migration row. */
    protected function lastMigrationId(): int
    {
        $this->indexRows();

        return $this->migrationId(count($this->migrationOrdinals));
    }

    /** A web POST of the admin (retry, cancel, device-action prepare/execute) with the flash result recorded. */
    protected function adminPost(string $label, string $routeName, int $migrationId, array $data = []): void
    {
        $this->step($label, 'http:'.$routeName, function () use ($routeName, $migrationId, $data): void {
            $migration = CustomerMigration::query()->findOrFail($migrationId);
            $response = $this->actingAs($this->admin())->post(route($routeName, $migration), $data);
            $errors = session('errors');
            $this->rec->add('http.response', [
                'route' => $routeName,
                'status' => $response->getStatusCode(),
                'success' => session('success'),
                'error' => session('error'),
                'errors' => $errors instanceof ViewErrorBag ? $errors->getBag('default')->all() : [],
            ]);
        });
    }

    /** A guided draft of one of the six customers: intake text for a Krian 15 to 514 move. */
    protected function guidedIntake(string $label, string $person, string $newSn, array $extra = []): int
    {
        $customer = $this->person($person);
        $this->say($label, $this->intakeText((string) $customer->name, (string) $customer->pppoe_user, $newSn, $extra));

        return $this->lastMigrationId();
    }

    /** Writes a synthetic JPEG to storage; returns its storage path. */
    protected function storeImage(string $name, int $width, int $height, int $seed, string $ocrText): string
    {
        $img = imagecreatetruecolor($width, $height);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, imagecolorallocate($img, ($seed * 37) % 256, ($seed * 91) % 256, ($seed * 53) % 256));
        imagestring($img, 4, 12, 12, $name.' '.$seed, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 80);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        $this->images[sha1($bytes)] = ['name' => $name, 'text' => $ocrText];
        $path = 'migrasi/inbox/'.$name.'-'.$this->nextMessageId().'.jpg';
        Storage::disk('local')->put($path, $bytes);
        $this->storedNames[$path] = $name;

        return $path;
    }

    /**
     * Three customers with the same name on the old Krian router (M4 guided goldens): a name-only message
     * finds all three with the same score, so the technician gets a list and picks a number.
     */
    protected function seedRinaCandidates(): void
    {
        foreach ([
            'rina1' => ['USERRINA_RT01_RW02', '081200000021', 'ZTEGA0000021', '1/1/1:11', 'RT 1 RW 2 Krian'],
            'rina2' => ['USERRINA_RT03_RW02', '081200000022', 'ZTEGA0000022', '1/1/1:12', 'RT 3 RW 2 Krian'],
            'rina3' => ['USERRINA_RT05_RW02', '081200000023', 'ZTEGA0000023', '1/1/1:13', 'RT 5 RW 2 Krian'],
        ] as $label => [$user, $phone, $oldSn, $oldRef, $address]) {
            $customer = Customer::create([
                'code' => 'KRN-'.strtoupper($label), 'name' => 'DRAFTM ASTUTI', 'phone' => $phone, 'address' => $address,
                'package_id' => (int) Package::query()->where('code', 'KRIAN-10')->value('id'),
                'pppoe_user' => $user, 'mikrotik_profile' => '10MB', 'onu_serial' => $oldSn, 'olt_port_label' => $oldRef,
                'odp_name' => 'ODP-KRN-01', 'ktp_photo_url' => 'customers/ktp/'.$label.'.jpg',
                'router_id' => self::KRIAN_OLD, 'olt_id' => self::OLT_KRIAN_OLD, 'status' => 'active',
            ]);
            $this->people[$label] = (int) $customer->id;
            OltOnu::create([
                'olt_id' => self::OLT_KRIAN_OLD, 'onu_ref' => $oldRef, 'pon_port' => preg_replace('/:\d+$/', '', $oldRef),
                'serial_number' => $oldSn, 'onu_name' => $user, 'state' => 'online', 'phase_state' => 'working',
            ]);
            $this->fakeRouterRow($customer, self::KRIAN_OLD);
        }
    }

    /** The name-only intake of the three same-name customers (the phone matches nobody, so no one wins). */
    protected function rinaIntakeText(string $newSn): string
    {
        return $this->intakeText('DRAFTM ASTUTI', '', $newSn, ['PPPoE' => null, 'HP' => '081200000099']);
    }

    /**
     * A command whose sender arrives under WhatsApp aliases (phone number id, "lid" id, group participant).
     *
     * @return array<string, mixed>
     */
    protected function aliasPayload(string $text, ?string $pn, ?string $lid, ?string $participant = null, ?string $group = null): array
    {
        $payload = $this->commandPayload($text, null, $group);
        $payload['sender_jid'] = (string) ($lid ?? $pn ?? $participant ?? '');
        $payload['sender_jid_pn'] = $pn;
        $payload['sender_jid_lid'] = $lid;
        $payload['participant'] = $participant;

        return $payload;
    }

    /** @return string the bytes of a stored synthetic image */
    protected function imageBytes(string $path): string
    {
        return (string) Storage::disk('local')->get($path);
    }

    // ───────────────────────── payload builders ─────────────────────────

    /** @return array<string, mixed> */
    protected function commandPayload(string $text, ?string $sender = null, ?string $group = null): array
    {
        return [
            'group_jid' => $group ?? self::GROUP,
            'sender_jid' => $sender ?? self::TECH,
            'sender_jid_pn' => $sender ?? self::TECH,
            'sender_name' => 'Teknisi Satu',
            'message_id' => $this->nextMessageId(),
            'text' => $text,
            'image_paths' => [],
            'media_contexts' => [],
        ];
    }

    /** @return array<string, mixed> same as a command but with images stored on the local disk */
    protected function imagePayload(string $text, array $imagePaths, ?string $sender = null): array
    {
        return ['image_paths' => $imagePaths] + $this->commandPayload($text, $sender);
    }

    protected function intakeText(string $name, string $pppoe, string $newSn, array $extra = []): string
    {
        $fields = array_merge([
            'Nama' => $name,
            'PPPoE' => $pppoe,
            'SN Baru' => $newSn,
            'HP' => null,
            'Router lama' => 'Krian',
            'Router baru' => 'Skynet-Krian-OLT',
            'OLT' => 'KRIAN',
        ], $extra);
        $lines = ['#migrasi'];
        foreach ($fields as $label => $value) {
            if ($value !== null) {
                $lines[] = $label.': '.$value;
            }
        }

        return implode("\n", $lines);
    }
}
