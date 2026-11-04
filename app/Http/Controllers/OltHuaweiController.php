<?php

namespace App\Http\Controllers;

use App\Exceptions\HuaweiOltCommandException;
use App\Exceptions\HuaweiOltSessionBusyException;
use App\Models\Olt;
use App\Services\HuaweiOltOnlyProvisioningService;
use App\Services\Olt\OltProvisioningGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * UI entry point for Gadang Huawei MA5608T provisioning, wired into the same
 * Aktivasi page as the FiberHome/C300 flow. Read-only detect + gated write.
 *
 * The PSB/migration WA bots remain the primary path; this lets NOC provision
 * from the panel when needed. OLT-only: MikroTik secret/customer creation is
 * handled by the PSB path.
 */
class OltHuaweiController extends Controller
{
    public function __construct(private HuaweiOltOnlyProvisioningService $huawei) {}

    private const AUTOFIND_CACHE_SECONDS = 30;

    private const AUTOFIND_REFRESH_FLOOR_SECONDS = 10;

    /**
     * Read-only list of ONTs waiting in `display ont autofind all` for the UI
     * button "Lihat ONT baru". Cached 30 s per OLT, one real read at a time,
     * never queues behind PSB (the UI session does not wait for the SSH lock).
     */
    public function autofind(Request $request)
    {
        $data = $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'refresh' => 'nullable|boolean',
        ]);

        $olt = Olt::findOrFail($data['olt_id']);
        if (! $this->isHuawei($olt)) {
            return response()->json(['success' => false, 'error' => 'OLT bukan Huawei MA5608T.'], 422);
        }

        $oltId = (int) $olt->id;
        $cacheKey = "huawei-autofind-ui:{$oltId}";
        $refresh = (bool) ($data['refresh'] ?? false);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $age = now()->getTimestamp() - (int) $cached['fetched_at'];
            if (! $refresh || $age < self::AUTOFIND_REFRESH_FLOOR_SECONDS) {
                return $this->autofindResponse($oltId, $cached, true);
            }
        }

        $fetchLock = Cache::lock("huawei-autofind-ui-fetch:{$oltId}", 25);
        if (! $fetchLock->get()) {
            if (is_array($cached)) {
                return $this->autofindResponse($oltId, $cached, true);
            }

            return response()->json(['success' => false, 'error' => 'Daftar sedang dimuat. Coba lagi 5 detik.'], 429);
        }

        $started = microtime(true);
        try {
            $result = $this->huawei->listAutofindForUi($olt);
            $payload = [
                'entries' => $result['entries'],
                'partial' => $result['partial'],
                'fetched_at' => now()->getTimestamp(),
            ];
            Cache::put($cacheKey, $payload, self::AUTOFIND_CACHE_SECONDS);
        } catch (HuaweiOltSessionBusyException) {
            return response()->json(['success' => false, 'busy' => true, 'error' => 'OLT sedang dipakai. Coba lagi 1 menit.'], 423);
        } catch (HuaweiOltCommandException) {
            return response()->json(['success' => false, 'error' => 'OLT tidak menjawab. Coba lagi.'], 504);
        } catch (RuntimeException $e) {
            if (preg_match('/deadline exceeded|cannot connect|timed out|timeout/i', $e->getMessage()) === 1) {
                return response()->json(['success' => false, 'error' => 'OLT tidak menjawab. Coba lagi.'], 504);
            }
            $known = $e->getMessage() === 'OLT menolak perintah daftar ONT.';

            return response()->json([
                'success' => false,
                'error' => $known ? $e->getMessage() : 'OLT tidak bisa dibaca. Periksa pengaturan OLT.',
            ], 422);
        } finally {
            try {
                $fetchLock->release();
            } catch (\Throwable) {
                // Lock cleanup must not replace the response.
            }
        }

        Log::info('huawei.autofind_ui', [
            'olt_id' => $oltId,
            'user_id' => $request->user()?->getKey(),
            'count' => count($payload['entries']),
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'cached' => false,
        ]);

        return $this->autofindResponse($oltId, $payload, false);
    }

    /** @param array{entries: list<array<string, ?string>>, partial: bool, fetched_at: int} $payload */
    private function autofindResponse(int $oltId, array $payload, bool $cached)
    {
        return response()->json([
            'success' => true,
            'olt_id' => $oltId,
            'count' => count($payload['entries']),
            'entries' => $payload['entries'],
            'partial' => (bool) $payload['partial'],
            'fetched_at' => date('c', (int) $payload['fetched_at']),
            'age_seconds' => max(0, now()->getTimestamp() - (int) $payload['fetched_at']),
            'cached' => $cached,
        ]);
    }

    public function detect(Request $request)
    {
        $data = $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
            'pon_port' => 'nullable|string|max:16',
        ]);

        $olt = Olt::findOrFail($data['olt_id']);
        if (! $this->isHuawei($olt)) {
            return response()->json(['success' => false, 'error' => 'OLT bukan Huawei MA5608T.'], 422);
        }

        $sn = $this->huawei->canonicalSerial($data['sn']);
        if ($sn === '' || preg_match('/^[A-Z0-9-]{8,64}$/', $sn) !== 1) {
            return response()->json(['success' => false, 'error' => 'SN tidak valid.'], 422);
        }

        try {
            $placement = $this->resolvePlacement($olt, $sn, $request->input('pon_port'));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'olt_id' => (int) $olt->id,
            'sn' => $sn,
            'pon_port' => $placement['pon_port'],
            'onu_id' => $placement['onu_id'],
            'onu_ref' => $placement['onu_ref'],
            'vendor_id' => $placement['vendor_id'],
            'vlan' => $placement['vlan'],
            'tr069_vlan' => $placement['tr069_vlan'],
            'line_profile' => $placement['line_profile'],
            'already_registered' => $placement['already_registered'],
            'source' => $placement['source'],
        ]);
    }

    public function provision(Request $request)
    {
        $data = $request->validate([
            'olt_id' => 'required|integer|exists:olts,id',
            'sn' => 'required|string|max:64',
            'name' => 'required|string|max:64',
            'pon_port' => 'nullable|string|max:16',
            'onu_id' => 'nullable|integer|min:0|max:127',
            'dry_run' => 'nullable|boolean',
        ]);

        $olt = Olt::findOrFail($data['olt_id']);
        if (! $this->isHuawei($olt)) {
            return response()->json(['success' => false, 'error' => 'OLT bukan Huawei MA5608T.'], 422);
        }

        $sn = $this->huawei->canonicalSerial($data['sn']);
        $pppoe = trim((string) $data['name']);
        $dryRun = (bool) $request->input('dry_run', false);

        if ($sn === '' || preg_match('/^[A-Z0-9-]{8,64}$/', $sn) !== 1) {
            return response()->json(['success' => false, 'error' => 'SN tidak valid.'], 422);
        }
        if ($pppoe === '' || preg_match('/^[A-Za-z0-9._-]+$/', $pppoe) !== 1) {
            return response()->json(['success' => false, 'error' => 'Nama/PPPoE tidak valid (A-Z 0-9 . _ -).'], 422);
        }

        try {
            $placement = $this->resolvePlacement($olt, $sn, $request->input('pon_port'), $request->input('onu_id'));
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        }

        $plan = $this->huawei->buildPlan(
            $sn,
            $pppoe,
            $placement['pon_port'],
            (int) $placement['onu_id'],
            $placement['vendor_id'],
            $placement['vlan'],
            $placement['tr069_vlan'],
            (bool) $placement['already_registered'],
        );

        if ($dryRun) {
            return response()->json([
                'success' => true,
                'dry_run' => true,
                'sn' => $sn,
                'name' => $pppoe,
                'pon_port' => $placement['pon_port'],
                'onu_id' => $placement['onu_id'],
                'onu_ref' => $placement['onu_ref'],
                'vlan' => $placement['vlan'],
                'tr069_vlan' => $placement['tr069_vlan'],
                'line_profile' => $placement['line_profile'],
                'already_registered' => $placement['already_registered'],
                'plan' => $plan,
                'note' => 'Dry run — tidak ada write ke OLT. PON/ONT-ID berasal dari autofind/read-back.',
            ]);
        }

        $approval = app(OltProvisioningGuard::class)->issueInternalApproval(
            $olt,
            'olt-huawei-ui',
            'operator-'.($request->user()?->getKey() ?? 'unknown'),
        );

        try {
            $result = $this->huawei->executePlan($olt, $plan, $approval);
        } catch (HuaweiOltSessionBusyException $e) {
            // First connect only: nothing was sent to the OLT. Same 422 + message
            // contract as the other operator-facing errors of this controller.
            return response()->json(['success' => false, 'busy' => true, 'error' => $e->userMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'success' => (bool) ($result['verified'] ?? false),
            'dry_run' => false,
            'device_write' => (bool) ($result['device_write'] ?? false),
            'sn' => $sn,
            'name' => $pppoe,
            'pon_port' => $placement['pon_port'],
            'onu_id' => $placement['onu_id'],
            'onu_ref' => $placement['onu_ref'],
            'vlan' => $placement['vlan'],
            'tr069_vlan' => $placement['tr069_vlan'],
            'tr069_pending' => (bool) ($result['tr069_pending'] ?? false),
            'result' => $result,
        ]);
    }

    /**
     * @return array{pon_port: string, onu_id: int, onu_ref: string, vendor_id: ?string, vlan: int, tr069_vlan: int, line_profile: int, already_registered: bool, source: string}
     */
    protected function resolvePlacement(Olt $olt, string $sn, mixed $ponHint, mixed $onuHint = null): array
    {
        // One short SSH session: full autofind text (no 5000-char cut) and, when
        // the SN is found, the port table of its PON (empty PON allowed).
        $read = $this->huawei->uiPlacementRead($olt, $sn);
        $matches = $read['matches'];

        $alreadyRegistered = false;
        $source = 'autofind';

        if ($matches === []) {
            // Resume path: the ONT may already be registered (no longer in
            // autofind). Fall back to the registered ONU snapshot.
            $snapshot = $this->huawei->collectSnapshot($olt);
            $registered = array_values(array_filter(
                (array) ($snapshot['onus'] ?? []),
                fn (array $onu): bool => $this->huawei->canonicalSerial((string) ($onu['serial_number'] ?? '')) === $this->huawei->canonicalSerial($sn),
            ));
            if (count($registered) > 1) {
                throw new RuntimeException('SN terdaftar lebih dari satu kali di OLT.');
            }
            if ($registered === []) {
                throw new RuntimeException('SN belum terdeteksi di autofind. Pastikan ONT menyala lalu coba lagi.');
            }
            $onu = $registered[0];
            $pon = $this->huawei->normalizePonPort((string) ($onu['pon_port'] ?? '')) ?? '';
            $onuId = $this->onuIdFromRef((string) ($onu['onu_ref'] ?? ''));
            if ($pon === '' || $onuId === null) {
                throw new RuntimeException('Referensi ONU terdaftar tidak lengkap.');
            }
            $vendorId = (string) ($onu['vendor_id'] ?? '') ?: null;
            $alreadyRegistered = true;
            $source = 'registered_olt_snapshot';
        } elseif (count($matches) > 1) {
            throw new RuntimeException('SN terdeteksi di lebih dari satu PON; periksa OLT manual.');
        } else {
            $pon = $this->huawei->normalizePonPort((string) ($matches[0]['pon_port'] ?? '')) ?? '';
            $vendorId = (string) ($matches[0]['vendor_id'] ?? '') ?: null;
            if ($pon === '' || $read['port'] === null) {
                throw new RuntimeException('PON dari autofind tidak valid.');
            }
            $onuId = $this->huawei->nextOnuId((array) $read['port']['onus'], $pon);
        }

        if ($ponHint !== null && trim((string) $ponHint) !== '') {
            $hint = $this->huawei->normalizePonPort((string) $ponHint);
            if ($hint !== null && $hint !== $pon) {
                throw new RuntimeException("PON {$hint} tidak cocok dengan hasil autofind {$pon}.");
            }
        }
        if ($onuHint !== null && $onuHint !== '' && $onuHint !== null && ! $alreadyRegistered) {
            $requested = (int) $onuHint;
            if ($requested >= 0 && $requested <= 127) {
                $onuId = $requested;
            }
        }

        $profile = $this->huawei->profileFor($pon, $sn, $vendorId);

        return [
            'pon_port' => $pon,
            'onu_id' => (int) $onuId,
            'onu_ref' => "{$pon}:{$onuId}",
            'vendor_id' => $vendorId,
            'vlan' => (int) $profile['vlan'],
            'tr069_vlan' => (int) $profile['tr069_vlan'],
            'line_profile' => (int) $profile['line_profile'],
            'already_registered' => $alreadyRegistered,
            'source' => $source,
        ];
    }

    protected function isHuawei(Olt $olt): bool
    {
        $vendor = strtolower((string) $olt->vendor);

        return str_contains($vendor, 'huawei') || str_contains($vendor, 'ma5608');
    }

    protected function onuIdFromRef(string $value): ?int
    {
        return preg_match('/:\s*(\d{1,3})\s*$/', trim($value), $m) === 1 ? (int) $m[1] : null;
    }
}
