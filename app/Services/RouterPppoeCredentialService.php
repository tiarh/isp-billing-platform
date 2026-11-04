<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Read-only PPPoE credential audit and approval-gated reconciliation support.
 *
 * Plaintext router passwords intentionally live only in the local call stack
 * while a MikroTik snapshot is being classified.  This service never returns
 * them, logs them, persists them, or places them in a queued payload.
 */
class RouterPppoeCredentialService
{
    public const ALLOWLIST = [
        'randuagung',
        'gadang',
        'skynet-ra-olt',
        'krian',
    ];

    /**
     * Explicit selector aliases only. The target is an exact canonical router
     * name; it is never resolved by fuzzy matching.
     *
     * @var array<string, string>
     */
    public const SELECTOR_ALIASES = [
        'gadang' => 'skynet-gadang',
    ];

    public function __construct(private readonly MikrotikService $mikrotik) {}

    /**
     * @param  list<string>  $selectors
     * @return array<string, mixed>
     */
    public function audit(?array $selectors = null, ?string $runId = null, bool $includeRows = true): array
    {
        $runId ??= 'pppoe-password-'.now('Asia/Jakarta')->format('YmdHis').'-'.Str::lower(Str::random(8));
        $requested = $this->normalizeSelectors($selectors);
        $activeRouters = Router::query()->where('is_active', true)->orderBy('id')->get();

        $bySelector = [];
        foreach ($activeRouters as $router) {
            $key = $this->routerKey((string) $router->name);
            if ($key !== '') {
                $bySelector[$key][] = $router;
            }
        }

        $targets = [];
        foreach ($requested as $selector) {
            $matches = $this->matchesForSelector($selector, $bySelector);
            if ($matches === []) {
                $targets[] = $this->notFoundTarget($selector);

                continue;
            }
            if (count($matches) !== 1) {
                $targets[] = [
                    'selector' => $selector,
                    'status' => 'BLOCKED_ROUTER_SELECTOR_CONFLICT',
                    'router_id' => null,
                    'router_name' => null,
                    'reason' => 'Lebih dari satu router aktif memiliki nama selector yang sama setelah normalisasi.',
                    'counts' => $this->emptyCounts(),
                    'rows' => [],
                ];

                continue;
            }

            $targets[] = $this->auditTarget($matches[0], $selector, $includeRows);
        }

        $selectedIds = collect($targets)
            ->pluck('router_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->all();
        $outOfScope = $activeRouters
            ->reject(fn (Router $router): bool => in_array((int) $router->id, $selectedIds, true))
            ->map(fn (Router $router): array => [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'selector' => $this->routerKey((string) $router->name),
                'status' => 'OUT_OF_SCOPE_PENDING_ACCESS',
            ])
            ->values()
            ->all();

        return [
            'run_id' => $runId,
            'mode' => 'READ_ONLY',
            'writes' => false,
            'wa_sent' => 0,
            'allowlist' => $requested,
            'generated_at' => now('Asia/Jakarta')->toIso8601String(),
            'targets' => $targets,
            'out_of_scope' => $outOfScope,
        ];
    }

    /**
     * Re-run an approved target audit immediately before a future DB apply.
     * No password snapshot is persisted; the caller must provide the exact
     * approval phrase and this method still returns only masked metadata.
     *
     * @param  list<string>  $selectors
     * @return array<string, mixed>
     */
    public function auditForApprovedApply(array $selectors, string $runId): array
    {
        return $this->audit($selectors, $runId, true);
    }

    /**
     * Apply only after an exact approval phrase and an existing backup
     * manifest. Passwords are read again from MikroTik; the report itself
     * contains metadata/HMAC fingerprints only.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function reconcileApproved(array $snapshot, string $approval, string $backupManifest): array
    {
        $runId = trim((string) ($snapshot['run_id'] ?? ''));
        $expectedApproval = 'APPROVE DB PASSWORD RECONCILIATION '.$runId;
        if ($runId === '' || ! hash_equals($expectedApproval, trim($approval))) {
            throw new RuntimeException('Approval phrase tidak cocok; tidak ada database update.');
        }
        if (! is_file($backupManifest) || trim((string) @file_get_contents($backupManifest)) === '') {
            throw new RuntimeException('Backup manifest wajib ada sebelum rekonsiliasi; tidak ada database update.');
        }

        $selectors = $this->normalizeSelectors(is_array($snapshot['allowlist'] ?? null) ? $snapshot['allowlist'] : null);
        $fresh = $this->audit($selectors, $runId, true);
        $snapshotTargets = collect(is_array($snapshot['targets'] ?? null) ? $snapshot['targets'] : [])
            ->keyBy(fn (array $target): string => (string) ($target['selector'] ?? ''));
        $updates = [];

        foreach ($fresh['targets'] as $target) {
            if (($target['status'] ?? '') !== 'READY_FOR_APPROVAL') {
                throw new RuntimeException('Target belum READY_FOR_APPROVAL; rekonsiliasi dibatalkan tanpa perubahan.');
            }
            $old = $snapshotTargets->get((string) $target['selector']);
            if (! is_array($old) || ($old['status'] ?? '') !== 'READY_FOR_APPROVAL'
                || (string) ($old['snapshot_fingerprint'] ?? '') !== (string) ($target['snapshot_fingerprint'] ?? '')) {
                throw new RuntimeException('Snapshot berubah atau stale; buat snapshot baru sebelum approval.');
            }

            $router = Router::query()->find((int) $target['router_id']);
            if (! $router) {
                throw new RuntimeException('Router target tidak ditemukan saat apply; tidak ada perubahan.');
            }
            $live = $this->readRawTarget($router);
            if ($live['fingerprint'] !== $target['snapshot_fingerprint']) {
                throw new RuntimeException('Password router berubah setelah snapshot; apply dibatalkan.');
            }

            $customers = Customer::ebilling()
                ->where('router_id', $router->id)
                ->whereNotNull('pppoe_user')
                ->where('pppoe_user', '!=', '')
                ->orderBy('id')
                ->get(['id', 'pppoe_user', 'pppoe_password']);
            $rows = $this->classifyCustomers($customers, $live['by_exact'], $live['by_key']);
            if (collect($rows)->contains(fn (array $row): bool => in_array($row['category'], ['CONFLICT', 'NOT_FOUND'], true))) {
                throw new RuntimeException('Mapping customer conflict/not-found; apply dibatalkan tanpa perubahan.');
            }
            if (($orphanCount = count($this->orphanUsers($live['by_exact'], $customers))) > 0) {
                throw new RuntimeException('PPP secret orphan terhadap customer mapping ('.$orphanCount.'); apply dibatalkan tanpa perubahan.');
            }

            foreach ($customers as $customer) {
                $secret = $live['by_exact'][(string) $customer->pppoe_user] ?? null;
                if (! is_array($secret) || ! isset($secret['password'])) {
                    throw new RuntimeException('Secret target tidak terbaca saat apply; tidak ada perubahan.');
                }
                $row = collect($rows)->firstWhere('customer_id', $customer->id);
                $category = is_array($row) ? (string) $row['category'] : 'MATCH';
                if ($category === 'MATCH') {
                    continue;
                }
                $updates[] = [
                    'customer_id' => (int) $customer->id,
                    'password' => (string) $secret['password'],
                    'category' => $category,
                ];
            }
            unset($live, $customers, $rows);
        }

        $changed = 0;
        DB::transaction(function () use ($updates, &$changed): void {
            foreach ($updates as $item) {
                $customer = Customer::query()->lockForUpdate()->find((int) $item['customer_id']);
                if (! $customer) {
                    throw new RuntimeException('Customer target hilang saat apply; transaksi dibatalkan.');
                }
                $customer->forceFill(['pppoe_password' => $item['password']])->save();
                if (($item['category'] ?? 'MATCH') !== 'MATCH') {
                    $changed++;
                }
            }
        });

        return [
            'run_id' => $runId,
            'mode' => 'APPROVED_DATABASE_RECONCILIATION',
            'writes' => true,
            'changed_rows' => $changed,
            'total_rows_checked' => count($updates),
            'backup_manifest' => $backupManifest,
            'raw_passwords_returned' => false,
            'wa_sent' => 0,
        ];
    }

    /**
     * Change a customer's password through MikroTik first, then persist only
     * the verified router value through Customer's encrypted cast.
     *
     * This method is intentionally not used by any production audit command;
     * callers must explicitly invoke the password-change workflow. No raw
     * password is returned, logged, queued, or included in the result.
     *
     * @return array{changed: bool, router_id: int, pppoe_user: string, verified: bool}
     */
    public function changeCustomerPassword(Customer $customer, string $password, ?string $profile = null): array
    {
        $password = trim($password);
        if ($password === '' || strlen($password) < 4 || strlen($password) > 64) {
            throw new RuntimeException('Password PPPoE harus 4-64 karakter.');
        }

        $username = trim((string) $customer->pppoe_user);
        if ($username === '') {
            throw new RuntimeException('PPPoE username pelanggan kosong; password tidak diubah.');
        }

        $router = Router::query()->whereKey((int) $customer->router_id)->first();
        if (! $router || ! $router->is_active) {
            throw new RuntimeException('Router pelanggan tidak tersedia atau nonaktif; password tidak diubah.');
        }

        $profile = trim((string) ($profile ?: $customer->mikrotik_profile ?: optional($customer->package)->mikrotik_profile));
        if ($profile === '') {
            throw new RuntimeException('Profile MikroTik pelanggan belum tersedia; password tidak diubah.');
        }

        try {
            $this->mikrotik->connect($router, ['timeout' => 15, 'attempts' => 1]);
            $result = $this->mikrotik->addPPPSecret(
                $username,
                $password,
                $profile,
                trim((string) $customer->pppoe_local_address) ?: null,
                trim((string) $customer->pppoe_remote_address) ?: null,
                'pppoe',
                'source=ebilling;password_change=verified'
            );
            if (! ($result['success'] ?? false)) {
                throw new RuntimeException('Password PPPoE gagal disimpan di router; database tidak diubah.');
            }

            $verified = $this->mikrotik->getPPPSecret($username);
            $verifiedPassword = trim((string) ($verified['password'] ?? ''));
            if ($verified === null || $verifiedPassword === '' || preg_match('/^\*+$/', $verifiedPassword) === 1
                || ! hash_equals(hash('sha256', $password), hash('sha256', $verifiedPassword))) {
                throw new RuntimeException('Password PPPoE gagal diverifikasi dari router; database tidak diubah.');
            }
        } finally {
            $this->mikrotik->disconnect();
        }

        $customer->forceFill([
            'pppoe_password' => $verifiedPassword,
            'mikrotik_sync_status' => 'synced',
            'mikrotik_synced_at' => now(),
            'mikrotik_sync_checked_at' => now(),
            'mikrotik_profile' => $profile,
        ])->save();

        return [
            'changed' => true,
            'router_id' => (int) $router->id,
            'pppoe_user' => $username,
            'verified' => true,
        ];
    }

    public function routerKey(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** @return list<string> */
    public function normalizeSelectors(?array $selectors): array
    {
        if ($selectors === null) {
            return self::ALLOWLIST;
        }

        $normalized = collect($selectors)
            ->flatMap(fn ($selector) => is_string($selector) ? explode(',', $selector) : [])
            ->map(fn (string $selector): string => $this->routerKey($selector))
            ->filter()
            ->filter(fn (string $selector): bool => in_array($selector, self::ALLOWLIST, true))
            ->unique()
            ->values()
            ->all();

        return $normalized;
    }

    /**
     * Resolve a selector through an explicit alias only. The returned router
     * identity is always the matched database router_id.
     *
     * @param  array<string, list<Router>>  $bySelector
     * @return list<Router>
     */
    private function matchesForSelector(string $selector, array $bySelector): array
    {
        $canonical = self::SELECTOR_ALIASES[$selector] ?? $selector;

        return $bySelector[$this->routerKey($canonical)] ?? [];
    }

    /** @return array<string, mixed> */
    private function auditTarget(Router $router, string $selector, bool $includeRows): array
    {
        $base = [
            'selector' => $selector,
            'router_id' => $router->id,
            'router_name' => $router->name,
            'status' => 'PENDING',
            'auth_source' => null,
            'secret_count' => ['expected' => null, 'actual' => null, 'complete' => false],
            'pppoe_secret_count' => 0,
            'orphan_count' => 0,
            'snapshot_fingerprint' => null,
            'counts' => $this->emptyCounts(),
            'rows' => [],
        ];

        try {
            $this->mikrotik->connect($router, ['timeout' => 15, 'attempts' => 1]);
            $authSource = $this->mikrotik->getPppAuthenticationSource();
            $base['auth_source'] = [
                'source' => $authSource['source'],
                'use_radius' => $authSource['use_radius'],
                'readable' => $authSource['readable'],
            ];

            if (! $authSource['readable']) {
                $base['status'] = 'BLOCKED_ROUTER_AUTH_SOURCE';
                $base['reason'] = 'RouterOS /ppp/aaa niet leesbaar atau kosong.';

                return $base;
            }
            if ($authSource['use_radius']) {
                $base['status'] = 'BLOCKED_ROUTER_AUTH_SOURCE';
                $base['reason'] = 'RADIUS aktif; tahap ini hanya mengizinkan local PPP secret.';

                return $base;
            }

            $expected = $this->mikrotik->getPppoeSecretCount();
            $secrets = $this->mikrotik->getPppoeSecretsWithPasswords();
            $actual = count($secrets);
            $base['secret_count'] = [
                'expected' => $expected,
                'actual' => $actual,
                'complete' => $expected === $actual,
            ];

            if ($expected !== $actual) {
                $base['status'] = 'BLOCKED_TARGET_ROUTER_PASSWORD_CAPABILITY';
                $base['reason'] = 'Snapshot PPP secret tidak lengkap; expected dan actual berbeda.';

                return $base;
            }

            $pppoeSecrets = $this->onlyPppoeSecrets($secrets);
            $base['pppoe_secret_count'] = count($pppoeSecrets);
            $classified = $this->classifySecrets($pppoeSecrets);
            if ($classified['blocked_reason'] !== null) {
                $base['status'] = 'BLOCKED_TARGET_ROUTER_PASSWORD_CAPABILITY';
                $base['reason'] = $classified['blocked_reason'];

                return $base;
            }

            $customers = Customer::ebilling()
                ->where('router_id', $router->id)
                ->whereNotNull('pppoe_user')
                ->where('pppoe_user', '!=', '')
                ->orderBy('id')
                ->get(['id', 'name', 'pppoe_user', 'pppoe_password']);

            $rows = $this->classifyCustomers($customers, $classified['by_exact'], $classified['by_key']);
            $base['counts'] = $this->countsFromRows($rows);
            $base['orphan_count'] = count($this->orphanUsers($classified['by_exact'], $customers));
            $base['snapshot_fingerprint'] = $classified['fingerprint'];
            if ($includeRows) {
                $base['rows'] = $rows;
            }

            if ($base['counts']['conflict'] > 0 || $base['counts']['not_found'] > 0 || $base['orphan_count'] > 0) {
                $base['status'] = 'BLOCKED_PASSWORD_MAPPING';
                $base['reason'] = 'Mapping customer/secret tidak lengkap atau conflict; approval diblokir.';

                return $base;
            }

            $base['status'] = 'READY_FOR_APPROVAL';

            unset($secrets, $pppoeSecrets, $classified, $customers, $rows);

            return $base;
        } catch (Throwable $e) {
            $base['status'] = $this->classifyAccessException($e);
            $base['reason'] = $this->safeReason($e);

            return $base;
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    /** @return array<string, mixed> */
    private function classifySecrets(array $secrets): array
    {
        $byExact = [];
        $byKey = [];
        foreach ($secrets as $secret) {
            $rawName = (string) ($secret['name'] ?? '');
            $name = trim($rawName);
            if ($name === '') {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'PPP secret tanpa username.'];
            }
            if ($rawName !== $name) {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'PPP username memiliki whitespace; mapping diblokir.'];
            }

            $password = $secret['password'] ?? null;
            if (! is_string($password) || trim($password) === '') {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'Password PPP secret kosong/tidak terbaca.'];
            }
            if (preg_match('/^\*+$/', trim($password)) === 1) {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'Password PPP secret masih masked.'];
            }

            if (isset($byExact[$name])) {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'Username PPP secret duplicate exact.'];
            }
            $key = $this->routerKey($name);
            if (isset($byKey[$key])) {
                return ['by_exact' => [], 'by_key' => [], 'blocked_reason' => 'Username PPP secret duplicate setelah normalisasi case.'];
            }

            // Keep only the minimum sensitive fields in this local map; it is
            // discarded before return and never crosses the service boundary.
            $safeSecret = ['name' => $name, 'password' => $password];
            $byExact[$name] = $safeSecret;
            $byKey[$key] = $safeSecret;
        }

        $fingerprintRows = [];
        foreach ($byExact as $name => $secret) {
            $password = (string) ($secret['password'] ?? '');
            $fingerprintRows[] = [$name, hash_hmac('sha256', $password, $this->hmacKey())];
        }
        usort($fingerprintRows, fn (array $left, array $right): int => strcmp($left[0], $right[0]));

        return [
            'by_exact' => $byExact,
            'by_key' => $byKey,
            'blocked_reason' => null,
            'fingerprint' => hash_hmac('sha256', json_encode($fingerprintRows, JSON_THROW_ON_ERROR), $this->hmacKey()),
        ];
    }

    /** @param list<array<string, mixed>> $secrets */
    private function onlyPppoeSecrets(array $secrets): array
    {
        return array_values(array_filter(
            $secrets,
            fn (array $secret): bool => strtolower(trim((string) ($secret['service'] ?? ''))) === 'pppoe'
        ));
    }

    /** @return array{by_exact: array<string, array<string, mixed>>, by_key: array<string, array<string, mixed>>, fingerprint: string} */
    private function readRawTarget(Router $router): array
    {
        try {
            $this->mikrotik->connect($router, ['timeout' => 15, 'attempts' => 1]);
            $auth = $this->mikrotik->getPppAuthenticationSource();
            if (! $auth['readable'] || $auth['use_radius']) {
                throw new RuntimeException('Router authentication source blocked.');
            }
            $expected = $this->mikrotik->getPppoeSecretCount();
            $secrets = $this->mikrotik->getPppoeSecretsWithPasswords();
            if ($expected !== count($secrets)) {
                throw new RuntimeException('Router secret snapshot incomplete.');
            }
            $classified = $this->classifySecrets($this->onlyPppoeSecrets($secrets));
            if ($classified['blocked_reason'] !== null) {
                throw new RuntimeException('Router password capability blocked.');
            }

            return [
                'by_exact' => $classified['by_exact'],
                'by_key' => $classified['by_key'],
                'fingerprint' => $classified['fingerprint'],
            ];
        } finally {
            $this->mikrotik->disconnect();
        }
    }

    private function hmacKey(): string
    {
        return (string) config('app.key');
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<string, array<string, mixed>>  $byExact
     * @param  array<string, array<string, mixed>>  $byKey
     * @return list<array<string, mixed>>
     */
    private function classifyCustomers(Collection $customers, array $byExact, array $byKey): array
    {
        $groups = $customers->groupBy(fn (Customer $customer): string => (string) $customer->pppoe_user);
        $rows = [];
        foreach ($customers as $customer) {
            $username = (string) $customer->pppoe_user;
            $dbPassword = trim((string) ($customer->pppoe_password ?? ''));
            $dbState = $dbPassword === '' ? 'empty' : 'available';
            $secret = $byExact[$username] ?? null;
            $caseVariant = $secret === null ? ($byKey[$this->routerKey($username)] ?? null) : null;
            $routerState = $secret === null ? ($caseVariant ? 'case_variant_conflict' : 'not_found') : 'readable';
            $category = 'NOT_FOUND';
            $reason = 'Username exact tidak ditemukan di router.';

            if ($groups->get($username)?->count() > 1) {
                $category = 'CONFLICT';
                $reason = 'Lebih dari satu customer memakai username PPPoE exact yang sama.';
            } elseif ($caseVariant !== null) {
                $category = 'CONFLICT';
                $reason = 'Username hanya cocok setelah normalisasi case; mapping wajib exact dan case-sensitive.';
            } elseif ($secret !== null) {
                $routerPassword = trim((string) ($secret['password'] ?? ''));
                if ($dbState === 'empty') {
                    $category = 'FILL_DATABASE';
                    $reason = 'Database kosong; router readable.';
                } elseif (hash_equals(hash('sha256', $dbPassword), hash('sha256', $routerPassword))) {
                    $category = 'MATCH';
                    $reason = 'Database dan router sama.';
                } else {
                    $category = 'UPDATE_DATABASE';
                    $reason = 'Database berbeda; router adalah source of truth.';
                }
            }

            $rows[] = [
                'customer_id' => $customer->id,
                'name' => (string) $customer->name,
                'pppoe_user' => $username,
                'database_state' => $dbState,
                'router_state' => $routerState,
                'category' => $category,
                'reason' => $reason,
            ];
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows */
    private function countsFromRows(array $rows): array
    {
        $counts = $this->emptyCounts();
        foreach ($rows as $row) {
            $category = strtolower((string) ($row['category'] ?? ''));
            $counts['rows']++;
            if (isset($counts[$category])) {
                $counts[$category]++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $byExact
     * @param  Collection<int, Customer>  $customers
     * @return list<string>
     */
    private function orphanUsers(array $byExact, Collection $customers): array
    {
        $customerUsers = $customers
            ->map(fn (Customer $customer): string => (string) $customer->pppoe_user)
            ->filter()
            ->flip()
            ->all();

        return array_values(array_diff(array_keys($byExact), array_keys($customerUsers)));
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [
            'rows' => 0,
            'match' => 0,
            'fill_database' => 0,
            'update_database' => 0,
            'conflict' => 0,
            'not_found' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function notFoundTarget(string $selector): array
    {
        return [
            'selector' => $selector,
            'status' => 'TARGET_ROUTER_NOT_FOUND',
            'router_id' => null,
            'router_name' => null,
            'reason' => 'Router target tidak ditemukan sebagai router aktif.',
            'counts' => $this->emptyCounts(),
            'rows' => [],
        ];
    }

    private function classifyAccessException(Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        foreach (['permission', 'not enough permissions', 'forbidden', 'denied', 'sensitive', 'password'] as $needle) {
            if (str_contains($message, $needle)) {
                return 'PENDING_API_SENSITIVE_ACCESS';
            }
        }

        return 'BLOCKED_TARGET_ROUTER_PASSWORD_CAPABILITY';
    }

    private function safeReason(Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '') {
            return 'Router audit gagal tanpa detail.';
        }

        return preg_replace('/(?i)(password|pass|secret)\s*[:=]\s*\S+/', '$1=[REDACTED]', $message) ?: 'Router audit gagal.';
    }
}
