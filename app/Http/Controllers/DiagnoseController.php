<?php

namespace App\Http\Controllers;

use App\Exceptions\DiagnoseLimitException;
use App\Models\Customer;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use App\Services\Outage\Diagnosis\DiagnoseNowService;
use App\Services\Outage\Diagnosis\DiagnosisPresenter;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;
use App\Support\AreaScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * RCA-4 "Cek sekarang" endpoints (JSON). start -> probe x3 (parallel from the browser) -> finish. Role gate =
 * the panel roles setting; the flag diagnosis_check_now_enabled = 0 answers 403. Read-only towards devices.
 */
class DiagnoseController extends Controller
{
    /** DB-only diagnosis of a customer (not stored): shown on the ticket form right after a customer is picked. */
    public function customer(Request $request, Customer $customer, OutageDiagnoser $diagnoser): JsonResponse
    {
        $this->authorizeRole($request);
        AreaScope::authorizeCustomer($customer, $request->user());
        $stored = OutageSettings::diagnosisEnabled() ? $diagnoser->preview($customer) : null;

        return response()->json([
            'diagnosis' => $stored,
            'checkNowEnabled' => OutageSettings::diagnosisCheckNowEnabled() && OutageSettings::diagnosisCheckNowAllowedTo((string) $request->user()->role),
            'cooldownMinutes' => OutageSettings::diagnoseNowCustomerCooldownMinutes(),
            'timeouts' => (array) config('diagnosis.timeouts'),
        ]);
    }

    public function start(Request $request, DiagnoseNowService $service): JsonResponse
    {
        $this->authorizeCheckNowRole($request);
        $data = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
        ]);
        $customer = Customer::query()->findOrFail($data['customer_id']);
        AreaScope::authorizeCustomer($customer, $request->user());
        $ticketId = null;
        if (! empty($data['ticket_id'])) {
            $ticket = SupportTicket::query()->findOrFail($data['ticket_id']);
            AreaScope::authorizeTicket($ticket, $request->user());
            abort_unless((int) $ticket->customer_id === (int) $customer->id, 422);
            $ticketId = (int) $ticket->id;
        }

        try {
            $check = $service->start($request->user(), $customer, $ticketId);
        } catch (DiagnoseLimitException $e) {
            return response()->json(['message' => $e->getMessage(), 'retry_after' => $e->retryAfter], $e->status);
        }

        return response()->json([
            'check' => (int) $check->id,
            'timeouts' => (array) config('diagnosis.timeouts'),
            'probes' => DiagnoseNowService::PROBES,
        ]);
    }

    public function probe(Request $request, OutageDiagnosis $diagnosis, string $probe, DiagnoseNowService $service): JsonResponse
    {
        $this->authorizeCheck($request, $diagnosis);
        abort_unless(in_array($probe, DiagnoseNowService::PROBES, true), 404);

        return response()->json($service->probe($diagnosis, $probe, $request->user()));
    }

    public function finish(Request $request, OutageDiagnosis $diagnosis, DiagnoseNowService $service): JsonResponse
    {
        $this->authorizeCheck($request, $diagnosis);
        $done = $service->finish($diagnosis);

        return response()->json([
            'diagnosis' => DiagnosisPresenter::payload($done),
            'failed' => $done->state === OutageDiagnosis::STATE_FAILED,
        ]);
    }

    public function show(Request $request, OutageDiagnosis $diagnosis): JsonResponse
    {
        $this->authorizeRole($request);
        abort_unless((int) $diagnosis->created_by === (int) $request->user()->id || $request->user()->isAdminLike(), 403);
        $customer = Customer::query()->find($diagnosis->customer_id);
        abort_if($customer === null, 404);
        AreaScope::authorizeCustomer($customer, $request->user());

        return response()->json(['diagnosis' => DiagnosisPresenter::payload($diagnosis)]);
    }

    private function authorizeRole(Request $request): void
    {
        abort_unless(DiagnosisPresenter::visibleTo($request->user()), 403);
    }

    /** "Cek sekarang" has its own role list (default admin only), independent of who sees the panel. */
    private function authorizeCheckNowRole(Request $request): void
    {
        $this->authorizeRole($request);
        abort_unless(OutageSettings::diagnosisCheckNowAllowedTo((string) $request->user()->role), 403);
    }

    /** The check must be the caller's own, still running and not older than a few minutes. */
    private function authorizeCheck(Request $request, OutageDiagnosis $diagnosis): void
    {
        $this->authorizeCheckNowRole($request);
        abort_unless((int) $diagnosis->created_by === (int) $request->user()->id, 403);
        abort_unless(OutageSettings::diagnosisCheckNowEnabled(), 403);
        abort_if($diagnosis->state !== OutageDiagnosis::STATE_RUNNING || DiagnoseNowService::stale($diagnosis), 410, 'Cek sudah selesai.');
    }
}
