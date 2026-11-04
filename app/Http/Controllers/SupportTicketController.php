<?php

namespace App\Http\Controllers;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\SupportTicket;
use App\Services\DeviceRecoveryService;
use App\Support\AreaScope;
use App\Support\OpsTime;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    public function index(Request $request)
    {
        $query = SupportTicket::query();
        AreaScope::applyToTickets($query, $request->user());

        return response()->json($query
            ->with(['customer:id,code,name,phone', 'assignee:id,name'])
            ->when($request->status && $request->status !== 'all', fn ($query) => $query->where('status', $request->status))
            ->when($request->type && $request->type !== 'all', fn ($query) => $query->where('type', $request->type))
            ->latest()
            ->paginate(min(max((int) $request->input('limit', 50), 1), 100)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'type' => ['nullable', 'in:installation,incident,billing,request,other'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ]);

        if (! empty($data['customer_id'])) {
            AreaScope::authorizeCustomer(Customer::withTrashed()->findOrFail($data['customer_id']), $request->user());
        } elseif ($request->user()?->hasAreaScope()) {
            abort(403, 'Tiket harus terhubung ke pelanggan dalam area Anda.');
        }

        if (! empty($data['due_at'])) {
            $data['due_at'] = OpsTime::parseLocal((string) $data['due_at']);
        }

        $ticket = SupportTicket::create($data + ['created_by' => $request->user()?->id]);

        return response()->json($ticket->load(['customer:id,code,name', 'assignee:id,name']), 201);
    }

    public function update(Request $request, SupportTicket $ticket)
    {
        AreaScope::authorizeTicket($ticket, $request->user());
        $oldAssignee = $ticket->assigned_to;

        $data = $request->validate([
            'assigned_to' => ['nullable', 'exists:users,id'],
            'type' => ['nullable', 'in:installation,incident,billing,request,other'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'status' => ['nullable', 'in:open,assigned,in_progress,resolved,closed,cancelled'],
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'due_at' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
            'root_cause' => OutageRootCause::rule(),
            'root_cause_note' => OutageRootCause::noteRule(),
            'root_cause_via' => OutageRootCause::viaRule(),
        ], OutageRootCause::messages());
        [$data, $rootCauseVia] = OutageRootCause::prepareClose($ticket, $data, $data['type'] ?? null, 'api', $request->user());
        $oldRootCause = $ticket->root_cause;

        if (! empty($data['due_at'])) {
            $data['due_at'] = OpsTime::parseLocal((string) $data['due_at']);
        }

        $ticket->update($data);
        OutageRootCause::recordChange($ticket->fresh(), $oldRootCause, $request->user(), 'api', $rootCauseVia);

        if (in_array($ticket->status, ['resolved', 'closed'], true) && ! $ticket->resolved_at) {
            $ticket->update(['resolved_at' => now()]);
        }

        if ((int) $oldAssignee !== (int) $ticket->assigned_to && $request->user()) {
            app(DeviceRecoveryService::class)->syncAssigneeFromTicket($ticket->fresh(), $request->user(), notifyAssignee: true);
        }

        return response()->json($ticket->fresh(['customer:id,code,name', 'assignee:id,name']));
    }
}
