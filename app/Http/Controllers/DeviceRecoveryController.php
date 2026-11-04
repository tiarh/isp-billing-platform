<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\CustomerPendingTermination;
use App\Models\DeviceRecoveryCase;
use App\Models\DeviceRecoveryEvent;
use App\Models\DeviceRecoveryItem;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\DeviceRecoveryReconciliationService;
use App\Services\DeviceRecoveryService;
use App\Services\Lifecycle\LifecycleTerminationService;
use App\Services\NotificationService;
use App\Support\AreaScope;
use App\Support\DismantleReason;
use App\Support\OpsTime;
use App\Support\SensitiveDataRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DeviceRecoveryController extends Controller
{
    public function index(Request $request, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('viewAny', DeviceRecoveryCase::class);

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_merge(array_keys($this->statusOptions($service)), array_keys(self::STATUS_GROUPS)))],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:50'],
        ]);

        $user = $request->user();
        $query = DeviceRecoveryCase::query();
        $this->applyScope($query, $user);

        if (array_key_exists('area_id', $validated) && $validated['area_id'] !== null) {
            AreaScope::authorizeAreaId((int) $validated['area_id'], $user);
            $query->where('area_id', $validated['area_id']);
        }
        if (array_key_exists('assigned_to', $validated) && $validated['assigned_to'] !== null) {
            $query->where('assigned_to', $validated['assigned_to']);
        }
        if (! empty($validated['search'])) {
            $search = trim($validated['search']);
            $query->where(function ($nested) use ($search) {
                $nested->whereHas('ticket', fn ($ticket) => $ticket->where('code', 'like', "%{$search}%"))
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->withTrashed()
                        ->where(fn ($customerSearch) => $customerSearch
                            ->where('code', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")))
                    ->orWhereHas('items', fn ($item) => $item
                        ->where('expected_serial_number', 'like', "%{$search}%")
                        ->orWhere('captured_serial_number', 'like', "%{$search}%"));
            });
        }

        // Summary tiles count every matching case (scope, area, technician, search) and
        // deliberately ignore the status filter, so they stay stable when a status chip is tapped.
        $byStatus = (clone $query)->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $countOf = fn (array $statuses): int => (int) collect($statuses)->sum(fn (string $status): int => (int) ($byStatus[$status] ?? 0));
        $summary = [
            'total' => (int) $byStatus->sum(),
            'in_progress' => $countOf(self::STATUS_GROUPS['in_progress']),
            'needs_review' => $countOf(self::STATUS_GROUPS['needs_review']),
        ];

        if (array_key_exists('status', $validated) && $validated['status'] !== null) {
            // A group key (in_progress / needs_review) filters all of its sub-statuses at once.
            $query->whereIn('status', self::STATUS_GROUPS[$validated['status']] ?? [$validated['status']]);
        }

        $cases = $query
            ->with([
                'ticket:id,code,status,dismantle_reason,dismantle_reason_note',
                'customer:id,code,name,area_id',
                'area:id,name,code',
                'assignee:id,name,role',
                'items:id,recovery_case_id,device_type,expected_serial_number,captured_serial_number,status,condition,captured_at,verified_at,photo_paths',
            ])
            ->orderByDesc('updated_at')
            ->paginate((int) ($validated['limit'] ?? 20))
            ->withQueryString();
        $cases->getCollection()->transform(fn (DeviceRecoveryCase $case): array => $this->payload($case, $service));

        $areasQuery = Area::query()->select(['id', 'name', 'code'])->orderBy('name');
        AreaScope::applyToAreas($areasQuery, $user);

        $technicians = User::query()
            ->where('role', 'teknisi')
            ->select(['id', 'name', 'role'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $technician): array => [
                'id' => $technician->id,
                'name' => $technician->name,
                'role' => $technician->role,
            ])
            ->values();

        return Inertia::render('Tickets/Recovery', [
            'cases' => $cases,
            'summary' => $summary,
            'filters' => [
                'status' => $validated['status'] ?? null,
                // Chip/select to highlight: a legacy single sub-status link (?status=assigned) maps to its group chip.
                'status_chip' => isset($validated['status'])
                    ? (collect(self::STATUS_GROUPS)->search(fn (array $members): bool => in_array($validated['status'], $members, true)) ?: $validated['status'])
                    : null,
                'area_id' => $validated['area_id'] ?? null,
                'assigned_to' => $validated['assigned_to'] ?? null,
                'search' => $validated['search'] ?? '',
                'limit' => (int) ($validated['limit'] ?? 20),
            ],
            'statusOptions' => $this->statusOptions($service),
            'statusChips' => $this->statusChips($service),
            'readOnly' => config('device-recovery.mode') !== 'write',
            'areas' => $areasQuery->get()->map(fn (Area $area): array => [
                'id' => $area->id,
                'name' => $area->name,
                'code' => $area->code,
            ])->values(),
            'technicians' => $technicians,
            'canReport' => $user->isTeknisi() && config('device-recovery.mode') === 'write',
        ]);
    }

    public function show(
        Request $request,
        DeviceRecoveryCase $recoveryCase,
        DeviceRecoveryService $service,
        DeviceRecoveryReconciliationService $reconciliation,
    ): JsonResponse {
        Gate::forUser($request->user())->authorize('view', $recoveryCase);

        $recoveryCase->load([
            'ticket:id,code,status,dismantle_reason,dismantle_reason_note',
            'customer:id,code,name,area_id',
            'area:id,name,code',
            'assignee:id,name,role',
            'items:id,recovery_case_id,device_type,expected_serial_number,captured_serial_number,status,condition,captured_by,captured_at,verified_by,verified_at,photo_paths,notes,meta',
            'events:id,recovery_case_id,item_id,actor_id,event_type,from_status,to_status,reason,source,created_at',
        ]);

        return response()->json($this->payload($recoveryCase, $service, true, $reconciliation));
    }

    public function photo(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryItem $item, int $index)
    {
        Gate::forUser($request->user())->authorize('view', $recoveryCase);
        abort_unless((int) $item->recovery_case_id === (int) $recoveryCase->id, 404);

        $paths = is_array($item->photo_paths) ? array_values($item->photo_paths) : [];
        abort_unless(array_key_exists($index, $paths), 404);

        $path = trim((string) $paths[$index]);
        $path = str_replace('\\', '/', rawurldecode($path));
        $segments = explode('/', trim($path, '/'));
        abort_if(
            $path === '' || str_contains($path, "\0") || filter_var($path, FILTER_VALIDATE_URL)
                || array_intersect($segments, ['.', '..'])
                || ! str_starts_with($path, 'device-recovery/'),
            404,
        );

        $path = implode('/', $segments);
        $disk = Storage::disk('private');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(Request $request, SupportTicket $ticket, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('create', [DeviceRecoveryCase::class, $ticket]);
        $validated = $request->validate([
            'device_type' => ['nullable', Rule::in(['onu', 'ont', 'router', 'modem', 'adapter', 'other'])],
            'idempotency_key' => ['nullable', 'string', 'max:191'],
        ]);

        $case = $service->createFromApprovedDismantle(
            $ticket,
            $request->user(),
            $validated['device_type'] ?? 'other',
            $validated['idempotency_key'] ?? null,
        );

        if ((int) $case->ticket_id !== (int) $ticket->id) {
            return back()->with('success', 'Perangkat pelanggan sudah tercatat di Pengambilan Perangkat #'.$case->id.'; tiket ditautkan dan ditutup otomatis.');
        }

        return back()->with('success', 'Recovery perangkat dibuat dan menunggu penugasan.');
    }

    /**
     * Owner 2026-10-10: customer lookup for the technician report form.
     * Minimal fields only (no phone/address/NIK); 3+ characters; 20 rows.
     */
    public function reportCustomers(Request $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('report', DeviceRecoveryCase::class);
        $validated = $request->validate(['q' => ['required', 'string', 'min:3', 'max:100']]);

        $query = Customer::query()
            ->ebilling()
            ->with('area:id,name')
            ->select(['id', 'code', 'name', 'pppoe_user', 'area_id', 'status', 'onu_serial']);
        AreaScope::applyToCustomers($query, $request->user());
        foreach (array_values(array_filter(preg_split('/\s+/', trim($validated['q'])) ?: [])) as $term) {
            $like = '%'.$term.'%';
            $query->where(fn ($nested) => $nested
                ->where('name', 'like', $like)
                ->orWhere('code', 'like', $like)
                ->orWhere('pppoe_user', 'like', $like)
                ->orWhere('onu_serial', 'like', $like));
        }

        return response()->json($query->orderBy('name')->limit(20)->get()->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'code' => $customer->code,
            'name' => $customer->name,
            'pppoe_user' => $customer->pppoe_user,
            'area' => $customer->area?->name,
            'status' => $customer->status,
            'onu_serial' => $customer->onu_serial,
        ])->values());
    }

    /**
     * Owner 2026-10-10: "Lapor pengambilan tanpa tiket" (technician only).
     * Creates the Dismantle ticket + a carried case; the WA group gets the
     * usual new-ticket message (no technician assignment WA). The admin then
     * confirms with "Diterima gudang".
     */
    public function report(Request $request, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('report', DeviceRecoveryCase::class);
        $validated = $request->validate([
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'condition' => ['required', Rule::in(['good', 'damaged', 'lost', 'unknown'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            'emergency_reason' => ['required', 'string', 'min:10', 'max:1000'],
            ...$this->pickupPhotoRules(),
            'idempotency_key' => ['required', 'string', 'max:191'],
        ], [
            ...$this->pickupPhotoMessages(),
            'customer_id.required' => 'Pilih pelanggan.',
            'customer_id.exists' => 'Pilih pelanggan.',
            'emergency_reason.required' => 'Alasan darurat wajib diisi.',
            'emergency_reason.min' => 'Alasan darurat minimal 10 karakter.',
        ]);

        $customer = Customer::query()->findOrFail($validated['customer_id']);
        AreaScope::authorizeCustomer($customer, $request->user());

        $photos = [];
        $reportToken = bin2hex(random_bytes(16));
        try {
            $photos = $this->storePickupPhotos($request);

            $case = $service->reportWithoutTicket(
                $request->user(),
                $customer,
                $validated['serial_number'] ?? null,
                $validated['condition'],
                $validated['reason'] ?? null,
                $validated['emergency_reason'],
                $photos,
                $validated['idempotency_key'],
                ['report_token' => $reportToken],
            );

            $event = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            $fresh = ($event?->metadata['report_token'] ?? null) === $reportToken;
            if (! $fresh) {
                $this->deletePhotos(array_column($photos, 'path'));
            }
        } catch (\Throwable $exception) {
            $this->deletePhotos(array_column($photos, 'path'));

            throw $exception;
        }

        if ($fresh && $case->ticket) {
            // Same group WA path as a new ticket; it carries the
            // "perlu konfirmasi admin" subject. No technician assignment WA.
            try {
                app(NotificationService::class)->sendTicketCreatedToGroup($case->ticket->fresh(['customer', 'assignee', 'creator']));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', $case->status === DeviceRecoveryService::STATUS_EXCEPTION
            ? 'Laporan tersimpan ('.$case->ticket?->code.'), masuk tinjauan exception. Serahkan perangkat ke admin.'
            : 'Laporan tersimpan ('.$case->ticket?->code.'). Serahkan perangkat ke admin untuk konfirmasi "Diterima gudang".');
    }

    public function addItem(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('addItem', $recoveryCase);
        $validated = $request->validate([
            'device_type' => ['required', Rule::in(['onu', 'ont', 'router', 'modem', 'adapter', 'other'])],
            'expected_serial_number' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        $service->addItem(
            $recoveryCase,
            $request->user(),
            $validated['device_type'],
            $validated['expected_serial_number'] ?? null,
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Perangkat tambahan masuk ke case recovery.');
    }

    public function updateItem(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryItem $item, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('updateItem', $recoveryCase);
        $validated = $request->validate([
            'device_type' => ['required', Rule::in(['onu', 'ont', 'router', 'modem', 'adapter', 'other'])],
            'expected_serial_number' => ['nullable', 'string', 'max:128'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        $service->updateItem(
            $recoveryCase,
            $item,
            $request->user(),
            $validated['device_type'],
            $validated['expected_serial_number'] ?? null,
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Data perangkat recovery diperbarui.');
    }

    public function assign(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('assign', $recoveryCase);
        $validated = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'scheduled_at' => ['nullable', 'date'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        $previousAssignee = $recoveryCase->assigned_to;
        $case = $service->assign(
            $recoveryCase,
            $request->user(),
            (int) $validated['assigned_to'],
            isset($validated['scheduled_at']) ? OpsTime::parseLocal($validated['scheduled_at']) : null,
            $validated['idempotency_key'],
        );
        if ((int) $case->assigned_to !== (int) $previousAssignee) {
            $service->notifyAssignee($case);
        }

        return back()->with('success', 'Teknisi recovery berhasil ditugaskan.');
    }

    public function schedule(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('schedule', $recoveryCase);
        $validated = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);

        $service->schedule(
            $recoveryCase,
            $request->user(),
            OpsTime::parseLocal($validated['scheduled_at']),
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Jadwal pengambilan disimpan.');
    }

    public function start(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('start', $recoveryCase);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        $service->start($recoveryCase, $request->user(), $validated['idempotency_key'], $validated['reason'] ?? null);

        return back()->with('success', 'Pengambilan dimulai.');
    }

    /**
     * Owner 2026-10-09: one technician form (SN, kondisi, catatan, foto). From
     * assigned/scheduled the service starts and captures atomically. Photos
     * are split by kind: label SN + perangkat utuh (both required unless the
     * device is lost).
     */
    public function capture(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryItem $item, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('capture', $recoveryCase);
        $validated = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:128'],
            'condition' => ['required', Rule::in(['good', 'damaged', 'lost', 'unknown'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            ...$this->pickupPhotoRules(),
            'idempotency_key' => ['required', 'string', 'max:191'],
        ], $this->pickupPhotoMessages());

        $photos = [];
        $captureToken = bin2hex(random_bytes(16));
        try {
            $photos = $this->storePickupPhotos($request);

            $service->recordPickup(
                $recoveryCase,
                $item,
                $request->user(),
                $validated['serial_number'] ?? null,
                $validated['condition'],
                $validated['reason'] ?? null,
                $photos,
                $validated['idempotency_key'],
                $captureToken,
            );

            $event = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            $eventMetadata = $event?->metadata ?? [];
            if (($eventMetadata['capture_token'] ?? null) !== $captureToken) {
                $this->deletePhotos(array_column($photos, 'path'));
            }
        } catch (\Throwable $exception) {
            $this->deletePhotos(array_column($photos, 'path'));

            throw $exception;
        }

        return back()->with('success', 'Hasil pengambilan perangkat tersimpan.');
    }

    /**
     * Owner 2026-10-09: warehouse receipt verifies the physical SN (typed or
     * scanned) against the captured SN and requires >= 1 receipt photo.
     */
    public function receive(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('receive', $recoveryCase);
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
            'serials' => ['required', 'array'],
            'serials.*' => ['nullable', 'string', 'max:128'],
            'receipt_photos' => ['required', 'array', 'min:1', 'max:3'],
            'receipt_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'submit_as_exception' => ['nullable', 'boolean'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ], [
            'serials.required' => 'SN fisik perangkat wajib diketik atau di-scan.',
            'receipt_photos.required' => 'Foto penerimaan gudang wajib diunggah minimal 1.',
            'receipt_photos.min' => 'Foto penerimaan gudang wajib diunggah minimal 1.',
            'receipt_photos.max' => 'Foto penerimaan gudang maksimal 3.',
            'receipt_photos.*.image' => 'Foto penerimaan harus berupa gambar.',
            'receipt_photos.*.mimes' => 'Foto penerimaan harus JPG, PNG, atau WEBP.',
            'receipt_photos.*.max' => 'Ukuran foto penerimaan maksimal 5 MB.',
        ]);

        $paths = [];
        $receiptToken = bin2hex(random_bytes(16));
        try {
            foreach ((array) $request->file('receipt_photos', []) as $photo) {
                $paths[] = $photo->store('device-recovery', 'private');
            }

            $case = $service->receiveVerified(
                $recoveryCase,
                $request->user(),
                (array) $validated['serials'],
                $paths,
                (bool) ($validated['submit_as_exception'] ?? false),
                $validated['idempotency_key'],
                $validated['reason'] ?? null,
                ['receipt_token' => $receiptToken],
            );

            $event = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if (($event?->metadata['receipt_token'] ?? null) !== $receiptToken) {
                $this->deletePhotos($paths);
            }
        } catch (\Throwable $exception) {
            $this->deletePhotos($paths);

            throw $exception;
        }

        if ($warning = $this->terminationDeferredFlash($event ?? null)) {
            return back()->with('warning', $warning);
        }

        return back()->with('success', $case->status === DeviceRecoveryService::STATUS_EXCEPTION
            ? 'SN gudang tidak cocok: case masuk tinjauan exception.'
            : 'Perangkat diterima gudang.');
    }

    /** Text T1 (owner approved) when the receipt deferred the customer termination. */
    private function terminationDeferredFlash(?DeviceRecoveryEvent $event): ?string
    {
        $meta = (array) ($event?->metadata ?? []);
        if (($meta['customer_termination_deferred'] ?? false) !== true) {
            return null;
        }
        $customerId = (int) ($meta['customer_id'] ?? 0);
        $code = Customer::withTrashed()->whereKey($customerId)->value('code') ?: '#'.$customerId;

        return 'Perangkat diterima gudang. Pelanggan '.$code.' BELUM diterminate karena masih terdeteksi dipakai: '
            .DeviceRecoveryService::deferralReasonText($meta)
            .' Tagihan baru ditahan dan pelanggan tidak diisolir. Sistem cek ulang otomatis tiap jam; pastikan SN yang diterima benar.';
    }

    /**
     * Owner 2026-10-09: admin quick record for a device already collected:
     * technician + SN + kondisi + required photos + receipt confirmation, in
     * one transaction straight to received (or exception).
     */
    public function quickRecord(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('quickRecord', $recoveryCase);
        $validated = $request->validate([
            'technician_id' => ['required', 'integer', 'exists:users,id'],
            'serial_number' => ['nullable', 'string', 'max:128'],
            'condition' => ['required', Rule::in(['good', 'damaged', 'lost', 'unknown'])],
            'reason' => ['nullable', 'string', 'max:2000'],
            ...$this->pickupPhotoRules(),
            'confirm_received' => ['accepted'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ], [
            ...$this->pickupPhotoMessages(),
            'technician_id.required' => 'Pilih teknisi yang mengambil perangkat.',
            'technician_id.exists' => 'Pilih teknisi yang mengambil perangkat.',
            'confirm_received.accepted' => 'Konfirmasi bahwa perangkat sudah diterima di gudang.',
        ]);

        $photos = [];
        $recordToken = bin2hex(random_bytes(16));
        try {
            $photos = $this->storePickupPhotos($request);

            $case = $service->quickRecord(
                $recoveryCase,
                $request->user(),
                (int) $validated['technician_id'],
                $validated['serial_number'] ?? null,
                $validated['condition'],
                $validated['reason'] ?? null,
                $photos,
                $validated['idempotency_key'],
                ['record_token' => $recordToken],
            );

            $event = DeviceRecoveryEvent::query()
                ->where('idempotency_key', $validated['idempotency_key'])
                ->first();
            if (($event?->metadata['record_token'] ?? null) !== $recordToken) {
                $this->deletePhotos(array_column($photos, 'path'));
            }
        } catch (\Throwable $exception) {
            $this->deletePhotos(array_column($photos, 'path'));

            throw $exception;
        }

        if ($warning = $this->terminationDeferredFlash($event ?? null)) {
            return back()->with('warning', $warning);
        }

        return back()->with('success', $case->status === DeviceRecoveryService::STATUS_EXCEPTION
            ? 'Perangkat dicatat sebagai exception dan menunggu tinjauan.'
            : 'Perangkat dicatat sudah diambil dan diterima gudang.');
    }

    public function resolveException(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('resolveException', $recoveryCase);
        $validated = $request->validate([
            'resolution' => ['required', Rule::in(['accept', 'reopen'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        $service->resolveException(
            $recoveryCase,
            $request->user(),
            $validated['resolution'],
            $validated['reason'],
            $validated['idempotency_key'],
        );

        return back()->with('success', 'Exception recovery diproses.');
    }

    public function cancel(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('cancel', $recoveryCase);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        $service->cancel($recoveryCase, $request->user(), $validated['reason'], $validated['idempotency_key']);

        return back()->with('success', 'Recovery case dibatalkan tanpa menghapus histori.');
    }

    public function keepCustomerActive(Request $request, DeviceRecoveryCase $recoveryCase, DeviceRecoveryService $service)
    {
        Gate::forUser($request->user())->authorize('keepCustomerActive', $recoveryCase);
        $validated = $request->validate([
            'keep_customer_active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ]);
        $service->setKeepCustomerActive(
            $recoveryCase,
            $request->user(),
            (bool) $validated['keep_customer_active'],
            $validated['reason'] ?? null,
            $validated['idempotency_key'],
        );

        return back()->with('success', $validated['keep_customer_active']
            ? 'Pelanggan tetap aktif setelah perangkat diterima.'
            : 'Pelanggan akan di-terminate saat perangkat diterima gudang.');
    }

    private function pickupPhotoRules(): array
    {
        return [
            'label_sn_photos' => ['nullable', 'array', 'max:2'],
            'label_sn_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'device_photos' => ['nullable', 'array', 'max:3'],
            'device_photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    private function pickupPhotoMessages(): array
    {
        return [
            'label_sn_photos.max' => 'Foto label SN maksimal 2.',
            'device_photos.max' => 'Foto perangkat utuh maksimal 3.',
            'label_sn_photos.*.image' => 'Foto label SN harus berupa gambar.',
            'device_photos.*.image' => 'Foto perangkat utuh harus berupa gambar.',
            'label_sn_photos.*.mimes' => 'Foto label SN harus JPG, PNG, atau WEBP.',
            'device_photos.*.mimes' => 'Foto perangkat utuh harus JPG, PNG, atau WEBP.',
            'label_sn_photos.*.max' => 'Ukuran foto label SN maksimal 5 MB.',
            'device_photos.*.max' => 'Ukuran foto perangkat utuh maksimal 5 MB.',
        ];
    }

    /**
     * @return list<array{path: string, kind: string}>
     */
    private function storePickupPhotos(Request $request): array
    {
        $photos = [];
        try {
            foreach ([
                'label_sn_photos' => DeviceRecoveryService::PHOTO_KIND_LABEL_SN,
                'device_photos' => DeviceRecoveryService::PHOTO_KIND_DEVICE,
            ] as $field => $kind) {
                foreach ((array) $request->file($field, []) as $photo) {
                    $photos[] = ['path' => $photo->store('device-recovery', 'private'), 'kind' => $kind];
                }
            }
        } catch (\Throwable $exception) {
            $this->deletePhotos(array_column($photos, 'path'));

            throw $exception;
        }

        return $photos;
    }

    private function deletePhotos(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('private')->delete($path);
        }
    }

    private function applyScope($query, User $user): void
    {
        if ($user->hasAreaScope()) {
            $query->whereIn('area_id', $user->accessibleAreaIds()->all());
        }
        if ($user->isTeknisi()) {
            $query->where('assigned_to', $user->id);
        }
    }

    /** Filter values that cover several sub-statuses; they match the summary tiles. */
    private const STATUS_GROUPS = [
        'in_progress' => [
            DeviceRecoveryService::STATUS_ASSIGNED,
            DeviceRecoveryService::STATUS_SCHEDULED,
            DeviceRecoveryService::STATUS_PICKUP_STARTED,
            DeviceRecoveryService::STATUS_CARRIED,
        ],
        'needs_review' => [
            DeviceRecoveryService::STATUS_EXCEPTION,
            DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED,
        ],
    ];

    /**
     * One filter option per visible label: sub-statuses sharing a label collapse into
     * their group key, so the UI shows a single chip per label.
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusChips(DeviceRecoveryService $service): array
    {
        $chips = [];
        $seen = [];
        foreach ($this->statusOptions($service) as $status => $label) {
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;
            $groupKey = collect(self::STATUS_GROUPS)->search(fn (array $members): bool => in_array($status, $members, true));
            $chips[] = ['value' => $groupKey !== false ? (string) $groupKey : $status, 'label' => $label];
        }

        return $chips;
    }

    private function statusOptions(DeviceRecoveryService $service): array
    {
        return array_combine(
            [
                DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT,
                DeviceRecoveryService::STATUS_ASSIGNED,
                DeviceRecoveryService::STATUS_SCHEDULED,
                DeviceRecoveryService::STATUS_PICKUP_STARTED,
                DeviceRecoveryService::STATUS_CARRIED,
                DeviceRecoveryService::STATUS_RECEIVED,
                DeviceRecoveryService::STATUS_EXCEPTION,
                DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED,
                DeviceRecoveryService::STATUS_CANCELLED,
                DeviceRecoveryService::STATUS_REUSED,
            ],
            array_map(
                fn (string $status): string => $service->statusPresentation($status)['group'],
                [
                    DeviceRecoveryService::STATUS_WAITING_ASSIGNMENT,
                    DeviceRecoveryService::STATUS_ASSIGNED,
                    DeviceRecoveryService::STATUS_SCHEDULED,
                    DeviceRecoveryService::STATUS_PICKUP_STARTED,
                    DeviceRecoveryService::STATUS_CARRIED,
                    DeviceRecoveryService::STATUS_RECEIVED,
                    DeviceRecoveryService::STATUS_EXCEPTION,
                    DeviceRecoveryService::STATUS_EXCEPTION_ACCEPTED,
                    DeviceRecoveryService::STATUS_CANCELLED,
                    DeviceRecoveryService::STATUS_REUSED,
                ],
            ),
        );
    }

    /** Banner T10 data: the customer's open deferred termination (case detail only). */
    private function pendingTerminationPayload(DeviceRecoveryCase $case): ?array
    {
        if (! $case->customer_id) {
            return null;
        }
        $row = CustomerPendingTermination::query()
            ->where('customer_id', $case->customer_id)
            ->pending()
            ->first();
        if (! $row) {
            return null;
        }

        return [
            'id' => $row->id,
            'reason_label' => LifecycleTerminationService::reasonLabel($row->last_reasons),
            'requested_at' => $row->requested_at?->timezone('Asia/Jakarta')->toIso8601String(),
        ];
    }

    private function payload(
        DeviceRecoveryCase $case,
        DeviceRecoveryService $service,
        bool $withEvents = false,
        ?DeviceRecoveryReconciliationService $reconciliation = null,
    ): array {
        $presentation = $service->statusPresentation($case->status);

        return [
            'id' => $case->id,
            'ticket' => $case->ticket ? [
                'id' => $case->ticket->id,
                'code' => $case->ticket->code,
                'status' => $case->ticket->status,
                'dismantle_reason' => $case->ticket->dismantle_reason,
                'dismantle_reason_label' => DismantleReason::label($case->ticket->dismantle_reason),
                'dismantle_reason_note' => $case->ticket->dismantle_reason_note,
            ] : null,
            'customer' => $case->customer ? ['id' => $case->customer->id, 'code' => $case->customer->code, 'name' => $case->customer->name] : null,
            'area' => $case->area ? ['id' => $case->area->id, 'name' => $case->area->name, 'code' => $case->area->code] : null,
            'status' => $case->status,
            'keep_customer_active' => (bool) $case->keep_customer_active,
            'status_group' => $presentation['group'],
            'substatus' => $presentation['substatus'],
            'assigned_to' => $case->assignee ? ['id' => $case->assignee->id, 'name' => $case->assignee->name] : null,
            'scheduled_at' => $case->scheduled_at?->timezone('Asia/Jakarta')->toIso8601String(),
            'started_at' => $case->started_at?->timezone('Asia/Jakarta')->toIso8601String(),
            'received_at' => $case->received_at?->timezone('Asia/Jakarta')->toIso8601String(),
            'exception_reason' => $case->exception_reason === null
                ? null
                : SensitiveDataRedactor::redact((string) $case->exception_reason),
            'version' => $case->version,
            'pending_termination' => $withEvents ? $this->pendingTerminationPayload($case) : null,
            'reconciliation' => $withEvents && $reconciliation
                ? $reconciliation->forCase($case, $service)
                : null,
            'items' => $case->items->map(fn (DeviceRecoveryItem $item): array => [
                'id' => $item->id,
                'device_type' => $item->device_type,
                'expected_serial_number' => $item->expected_serial_number,
                'captured_serial_number' => $item->captured_serial_number,
                'status' => $item->status,
                'condition' => $item->condition,
                'captured_at' => $item->captured_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'verified_at' => $item->verified_at?->timezone('Asia/Jakarta')->toIso8601String(),
                'has_photos' => is_array($item->photo_paths) && $item->photo_paths !== [],
                'warehouse_serial_number' => $withEvents ? data_get($item->meta, 'warehouse_serial') : null,
                'photos' => $withEvents
                    ? collect(is_array($item->photo_paths) ? array_values($item->photo_paths) : [])
                        ->keys()
                        ->map(fn (int $index): array => [
                            'index' => $index,
                            'kind' => $this->photoKind($item, $index),
                            'url' => URL::temporarySignedRoute('device-recovery.photo', now()->addMinutes(15), [
                                'recoveryCase' => $case->id,
                                'item' => $item->id,
                                'index' => $index,
                            ]),
                        ])
                        ->values()
                        ->all()
                    : [],
                'notes' => $withEvents && $item->notes !== null
                    ? SensitiveDataRedactor::redact((string) $item->notes)
                    : null,
            ])->values()->all(),
            'events' => $withEvents ? $case->events->map(fn ($event): array => [
                'id' => $event->id,
                'item_id' => $event->item_id,
                'event_type' => $event->event_type,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'reason' => $event->reason === null
                    ? null
                    : SensitiveDataRedactor::redact((string) $event->reason),
                'source' => $event->source,
                'created_at' => $event->created_at?->timezone('Asia/Jakarta')->toIso8601String(),
            ])->values()->all() : [],
        ];
    }

    private function photoKind(DeviceRecoveryItem $item, int $index): ?string
    {
        $kinds = data_get($item->meta, 'photo_kinds');
        $kind = is_array($kinds) ? ($kinds[$index] ?? null) : null;

        return in_array($kind, DeviceRecoveryService::PHOTO_KINDS, true) ? $kind : null;
    }
}
