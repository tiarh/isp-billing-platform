<?php

namespace App\Services\Wa;

use App\Models\Setting;
use App\Services\EvolutionApiService;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only view of how WA functions are really routed to Evolution instances, for the
 * WA Gateway pairing page. It resolves names with the same rules as EvolutionApiService::forChannel()
 * and never changes what is sent. Phone numbers are masked to the last 4 digits.
 */
class WaRoutingOverview
{
    public const STATUS_TTL = 15;

    /** Total time budget for the (warning-only) membership probes before the rest are reported as unchecked. */
    public const CHECK_BUDGET_SECONDS = 8;

    /** Card slots, in display order: key => [number, forChannel() channel]. */
    private const SLOTS = [
        'ops' => 1,
        'billing' => 2,
        'ticket' => 3,
    ];

    /** Functions and the forChannel() channel each one sends through. */
    private const FUNCTIONS = [
        'ops' => ['label' => 'PSB WA, Ops (/pppoe), OLT/GenieACS alert & Absensi', 'channel' => 'ops'],
        'billing' => ['label' => 'Billing & Broadcast', 'channel' => 'billing'],
        'ticket' => ['label' => 'Ticket & Progress', 'channel' => 'ticket'],
    ];

    /** Dedicated Nomor 3 instance (not the same as the instance Ticket & Progress currently uses). */
    public function dedicatedTicketInstance(): string
    {
        $name = trim((string) config('services.evolution_api.instance_ticket_dedicated', 'skynet-ticket'));

        return $name !== '' ? $name : 'skynet-ticket';
    }

    public function resolve(string $channel): string
    {
        return app(EvolutionApiService::class)->forChannel($channel)->getInstanceName();
    }

    /** @return array<string, string> slot key => instance name that the card represents */
    public function slotInstances(): array
    {
        return [
            'ops' => $this->resolve('ops'),
            'billing' => $this->resolve('billing'),
            'ticket' => $this->dedicatedTicketInstance(),
        ];
    }

    /** @return list<string> */
    public function knownInstances(): array
    {
        return array_values(array_unique(array_values($this->slotInstances())));
    }

    /** Instances Ticket & Progress may be routed to: Nomor 1 (ops) or Nomor 3 (dedicated ticket); never billing. */
    public function ticketSelectableInstances(): array
    {
        $slots = $this->slotInstances();

        return array_values(array_unique([$slots['ops'], $slots['ticket']]));
    }

    public function isKnownInstance(string $name): bool
    {
        return in_array($name, $this->knownInstances(), true);
    }

    /**
     * Real routing per function.
     *
     * @return list<array{key:string,label:string,channel:string,instance:string}>
     */
    public function functions(): array
    {
        $out = [];
        foreach (self::FUNCTIONS as $key => $fn) {
            $out[] = ['key' => $key, 'label' => $fn['label'], 'channel' => $fn['channel'], 'instance' => $this->resolve($fn['channel'])];
        }
        $reportChannel = strtolower(trim((string) Setting::get('report_wa_channel', 'ops')));
        $reportChannel = in_array($reportChannel, ['ops', 'ticket'], true) ? $reportChannel : 'ops';
        $out[] = [
            'key' => 'report',
            'label' => 'Report grup (saluran: '.$reportChannel.')',
            'channel' => $reportChannel,
            'instance' => $this->resolve($reportChannel),
        ];

        return $out;
    }

    /**
     * Live status of one instance (cached ~15 s, bounded timeouts, number masked).
     *
     * @return array{state:string,connected:bool,configured:bool,number_masked:?string}
     */
    public function liveStatus(string $instance, bool $fresh = false): array
    {
        $key = 'wa-routing:status:'.md5($instance);
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::STATUS_TTL, function () use ($instance) {
            $evo = app(EvolutionApiService::class)->forInstance($instance);
            $state = $evo->getConnectionState();
            $digits = $state === 'open' ? $evo->fetchOwnerDigits() : null;

            return [
                'state' => $state,
                'connected' => $state === 'open',
                'configured' => $evo->isConfigured(),
                'number_masked' => self::maskNumber($digits),
            ];
        });
    }

    public static function maskNumber(?string $digits): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $digits) ?? '';
        if ($digits === '') {
            return null;
        }

        return '••••'.substr($digits, -4);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function cards(bool $fresh = false): array
    {
        $slots = $this->slotInstances();
        $functions = $this->functions();
        $cards = [];

        foreach (self::SLOTS as $slotKey => $number) {
            $name = $slots[$slotKey];
            $status = $this->liveStatus($name, $fresh);

            $usedBy = [];
            $alsoHandles = [];
            foreach ($functions as $fn) {
                if ($fn['instance'] !== $name) {
                    continue;
                }
                $usedBy[] = $fn['label'];
                // Home slot of a function is its channel (ops|billing|ticket).
                if ($fn['channel'] !== $slotKey) {
                    $alsoHandles[] = $fn['label'];
                }
            }

            $unusedMessage = null;
            if ($usedBy === []) {
                $own = self::FUNCTIONS[$slotKey]['label'];
                $actual = collect($functions)->firstWhere('key', $slotKey);
                $actualSlot = array_search($actual['instance'] ?? '', $slots, true);
                $unusedMessage = 'TIDAK DIPAKAI — '.$own.' saat ini lewat Nomor '
                    .($actualSlot !== false ? self::SLOTS[$actualSlot] : '?').' ('.($actual['instance'] ?? '?').')';
            }

            $cards[] = [
                'key' => $slotKey,
                'label' => 'Nomor '.$number,
                'number' => $number,
                'description' => $usedBy === [] ? '' : 'Dipakai untuk: '.implode(', ', $usedBy),
                'name' => $name,
                'state' => $status['state'],
                'connected' => $status['connected'],
                'configured' => $status['configured'],
                'number_masked' => $status['number_masked'],
                'used_by' => $usedBy,
                'also_handles' => array_map(static fn ($l) => 'Nomor '.$number.' juga menangani '.$l, $alsoHandles),
                'unused' => $usedBy === [],
                'unused_message' => $unusedMessage,
            ];
        }

        return $cards;
    }

    /**
     * Selector data for "Ticket & Progress memakai ...".
     *
     * @return array{current:string,options:list<array{value:string,label:string}>}
     */
    public function ticketRouting(): array
    {
        $slots = $this->slotInstances();

        return [
            'current' => $this->resolve('ticket'),
            'options' => [
                ['value' => $slots['ops'], 'label' => 'Nomor 1 ('.$slots['ops'].')'],
                ['value' => $slots['ticket'], 'label' => 'Nomor 3 ('.$slots['ticket'].')'],
            ],
        ];
    }

    /**
     * Warnings (never blocking) for routing Ticket & Progress through $instance.
     *
     * @return list<string>
     */
    public function ticketRoutingWarnings(string $instance): array
    {
        $warnings = [];
        $status = $this->liveStatus($instance, true);
        if (! $status['connected']) {
            $warnings[] = 'Instance '.$instance.' tidak terhubung (status: '.$status['state'].'). Pesan ticket/progress akan gagal terkirim sampai dipairing.';
        }

        $evo = app(EvolutionApiService::class)->forInstance($instance);
        $deadline = microtime(true) + self::CHECK_BUDGET_SECONDS;
        foreach ($this->ticketGroupJids() as $label => $jid) {
            if (microtime(true) >= $deadline) {
                $warnings[] = 'Keanggotaan '.$instance.' di grup '.$label.' tidak bisa dicek (waktu pemeriksaan habis).';

                continue;
            }
            $m = $evo->groupMembership($jid);
            if ($m['member'] === false) {
                $warnings[] = 'Instance '.$instance.' bukan anggota grup '.$label.($m['subject'] ? ' ('.$m['subject'].')' : '').'.';
            } elseif ($m['member'] === null) {
                $warnings[] = 'Keanggotaan '.$instance.' di grup '.$label.' belum bisa diverifikasi.';
            }
        }

        return $warnings;
    }

    /** @return array<string, string> label => jid */
    private function ticketGroupJids(): array
    {
        $out = [];
        $ticket = (string) (Setting::get('wa_ticket_group_jid') ?: config('services.evolution_api.ticket_group_id', ''));
        if ($ticket !== '') {
            $out['Ticket'] = $ticket;
        }
        $report = Setting::get('report_wa_group_ids', []);
        if (is_array($report)) {
            foreach (array_values(array_filter($report, 'is_string')) as $i => $jid) {
                if ($jid !== '') {
                    $out['Report'.($i > 0 ? ' #'.($i + 1) : '')] = $jid;
                }
            }
        }

        return $out;
    }
}
