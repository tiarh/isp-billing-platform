<?php

namespace App\Http\Controllers;

use App\Enums\OutageRootCause;
use App\Models\Customer;
use App\Models\DeviceRecoveryCase;
use App\Models\MonitoringAlertEvent;
use App\Models\OutageDiagnosis;
use App\Models\SupportTicket;
use App\Models\TicketActivity;
use App\Models\TicketComment;
use App\Models\User;
use App\Services\DeviceRecoveryService;
use App\Services\NotificationService;
use App\Services\Outage\Diagnosis\DiagnosisPresenter;
use App\Services\Outage\Diagnosis\OutageDiagnoser;
use App\Services\Outage\OutageSettings;
use App\Services\TicketCustomerLifecycleService;
use App\Services\TicketMonitoringEvidenceService;
use App\Support\AreaScope;
use App\Support\DismantleReason;
use App\Support\PerPage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TicketController extends Controller
{
    private const ALL_STATUSES = ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'];

    private const ARCHIVE_STATUSES = ['resolved', 'closed', 'cancelled'];

    public const DISMANTLE_CLOSE_HINT = 'Tiket dismantle tertutup otomatis setelah perangkat diterima gudang. Pilih Resolved setelah perangkat diambil.';

    public static function getValidTransitions(string $currentStatus): array
    {
        return array_values(array_filter(self::ALL_STATUSES, fn ($s) => $s !== $currentStatus));
    }

    public static function isValidTransition(string $from, string $to): bool
    {
        return $from !== $to && in_array($to, self::ALL_STATUSES, true);
    }

    public function index(Request $request, TicketMonitoringEvidenceService $monitoringEvidence)
    {
        $user = $request->user();
        $query = SupportTicket::query()
            ->with(['customer:id,code,name,phone,geo_lat,geo_long,area_id', 'assignee:id,name', 'creator:id,name']);

        AreaScope::applyToTickets($query, $user);

        $requestedStatus = (string) $request->input('status', '');
        $isArchive = $request->boolean('archive')
            || $requestedStatus === 'archive'
            || in_array($requestedStatus, self::ARCHIVE_STATUSES, true);

        if ($isArchive) {
            $query->whereIn('status', self::ARCHIVE_STATUSES);
        } else {
            $query->whereNotIn('status', self::ARCHIVE_STATUSES);
        }

        if ($user->isTeknisi()) {
            $query->where('assigned_to', $user->id);
        }
        if ($request->filled('status') && ! in_array($request->status, ['all', 'archive'], true)) {
            if ($request->status === 'open_assigned') {
                $query->whereIn('status', ['open', 'assigned']);
            } else {
                $query->where('status', $request->status);
            }
        }
        if ($request->filled('priority') && $request->priority !== 'all') {
            $query->where('priority', $request->priority);
        }
        if ($request->filled('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }
        if ($request->filled('assigned_to') && $request->assigned_to !== 'all') {
            $query->where('assigned_to', $request->assigned_to);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('code', 'like', "%{$search}%")
                    ->orWhere('report_id', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('gangguan', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        $limit = PerPage::from($request, 20);
        $tickets = $query->latest()->orderByDesc('id')->paginate($limit)->withQueryString();

        $technicians = User::where('role', 'teknisi')
            ->orWhere('role', 'admin')
            ->orWhere('role', 'noc')
            ->select('id', 'name', 'role', 'phone')
            ->orderBy('name')
            ->get();

        $baseCountQuery = SupportTicket::query();
        AreaScope::applyToTickets($baseCountQuery, $user);
        if ($user->isTeknisi()) {
            $baseCountQuery->where('assigned_to', $user->id);
        }
        $statusCounts = (clone $baseCountQuery)->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $technicianStats = [];
        if (in_array($user->role, ['admin', 'noc', 'superadmin'], true)) {
            $technicianStats = User::where('role', 'teknisi')
                ->orWhere('role', 'admin')
                ->orWhere('role', 'noc')
                ->select('id', 'name', 'role')
                ->withCount([
                    'assignedTickets as total_assigned',
                    'assignedTickets as closed_count' => fn ($q) => $q->whereIn('status', ['resolved', 'closed']),
                    'assignedTickets as open_count' => fn ($q) => $q->whereIn('status', ['open', 'assigned', 'in_progress', 'pending']),
                ])
                ->having('total_assigned', '>', 0)
                ->orderByDesc('closed_count')
                ->get()
                ->toArray();
        }

        $filterParams = $request->only(['status', 'priority', 'type', 'assigned_to', 'search']);
        $filterParams['limit'] = $limit;
        if ($isArchive && ! array_key_exists('status', $filterParams)) {
            $filterParams['status'] = 'archive';
        }

        return Inertia::render('Tickets/Index', [
            'tickets' => $tickets,
            'viewMode' => $isArchive ? 'archive' : 'active',
            'filters' => array_merge($filterParams, ['archive' => $isArchive ? '1' : '0']),
            'technicians' => $technicians,
            'statusOptions' => ['open', 'assigned', 'in_progress', 'pending', 'resolved', 'closed'],
            'priorityOptions' => ['low', 'normal', 'high', 'urgent'],
            'typeOptions' => ['installation', 'incident', 'billing', 'request', 'other'],
            'statusCounts' => $statusCounts,
            'technicianStats' => $technicianStats,
        ] + $this->monitoringCandidateProps($request, $monitoringEvidence));
    }

    /**
     * Monitoring candidates are for admin/NOC only: technicians get neither the chip summary nor the list.
     * The list is an optional prop, loaded by a partial reload when the drawer opens.
     *
     * @return array<string, mixed>
     */
    private function monitoringCandidateProps(Request $request, TicketMonitoringEvidenceService $monitoringEvidence): array
    {
        $user = $request->user();
        if ($user->isTeknisi()) {
            return [];
        }

        $filters = $request->only(['monitoring_status', 'monitoring_issue', 'monitoring_severity', 'monitoring_source', 'monitoring_area', 'monitoring_show_ticketed']);

        return [
            'monitoringSummary' => fn () => $monitoringEvidence->summary($user),
            'monitoringEvidence' => Inertia::optional(fn () => $monitoringEvidence->indexPayload($user, 50, $filters)),
        ];
    }

    public function create(Request $request)
    {
        $customers = $this->customerOptions($request);

        $technicians = User::where('role', 'teknisi')
            ->orWhere('role', 'admin')
            ->orWhere('role', 'noc')
            ->select('id', 'name', 'role', 'phone')
            ->orderBy('name')
            ->get();

        return Inertia::render('Tickets/Create', [
            'customers' => $customers,
            'technicians' => $technicians,
            'dismantleReasonOptions' => DismantleReason::options(),
            // RCA-4: null = the "Diagnosa otomatis" panel is not shown to this role
            'diagnosisPanel' => DiagnosisPresenter::panelProps($request->user(), null),
        ]);
    }

    /**
     * RCA-4: every incident ticket with a customer gets a stored "ticket_open" diagnosis (the prediction of the
     * weekly precision report). DB only (<200 ms, no device I/O); it can never break ticket creation. A finished
     * "Cek sekarang" check of the same customer (hidden field diagnosis_check_id) is linked to the ticket.
     */
    private function recordDiagnosis(SupportTicket $ticket, Request $request): void
    {
        try {
            if ($ticket->type !== 'incident' || ! $ticket->customer_id || ! OutageSettings::diagnosisEnabled() || ! Schema::hasTable('outage_diagnoses')) {
                return;
            }
            $checkId = (int) $request->input('diagnosis_check_id', 0);
            if ($checkId > 0) {
                OutageDiagnosis::query()->whereKey($checkId)
                    ->where('customer_id', $ticket->customer_id)->where('created_by', Auth::id())->whereNull('ticket_id')
                    ->update(['ticket_id' => $ticket->id]);
            }
            $customer = Customer::query()->find($ticket->customer_id);
            if ($customer !== null) {
                app(OutageDiagnoser::class)->diagnose($customer, OutageDiagnosis::TRIGGER_TICKET_OPEN, ['ticket_id' => $ticket->id, 'created_by' => Auth::id()]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Ticket] ticket_open diagnosis failed', ['ticket_id' => $ticket->id, 'exception_class' => $e::class]);
        }
    }

    public function store(Request $request)
    {
        $request->merge([
            'customer_id' => $request->input('customer_id') === '__none__' ? null : $request->input('customer_id'),
            'assigned_to' => $request->input('assigned_to') === '__none__' ? null : $request->input('assigned_to'),
            'due_at' => $request->input('due_at') === '' ? null : $request->input('due_at'),
        ]);

        $validated = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'customer_coords' => ['nullable', 'string', 'max:255'],
            'gangguan' => ['required', 'string', 'max:1000'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:installation,incident,billing,request,other,dismantle'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'due_at' => ['nullable', 'date'],
            ...DismantleReason::rules(),
        ], DismantleReason::messages());
        $validated = DismantleReason::normalize($validated, $validated['type'] ?? null);

        if (array_key_exists('due_at', $validated)) {
            $validated['due_at'] = $this->parseWibDateTime($validated['due_at']);
        }

        if (! empty($validated['customer_id'])) {
            $customer = Customer::find($validated['customer_id']);
            if ($customer) {
                AreaScope::authorizeCustomer($customer, $request->user());
                $validated['customer_name'] = $validated['customer_name'] ?: $customer->name;
                $validated['customer_phone'] = $validated['customer_phone'] ?: $customer->phone;
                if (empty($validated['customer_coords']) && $customer->geo_lat && $customer->geo_long) {
                    $validated['customer_coords'] = $customer->geo_lat.','.$customer->geo_long;
                }
            }
        }

        $validated['created_by'] = Auth::id();
        $validated['subject'] = Str::limit($validated['gangguan'], 255);

        if (! empty($validated['assigned_to'])) {
            $validated['status'] = 'assigned';
            $validated['assigned_at'] = now();
        }

        $ticket = SupportTicket::create($validated);

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => Auth::id(),
            'action' => 'created',
            'description' => 'Tiket dibuat',
        ]);
        DismantleReason::recordChange($ticket, null, null, $request->user());
        $this->recordDiagnosis($ticket, $request);

        // Always notify WA group on new ticket (even if unassigned)
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->sendTicketCreatedToGroup($ticket);
        } catch (\Throwable $e) {
            \Log::warning('[Ticket] Failed to dispatch new-ticket group notification', [
                'ticket_id' => $ticket->id,
                'error' => $e->getMessage(),
            ]);
        }

        // A Dismantle ticket lands in Pengambilan Perangkat right away (already
        // assigned to the ticket technician; pickup info rides on the ticket WA).
        $recoveryCase = app(DeviceRecoveryService::class)->ensureForDismantleTicket($ticket, $request->user(), notifyAssignee: false);
        // Owner 2026-10-10: the device was already carried/received for this
        // customer, so the ticket was linked to that case and closed. No
        // technician assignment WA (the group WA above still went out).
        $linkedRecovery = $recoveryCase && (int) $recoveryCase->ticket_id !== (int) $ticket->id;

        $assignmentNotificationSent = null;
        if (! empty($validated['assigned_to'])) {
            $assignee = User::find($validated['assigned_to']);
            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'assigned',
                'new_value' => ['assignee_id' => $validated['assigned_to'], 'assignee_name' => $assignee ? $assignee->name : '-'],
                'description' => 'Teknisi di-assign: '.($assignee ? $assignee->name : '-'),
            ]);

            // Match the Assign action: a ticket created with an assignee must
            // also notify that technician directly.
            if (! $linkedRecovery) {
                $assignmentNotificationSent = $this->sendAssignmentNotification($ticket->fresh(['assignee']));
            }
        }

        $response = redirect()->route('tickets.show', $ticket)
            ->with('success', 'Tiket berhasil dibuat: '.$ticket->code
                .($linkedRecovery
                    ? ' (perangkat sudah tercatat di Pengambilan Perangkat #'.$recoveryCase->id.'; tiket ditutup otomatis)'
                    : ($recoveryCase ? ' (masuk Pengambilan Perangkat)' : '')));

        if ($assignmentNotificationSent === false) {
            $response->with('warning', 'Tiket tersimpan, tetapi notifikasi WA teknisi belum terkirim. Periksa nomor teknisi dan status gateway.');
        }

        return $response;
    }

    public function show(
        SupportTicket $ticket,
        TicketMonitoringEvidenceService $monitoringEvidence,
        TicketCustomerLifecycleService $customerLifecycle
    ) {
        $user = request()->user();
        AreaScope::authorizeTicket($ticket, $user);
        if ($user->isTeknisi() && $ticket->assigned_to !== $user->id) {
            abort(403);
        }

        $ticket->load(['customer', 'assignee', 'creator', 'activities.user:id,name,role', 'comments.user:id,name,role']);
        $ticket->setAttribute('photos', collect(is_array($ticket->photos) ? $ticket->photos : [])
            ->values()
            ->map(fn ($path, $index) => URL::temporarySignedRoute(
                'media.tickets.photo',
                now()->addMinutes(10),
                ['ticket' => $ticket->getKey(), 'index' => $index],
            ))
            ->all());

        $technicians = User::where('role', 'teknisi')
            ->orWhere('role', 'admin')
            ->orWhere('role', 'noc')
            ->select('id', 'name', 'role', 'phone')
            ->orderBy('name')
            ->get();

        $validTransitions = self::getValidTransitions($ticket->status);
        $hasApprovedDismantle = $ticket->activities->contains(
            fn (TicketActivity $activity): bool => $activity->action === TicketCustomerLifecycleService::APPLIED
                && ($activity->new_value['lifecycle_action'] ?? null) === 'dismantle'
        );
        $recoveryCase = DeviceRecoveryCase::query()
            ->where('ticket_id', $ticket->id)
            ->first(['id', 'status']);
        $linkedRecoveryCase = $recoveryCase ? null : app(DeviceRecoveryService::class)->linkedCaseForTicket($ticket);
        // Closing a dismantle ticket is blocked until the warehouse receives the
        // device (receipt auto-closes it). Tell the UI so it can disable Closed
        // instead of letting the technician submit a form that is rejected.
        $closeBlocked = false;
        if ($recoveryCase && $ticket->status !== 'closed') {
            try {
                app(DeviceRecoveryService::class)->assertTicketCanClose($ticket);
            } catch (ValidationException) {
                $closeBlocked = true;
            }
        }

        // Generate wa.me link for manual WhatsApp fallback
        $waMeLink = null;
        $waProvider = config('services.whatsapp.provider', 'evolution_api');
        if ($ticket->assigned_to && $user->isAdminLike()) {
            try {
                $notificationService = app(NotificationService::class);
                $waMeLink = $notificationService->generateWaMeLink($ticket);
            } catch (\Throwable $e) {
                \Log::warning('[Ticket] Failed to generate wa.me link', ['error' => $e->getMessage()]);
            }
        }

        return Inertia::render('Tickets/Show', [
            'ticket' => $ticket,
            'technicians' => $technicians,
            'validTransitions' => $validTransitions,
            'allStatuses' => self::ALL_STATUSES,
            'waMeLink' => $waMeLink,
            'waProvider' => $waProvider,
            'monitoringEvidence' => $monitoringEvidence->ticketEvidence($ticket, $user),
            // Manual "Buat tiket" from Deteksi Gangguan: the episode this ticket came from (null for every other ticket).
            'detectionSource' => ($ticket->meta['source'] ?? null) === 'deteksi_manual' && ! empty($ticket->meta['outage_signal_id'])
                ? ['episode_id' => (int) $ticket->meta['outage_signal_id'], 'url' => '/monitoring?tab=deteksi&episode='.(int) $ticket->meta['outage_signal_id']]
                : null,
            'customerLifecycleApprovals' => $customerLifecycle->pendingFor($ticket),
            'rootCauseOptions' => OutageRootCause::columnReady() ? OutageRootCause::pickOptions() : [],
            'rootCauseSuggestion' => OutageRootCause::columnReady() && $ticket->root_cause === null ? OutageRootCause::suggestionFor($ticket, $user) : null,
            'repairSignature' => OutageRootCause::columnReady() && $ticket->root_cause === null ? OutageRootCause::signatureFor($ticket, $user) : null,
            'rootCause' => $ticket->root_cause === null || $ticket->root_cause === '' ? null : [
                'code' => $ticket->root_cause,
                'label' => OutageRootCause::ticketLabel($ticket->root_cause),
                'note' => OutageRootCause::detailReady() ? ($ticket->root_cause_note ?: null) : null,
            ],
            // RCA-4: "Diagnosa otomatis" panel (incident tickets of a customer; null = hidden for this role)
            'diagnosisPanel' => $ticket->type === 'incident' && $ticket->customer_id && Schema::hasTable('outage_diagnoses')
                ? DiagnosisPresenter::panelProps($user, DiagnosisPresenter::latestForTicket((int) $ticket->id))
                : null,
            'dismantleReason' => $ticket->dismantle_reason === null ? null : [
                'code' => $ticket->dismantle_reason,
                'label' => DismantleReason::label($ticket->dismantle_reason),
                'note' => $ticket->dismantle_reason_note,
            ],
            'deviceRecovery' => [
                'approved_dismantle' => $hasApprovedDismantle,
                // A dismantle request still pending (e.g. refused by the lifecycle guard) also allows the pickup case.
                'pending_dismantle' => collect($customerLifecycle->pendingFor($ticket))->contains(fn (array $p): bool => ($p['lifecycle_action'] ?? null) === 'dismantle'),
                'dismantle_ticket' => $ticket->type === DeviceRecoveryService::DISMANTLE_TICKET_TYPE,
                'case' => $recoveryCase
                    ? ['id' => $recoveryCase->id, 'status' => $recoveryCase->status]
                    : ($linkedRecoveryCase ? [
                        'id' => $linkedRecoveryCase->id,
                        'status' => $linkedRecoveryCase->status,
                        'linked' => true,
                        'ticket_code' => $linkedRecoveryCase->ticket?->code,
                    ] : null),
                'close_blocked' => $closeBlocked,
            ],
        ]);
    }

    public function requestCustomerLifecycle(
        Request $request,
        SupportTicket $ticket,
        TicketCustomerLifecycleService $customerLifecycle
    ) {
        AreaScope::authorizeTicket($ticket, $request->user());
        abort_unless($request->user()?->isAdminLike(), 403);

        $validated = $request->validate([
            'lifecycle_action' => ['required', 'in:dismantle,isolir,reconnect,pindah_odp'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
            'new_odp_name' => ['nullable', 'string', 'max:255'],
            'new_address' => ['nullable', 'string', 'max:1000'],
        ]);

        $customerLifecycle->request($ticket, $request->user(), $validated);

        return back()->with('success', 'Request lifecycle pelanggan masuk pending approval.');
    }

    public function approveCustomerLifecycle(
        Request $request,
        SupportTicket $ticket,
        TicketActivity $activity,
        TicketCustomerLifecycleService $customerLifecycle
    ) {
        AreaScope::authorizeTicket($ticket, $request->user());
        abort_unless($request->user()?->isAdminLike(), 403);

        $request->validate([
            'override' => ['nullable', 'boolean'],
            'override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $applied = $customerLifecycle->approve(
                $ticket,
                $activity,
                $request->user(),
                $request->boolean('override'),
                (string) $request->input('override_reason', ''),
            );
        } catch (ValidationException $e) {
            // Roadmap 1.3: the guard refused a dismantle approval because the customer is
            // still in use. The request stays pending and the customer is NOT terminated,
            // but the device pickup case is still created (idempotent) so technicians can
            // collect the device; otherwise the refusal could never be resolved.
            $guardMessage = $e->errors()['lifecycle_guard'][0] ?? null;
            if ($guardMessage !== null && ($activity->new_value['lifecycle_action'] ?? null) === 'dismantle') {
                $case = app(DeviceRecoveryService::class)->ensureForDismantleTicket($ticket->fresh(), $request->user());
                if ($case) {
                    throw ValidationException::withMessages(['lifecycle_guard' => $guardMessage.' Kasus Pengambilan Perangkat #'.$case->id.' sudah dibuat agar perangkat bisa dijemput; approval bisa diulang setelah perangkat dicabut.'] + collect($e->errors())->except('lifecycle_guard')->map(fn ($m) => $m[0])->all());
                }
            }

            throw $e;
        }

        // An approved dismantle goes straight to Pengambilan Perangkat.
        if (($applied->new_value['lifecycle_action'] ?? null) === 'dismantle') {
            app(DeviceRecoveryService::class)->ensureForDismantleTicket($ticket->fresh(), $request->user());
        }

        return back()->with('success', 'Lifecycle pelanggan di-approve dan tercatat audit.');
    }

    public function approveMonitoringCandidate(
        Request $request,
        MonitoringAlertEvent $event,
        TicketMonitoringEvidenceService $monitoringEvidence
    ) {
        abort_unless($request->user()?->isAdminLike(), 403);
        abort_unless($event->source === 'connectivity_scan' && $event->status === 'active', 404);

        $ticket = $monitoringEvidence->createDraftTicket($event, $request->user());

        return redirect()->route('tickets.show', $ticket)
            ->with('success', 'Draft tiket monitoring siap direview: '.$ticket->code);
    }

    public function edit(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        AreaScope::authorizeTicket($ticket, $user);
        if ($user->isTeknisi() && $ticket->assigned_to !== $user->id) {
            abort(403);
        }

        $customers = $this->customerOptions($request);

        $technicians = User::where('role', 'teknisi')
            ->orWhere('role', 'admin')
            ->orWhere('role', 'noc')
            ->select('id', 'name', 'role', 'phone')
            ->orderBy('name')
            ->get();

        $ticket->load(['customer', 'assignee', 'creator']);

        $validTransitions = self::getValidTransitions($ticket->status);

        return Inertia::render('Tickets/Edit', [
            'ticket' => $ticket,
            'customers' => $customers,
            'technicians' => $technicians,
            'validTransitions' => $validTransitions,
            'allStatuses' => self::ALL_STATUSES,
            'dismantleReasonOptions' => DismantleReason::options(),
            'rootCauseOptions' => OutageRootCause::columnReady() ? OutageRootCause::pickOptions() : [],
            'rootCauseSuggestion' => OutageRootCause::columnReady() && $ticket->root_cause === null ? OutageRootCause::suggestionFor($ticket, $user) : null,
            'repairSignature' => OutageRootCause::columnReady() && $ticket->root_cause === null ? OutageRootCause::signatureFor($ticket, $user) : null,
        ]);
    }

    public function update(Request $request, SupportTicket $ticket, DeviceRecoveryService $recoveryService)
    {
        $user = $request->user();
        AreaScope::authorizeTicket($ticket, $user);
        // Same contract as edit()/technicianUpdate(): a teknisi may only mutate
        // tickets assigned to them. AreaScope is a no-op for teknisi, so without
        // this check any teknisi could PUT /tickets/{id} and close/rewrite it.
        if ($user->isTeknisi() && $ticket->assigned_to !== $user->id) {
            abort(403);
        }
        if ($request->has('assigned_to') && $request->input('assigned_to') === '__none__') {
            $request->merge(['assigned_to' => null]);
        }
        if ($request->has('due_at') && $request->input('due_at') === '') {
            $request->merge(['due_at' => null]);
        }

        $validated = $request->validate([
            'gangguan' => ['sometimes', 'string', 'max:1000'],
            'customer_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'customer_coords' => ['sometimes', 'nullable', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:installation,incident,billing,request,other,dismantle'],
            'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'status' => ['sometimes', 'in:open,assigned,in_progress,pending,resolved,closed'],
            'due_at' => ['nullable', 'date'],
            'root_cause' => OutageRootCause::rule(),
            'root_cause_note' => OutageRootCause::noteRule(),
            'root_cause_via' => OutageRootCause::viaRule(),
            ...DismantleReason::rules(),
        ], DismantleReason::messages() + OutageRootCause::messages());
        $validated = DismantleReason::normalize($validated, $validated['type'] ?? $ticket->type);
        // Closing an incident ticket needs "Penyebab sebenarnya" (answer "Tidak tahu" is allowed), also when the
        // type is changed in the same request. "Lainnya" needs a note.
        [$validated, $rootCauseVia] = OutageRootCause::prepareClose($ticket, $validated, $validated['type'] ?? null, OutageRootCause::sourceFor($user), $user);
        $oldRootCause = $ticket->root_cause;
        if (isset($validated['type']) && $validated['type'] !== 'dismantle' && $ticket->type === 'dismantle' && $ticket->dismantle_reason !== null) {
            // Re-typed away from Dismantle: the reason no longer applies.
            $validated['dismantle_reason'] = null;
            $validated['dismantle_reason_note'] = null;
        }
        $oldReason = $ticket->dismantle_reason;
        $oldReasonNote = $ticket->dismantle_reason_note;

        if (array_key_exists('due_at', $validated)) {
            $validated['due_at'] = $this->parseWibDateTime($validated['due_at']);
        }

        if (($validated['status'] ?? null) === 'closed') {
            $shouldNotifyClosed = $ticket->status !== 'closed';
            $response = DB::transaction(function () use ($ticket, $validated, $recoveryService) {
                $lockedTicket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                $recoveryService->assertTicketCanClose($lockedTicket, true);

                return $this->persistUpdate($lockedTicket, $validated, false);
            });
            if ($shouldNotifyClosed) {
                $this->sendClosedNotification($ticket->fresh());
            }
            DismantleReason::recordChange($ticket->fresh(), $oldReason, $oldReasonNote, $user);
            OutageRootCause::recordChange($ticket->fresh(), $oldRootCause, $user, 'ticket_form', $rootCauseVia);

            return $response;
        }

        $response = $this->persistUpdate($ticket, $validated);
        DismantleReason::recordChange($ticket->fresh(), $oldReason, $oldReasonNote, $user);
        OutageRootCause::recordChange($ticket->fresh(), $oldRootCause, $user, 'ticket_form', $rootCauseVia);
        // Re-typed to Dismantle (e.g. an older ticket filed as Lainnya/Gangguan):
        // create its pickup case. Idempotent; no-op for other types.
        $recoveryService->ensureForDismantleTicket($ticket->fresh(), $user);

        return $response;
    }

    private function persistUpdate(SupportTicket $ticket, array $validated, bool $sendClosedNotification = true)
    {
        $statusChanged = isset($validated['status']) && $validated['status'] !== $ticket->status;
        if ($statusChanged) {
            $oldStatus = $ticket->status;

            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'status_changed',
                'old_value' => ['status' => $oldStatus],
                'new_value' => ['status' => $validated['status']],
                'description' => "Status diubah: {$oldStatus} -> {$validated['status']}",
            ]);

            if (! in_array($validated['status'], ['resolved', 'closed'], true)) {
                $this->sendStatusChangeNotification($ticket, $oldStatus, $validated['status']);
            }
        }

        if (isset($validated['gangguan'])) {
            $validated['subject'] = Str::limit($validated['gangguan'], 255);
        }

        if (isset($validated['status']) && in_array($validated['status'], ['resolved', 'closed'], true) && ! $ticket->resolved_at) {
            $validated['resolved_at'] = now();
        }

        $ticket->update($validated);

        // Notify WA group when ticket is closed/resolved
        if ($sendClosedNotification && $statusChanged && in_array($validated['status'], ['resolved', 'closed'], true)) {
            $this->sendClosedNotification($ticket->fresh());
        }

        // root_cause_set / root_cause_changed already record the cause details; keep column names out of the timeline.
        $nonStatusFields = array_diff_key($validated, array_flip(['status', 'root_cause_source', 'root_cause_suggested', 'root_cause_note', 'root_cause_via']));
        if (! empty($nonStatusFields)) {
            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'updated',
                'new_value' => $nonStatusFields,
                'description' => 'Tiket diupdate: '.implode(', ', array_keys($nonStatusFields)),
            ]);
        }

        return back()->with('success', 'Tiket berhasil diupdate.');
    }

    public function assign(Request $request, SupportTicket $ticket)
    {
        AreaScope::authorizeTicket($ticket, $request->user());
        abort_unless($request->user()?->isAdminLike(), 403);

        $validated = $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        $oldAssigneeId = $ticket->assigned_to;
        $oldAssignee = $ticket->assignee;
        $isReassign = $oldAssigneeId && $oldAssigneeId != $validated['assigned_to'];

        $ticket->update([
            'assigned_to' => $validated['assigned_to'],
            'status' => 'assigned',
            'assigned_at' => now(),
        ]);

        $newAssignee = User::find($validated['assigned_to']);

        // Pre-start pickup case follows the ticket technician; its info rides on the ticket WA below.
        app(DeviceRecoveryService::class)->syncAssigneeFromTicket($ticket->fresh(), $request->user());

        if ($isReassign && $oldAssignee) {
            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'assigned',
                'old_value' => ['assignee_id' => $oldAssigneeId, 'assignee_name' => $oldAssignee->name],
                'new_value' => ['assignee_id' => $validated['assigned_to'], 'assignee_name' => $newAssignee ? $newAssignee->name : '-'],
                'description' => "Teknisi dipindahkan: {$oldAssignee->name} -> ".($newAssignee ? $newAssignee->name : '-'),
            ]);

            $this->sendAssignmentNotification($ticket->fresh());

            try {
                $notificationService = app(NotificationService::class);
                $notificationService->sendTicketReassigned($ticket->fresh(), $oldAssignee);
            } catch (\Throwable $e) {
                \Log::error('[Notification] Exception in reassign notification', [
                    'ticket_code' => $ticket->code,
                    'error' => $e->getMessage(),
                ]);
            }
        } else {
            TicketActivity::create([
                'ticket_id' => $ticket->id,
                'user_id' => Auth::id(),
                'action' => 'assigned',
                'old_value' => $oldAssigneeId ? ['assignee_id' => $oldAssigneeId, 'assignee_name' => $oldAssignee ? $oldAssignee->name : '-'] : null,
                'new_value' => ['assignee_id' => $validated['assigned_to'], 'assignee_name' => $newAssignee ? $newAssignee->name : '-'],
                'description' => 'Teknisi di-assign: '.($newAssignee ? $newAssignee->name : '-'),
            ]);
            $this->sendAssignmentNotification($ticket->fresh());
        }

        return back()->with('success', 'Teknisi berhasil di-assign.');
    }

    public function technicianUpdate(Request $request, SupportTicket $ticket, DeviceRecoveryService $recoveryService)
    {
        $user = $request->user();
        AreaScope::authorizeTicket($ticket, $user);
        if ($user->isTeknisi() && $ticket->assigned_to !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'kendala' => ['nullable', 'string', 'max:2000'],
            'action_taken' => ['nullable', 'string', 'max:2000'],
            // The form defaults the select to the current status ("(saat ini)"),
            // e.g. `assigned`. Accept it (unchanged status, no status activity)
            // instead of failing the whole update (kendala/tindakan/foto) on an
            // error the form never showed.
            'status' => ['sometimes', Rule::in(array_values(array_unique(['in_progress', 'pending', 'resolved', 'closed', (string) $ticket->status])))],
            'root_cause' => OutageRootCause::rule(),
            'root_cause_note' => OutageRootCause::noteRule(),
            'root_cause_via' => OutageRootCause::viaRule(),
            'photos' => ['nullable', 'array', 'max:5'],
            // Phones upload the resized JPEG from compressImage() (~1600px). The
            // server limits only catch the fallback when the browser could not
            // resize: 50 MP cameras shoot 8160x6120, so allow up to 10000px/10 MB.
            // docker/php-uploads.ini must keep upload_max_filesize above this.
            'photos.*' => ['image', 'mimes:jpeg,jpg,png,webp', 'max:10240', 'dimensions:min_width=100,min_height=100,max_width=10000,max_height=10000'],
        ], OutageRootCause::messages() + [
            'photos.max' => 'Maksimal 5 foto per update.',
            'photos.*.uploaded' => 'Foto gagal terupload (ukuran terlalu besar atau koneksi terputus). Coba pilih ulang fotonya.',
            'photos.*.image' => 'File harus berupa foto.',
            'photos.*.mimes' => 'Foto harus JPG, PNG, atau WebP.',
            'photos.*.max' => 'Ukuran foto maksimal 10 MB.',
            'photos.*.dimensions' => 'Resolusi foto harus antara 100 dan 10000 piksel per sisi.',
        ]);

        // Closing an incident ticket needs "Penyebab sebenarnya" (answer "Tidak tahu" is allowed). "Lainnya" needs a note.
        [$validated, $rootCauseVia] = OutageRootCause::prepareClose($ticket, $validated, null, OutageRootCause::sourceFor($user), $user);
        $oldRootCause = $ticket->root_cause;

        $originalStatus = $ticket->status;
        $newPhotos = [];
        try {
            DB::transaction(function () use ($request, $ticket, &$validated, &$newPhotos, $recoveryService): void {
                if (($validated['status'] ?? null) === 'closed') {
                    $lockedTicket = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);
                    try {
                        $recoveryService->assertTicketCanClose($lockedTicket, true);
                    } catch (ValidationException $e) {
                        // Rejected, not downgraded: the form keeps kendala/tindakan/
                        // foto client-side (Inertia keeps state on validation errors),
                        // so the technician only has to pick Resolved and resubmit.
                        throw ValidationException::withMessages([
                            'status' => self::DISMANTLE_CLOSE_HINT,
                        ]);
                    }
                }

                if ($request->hasFile('photos')) {
                    foreach ((array) $request->file('photos') as $photo) {
                        // The private disk is configured with 'throw' => false, so a
                        // failed write (permissions, S3 outage) returns false instead
                        // of throwing. Never persist `false` as a photo path.
                        $path = $photo->store('tickets', 'private');
                        if (! is_string($path) || $path === '') {
                            Log::warning('ticket.photo_store_failed', [
                                'ticket_id' => $ticket->id,
                                'disk' => 'private',
                                'driver' => config('filesystems.disks.private.driver'),
                                'size' => $photo->getSize(),
                            ]);

                            throw ValidationException::withMessages([
                                'photos' => 'Foto gagal disimpan di server. Coba lagi atau hubungi admin.',
                            ]);
                        }
                        $newPhotos[] = $path;
                    }

                    $existingPhotos = is_array($ticket->photos) ? $ticket->photos : [];
                    $validated['photos'] = array_values(array_merge($existingPhotos, $newPhotos));
                }

                if (isset($validated['status']) && $validated['status'] !== $ticket->status) {
                    $oldStatus = $ticket->status;

                    TicketActivity::create([
                        'ticket_id' => $ticket->id,
                        'user_id' => Auth::id(),
                        'action' => 'status_changed',
                        'old_value' => ['status' => $oldStatus],
                        'new_value' => ['status' => $validated['status']],
                        'description' => "Status diubah: {$oldStatus} -> {$validated['status']}",
                    ]);
                }

                if (isset($validated['status']) && in_array($validated['status'], ['resolved', 'closed'], true) && ! $ticket->resolved_at) {
                    $validated['resolved_at'] = now();
                }

                if (empty($ticket->kendala) && empty($validated['kendala']) && ! isset($validated['status'])) {
                    $validated['status'] = 'in_progress';
                }

                $ticket->update($validated);

                TicketActivity::create([
                    'ticket_id' => $ticket->id,
                    'user_id' => Auth::id(),
                    'action' => 'updated',
                    'new_value' => array_diff_key($validated, array_flip(['root_cause_source', 'root_cause_suggested', 'root_cause_note', 'root_cause_via'])),
                    'description' => 'Teknisi mengupdate tiket',
                ]);
            });
        } catch (\Throwable $e) {
            foreach ($newPhotos as $path) {
                Storage::disk('private')->delete($path);
                Storage::disk('public')->delete($path);
            }

            throw $e;
        }

        OutageRootCause::recordChange($ticket->fresh(), $oldRootCause, $user, 'technician_update', $rootCauseVia);

        $newStatus = $validated['status'] ?? null;
        if ($newStatus !== null && $newStatus !== $originalStatus) {
            if (! in_array($newStatus, ['resolved', 'closed'], true)) {
                $this->sendStatusChangeNotification($ticket, $originalStatus, $newStatus);
            }
        }

        // Notify WA group when teknisi closes ticket
        if ($newStatus !== null && $newStatus !== $originalStatus && in_array($newStatus, ['resolved', 'closed'], true)) {
            $this->sendClosedNotification($ticket->fresh());
        }

        return back()->with('success', 'Update berhasil disimpan.');
    }

    public function comment(Request $request, SupportTicket $ticket)
    {
        $user = $request->user();
        AreaScope::authorizeTicket($ticket, $user);
        if (! in_array($user->role, ['admin', 'noc', 'teknisi', 'superadmin'], true)) {
            abort(403);
        }
        // Mirrors show(): a teknisi cannot even view an unassigned ticket, so
        // they must not be able to comment on one either.
        if ($user->isTeknisi() && $ticket->assigned_to !== $user->id) {
            abort(403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        TicketComment::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $validated['body'],
        ]);

        TicketActivity::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'action' => 'commented',
            'description' => 'Komentar ditambahkan',
        ]);

        return back()->with('success', 'Komentar ditambahkan.');
    }

    public function destroy(Request $request, SupportTicket $ticket)
    {
        AreaScope::authorizeTicket($ticket, $request->user());
        abort_unless($request->user()?->isAdminLike(), 403);

        if (! empty($ticket->photos)) {
            foreach ($ticket->photos as $photo) {
                Storage::disk('private')->delete($photo);
                Storage::disk('public')->delete($photo);
            }
        }
        $ticket->delete();

        return redirect()->route('tickets.index')
            ->with('success', 'Tiket berhasil dihapus.');
    }

    private function parseWibDateTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        // datetime-local has no offset. Treat the technician/admin input as WIB,
        // then store the instant in UTC without changing the database convention.
        return CarbonImmutable::parse($value, 'Asia/Jakarta')->utc();
    }

    private function sendAssignmentNotification(SupportTicket $ticket): bool
    {
        $technician = $ticket->assignee;
        if (! $technician) {
            return false;
        }

        try {
            $notificationService = app(NotificationService::class);

            return $notificationService->sendTicketAssignment($ticket);
        } catch (\Throwable $e) {
            \Log::error('[Notification] Exception in assignment dispatch', [
                'ticket_code' => $ticket->code,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function sendClosedNotification(SupportTicket $ticket): void
    {
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->sendTicketClosedToGroup($ticket);
        } catch (\Throwable $e) {
            \Log::error('[Notification] Exception in closed notification', [
                'ticket_code' => $ticket->code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendStatusChangeNotification(SupportTicket $ticket, string $oldStatus, string $newStatus): void
    {
        try {
            $notificationService = app(NotificationService::class);
            $notificationService->sendTicketStatusChangeToGroup($ticket, $oldStatus, $newStatus);
        } catch (\Throwable $e) {
            \Log::error('[Notification] Exception in status change notification', [
                'ticket_code' => $ticket->code,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function customerOptions(Request $request)
    {
        $search = $request->query('customer_search', '');
        $query = Customer::query()
            ->ebilling()
            ->with(['area:id,name', 'package:id,name'])
            ->select('id', 'code', 'name', 'phone', 'address', 'pppoe_user', 'geo_lat', 'geo_long', 'area_id', 'package_id', 'status');
        AreaScope::applyToCustomers($query, $request->user());

        return $query
            ->when($search, fn ($q) => $this->applyCustomerSearch($q, (string) $search))
            ->orderBy('name')
            ->limit(50)
            ->get();
    }

    private function applyCustomerSearch($query, string $search)
    {
        $terms = array_values(array_filter(preg_split('/\s+/', trim($search)) ?: []));

        foreach ($terms as $term) {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('code', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('pppoe_user', 'like', $like)
                    ->orWhere('address', 'like', $like)
                    ->orWhereHas('area', fn ($aq) => $aq->where('name', 'like', $like))
                    ->orWhereHas('package', fn ($pq) => $pq->where('name', 'like', $like));
            });
        }

        $first = $terms[0] ?? '';
        if ($first !== '') {
            $prefix = $first.'%';
            $query->orderByRaw(
                'CASE WHEN name LIKE ? THEN 0 WHEN code LIKE ? THEN 1 WHEN pppoe_user LIKE ? THEN 2 ELSE 3 END',
                [$prefix, $prefix, $prefix]
            );
        }

        return $query;
    }
}
