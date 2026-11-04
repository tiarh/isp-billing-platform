<?php

namespace Tests\Support;

use RouterOS\Client;
use RouterOS\Interfaces\ClientInterface;
use RouterOS\Query;

/**
 * RouterOS API client in memory (tanpa socket): tabel PPP secret + sesi aktif.
 * Hanya sisi jaringan yang dipalsukan; kode MikrotikService/PppSecretRemover yang asli tetap jalan.
 * `calls` mencatat endpoint + atribut dengan nilai password disamarkan.
 */
final class FakeRouterOsClient extends Client
{
    /** @var list<array<string, mixed>> */
    public array $secrets = [];

    /** @var list<array<string, mixed>> */
    public array $sessions = [];

    /** @var list<string> */
    public array $calls = [];

    /** Pesan !trap untuk /ppp/secret/remove (null = berhasil). */
    public ?string $removeError = null;

    /** true = perintah remove "berhasil" tapi secret tidak benar-benar hilang. */
    public bool $removeIsNoop = false;

    /** true = /ppp/active/remove ditolak. */
    public bool $kickError = false;

    /** @var (\Closure(string): void)|null dipanggil sebelum tiap perintah dijawab */
    public ?\Closure $onRead = null;

    private mixed $pending = null;

    public function __construct() {}

    public function query($endpoint, ?array $where = null, ?string $operations = null, ?string $tag = null): ClientInterface
    {
        $this->pending = $endpoint;

        return $this;
    }

    public function read(bool $parse = true, array $options = [])
    {
        /** @var Query $query */
        $query = $this->pending;
        $endpoint = $query->getEndpoint();
        $set = [];
        $where = [];
        foreach ($query->getAttributes() as $attribute) {
            if (str_starts_with($attribute, '?')) {
                [$key, $value] = explode('=', substr($attribute, 1), 2) + [1 => ''];
                $where[$key] = $value;
            } elseif (str_starts_with($attribute, '=')) {
                [$key, $value] = explode('=', substr($attribute, 1), 2) + [1 => ''];
                $set[$key] = $value;
            }
        }
        // Tes race: sisipkan perubahan database "dari admin lain" tepat saat router dibaca.
        if ($this->onRead !== null) {
            ($this->onRead)($endpoint);
        }
        $this->calls[] = $endpoint.' '.json_encode(array_map(fn ($v, $k) => $k === 'password' ? '<pw>' : $v, $set + $where, array_keys($set + $where)));

        return match ($endpoint) {
            '/ppp/secret/print' => $this->match($this->secrets, $where),
            '/ppp/active/print' => $this->match($this->sessions, $where),
            '/ppp/secret/remove' => $this->removeSecret($set['.id'] ?? ''),
            '/ppp/active/remove' => $this->kick($set['.id'] ?? ''),
            '/ppp/secret/add' => $this->addSecret($set),
            default => [],
        };
    }

    /** @return list<string> */
    public function endpoints(): array
    {
        return array_map(fn (string $call): string => explode(' ', $call, 2)[0], $this->calls);
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function match(array $rows, array $where): array
    {
        return array_values(array_filter($rows, function (array $row) use ($where): bool {
            foreach ($where as $key => $value) {
                if (($row[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function removeSecret(string $id): array
    {
        if ($this->removeError !== null) {
            return ['after' => ['message' => $this->removeError]];
        }
        if (! $this->removeIsNoop) {
            $this->secrets = array_values(array_filter($this->secrets, fn (array $row): bool => ($row['.id'] ?? '') !== $id));
        }

        return [];
    }

    private function kick(string $id): array
    {
        if ($this->kickError) {
            return ['after' => ['message' => 'not enough permissions']];
        }
        $this->sessions = array_values(array_filter($this->sessions, fn (array $row): bool => ($row['.id'] ?? '') !== $id));

        return [];
    }

    private function addSecret(array $fields): array
    {
        $this->secrets[] = $fields + ['.id' => '*A'.(count($this->secrets) + 1)];

        return ['after' => ['ret' => '*A'.count($this->secrets)]];
    }
}
