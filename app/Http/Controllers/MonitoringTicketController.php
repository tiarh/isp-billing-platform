<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\User;
use App\Services\MonitoringTicketTriageService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MonitoringTicketController extends Controller
{
    public function acknowledge(Request $request, SupportTicket $ticket, MonitoringTicketTriageService $triage): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $triage->acknowledge($ticket, $request->user(), $data['reason']);

        return back()->with('success', 'Incident monitoring di-acknowledge.');
    }

    public function assign(Request $request, SupportTicket $ticket, MonitoringTicketTriageService $triage): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate([
            'assignee_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $assignee = User::query()->findOrFail($data['assignee_id']);
        $triage->assign($ticket, $request->user(), $assignee, $data['reason']);

        return back()->with('success', 'Incident monitoring di-assign.');
    }

    public function snooze(Request $request, SupportTicket $ticket, MonitoringTicketTriageService $triage): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate([
            'until' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $triage->snooze($ticket, $request->user(), Carbon::parse($data['until']), $data['reason']);

        return back()->with('success', 'Incident monitoring di-snooze.');
    }

    public function falsePositive(Request $request, SupportTicket $ticket, MonitoringTicketTriageService $triage): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $triage->markFalsePositive($ticket, $request->user(), $data['reason']);

        return back()->with('success', 'Incident ditandai false positive.');
    }

    public function verifyRecovery(Request $request, SupportTicket $ticket, MonitoringTicketTriageService $triage): RedirectResponse
    {
        Gate::authorize('view', $ticket);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $triage->verifyRecovery($ticket, $request->user(), $data['reason']);

        return back()->with('success', 'Recovery incident diverifikasi.');
    }
}
