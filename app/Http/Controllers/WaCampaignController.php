<?php

namespace App\Http\Controllers;

use App\Http\Requests\BroadcastStoreRequest;
use App\Jobs\ProcessWaCampaign;
use App\Jobs\SendWaCampaignMessage;
use App\Models\Area;
use App\Models\WaCampaign;
use App\Support\AreaScope;
use App\Support\PerPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Throwable;

class WaCampaignController extends Controller
{
    public function index(Request $request)
    {
        $query = WaCampaign::with('targetArea')->latest()->orderByDesc('id');
        AreaScope::applyToCampaigns($query, $request->user());

        $rawSearch = $request->query('search');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $status = (string) $request->query('status', '');
        if ($search !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where('name', 'like', "%{$escaped}%");
        }
        if (in_array($status, ['processing', 'completed', 'failed', 'draft', 'paused'], true)) {
            $query->where('status', $status);
        }

        $limit = PerPage::from($request, 10);
        $campaigns = $query->paginate($limit)->withQueryString();

        return Inertia::render('Broadcasts/Index', [
            'campaigns' => $campaigns,
            'filters' => ['limit' => $limit, 'search' => $search, 'status' => $status],
        ]);
    }

    public function create()
    {
        $areasQuery = Area::query();
        AreaScope::applyToAreas($areasQuery, request()->user());

        return Inertia::render('Broadcasts/Create', [
            'areas' => $areasQuery->get(),
        ]);
    }

    public function store(BroadcastStoreRequest $request)
    {
        $validated = $request->validated();
        if ($request->user()->hasAreaScope()) {
            AreaScope::authorizeAreaId(isset($validated['target_area_id']) ? (int) $validated['target_area_id'] : null, $request->user());
        }

        $campaign = WaCampaign::create([...$validated, 'status' => 'processing']);

        try {
            ProcessWaCampaign::dispatch($campaign);
        } catch (Throwable) {
            $campaign->update(['status' => 'failed']);

            return back()->withErrors(['broadcast' => 'Broadcast gagal masuk antrean. Periksa worker WA.']);
        }

        return redirect()->route('broadcasts.index')->with('success', 'Campaign created and is processing.');
    }

    public function start(WaCampaign $campaign)
    {
        AreaScope::authorizeCampaign($campaign, request()->user());

        $started = DB::transaction(function () use ($campaign): bool {
            $locked = WaCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'draft') {
                return false;
            }

            $locked->update(['status' => 'processing']);

            return true;
        });

        if ($started) {
            try {
                ProcessWaCampaign::dispatch($campaign->fresh());
            } catch (Throwable) {
                WaCampaign::query()
                    ->whereKey($campaign->id)
                    ->where('status', 'processing')
                    ->update(['status' => 'failed']);

                return back()->withErrors(['broadcast' => 'Broadcast gagal masuk antrean. Periksa worker WA.']);
            }
        }

        return back()->with('success', $started
            ? 'Broadcast masuk antrean pemrosesan.'
            : 'Broadcast sudah diproses atau tidak lagi berstatus draft.');
    }

    public function show(WaCampaign $campaign)
    {
        AreaScope::authorizeCampaign($campaign, request()->user());

        $campaign->load('targetArea');
        $rawSearch = request()->query('search');
        $search = is_string($rawSearch) ? trim($rawSearch) : '';
        $limit = PerPage::from(request(), 20);
        $recipients = $campaign->recipients()
            ->with('customer')
            ->when($search !== '', function ($query) use ($search) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $query->where(function ($inner) use ($escaped) {
                    $inner->where('phone_number', 'like', "%{$escaped}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$escaped}%"));
                });
            })
            ->when(request()->user()->hasAreaScope(), function ($query) {
                $areaIds = request()->user()->accessibleAreaIds()->all();
                empty($areaIds)
                    ? $query->whereRaw('1 = 0')
                    : $query->whereHas('customer', fn ($customer) => $customer->whereIn('area_id', $areaIds));
            })
            ->orderBy('id', 'desc')
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Broadcasts/Show', [
            'campaign' => $campaign,
            'recipients' => $recipients,
            'filters' => ['limit' => $limit, 'search' => $search],
        ]);
    }

    public function retryFailed(WaCampaign $campaign)
    {
        AreaScope::authorizeCampaign($campaign, request()->user());

        $queued = 0;
        $recipientIdsToQueue = [];

        DB::transaction(function () use ($campaign, &$queued, &$recipientIdsToQueue) {
            $locked = WaCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            // A dry-run guard leaves recipients pending with an error message and
            // pauses the campaign. Treat only those known guard outcomes as
            // retryable; do not accidentally requeue unrelated pending rows.
            $retryable = $locked->recipients()->where(function ($query) {
                $query->where('status', 'failed')
                    ->orWhere(function ($pending) {
                        $pending->where('status', 'pending')
                            ->where(function ($guard) {
                                $guard->where('error_message', 'like', 'Dry-run: broadcast WA billing belum di-enable.%')
                                    ->orWhere('error_message', 'like', 'Broadcast dijeda: WA billing state=%');
                            });
                    });
            });

            $retryableIds = $retryable->pluck('id');
            if ($retryableIds->isEmpty()) {
                return;
            }

            $failedRetried = $locked->recipients()
                ->whereIn('id', $retryableIds)
                ->where('status', 'failed')
                ->count();

            $updated = $locked->recipients()
                ->whereIn('id', $retryableIds)
                ->where(function ($query) {
                    $query->where('status', 'failed')
                        ->orWhere(function ($pending) {
                            $pending->where('status', 'pending')
                                ->where(function ($guard) {
                                    $guard->where('error_message', 'like', 'Dry-run: broadcast WA billing belum di-enable.%')
                                        ->orWhere('error_message', 'like', 'Broadcast dijeda: WA billing state=%');
                                });
                        });
                })
                ->update([
                    'status' => 'pending',
                    'error_message' => null,
                    'sent_at' => null,
                ]);

            if ($updated === 0) {
                return;
            }

            $locked->update([
                'failed_count' => max(0, $locked->failed_count - $failedRetried),
                'status' => 'processing',
            ]);

            $queued = $updated;
            $recipientIdsToQueue = $locked->recipients()
                ->whereIn('id', $retryableIds)
                ->where('status', 'pending')
                ->pluck('id')
                ->all();
        });

        $delaySeconds = 0;
        foreach ($recipientIdsToQueue as $recipientId) {
            $recipient = $campaign->recipients()->find($recipientId);
            if (! $recipient || $recipient->status !== 'pending') {
                continue;
            }
            $delaySeconds += rand(4, 9);
            SendWaCampaignMessage::dispatch($recipient)->delay(now()->addSeconds($delaySeconds));
        }

        if ($queued === 0) {
            return back()->with('success', 'No retryable messages to retry.');
        }

        return back()->with('success', "Retrying {$queued} message(s). They have been queued.");
    }
}
