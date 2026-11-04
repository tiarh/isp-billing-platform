<?php

namespace App\Services\Mikrotik;

use App\Exceptions\RouterOsWriteException;
use App\Models\Router;
use App\Services\MikrotikService;
use RouterOS\Client;
use RouterOS\Query;

/**
 * Hapus (dan pulihkan) satu PPP secret lewat RouterOS API. Hanya dipakai "Hapus secret"
 * dan `routers:secret-restore`; MikrotikService sengaja tidak diubah.
 *
 * Satu objek = satu koneksi. Tidak pernah menulis password ke log.
 */
class PppSecretRemover
{
    /** Field yang boleh ditulis balik saat pemulihan (tanpa .id dan field baca-saja). */
    public const RESTORE_FIELDS = [
        'name', 'password', 'profile', 'service', 'local-address', 'remote-address', 'caller-id',
        'routes', 'limit-bytes-in', 'limit-bytes-out', 'comment', 'disabled',
    ];

    public function __construct(private readonly MikrotikService $mikrotik) {}

    public function connect(Router $router, int $timeout): void
    {
        $this->mikrotik->connect($router, ['timeout' => $timeout, 'attempts' => 1]);
    }

    public function disconnect(): void
    {
        $this->mikrotik->disconnect();
    }

    /**
     * Seluruh baris secret (termasuk password) dengan nama persis sama, atau null.
     *
     * @return array<string, mixed>|null
     */
    public function readFull(string $name): ?array
    {
        foreach ($this->rows((new Query('/ppp/secret/print'))->where('name', $name)) as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }

        return null;
    }

    public function exists(string $name): bool
    {
        return $this->rows((new Query('/ppp/secret/print'))->equal('.proplist', '.id,name')->where('name', $name)) !== [];
    }

    /** @return array{name?: string, uptime?: string, 'caller-id'?: string, address?: string}|null */
    public function activeSession(string $name): ?array
    {
        return $this->mikrotik->activeSessionFor($name);
    }

    /** @return array<string, mixed>|null  disabled, profile, last-logged-out (RouterOS v7) */
    public function state(string $name): ?array
    {
        return $this->mikrotik->secretStateFor($name);
    }

    /** @throws RouterOsWriteException kalau router menolak */
    public function remove(string $id): void
    {
        $response = $this->client()->query((new Query('/ppp/secret/remove'))->equal('.id', $id))->read();
        $error = $this->routerOsError($response);
        if ($error !== null) {
            throw new RouterOsWriteException($error);
        }
    }

    /** Putuskan sesi aktif (setelah secret dihapus, supaya CPE tidak masuk lagi). */
    public function kick(string $name): void
    {
        $this->mikrotik->kickUser($name, true);
    }

    /**
     * Pulihkan secret dari cadangan (hanya field yang diizinkan).
     *
     * @param  array<string, mixed>  $row
     *
     * @throws RouterOsWriteException kalau router menolak
     */
    public function add(array $row): void
    {
        $query = new Query('/ppp/secret/add');
        foreach (self::RESTORE_FIELDS as $field) {
            if (isset($row[$field]) && $row[$field] !== '') {
                $query->equal($field, is_bool($row[$field]) ? ($row[$field] ? 'yes' : 'no') : (string) $row[$field]);
            }
        }

        $error = $this->routerOsError($this->client()->query($query)->read());
        if ($error !== null) {
            throw new RouterOsWriteException($error);
        }
    }

    /** RouterOS mengirim disabled sebagai "true"/"false" (atau yes/no). */
    public static function isTrue(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes'], true);
    }

    /** @return list<array<string, mixed>> */
    private function rows(Query $query): array
    {
        $response = $this->client()->query($query)->read();

        return is_array($response) ? array_values(array_filter($response, 'is_array')) : [];
    }

    private function client(): Client
    {
        return $this->mikrotik->client() ?? throw new \RuntimeException('Tidak terhubung ke router.');
    }

    /** Pesan error RouterOS (!trap) dari balasan, atau null kalau sukses. */
    private function routerOsError(mixed $response): ?string
    {
        if (! is_array($response)) {
            return null;
        }

        $message = $response['after']['message'] ?? $response['message'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        foreach ($response as $row) {
            if (is_array($row) && is_string($row['message'] ?? null) && $row['message'] !== '' && ! isset($row['ret']) && ! isset($row['.id'])) {
                return $row['message'];
            }
        }

        return null;
    }
}
