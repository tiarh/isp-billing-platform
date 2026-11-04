<?php

namespace App\Services\Cpe;

use App\Models\Setting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * RCA-3: thin GenieACS NBI client for the watch / summon services and the config command.
 * Same base URL + optional basic auth as GenieAcsService (settings genieacs_url / _username / _password).
 * It only ever creates getParameterValues / refreshObject tasks; it never writes parameter values.
 */
class CpeNbi
{
    public function baseUrl(): string
    {
        return rtrim((string) Setting::get('genieacs_url', ''), '/');
    }

    public function http(int $timeoutSeconds): PendingRequest
    {
        $request = Http::timeout($timeoutSeconds)->connectTimeout(min(5, $timeoutSeconds))->acceptJson();
        $username = Setting::get('genieacs_username');
        $password = Setting::get('genieacs_password');
        if ($username || $password) {
            $request = $request->withBasicAuth((string) $username, (string) $password);
        }

        return $request;
    }

    /**
     * One GET /tasks: device ids that still have a pending task.
     *
     * @return array<string,true>
     */
    public function pendingTaskDevices(): array
    {
        $response = $this->http(10)->get($this->baseUrl().'/tasks', ['projection' => 'device,name']);
        $response->throw();
        $out = [];
        foreach ((array) $response->json() as $task) {
            if (is_array($task) && isset($task['device']) && is_string($task['device'])) {
                $out[$task['device']] = true;
            }
        }

        return $out;
    }

    /**
     * Create one task. $connectionRequest = false queues it for the next periodic inform (no packet to the CPE).
     *
     * @param  array<string,mixed>  $body
     * @return array{status: int, ok: bool}
     */
    public function postTask(string $deviceId, array $body, bool $connectionRequest, int $timeoutMs = 8000): array
    {
        $url = $this->baseUrl().'/devices/'.rawurlencode($deviceId).'/tasks';
        $httpTimeout = 10;
        if ($connectionRequest) {
            $url .= '?connection_request&timeout='.$timeoutMs;
            $httpTimeout = (int) ceil($timeoutMs / 1000) + 3;
        }
        $response = $this->http($httpTimeout)->post($url, $body);

        return ['status' => $response->status(), 'ok' => $response->successful()];
    }

    /**
     * Read documents back with a small projection (query _id $in).
     *
     * @param  list<string>  $deviceIds
     * @param  list<string>  $projection
     * @return array<string, array<string,mixed>> device_id => document
     */
    public function fetchDocs(array $deviceIds, array $projection): array
    {
        if ($deviceIds === []) {
            return [];
        }
        $response = $this->http(15)->get($this->baseUrl().'/devices', [
            'query' => json_encode(['_id' => ['$in' => array_values($deviceIds)]], JSON_UNESCAPED_SLASHES),
            'projection' => implode(',', $projection),
        ]);
        $response->throw();
        $out = [];
        foreach ((array) $response->json() as $doc) {
            if (is_array($doc) && isset($doc['_id'])) {
                $out[(string) $doc['_id']] = $doc;
            }
        }

        return $out;
    }
}
