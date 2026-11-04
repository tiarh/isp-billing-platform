<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Services\IndonesianPhoneNormalizer;
use App\Support\PerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Saleskit: calon pelanggan (prospek) per daerah + RT/RW.
 *
 * Access: admin/superadmin/noc see everything; teknisi may add and view leads
 * (edit/delete limited to admins or the creator). No device writes.
 */
class SalesLeadController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = [
            'status' => (string) $request->query('status', ''),
            'area_id' => $request->query('area_id'),
            'daerah' => (string) $request->query('daerah', ''),
            'rt' => (string) $request->query('rt', ''),
            'rw' => (string) $request->query('rw', ''),
            'reason' => (string) $request->query('reason', ''),
            'search' => (string) $request->query('search', ''),
        ];

        $limit = PerPage::from($request, 50);

        $query = SalesLead::query()->with(['area:id,name', 'customer:id,name,code']);

        if (in_array($filters['status'], SalesLead::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }
        if ((int) $filters['area_id'] > 0) {
            $query->where('area_id', (int) $filters['area_id']);
        }
        if ($filters['daerah'] !== '') {
            $query->where('daerah', $filters['daerah']);
        }
        if ($filters['rt'] !== '') {
            $query->where('rt', $filters['rt']);
        }
        if ($filters['rw'] !== '') {
            $query->where('rw', $filters['rw']);
        }
        if (in_array($filters['reason'], SalesLead::REASONS, true)) {
            $query->where('reason_code', $filters['reason']);
        }
        if ($filters['search'] !== '') {
            $search = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['search']).'%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhere('address', 'like', $search)
                    ->orWhere('code', 'like', $search);
            });
        }

        $leads = $query->orderBy('daerah')->orderBy('rw')->orderBy('rt')->orderByDesc('id')
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Saleskit/Index', [
            'leads' => $leads,
            'filters' => $filters + ['limit' => $limit],
            'summary' => $this->summary($filters),
            'areas' => Area::query()->orderBy('name')->get(['id', 'name']),
            'daerahOptions' => SalesLead::query()->select('daerah')->distinct()->orderBy('daerah')->pluck('daerah'),
            'reasons' => SalesLead::REASONS,
            'statuses' => SalesLead::STATUSES,
            'canManage' => (bool) $user?->isAdminLike(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()?->id;
        $data['source'] = $data['source'] ?? 'manual';
        $data['status'] = SalesLead::STATUS_CALON;

        $lead = SalesLead::create($data);
        $this->autoMarkInstalled($lead);

        return back()->with('success', "Calon pelanggan {$lead->name} ditambahkan.");
    }

    public function update(Request $request, SalesLead $salesLead): RedirectResponse
    {
        $this->authorizeManage($request, $salesLead);
        $data = $this->validated($request, $salesLead);

        // Manual "terpasang" toggle: keep installed_at consistent.
        if (($data['status'] ?? null) === SalesLead::STATUS_TERPASANG && $salesLead->status !== SalesLead::STATUS_TERPASANG) {
            $data['installed_at'] = now();
            $this->autoMarkInstalled($salesLead, $data);
        }

        $salesLead->update($data);

        return back()->with('success', 'Data calon pelanggan diperbarui.');
    }

    public function destroy(Request $request, SalesLead $salesLead): RedirectResponse
    {
        $this->authorizeManage($request, $salesLead);
        $salesLead->delete();

        return back()->with('success', 'Data calon pelanggan dihapus.');
    }

    /**
     * Import CSV (Excel "Save as CSV"). Wajib: nama + daerah.
     * Dedupe by phone, or name+daerah+rt+rw.
     */
    public function import(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdminLike(), 403);

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,text/csv,text/plain'],
        ]);

        $path = $request->file('file')?->getRealPath();
        if ($path === false || $path === null) {
            return back()->with('error', 'File tidak terbaca.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return back()->with('error', 'Gagal membuka file CSV.');
        }

        $header = null;
        $inserted = 0;
        $skipped = 0;
        $duplicate = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            if ($header === null) {
                $row[0] = isset($row[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) : $row[0];
                $header = array_map(fn ($h) => Str::slug(strtolower(trim((string) $h)), '_'), $row);

                continue;
            }

            $data = [];
            foreach ($header as $i => $key) {
                $data[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $name = $data['nama'] ?? $data['name'] ?? '';
            $daerah = $data['daerah'] ?? $data['area_name'] ?? $data['area'] ?? '';
            if ($name === '' || $daerah === '') {
                $skipped++;

                continue;
            }

            $phone = $data['phone'] ?? $data['hp'] ?? $data['no_hp'] ?? $data['telepon'] ?? null;
            $rt = $data['rt'] ?? null;
            $rw = $data['rw'] ?? null;

            if ($this->isDuplicate($phone, $name, $daerah, $rt, $rw)) {
                $duplicate++;

                continue;
            }

            $lead = SalesLead::create([
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'address' => ($data['alamat'] ?? $data['address'] ?? '') ?: null,
                'daerah' => $daerah,
                'rt' => $rt !== '' ? $rt : null,
                'rw' => $rw !== '' ? $rw : null,
                'odp_name' => ($data['odp'] ?? $data['odp_name'] ?? '') ?: null,
                'source' => 'excel',
                'status' => SalesLead::STATUS_CALON,
                'created_by' => $request->user()?->id,
            ]);
            $this->autoMarkInstalled($lead);
            $inserted++;
        }

        fclose($handle);

        return back()->with(
            'success',
            "Import selesai: {$inserted} ditambahkan, {$duplicate} duplikat, {$skipped} dilewati (nama/daerah kosong)."
        );
    }

    public function export(Request $request): StreamedResponse
    {
        abort_unless($request->user()?->isAdminLike(), 403);

        $filename = 'saleskit-'.now('Asia/Jakarta')->format('Ymd-His').'.csv';

        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['kode', 'nama', 'hp', 'alamat', 'area', 'daerah', 'rt', 'rw', 'odp', 'status', 'alasan', 'catatan']);

            SalesLead::query()->with('area:id,name')->orderBy('daerah')->orderBy('rw')->orderBy('rt')
                ->chunk(500, function ($chunk) use ($out): void {
                    foreach ($chunk as $lead) {
                        fputcsv($out, [
                            $lead->code,
                            $lead->name,
                            $lead->phone,
                            $lead->address,
                            $lead->area?->name,
                            $lead->daerah,
                            $lead->rt,
                            $lead->rw,
                            $lead->odp_name,
                            $lead->status,
                            $lead->reason_code,
                            $lead->reason_note ?? $lead->notes,
                        ]);
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Re-run auto "terpasang" matching for every open lead (admin). */
    public function syncInstalled(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdminLike(), 403);

        $updated = 0;
        SalesLead::query()->where('status', SalesLead::STATUS_CALON)->chunkById(500, function ($chunk) use (&$updated): void {
            foreach ($chunk as $lead) {
                if ($this->autoMarkInstalled($lead)) {
                    $updated++;
                }
            }
        });

        return back()->with('success', "Sinkron selesai: {$updated} calon ditandai terpasang.");
    }

    /** @return array<string, mixed> */
    private function summary(array $filters): array
    {
        $base = SalesLead::query();
        if ((int) ($filters['area_id'] ?? 0) > 0) {
            $base->where('area_id', (int) $filters['area_id']);
        }
        if (($filters['daerah'] ?? '') !== '') {
            $base->where('daerah', $filters['daerah']);
        }

        $total = (clone $base)->count();
        $installed = (clone $base)->where('status', SalesLead::STATUS_TERPASANG)->count();
        $cancelled = (clone $base)->where('status', SalesLead::STATUS_BATAL)->count();
        $open = (clone $base)->where('status', SalesLead::STATUS_CALON)->count();

        $byReason = (clone $base)
            ->where('status', SalesLead::STATUS_CALON)
            ->whereNotNull('reason_code')
            ->selectRaw('reason_code, COUNT(*) as total')
            ->groupBy('reason_code')
            ->pluck('total', 'reason_code')
            ->all();

        return [
            'total' => $total,
            'installed' => $installed,
            'open' => $open,
            'cancelled' => $cancelled,
            'conversion' => $total > 0 ? round($installed / $total * 100, 1) : 0.0,
            'by_reason' => $byReason,
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?SalesLead $lead = null): array
    {
        $required = $lead === null ? 'required' : 'sometimes';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:500'],
            'area_id' => ['nullable', 'integer', 'exists:areas,id'],
            'daerah' => [$required, 'string', 'max:64'],
            'rt' => ['nullable', 'string', 'max:8'],
            'rw' => ['nullable', 'string', 'max:8'],
            'odp_name' => ['nullable', 'string', 'max:128'],
            'status' => ['nullable', 'in:'.implode(',', SalesLead::STATUSES)],
            'reason_code' => ['nullable', 'in:'.implode(',', SalesLead::REASONS)],
            'reason_note' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($lead === null) {
            $data['code'] = $this->nextCode();
        }

        return $data;
    }

    private function nextCode(): string
    {
        $prefix = 'LEAD-'.now('Asia/Jakarta')->format('ym').'-';
        $last = SalesLead::query()->where('code', 'like', $prefix.'%')->orderByDesc('code')->value('code');
        $seq = $last ? ((int) substr((string) $last, -4)) + 1 : 1;

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function isDuplicate(?string $phone, string $name, string $daerah, ?string $rt, ?string $rw): bool
    {
        if (is_string($phone) && trim($phone) !== '') {
            $digits = $this->phoneKey($phone);
            if ($digits !== '' && SalesLead::query()->where('phone', 'like', '%'.substr($digits, -8).'%')->exists()) {
                return true;
            }
        }

        return SalesLead::query()
            ->where('daerah', $daerah)
            ->where('rt', $rt)
            ->where('rw', $rw)
            ->whereRaw('UPPER(TRIM(name)) = ?', [mb_strtoupper(trim($name))])
            ->exists();
    }

    /** Mark installed when a matching customer exists (by phone, else name). */
    private function autoMarkInstalled(SalesLead $lead, ?array &$pending = null): bool
    {
        if ($lead->status === SalesLead::STATUS_TERPASANG) {
            return false;
        }

        $customer = $this->matchCustomer($lead);
        if (! $customer) {
            return false;
        }

        $payload = [
            'status' => SalesLead::STATUS_TERPASANG,
            'converted_customer_id' => $customer->id,
            'installed_at' => $lead->installed_at ?? now(),
        ];

        if ($pending !== null) {
            $pending = array_merge($pending, $payload);

            return true;
        }

        $lead->forceFill($payload)->save();

        return true;
    }

    private function matchCustomer(SalesLead $lead): ?Customer
    {
        $phoneKey = $this->phoneKey((string) $lead->phone);
        if ($phoneKey !== '') {
            $byPhone = Customer::query()
                ->whereNotNull('phone')
                ->get(['id', 'phone', 'name'])
                ->first(fn (Customer $c) => $this->phoneKey((string) $c->phone) === $phoneKey);
            if ($byPhone) {
                return $byPhone;
            }
        }

        $nameKey = $this->nameKey((string) $lead->name);
        if ($nameKey !== '') {
            return Customer::query()
                ->get(['id', 'name'])
                ->first(fn (Customer $c) => $this->nameKey((string) $c->name) === $nameKey);
        }

        return null;
    }

    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if ($digits === '') {
            return '';
        }
        $normalized = app(IndonesianPhoneNormalizer::class)->normalize($phone);
        $local = (string) ($normalized['canonical_local'] ?? '');

        return $local !== '' ? $local : $digits;
    }

    private function nameKey(string $name): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(trim($name))) ?? '';
    }

    private function authorizeManage(Request $request, SalesLead $lead): void
    {
        $user = $request->user();
        if ($user?->isAdminLike()) {
            return;
        }
        abort_unless($user && (int) $lead->created_by === (int) $user->id, 403);
    }
}
