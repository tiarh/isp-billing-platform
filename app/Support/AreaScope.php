<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PsbDraft;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\WaCampaign;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AreaScope
{
    public static function applyToCustomers(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('area_id', $areaIds);
    }

    public static function applyToInvoices(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('customer', fn (Builder $customer) => $customer
                ->withTrashed()
                ->whereIn('area_id', $areaIds));
    }

    public static function applyToTransactions(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('invoice.customer', fn (Builder $customer) => $customer
                ->withTrashed()
                ->whereIn('area_id', $areaIds));
    }

    public static function applyToPaymentGatewayEvents(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereHas('invoice.customer', fn (Builder $customer) => $customer
                ->withTrashed()
                ->whereIn('area_id', $areaIds));
    }

    public static function applyToGatewayTransactions(Builder $query, ?User $user): Builder
    {
        // Same rule as gateway events: scoped admins see their areas' invoices only.
        return self::applyToPaymentGatewayEvents($query, $user);
    }

    public static function applyToCampaigns(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('target_area_id', $areaIds);
    }

    public static function authorizeCustomer(Customer $customer, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        abort_unless(
            $customer->area_id && $user->accessibleAreaIds()->contains($customer->area_id),
            403
        );
    }

    public static function authorizeInvoice(Invoice $invoice, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        $invoice->loadMissing(['customer' => fn ($query) => $query->withTrashed()]);

        abort_unless(
            $invoice->customer?->area_id && $user->accessibleAreaIds()->contains($invoice->customer->area_id),
            403
        );
    }

    public static function authorizeCampaign(WaCampaign $campaign, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        abort_unless(
            $campaign->target_area_id && $user->accessibleAreaIds()->contains($campaign->target_area_id),
            403
        );
    }

    public static function authorizeAreaId(?int $areaId, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        abort_unless($areaId && $user->accessibleAreaIds()->contains($areaId), 403);
    }

    public static function applyToAreas(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        return $query->whereIn('id', $user->accessibleAreaIds()->all());
    }

    /**
     * Tickets scoped via linked customer.area_id (tickets without customer stay visible only to global admins).
     */
    public static function applyToTickets(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->where(function (Builder $q) use ($areaIds) {
                $q->whereHas('customer', fn (Builder $customer) => $customer
                    ->withTrashed()
                    ->whereIn('area_id', $areaIds))
                    ->orWhere(function (Builder $orphan) {
                        // Legacy tickets: no customer_id but may store area on related fields later
                        $orphan->whereNull('customer_id')->whereRaw('1 = 0');
                    });
            });
    }

    public static function authorizeTicket(SupportTicket $ticket, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        $ticket->loadMissing(['customer' => fn ($query) => $query->withTrashed()]);

        abort_unless(
            $ticket->customer?->area_id && $user->accessibleAreaIds()->contains($ticket->customer->area_id),
            403
        );
    }

    /**
     * PSB drafts carry KTP data, phone, coordinates and photos. A scoped
     * (per-area) admin only sees drafts whose target area is theirs; a draft
     * without its own area falls back to its linked customer's area. Drafts
     * with no area at all stay global-admin only (same rule as the KTP media
     * check in SensitiveMediaController).
     */
    public static function applyToPsbDrafts(Builder $query, ?User $user): Builder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();
        if (empty($areaIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($areaIds) {
            $q->whereIn('area_id', $areaIds)
                ->orWhere(function (Builder $fallback) use ($areaIds) {
                    $fallback->whereNull('area_id')
                        ->whereIn('customer_id', Customer::withTrashed()->select('id')->whereIn('area_id', $areaIds));
                });
        });
    }

    /**
     * PSB import staging rows (psb_import_candidates, raw query builder) are
     * scoped by their resolved area. Rows without a resolved area stay
     * global-admin only. $column lets callers qualify it with a table alias.
     */
    public static function applyToPsbImportCandidates(QueryBuilder $query, ?User $user, string $column = 'resolved_area_id'): QueryBuilder
    {
        if (! $user || ! $user->hasAreaScope()) {
            return $query;
        }

        $areaIds = $user->accessibleAreaIds()->all();

        return empty($areaIds)
            ? $query->whereRaw('1 = 0')
            : $query->whereIn($column, $areaIds);
    }

    public static function authorizePsbDraft(PsbDraft $draft, ?User $user): void
    {
        if (! $user || ! $user->hasAreaScope()) {
            return;
        }

        $areaId = $draft->area_id
            ?: ($draft->customer_id ? Customer::withTrashed()->whereKey($draft->customer_id)->value('area_id') : null);

        abort_unless($areaId && $user->accessibleAreaIds()->contains((int) $areaId), 403);
    }
}
