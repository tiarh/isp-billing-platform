<?php

namespace App\Http\Controllers;

use App\Models\ResellerCommission;
use Illuminate\Http\Request;

class ResellerCommissionController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(ResellerCommission::query()
            ->with(['reseller:id,name'])
            ->when($request->reseller_id, fn ($query, $id) => $query->where('reseller_id', $id))
            ->when($request->status && $request->status !== 'all', fn ($query) => $query->where('status', $request->status))
            ->latest()
            ->paginate(min(max((int) $request->input('limit', 50), 1), 100)));
    }

    public function store(Request $request)
    {
        $commission = ResellerCommission::create($request->validate([
            'reseller_id' => ['required', 'exists:users,id'],
            'customer_id' => ['nullable', 'exists:customers,id'],
            'invoice_id' => ['nullable', 'exists:invoices,id'],
            'transaction_id' => ['nullable', 'exists:transactions,id'],
            'period' => ['required', 'date_format:Y-m'],
            'base_amount' => ['required', 'numeric', 'min:0'],
            'commission_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:pending,approved,paid,void'],
            'meta' => ['nullable', 'array'],
        ]));

        return response()->json($commission, 201);
    }

    public function markPaid(ResellerCommission $commission)
    {
        $commission->update(['status' => 'paid', 'paid_at' => now()]);

        return response()->json($commission);
    }
}
