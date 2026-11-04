<?php

namespace App\Http\Controllers;

use App\Models\HotspotVoucher;
use App\Services\HotspotVoucherService;
use Illuminate\Http\Request;

class HotspotVoucherController extends Controller
{
    public function index(Request $request)
    {
        $vouchers = HotspotVoucher::query()
            ->with(['router:id,name', 'package:id,name'])
            ->when($request->search, fn ($query, $search) => $query
                ->where('username', 'like', "%{$search}%")
                ->orWhere('batch_code', 'like', "%{$search}%"))
            ->when($request->status && $request->status !== 'all', fn ($query) => $query->where('status', $request->status))
            ->latest()
            ->paginate(min(max((int) $request->input('limit', 50), 1), 100))
            ->withQueryString();

        return response()->json($vouchers);
    }

    public function store(Request $request, HotspotVoucherService $service)
    {
        $validated = $request->validate([
            'router_id' => ['nullable', 'exists:routers,id'],
            'package_id' => ['nullable', 'exists:packages,id'],
            'batch_code' => ['nullable', 'string', 'max:40'],
            'prefix' => ['nullable', 'string', 'max:12'],
            'count' => ['required', 'integer', 'min:1', 'max:1000'],
            'profile' => ['nullable', 'string', 'max:128'],
            'rate_limit' => ['nullable', 'string', 'max:128'],
            'price' => ['nullable', 'integer', 'min:0'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'quota_bytes' => ['nullable', 'integer', 'min:1'],
            'password_same_as_username' => ['nullable', 'boolean'],
            'password_length' => ['nullable', 'integer', 'min:4', 'max:16'],
        ]);

        return response()->json([
            'batch' => $validated['batch_code'] ?? null,
            'vouchers' => $service->generateBatch($validated)->values(),
        ], 201);
    }

    public function sync(HotspotVoucher $voucher, HotspotVoucherService $service)
    {
        return response()->json($service->syncToRadius($voucher));
    }

    public function disable(HotspotVoucher $voucher, HotspotVoucherService $service)
    {
        return response()->json($service->disable($voucher));
    }
}
