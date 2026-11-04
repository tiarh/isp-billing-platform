<?php

namespace App\Services\Search;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Olt;
use App\Models\Router;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\AreaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Global (Ctrl+K) search. Every group applies the caller's scope FIRST, selects
 * only the columns it needs and never returns NIK, address, IP/host or credentials.
 * Customer results intentionally show the phone number (owner decision Q15).
 */
class GlobalSearchService
{
    public const LIMIT = 5;

    /**
     * @return list<array{key:string,label:string,items:list<array<string,mixed>>}>
     */
    public function search(User $user, string $q): array
    {
        $q = trim($q);
        $groups = [];

        $definitions = [
            ['customers', 'Pelanggan', fn () => $this->customers($user, $q)],
            ['invoices', 'Invoice', fn () => $this->invoices($user, $q)],
            ['tickets', 'Tiket', fn () => $this->tickets($user, $q)],
        ];
        if ($user->isGlobalAdmin()) {
            $definitions[] = ['network', 'Jaringan', fn () => $this->network($q)];
        }

        foreach ($definitions as [$key, $label, $run]) {
            try {
                $items = $run();
            } catch (Throwable $e) {
                // Never log the term: it can be a name or phone number.
                Log::warning('global_search_group_failed', ['group' => $key]);

                continue;
            }
            if ($items !== []) {
                $groups[] = ['key' => $key, 'label' => $label, 'items' => $items];
            }
        }

        return $groups;
    }

    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function customers(User $user, string $q): array
    {
        $like = self::escapeLike($q);
        $terms = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);

        // Same scope as the Pelanggan list: MikroTik-imported (IMP-) customers are excluded.
        $query = Customer::withTrashed()
            ->ebilling()
            ->select(['id', 'code', 'name', 'phone', 'pppoe_user', 'onu_serial', 'area_id', 'status', 'deleted_at']);
        AreaScope::applyToCustomers($query, $user);

        $isPhoneLike = (bool) preg_match('/^[\d\s\-\.+]+$/', $q);
        $phoneTail = null;
        if ($isPhoneLike) {
            $digits = preg_replace('/\D/', '', $q) ?? '';
            $digits = preg_replace('/^(62|0)/', '', $digits) ?? '';
            $phoneTail = strlen($digits) >= 4 ? self::escapeLike($digits) : null;
        }

        $query->where(function (Builder $w) use ($like, $terms, $q, $phoneTail) {
            $w->where('code', 'like', $like.'%')
                ->orWhere('pppoe_user', 'like', $like.'%');
            if (mb_strlen($q) >= 4) {
                $w->orWhere('onu_serial', 'like', $like.'%');
            }
            if ($phoneTail !== null) {
                $w->orWhere('phone', 'like', '%'.$phoneTail.'%');
            }
            $w->orWhere(function (Builder $name) use ($terms) {
                foreach ($terms as $term) {
                    $name->where('name', 'like', '%'.self::escapeLike($term).'%');
                }
            });
        });

        $rows = $query
            ->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END')
            ->orderByRaw(
                'CASE WHEN code = ? OR pppoe_user = ? OR onu_serial = ? THEN 0 WHEN code LIKE ? OR pppoe_user LIKE ? THEN 1 ELSE 2 END',
                [$q, $q, $q, $like.'%', $like.'%']
            )
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        $areaNames = Area::query()
            ->whereIn('id', $rows->pluck('area_id')->filter()->unique()->all())
            ->pluck('name', 'id');

        return $rows->map(function (Customer $c) use ($areaNames) {
            $archived = $c->deleted_at !== null;
            $parts = array_values(array_filter([
                $c->code,
                $areaNames[$c->area_id] ?? null,
                $c->phone,
            ], fn ($v) => filled($v)));

            return [
                'id' => 'customer-'.$c->id,
                'type' => 'customer',
                'title' => (string) $c->name,
                'subtitle' => implode(' · ', $parts),
                'badge' => $archived
                    ? ['label' => 'Arsip', 'tone' => 'neutral']
                    : $this->customerBadge((string) $c->status),
                'href' => $archived
                    ? route('customers.index', ['archive' => 1, 'search' => $c->code ?: ($c->pppoe_user ?: $c->name)], false)
                    : route('customers.show', $c->id, false),
            ];
        })->all();
    }

    /**
     * @return array{label:string,tone:string}
     */
    private function customerBadge(string $status): array
    {
        return match ($status) {
            'active' => ['label' => 'Aktif', 'tone' => 'up'],
            'isolated' => ['label' => 'Isolir', 'tone' => 'down'],
            // Mirrors resources/js/lib/customerLabels.ts
            'pending_installation' => ['label' => 'Menunggu Instalasi', 'tone' => 'degraded'],
            'inactive' => ['label' => 'Tidak Aktif', 'tone' => 'neutral'],
            'terminated' => ['label' => 'Dihentikan', 'tone' => 'neutral'],
            default => ['label' => str_replace('_', ' ', $status), 'tone' => 'neutral'],
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function invoices(User $user, string $q): array
    {
        if (mb_strlen($q) < 4 || ! preg_match('/[\d\-]|^inv/i', $q)) {
            return [];
        }
        $like = self::escapeLike($q);

        $query = Invoice::query()
            ->select(['id', 'code', 'customer_id', 'period', 'amount', 'status']);
        AreaScope::applyToInvoices($query, $user);

        $rows = $query
            ->where('code', 'like', '%'.$like.'%')
            ->with(['customer' => fn ($c) => $c->withTrashed()->select(['id', 'name'])])
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$q])
            ->orderByDesc('period')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $rows->map(function (Invoice $i) {
            $status = (string) $i->status;
            $badge = match ($status) {
                'paid' => ['label' => 'Lunas', 'tone' => 'up'],
                'unpaid' => ['label' => 'Belum Lunas', 'tone' => 'degraded'],
                'void' => ['label' => 'Batal', 'tone' => 'neutral'],
                default => ['label' => str_replace('_', ' ', $status), 'tone' => 'neutral'],
            };
            $period = $i->period ? $i->period->format('m/Y') : null;

            return [
                'id' => 'invoice-'.$i->id,
                'type' => 'invoice',
                'title' => (string) $i->code,
                'subtitle' => implode(' · ', array_values(array_filter([
                    $i->customer?->name,
                    $period,
                    'Rp '.number_format((float) $i->amount, 0, ',', '.'),
                ], fn ($v) => filled($v)))),
                'badge' => $badge,
                'href' => route('invoices.show', $i->id, false),
            ];
        })->all();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function tickets(User $user, string $q): array
    {
        $like = self::escapeLike($q);

        $query = SupportTicket::query()
            ->select(['id', 'code', 'subject', 'status', 'customer_id', 'assigned_to']);
        AreaScope::applyToTickets($query, $user);
        if ($user->isTeknisi()) {
            $query->where('assigned_to', $user->id);
        }

        $rows = $query
            ->where(function (Builder $w) use ($like) {
                $w->where('code', 'like', $like.'%')
                    ->orWhere('subject', 'like', '%'.$like.'%');
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 WHEN code LIKE ? THEN 1 ELSE 2 END', [$q, $like.'%'])
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return $rows->map(function (SupportTicket $t) {
            $status = (string) $t->status;
            $label = $t->getStatusLabel();
            $badge = match ($status) {
                'resolved', 'closed' => ['label' => $label, 'tone' => 'up'],
                'cancelled' => ['label' => $label, 'tone' => 'neutral'],
                'open' => ['label' => $label, 'tone' => 'degraded'],
                default => ['label' => $label, 'tone' => 'info'],
            };

            return [
                'id' => 'ticket-'.$t->id,
                'type' => 'ticket',
                'title' => (string) ($t->code ?: ('Tiket #'.$t->id)),
                'subtitle' => mb_strimwidth((string) $t->subject, 0, 80, '…'),
                'badge' => $badge,
                'href' => route('tickets.show', $t->id, false),
            ];
        })->all();
    }

    /**
     * Global admins only. Name/code only; never IP, host or credentials.
     *
     * @return list<array<string,mixed>>
     */
    private function network(string $q): array
    {
        $like = self::escapeLike($q);
        $items = [];

        foreach (Router::query()->select(['id', 'name'])->where('name', 'like', '%'.$like.'%')->orderBy('name')->limit(self::LIMIT)->get() as $r) {
            $items[] = [
                'id' => 'router-'.$r->id,
                'type' => 'router',
                'title' => (string) $r->name,
                'subtitle' => 'MikroTik',
                'badge' => null,
                'href' => route('routers.show', $r->id, false),
            ];
        }
        foreach (Olt::query()->select(['id', 'name', 'code'])
            ->where(fn ($w) => $w->where('name', 'like', '%'.$like.'%')->orWhere('code', 'like', $like.'%'))
            ->orderBy('name')->limit(self::LIMIT)->get() as $o) {
            $items[] = [
                'id' => 'olt-'.$o->id,
                'type' => 'olt',
                'title' => (string) $o->name,
                'subtitle' => trim('OLT '.($o->code ?? '')),
                'badge' => null,
                'href' => route('olts.show', $o->id, false),
            ];
        }
        foreach (Area::query()->select(['id', 'name', 'code'])
            ->where(fn ($w) => $w->where('name', 'like', '%'.$like.'%')->orWhere('code', 'like', $like.'%'))
            ->orderBy('name')->limit(self::LIMIT)->get() as $a) {
            $items[] = [
                'id' => 'area-'.$a->id,
                'type' => 'area',
                'title' => (string) $a->name,
                'subtitle' => trim('Area '.($a->code ?? '')),
                'badge' => null,
                'href' => route('areas.show', $a->id, false),
            ];
        }

        return array_slice($items, 0, self::LIMIT * 3);
    }
}
