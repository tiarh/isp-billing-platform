<?php

namespace App\Services\Wa;

use App\Services\EvolutionApiService;
use App\Support\WaGroupJid;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Group picker source for the Grup WhatsApp page. Never throws: when Evolution is slow or down it
 * returns the last known good list flagged stale. Rows are whitelisted to {jid, name, members}:
 * raw Evolution rows carry member phone numbers and last-message content that must not leave the server.
 */
class WaGroupDirectory
{
    public const TTL_FRESH = 600;

    public const TTL_LAST_GOOD = 7 * 86400;

    /**
     * @return array{groups: list<array{jid:string,name:string,members:int}>, stale: bool, fetched_at: ?int, error_code: ?string}
     */
    public function list(string $channel, bool $fresh = false): array
    {
        $channel = in_array($channel, ['ops', 'ticket'], true) ? $channel : 'ops';
        $key = 'wa-groups:dir:'.$channel;

        if (! $fresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return ['groups' => $cached['groups'], 'stale' => false, 'fetched_at' => $cached['at'], 'error_code' => null];
            }
        }

        // Single flight per channel: concurrent loads must not pile heavy calls onto the ops WA instance
        // (the PSB bot sends from it). Losers serve the last known good list.
        $lock = Cache::lock($key.':lock', 45);
        if (! $lock->get()) {
            $lkg = Cache::get($key.':lkg');
            $fresh = Cache::get($key);
            $use = is_array($fresh) ? $fresh : (is_array($lkg) ? $lkg : null);

            return ['groups' => $use['groups'] ?? [], 'stale' => ! is_array($fresh), 'fetched_at' => $use['at'] ?? null, 'error_code' => is_array($fresh) ? null : 'busy'];
        }

        $errorCode = null;
        try {
            $evolution = app(EvolutionApiService::class)->forChannel($channel);
            $source = 'chats';
            $result = $evolution->listGroupsViaChats();
            if (! $result['success'] || $result['groups'] === []) {
                $errorCode = $result['error'] ?? 'empty';
                $result = $evolution->listGroupsLight();
                $source = 'light';
            }
            if ($result['success'] && $result['groups'] !== []) {
                $groups = $this->whitelist($result['groups']);
                $payload = ['groups' => $groups, 'at' => time(), 'source' => $source];
                Cache::put($key, $payload, self::TTL_FRESH);
                Cache::put($key.':lkg', $payload, self::TTL_LAST_GOOD);

                return ['groups' => $groups, 'stale' => false, 'fetched_at' => $payload['at'], 'error_code' => null];
            }
            $errorCode = $result['error'] ?? $errorCode ?? 'empty';
        } catch (\Throwable $e) {
            $errorCode = $e::class;
        } finally {
            $lock->release();
        }

        Log::info('[WA-GROUPS] directory fetch failed, serving last known good', ['channel' => $channel, 'error_code' => $errorCode]);
        $lkg = Cache::get($key.':lkg');

        return [
            'groups' => is_array($lkg) ? $lkg['groups'] : [],
            'stale' => true,
            'fetched_at' => is_array($lkg) ? $lkg['at'] : null,
            'error_code' => (string) $errorCode,
        ];
    }

    /**
     * Membership of this channel's WA number in $jid. A hit in the already cached directory needs no
     * network call; otherwise one findGroupInfos probe (result cached 10 minutes unless unknown).
     *
     * @return array{member: ?bool, subject: ?string, size: ?int, error: ?string}
     */
    public function membership(string $channel, string $jid): array
    {
        $channel = in_array($channel, ['ops', 'ticket'], true) ? $channel : 'ops';
        $jid = WaGroupJid::normalize($jid);
        if ($jid === '') {
            return ['member' => null, 'subject' => null, 'size' => null, 'error' => 'empty'];
        }

        // findChats can list groups the number already LEFT, so only a list from fetchAllGroups
        // (source "light") counts as proof of membership; anything else is probed like the CLI does.
        $cached = Cache::get('wa-groups:dir:'.$channel);
        if (is_array($cached) && ($cached['source'] ?? '') === 'light') {
            foreach ($cached['groups'] as $g) {
                if (($g['jid'] ?? '') === $jid) {
                    return ['member' => true, 'subject' => $g['name'], 'size' => $g['members'], 'error' => null];
                }
            }
        }

        $key = 'wa-groups:member:'.$channel.':'.md5($jid);
        $hit = Cache::get($key);
        if (is_array($hit)) {
            return $hit;
        }

        try {
            $m = app(EvolutionApiService::class)->forChannel($channel)->groupMembership($jid);
        } catch (\Throwable $e) {
            return ['member' => null, 'subject' => null, 'size' => null, 'error' => $e::class];
        }
        if ($m['member'] !== null) {
            Cache::put($key, $m, self::TTL_FRESH);
        }

        return $m;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array{jid:string,name:string,members:int}>
     */
    private function whitelist(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! str_ends_with($row['id'], '@g.us')) {
                continue;
            }
            $name = is_string($row['subject'] ?? null) && $row['subject'] !== '' ? $row['subject'] : (is_string($row['name'] ?? null) ? $row['name'] : $row['id']);
            $out[$row['id']] = [
                'jid' => $row['id'],
                'name' => mb_substr($name, 0, 100),
                'members' => is_numeric($row['members'] ?? null) ? (int) $row['members'] : 0,
            ];
        }

        return array_values($out);
    }
}
