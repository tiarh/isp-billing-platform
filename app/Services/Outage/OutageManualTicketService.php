<?php

namespace App\Services\Outage;

use App\Enums\OutageRootCause;
use App\Jobs\SendOutageTicketReportJob;
use App\Models\Customer;
use App\Models\Olt;
use App\Models\OltOnu;
use App\Models\OutageDiagnosis;
use App\Models\OutageSignal;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\User;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Report\ReportGroupRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Manual "Buat tiket" of Deteksi Gangguan. Writes ONE support_tickets row and links it to the episode. It does
 * not use the ticket form's store path or the notification service: no customer, technician or ticket-group WA.
 * The only message is one Report-group text (SendOutageTicketReportJob). The read model / controller of the tab
 * stay read-only.
 */
class OutageManualTicketService
{
    public const FINAL_STATUSES = ['resolved', 'closed', 'cancelled'];

    public const BLOCKED_CUSTOMER_STATUSES = ['isolated', 'inactive', 'terminated'];

    /** affected_customer_ids kept on a mass ticket. */
    public const META_IDS_CAP = 300;

    /**
     * Preview of what a click would create (no writes).
     *
     * @return array<string,mixed>
     */
    public function preview(OutageSignal $s): array
    {
        $existing = $this->existingTicket($s);
        if ($existing !== null) {
            return ['ticket' => $this->ticketInfo($existing), 'created' => false];
        }
        $this->assertEligible($s);
        $ctx = $this->context($s);
        $built = OutageTicketEvidence::build($s, $ctx);
        $registry = new ReportGroupRegistry;

        return [
            'ticket' => null,
            'kind' => $built['kind'],
            'title' => $built['title'],
            'customer_name' => $built['kind'] === 'single' ? $built['customer_name'] : null,
            'priority' => $built['priority'],
            'affected_count' => $built['affected_count'],
            'lines' => $built['lines'],
            'report_group_set' => $registry->sendableGroupJids() !== [],
        ];
    }

    /**
     * Create (or return the existing) ticket of an episode. Idempotent: row lock + whereNull(ticket_id) guard.
     *
     * @return array{ticket:SupportTicket,created:bool}
     */
    public function create(OutageSignal $signal, User $user, ?string $note, ?string $priority): array
    {
        $ctx = null;
        $built = null;
        $created = false;

        $ticket = DB::transaction(function () use ($signal, $user, $note, $priority, &$ctx, &$built, &$created): SupportTicket {
            /** @var OutageSignal $s */
            $s = OutageSignal::query()->whereKey($signal->getKey())->lockForUpdate()->firstOrFail();

            $existing = $this->existingTicket($s);
            if ($existing !== null) {
                if ($s->ticket_id === null) {
                    // meta repair: the ticket was saved but the link was not (should not happen, cheap to heal).
                    DB::table('outage_signals')->where('id', $s->id)->whereNull('ticket_id')->update(['ticket_id' => $existing->id, 'updated_at' => now()]);
                }

                return $existing;
            }

            $this->assertEligible($s);
            $ctx = $this->context($s);
            $built = OutageTicketEvidence::build($s, $ctx);
            $customer = (array) ($ctx['customer'] ?? []);

            if ($built['kind'] === 'single') {
                // Serialise clicks on different open episodes of the same customer (one open ticket per customer).
                Customer::query()->whereKey($customer['id'])->lockForUpdate()->first(['id']);
                $open = SupportTicket::query()->where('customer_id', $customer['id'])->whereNotIn('status', self::FINAL_STATUSES)->orderBy('id')->first(['id']);
                if ($open !== null) {
                    throw new OutageManualTicketException('Pelanggan ini sudah punya tiket terbuka #'.$open->id.'.', 409, (int) $open->id);
                }
            }

            $ticket = new SupportTicket([
                'customer_id' => $built['kind'] === 'single' ? $customer['id'] : null,
                'customer_name' => $built['customer_name'],
                'customer_phone' => $built['kind'] === 'single' ? ($customer['phone'] ?? null) : null,
                'customer_coords' => $built['kind'] === 'single' ? ($customer['coords'] ?? null) : null,
                'created_by' => $user->id,
                'type' => 'incident',
                'status' => 'open',
                'priority' => $priority ?: $built['priority'],
                'gangguan' => $built['title'],
                'keterangan' => OutageTicketEvidence::keterangan($built['lines'], $note),
                'meta' => $this->meta($s, $ctx, $built),
            ]);
            $ticket->forceFill(['area_id' => $ctx['area_id'] ?? null]);
            $ticket->save();

            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => $user->id,
                'action' => 'created',
                'description' => 'Tiket dibuat dari Deteksi Gangguan (kejadian #'.$s->id.')',
            ]);

            $linked = DB::table('outage_signals')->where('id', $s->id)->whereNull('ticket_id')->update(['ticket_id' => $ticket->id, 'updated_at' => now()]);
            if ($linked !== 1) {
                throw new OutageManualTicketException('Gagal membuat tiket. Coba lagi.', 409);
            }
            $created = true;

            return $ticket;
        });

        if ($created && $ctx !== null && $built !== null) {
            $this->afterCreate($signal->refresh(), $ticket, $user, $ctx, $built);
        }

        return ['ticket' => $ticket, 'created' => $created];
    }

    /** @return array<string,mixed> */
    public function ticketInfo(SupportTicket $t): array
    {
        return [
            'id' => (int) $t->id,
            'code' => $t->code,
            'status' => $t->status,
            'url' => route('tickets.show', $t->id),
        ];
    }

    /**
     * Ticket that already belongs to the episode: the linked one, or (repair) one carrying meta.outage_signal_id.
     */
    public function existingTicket(OutageSignal $s): ?SupportTicket
    {
        if ($s->ticket_id !== null) {
            $t = SupportTicket::query()->find($s->ticket_id);
            if ($t !== null) {
                return $t;
            }
        }

        return SupportTicket::query()
            ->where('meta->source', 'deteksi_manual')
            ->where('meta->outage_signal_id', (int) $s->id)
            ->orderBy('id')
            ->first();
    }

    private function assertEligible(OutageSignal $s): void
    {
        $kind = OutageTicketEvidence::kind($s);
        if ($kind === null) {
            throw new OutageManualTicketException('Jenis kejadian ini tidak bisa dibuatkan tiket.');
        }
        if (! in_array($s->state, [OutageSignal::STATE_OPEN, OutageSignal::STATE_CONFIRMED], true)) {
            throw new OutageManualTicketException('Kejadian ini sudah selesai.');
        }
        if ($kind === 'single') {
            $c = $s->customer_id === null ? null : Customer::query()->find($s->customer_id, ['id', 'status']);
            if ($c === null) {
                throw new OutageManualTicketException('Data pelanggan tidak ditemukan.');
            }
            if (in_array((string) $c->status, self::BLOCKED_CUSTOMER_STATUSES, true)) {
                throw new OutageManualTicketException('Pelanggan ini sudah tidak aktif atau sedang diisolir.');
            }
        }
    }

    /**
     * Plain read-only data for the text builder (bounded queries).
     *
     * @return array<string,mixed>
     */
    public function context(OutageSignal $s): array
    {
        $payload = (array) $s->payload;
        $kind = OutageTicketEvidence::kind($s);
        $ctx = [
            'olt_name' => $s->olt_id !== null ? (Olt::query()->whereKey($s->olt_id)->value('name') ?? 'OLT #'.$s->olt_id) : null,
            'pon' => $s->pon_port,
            'onu_ref' => null,
            'odp' => $s->odp_label,
            'router_name' => null,
            'customer' => null,
            'affected' => [],
            'affected_total' => (int) $s->affected_count,
            'rx' => null,
            'history' => [],
            'pon_total' => isset($payload['pon_total']) ? (int) $payload['pon_total'] : null,
            'segment' => is_array($payload['segment_estimate_m'] ?? null) ? $payload['segment_estimate_m'] : null,
            'cause_text' => OutageCauseText::forPayload($payload)['label'] ?? null,
            'diagnosed_label' => $s->diagnosed_cause === null ? null : OutageRootCause::labelFor((string) $s->diagnosed_cause),
            'area_id' => null,
            'affected_ids' => [],
        ];

        if (isset($payload['router_id'])) {
            $ctx['router_name'] = Router::query()->whereKey((int) $payload['router_id'])->value('name');
        }

        if ($kind === 'single' && $s->customer_id !== null) {
            $c = Customer::query()->find($s->customer_id);
            if ($c !== null) {
                $coords = $c->geo_lat && $c->geo_long ? $c->geo_lat.','.$c->geo_long : null;
                $ctx['customer'] = ['id' => (int) $c->id, 'code' => $c->code, 'name' => $c->name, 'area_id' => $c->area_id, 'phone' => $c->phone, 'coords' => $coords];
                $ctx['area_id'] = $c->area_id ? (int) $c->area_id : null;
                $ctx['onu_ref'] = $c->olt_port_label;
                $ctx['odp'] = $s->odp_label ?: $c->odp_name;
                $ctx['rx'] = $this->rx($s, $c);
                $ctx['history'] = (new OutageShadowEpisodeReadModel)->history($s);
            }
        }

        if ($kind === 'mass') {
            $ids = $this->affectedIds($s);
            $ctx['affected_ids'] = $ids;
            if ($ids !== []) {
                $firstIds = array_slice($ids, 0, OutageTicketEvidence::LIST_LIMIT);
                $rows = Customer::query()->whereIn('id', $firstIds)->get(['id', 'code', 'name'])->keyBy('id');
                foreach ($firstIds as $id) {
                    if (isset($rows[$id])) {
                        $ctx['affected'][] = ['code' => $rows[$id]->code, 'name' => $rows[$id]->name];
                    }
                }
                $areas = Customer::query()->whereIn('id', $ids)->whereNotNull('area_id')->pluck('area_id')->countBy()->sortDesc();
                $ctx['area_id'] = $areas->isEmpty() ? null : (int) $areas->keys()->first();
            }
        }

        return $ctx;
    }

    /** @return array{dbm:float,at:?string}|null */
    private function rx(OutageSignal $s, Customer $c): ?array
    {
        if ($s->signal_type === 'weak_rx') {
            $p = (array) $s->payload;
            $own = $p['rx_dbm'] ?? $p['worst_dbm'] ?? null;
            if (is_numeric($own)) {
                return ['dbm' => (float) $own, 'at' => null];
            }
        }
        $ref = $c->olt_id !== null ? OutageDetector::normalizeRef((string) $c->olt_port_label) : null;
        if ($ref === null) {
            return null;
        }
        $onu = OltOnu::query()->where('olt_id', $c->olt_id)->whereIn('onu_ref', [$ref, 'gpon-onu_'.$ref])->whereNotNull('rx_power_dbm')
            ->orderByDesc('rx_collected_at')->first(['rx_power_dbm', 'rx_collected_at']);

        return $onu === null ? null : ['dbm' => (float) $onu->rx_power_dbm, 'at' => $onu->rx_collected_at?->toIso8601String()];
    }

    /** @return list<int> */
    private function affectedIds(OutageSignal $s): array
    {
        $affected = $s->affected_customer_ids;
        if (! is_array($affected) || $affected === []) {
            $affected = (array) (((array) $s->payload)['affected_customer_ids'] ?? []);
        }
        $ids = [];
        foreach ($affected as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_slice(array_values($ids), 0, self::META_IDS_CAP);
    }

    /**
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $built
     * @return array<string,mixed>
     */
    private function meta(OutageSignal $s, array $ctx, array $built): array
    {
        $meta = [
            'source' => 'deteksi_manual',
            'outage_signal_id' => (int) $s->id,
            'signal_type' => $s->signal_type,
            'cause' => ((array) $s->payload)['cause'] ?? null,
            'scope_key' => $s->scope_key,
            'episode_snapshot' => [
                'state_at_click' => $s->state,
                'opened_at' => $s->opened_at?->toIso8601String(),
                'confirmed_at' => $s->confirmed_at?->toIso8601String(),
                'would_action' => $s->would_action,
                'suppressed_reason' => $s->suppressed_reason,
                'cause_text' => $ctx['cause_text'] ?? null,
                'diagnosed_cause' => $s->diagnosed_cause,
                'cause_code' => $s->cause_code,
                'affected_count' => (int) $s->affected_count,
            ],
            'customer_wa' => 'never',
            'created_via' => 'monitoring_deteksi',
        ];
        if ($built['kind'] === 'mass') {
            $meta['affected_customer_ids'] = $ctx['affected_ids'] ?? [];
        }

        return $meta;
    }

    /**
     * Everything that must not roll the ticket back: activity log, stored diagnosis, the one Report message.
     *
     * @param  array<string,mixed>  $ctx
     * @param  array<string,mixed>  $built
     */
    private function afterCreate(OutageSignal $s, SupportTicket $ticket, User $user, array $ctx, array $built): void
    {
        try {
            activity('outage')
                ->withProperties(['outage_signal_id' => $s->id, 'ticket_id' => $ticket->id, 'signal_type' => $s->signal_type, 'state' => $s->state, 'actor' => $user->name])
                ->event('manual_ticket_created')
                ->log('manual_ticket_created');
        } catch (\Throwable $e) {
            Log::warning('[OutageManualTicket] activity log failed', ['ticket_id' => $ticket->id, 'exception_class' => $e::class]);
        }

        if ($built['kind'] === 'single' && $ticket->customer_id !== null) {
            try {
                if (OutageSettings::diagnosisEnabled() && Schema::hasTable('outage_diagnoses')) {
                    $customer = Customer::query()->find($ticket->customer_id);
                    if ($customer !== null) {
                        app(OutageDiagnoser::class)->diagnose($customer, OutageDiagnosis::TRIGGER_TICKET_OPEN, ['ticket_id' => $ticket->id, 'created_by' => $user->id]);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[OutageManualTicket] ticket_open diagnosis failed', ['ticket_id' => $ticket->id, 'exception_class' => $e::class]);
            }
        }

        try {
            if ((new ReportGroupRegistry)->sendableGroupJids() !== []) {
                $creator = trim($user->name.' ('.strtoupper((string) $user->role).')');
                $text = OutageTicketEvidence::reportMessage($s, $ctx, (int) $ticket->id, (string) $ticket->code, $creator);
                // We are already past our own transaction. A caller inside an outer transaction must not dispatch
                // before the commit (the job would not find the ticket): defer it then.
                $pending = SendOutageTicketReportJob::dispatch((int) $ticket->id, $text);
                if (DB::transactionLevel() > 0) {
                    $pending->afterCommit();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[OutageManualTicket] report dispatch failed', ['ticket_id' => $ticket->id, 'exception_class' => $e::class]);
        }
    }
}
