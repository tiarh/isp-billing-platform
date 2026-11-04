<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Models\Area;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Router;
use App\Services\DueDayResolver;
use App\Support\AreaScope;
use App\Support\OpsTime;
use App\Support\PerPage;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PsbImportController extends Controller
{
    public function index(Request $request): Response
    {
        $this->ensureResolvedColumns();

        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();
        $year = (int) ($request->input('year') ?: OpsTime::now()->year);
        if ($year < 2000 || $year > 2100) {
            $year = (int) OpsTime::now()->year;
        }

        $query = DB::table('psb_import_candidates as p')
            ->leftJoin('customers as c', 'c.id', '=', 'p.matched_customer_id')
            ->leftJoin('customers as ac', 'ac.id', '=', 'p.applied_customer_id')
            ->leftJoin('areas as a', 'a.id', '=', 'p.resolved_area_id')
            ->leftJoin('packages as pkg', 'pkg.id', '=', 'p.resolved_package_id')
            ->leftJoin('routers as r', 'r.id', '=', 'p.resolved_router_id')
            ->select([
                'p.id',
                'p.source_file',
                'p.customer_code',
                'p.pppoe_user',
                'p.name',
                'p.address',
                'p.area_name',
                'p.package_name',
                'p.speed',
                'p.price',
                'p.status',
                'p.match_status',
                'p.match_notes',
                'p.router_profile',
                'p.applied_at',
                'c.name as matched_customer_name',
                'ac.name as applied_customer_name',
                'a.name as resolved_area',
                'pkg.name as resolved_package',
                'pkg.price as resolved_package_price',
                'r.name as resolved_router',
            ])
            ->orderByRaw("FIELD(p.status, 'needs_mapping', 'staged', 'applied', 'skipped')")
            ->orderBy('p.id');

        $user = $request->user();
        AreaScope::applyToPsbImportCandidates($query, $user, 'p.resolved_area_id');

        if ($status !== '') {
            $query->where('p.status', $status);
        }

        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('p.customer_code', 'like', "%{$search}%")
                    ->orWhere('p.name', 'like', "%{$search}%")
                    ->orWhere('p.area_name', 'like', "%{$search}%");
            });
        }

        $limit = PerPage::from($request, 30);

        $scopedCandidates = fn () => AreaScope::applyToPsbImportCandidates(DB::table('psb_import_candidates'), $user);

        $summary = $scopedCandidates()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return Inertia::render('PsbImports/Index', [
            'candidates' => $query->paginate($limit)->withQueryString(),
            'filters' => [
                'limit' => $limit,
                'status' => $status,
                'search' => $search,
                'year' => $year,
            ],
            'summary' => [
                'total' => $scopedCandidates()->count(),
                'ready' => (int) ($summary['staged'] ?? 0),
                'needsMapping' => (int) ($summary['needs_mapping'] ?? 0),
                'applied' => (int) ($summary['applied'] ?? 0),
                'existing' => $scopedCandidates()->where('match_status', 'existing_customer')->count(),
            ],
            'chart' => $this->monthlyPsbChart($year),
            'kpis' => $this->psbKpis(),
            'areas' => Area::query()->orderBy('name')->get(['id', 'name']),
            'routers' => Router::query()->orderBy('name')->get(['id', 'name']),
            // Global catalog only — avoid dumping router-bound package clones into HTML
            'packages' => Package::query()
                ->whereNull('router_id')
                ->when(
                    Schema::hasColumn('packages', 'is_active'),
                    fn ($q) => $q->where('is_active', true)
                )
                ->orderBy('name')
                ->get(['id', 'name', 'price']),
        ]);
    }

    /**
     * Import CSV cadangan saat scraper/API gangguan.
     * Kolom: code/customer_code, name, area/area_name, package/package_name, price, speed,
     * pppoe/pppoe_user, phone, address, join_date (opsional).
     */
    public function import(Request $request): RedirectResponse
    {
        $this->ensureResolvedColumns();

        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,text/csv,text/plain'],
            'source_label' => ['nullable', 'string', 'max:120'],
        ]);

        $file = $request->file('file');
        $source = $request->string('source_label')->toString()
            ?: ('manual-import-'.now()->format('Y-m-d_His'));
        $path = $file->getRealPath();
        if ($path === false) {
            return back()->with('error', 'File tidak terbaca.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return back()->with('error', 'Gagal membuka file CSV.');
        }

        $header = null;
        $inserted = 0;
        $skipped = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }
            // strip BOM
            if ($header === null) {
                $row[0] = isset($row[0]) ? preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]) : $row[0];
                $header = array_map(fn ($h) => Str::slug(strtolower(trim((string) $h)), '_'), $row);

                continue;
            }

            $data = [];
            foreach ($header as $i => $key) {
                $data[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
            }

            $code = $data['customer_code'] ?? $data['code'] ?? $data['kode'] ?? '';
            $name = $data['name'] ?? $data['nama'] ?? '';
            if ($code === '' || $name === '') {
                $skipped++;

                continue;
            }

            $pppoe = $data['pppoe_user'] ?? $data['pppoe'] ?? $data['pppoe_username'] ?? $code;
            $area = $data['area_name'] ?? $data['area'] ?? $data['wilayah'] ?? null;
            $package = $data['package_name'] ?? $data['package'] ?? $data['paket'] ?? null;
            $price = isset($data['price']) && $data['price'] !== ''
                ? (float) preg_replace('/[^\d.]/', '', $data['price'])
                : null;
            $speed = $data['speed'] ?? $data['kecepatan'] ?? null;
            $phone = $data['phone'] ?? $data['telp'] ?? $data['hp'] ?? null;
            $address = $data['address'] ?? $data['alamat'] ?? null;
            $joinRaw = $data['join_date'] ?? $data['tgl_daftar'] ?? $data['tanggal'] ?? null;
            $joinDate = null;
            if ($joinRaw) {
                try {
                    $joinDate = Carbon::parse($joinRaw)->toDateString();
                } catch (\Throwable) {
                    $joinDate = null;
                }
            }

            $exists = DB::table('psb_import_candidates')
                ->where('customer_code', $code)
                ->whereNull('applied_customer_id')
                ->exists();
            if ($exists) {
                $skipped++;

                continue;
            }

            DB::table('psb_import_candidates')->insert([
                'source_file' => $source,
                'customer_code' => $code,
                'pppoe_user' => $pppoe,
                'name' => $name,
                'address' => $address,
                'area_name' => $area,
                'phone' => $phone,
                'package_name' => $package,
                'speed' => $speed,
                'price' => $price,
                'status' => 'needs_mapping',
                'match_status' => 'imported',
                'match_notes' => 'Import manual CSV (cadangan scraper)',
                'raw_payload' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'imported_at' => $joinDate ? Carbon::parse($joinDate) : $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $inserted++;
        }
        fclose($handle);

        $this->refreshResolvedFields();

        return back()->with(
            'success',
            "Import CSV selesai: {$inserted} masuk staging, {$skipped} dilewati. Jalankan Refresh Mapping bila perlu."
        );
    }

    public function refresh(): RedirectResponse
    {
        $this->ensureResolvedColumns();
        $count = $this->refreshResolvedFields();

        return back()->with('success', "PSB staging refreshed: {$count} kandidat diproses.");
    }

    public function apply(Request $request, int $id): RedirectResponse
    {
        $this->ensureResolvedColumns();

        $candidate = DB::table('psb_import_candidates')->where('id', $id)->first();
        if (! $candidate) {
            return back()->with('error', 'Kandidat PSB tidak ditemukan.');
        }

        // Scoped admins may only act on candidates already resolved to their own areas
        // (checked before refreshResolvedFields so a foreign row is never mutated).
        AreaScope::authorizeAreaId($candidate->resolved_area_id ? (int) $candidate->resolved_area_id : null, $request->user());

        $this->refreshResolvedFields($id);
        $candidate = DB::table('psb_import_candidates')->where('id', $id)->first();

        if ($candidate->status === 'applied' || $candidate->applied_customer_id) {
            return back()->with('error', 'Kandidat ini sudah pernah di-apply.');
        }

        if (Customer::where('code', $candidate->customer_code)->orWhere('pppoe_user', $candidate->pppoe_user ?: $candidate->customer_code)->exists()) {
            DB::table('psb_import_candidates')->where('id', $id)->update([
                'status' => 'existing_customer',
                'match_status' => 'existing_customer',
                'updated_at' => now(),
            ]);

            return back()->with('error', 'Customer sudah ada di eBilling. Kandidat ditandai existing.');
        }

        if (! $candidate->resolved_area_id || ! $candidate->resolved_package_id) {
            return back()->with('error', 'Belum bisa apply: area atau paket belum berhasil dimapping.');
        }

        AreaScope::authorizeAreaId((int) $candidate->resolved_area_id, $request->user());

        if ($error = $this->mappingError($candidate)) {
            return back()->with('error', $error);
        }

        try {
            $customer = DB::transaction(function () use ($candidate, $id) {
                $customer = Customer::create([
                    'code' => $candidate->customer_code,
                    'name' => $candidate->name,
                    'address' => $candidate->address ?: '-',
                    'phone' => $candidate->phone,
                    'pppoe_user' => $candidate->pppoe_user ?: $candidate->customer_code,
                    'package_id' => $candidate->resolved_package_id,
                    'area_id' => $candidate->resolved_area_id,
                    'router_id' => $candidate->resolved_router_id,
                    'status' => CustomerStatus::Active->value,
                    'join_date' => $candidate->imported_at ?: now(),
                    'due_day' => $this->importDueDay($candidate),
                    'mikrotik_profile' => $candidate->router_profile,
                    'mikrotik_sync_status' => $candidate->matched_router_staged_customer_id ? 'synced' : 'unknown',
                    'mikrotik_sync_checked_at' => now(),
                ]);

                DB::table('psb_import_candidates')->where('id', $id)->update([
                    'status' => 'applied',
                    'match_status' => 'applied',
                    'applied_customer_id' => $customer->id,
                    'applied_at' => now(),
                    'updated_at' => now(),
                ]);

                return $customer;
            });
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'Apply gagal: '.$e->getMessage());
        }

        return back()->with('success', "Customer {$customer->code} dibuat dari staging PSB.");
    }

    public function updateMapping(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate([
            'resolved_area_id' => ['required', 'integer', 'exists:areas,id'],
            'resolved_router_id' => ['nullable', 'integer', 'exists:routers,id'],
            'resolved_package_id' => ['required', 'integer', 'exists:packages,id'],
        ]);

        $candidate = DB::table('psb_import_candidates')->where('id', $id)->first();
        if (! $candidate) {
            return back()->with('error', 'Kandidat PSB tidak ditemukan.');
        }

        // Both the current and the target area must be in scope (no remapping a foreign row into own area).
        AreaScope::authorizeAreaId($candidate->resolved_area_id ? (int) $candidate->resolved_area_id : null, $request->user());
        AreaScope::authorizeAreaId((int) $validated['resolved_area_id'], $request->user());

        DB::table('psb_import_candidates')->where('id', $id)->update([
            'resolved_area_id' => $validated['resolved_area_id'],
            'resolved_router_id' => ($validated['resolved_router_id'] ?? null) ?: null,
            'resolved_package_id' => $validated['resolved_package_id'],
            'status' => 'staged',
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Mapping manual berhasil disimpan, kandidat kini berstatus Siap Apply.');
    }

    public function bulkApply(Request $request): RedirectResponse
    {
        $this->ensureResolvedColumns();

        $candidates = DB::table('psb_import_candidates')
            ->where('status', 'staged')
            ->whereNull('applied_customer_id')
            ->whereNotNull('resolved_area_id')
            ->whereNotNull('resolved_package_id')
            ->get();

        if ($candidates->isEmpty()) {
            return back()->with('error', 'Tidak ada kandidat dengan status Siap Apply.');
        }

        $count = 0;
        $user = $request->user();
        foreach ($candidates as $candidate) {
            if (Customer::where('code', $candidate->customer_code)->orWhere('pppoe_user', $candidate->pppoe_user ?: $candidate->customer_code)->exists()) {
                DB::table('psb_import_candidates')->where('id', $candidate->id)->update([
                    'status' => 'existing_customer',
                    'match_status' => 'existing_customer',
                    'updated_at' => now(),
                ]);

                continue;
            }

            try {
                AreaScope::authorizeAreaId((int) $candidate->resolved_area_id, $user);
            } catch (HttpException) {
                continue;
            }

            if ($this->mappingError($candidate)) {
                continue;
            }

            try {
                DB::transaction(function () use ($candidate) {
                    $customer = Customer::create([
                        'code' => $candidate->customer_code,
                        'name' => $candidate->name,
                        'address' => $candidate->address ?: '-',
                        'phone' => $candidate->phone,
                        'pppoe_user' => $candidate->pppoe_user ?: $candidate->customer_code,
                        'package_id' => $candidate->resolved_package_id,
                        'area_id' => $candidate->resolved_area_id,
                        'router_id' => $candidate->resolved_router_id,
                        'status' => CustomerStatus::Active->value,
                        'join_date' => $candidate->imported_at ?? now(),
                        'due_day' => $this->importDueDay($candidate),
                        'mikrotik_profile' => $candidate->router_profile,
                        'mikrotik_sync_status' => $candidate->matched_router_staged_customer_id ? 'synced' : 'unknown',
                        'mikrotik_sync_checked_at' => now(),
                    ]);

                    DB::table('psb_import_candidates')->where('id', $candidate->id)->update([
                        'status' => 'applied',
                        'match_status' => 'applied',
                        'applied_customer_id' => $customer->id,
                        'applied_at' => now(),
                        'updated_at' => now(),
                    ]);
                });
                $count++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with('success', "Berhasil apply $count customer baru dari staging PSB.");
    }

    /**
     * PSB imports historically use due_day 20; the owner 2026-10-09 fixed-day
     * rule (RA-OLT router / STARLITE-AKUISISI area, default 25) overrides it.
     */
    private function importDueDay(object $candidate): int
    {
        return app(DueDayResolver::class)->fixedDueDayFor(
            $candidate->resolved_router_id ? (int) $candidate->resolved_router_id : null,
            $candidate->resolved_area_id ? (int) $candidate->resolved_area_id : null,
        ) ?? 20;
    }

    private function mappingError(object $candidate): ?string
    {
        $package = DB::table('packages')
            ->where('id', $candidate->resolved_package_id)
            ->where('is_active', true)
            ->first(['id', 'router_id']);

        if (! $package) {
            return 'Paket resolved tidak aktif atau tidak ditemukan.';
        }

        if ($candidate->resolved_router_id && (int) $package->router_id !== (int) $candidate->resolved_router_id) {
            return 'Paket tidak cocok dengan router hasil mapping.';
        }

        return null;
    }

    private function ensureResolvedColumns(): void
    {
        abort_unless(Schema::hasColumn('psb_import_candidates', 'resolved_area_id'), 500, 'Migration PSB resolved fields belum jalan.');
    }

    /**
     * PSB per bulan:
     * - join_count = pelanggan scraper (join_date)
     * - applied_count = staging apply di bulan itu (info)
     * - applied_extra = applied yang BELUM ada di join bulan yang sama (anti double-count)
     * - total_count = join + applied_extra (unik)
     *
     * @return array{year: int, months: list<array{month: string, label: string, join_count: int, applied_count: int, applied_extra: int, total_count: int}>, total_join: int, total_applied: int, total_unique: int}
     */
    private function monthlyPsbChart(int $year): array
    {
        $labels = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
            7 => 'Jul', 8 => 'Agu', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
        ];

        $joinByMonth = Customer::query()
            ->whereNotNull('join_date')
            ->whereYear('join_date', $year)
            ->selectRaw('MONTH(join_date) as m, COUNT(*) as c')
            ->groupBy('m')
            ->pluck('c', 'm');

        // applied_at is UTC; bucket by WIB month (UTC+7, no DST).
        $appliedByMonth = DB::table('psb_import_candidates')
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', [
                OpsTime::monthBounds(sprintf('%04d-01', $year))[0],
                OpsTime::monthBounds(sprintf('%04d-12', $year))[1],
            ])
            ->selectRaw('MONTH(DATE_ADD(applied_at, INTERVAL 7 HOUR)) as m, COUNT(*) as c')
            ->groupBy('m')
            ->pluck('c', 'm');

        $months = [];
        $totalJoin = 0;
        $totalApplied = 0;
        $totalUnique = 0;
        for ($m = 1; $m <= 12; $m++) {
            $join = (int) ($joinByMonth[$m] ?? 0);
            $applied = (int) ($appliedByMonth[$m] ?? 0);
            $extra = $this->appliedStagingExtraCount($year, $m);
            // Anti double-count: hanya tambah applied yang belum terhitung di join scraper bulan itu
            $total = $join + $extra;
            $totalJoin += $join;
            $totalApplied += $applied;
            $totalUnique += $total;
            $months[] = [
                'month' => sprintf('%04d-%02d', $year, $m),
                'label' => $labels[$m],
                'join_count' => $join,
                'applied_count' => $applied,
                'applied_extra' => $extra,
                'total_count' => $total,
            ];
        }

        $years = Customer::query()
            ->whereNotNull('join_date')
            ->selectRaw('YEAR(join_date) y')
            ->groupBy('y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->values()
            ->all();
        if (! in_array($year, $years, true)) {
            $years[] = $year;
            rsort($years);
        }

        return [
            'year' => $year,
            'years' => $years,
            'months' => $months,
            'total_join' => $totalJoin,
            'total_applied' => $totalApplied,
            'total_unique' => $totalUnique,
            'total_sum' => $totalUnique,
        ];
    }

    /**
     * Applied staging in month that are NOT already counted via join_date in the same month.
     */
    private function appliedStagingExtraCount(int $year, int $month): int
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        $applied = DB::table('psb_import_candidates')
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', OpsTime::monthBounds(sprintf('%04d-%02d', $year, $month)))
            ->get(['applied_customer_id', 'customer_code', 'pppoe_user']);

        if ($applied->isEmpty()) {
            return 0;
        }

        $extra = 0;
        foreach ($applied as $row) {
            $customer = null;
            if ($row->applied_customer_id) {
                $customer = Customer::query()->find($row->applied_customer_id);
            }
            if (! $customer && $row->customer_code) {
                $customer = Customer::query()->where('code', $row->customer_code)->first();
            }
            if (! $customer && $row->pppoe_user) {
                $customer = Customer::query()->where('pppoe_user', $row->pppoe_user)->first();
            }

            // No customer / no join_date → staging-only extra
            if (! $customer || ! $customer->join_date) {
                $extra++;

                continue;
            }

            $jd = $customer->join_date instanceof Carbon
                ? $customer->join_date->toDateString()
                : substr((string) $customer->join_date, 0, 10);

            // Already in scraper join for same month → do NOT add again
            if ($jd >= $start && $jd <= $end) {
                continue;
            }

            $extra++;
        }

        return $extra;
    }

    /**
     * KPI unik: join scraper + applied staging yang belum ada di join bulan itu.
     *
     * @return array{this_month: int, last_month: int, ytd: int, total: int, this_month_join: int, this_month_applied: int, this_month_extra: int, last_month_join: int, last_month_applied: int, last_month_extra: int}
     */
    private function psbKpis(): array
    {
        // Month/year boundaries are WIB calendar dates (join_date is a WIB date).
        $nowWib = OpsTime::now();
        $thisY = (int) $nowWib->year;
        $thisM = (int) $nowWib->month;
        $last = $nowWib->startOfMonth()->subMonthNoOverflow();
        $lastY = (int) $last->year;
        $lastM = (int) $last->month;

        $thisMonthStart = $nowWib->startOfMonth()->toDateString();
        $thisMonthEnd = $nowWib->endOfMonth()->toDateString();
        $lastMonthStart = $last->startOfMonth()->toDateString();
        $lastMonthEnd = $last->endOfMonth()->toDateString();
        $yearStart = $nowWib->startOfYear()->toDateString();

        $joinThis = Customer::query()
            ->whereNotNull('join_date')
            ->whereBetween('join_date', [$thisMonthStart, $thisMonthEnd])
            ->count();
        $joinLast = Customer::query()
            ->whereNotNull('join_date')
            ->whereBetween('join_date', [$lastMonthStart, $lastMonthEnd])
            ->count();
        $joinYtd = Customer::query()
            ->whereNotNull('join_date')
            ->where('join_date', '>=', $yearStart)
            ->count();
        $joinAll = Customer::query()->whereNotNull('join_date')->count();

        $appThis = (int) DB::table('psb_import_candidates')
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', OpsTime::monthBounds(sprintf('%04d-%02d', $thisY, $thisM)))
            ->count();
        $appLast = (int) DB::table('psb_import_candidates')
            ->whereNotNull('applied_at')
            ->whereBetween('applied_at', OpsTime::monthBounds(sprintf('%04d-%02d', $lastY, $lastM)))
            ->count();

        $extraThis = $this->appliedStagingExtraCount($thisY, $thisM);
        $extraLast = $this->appliedStagingExtraCount($lastY, $lastM);

        // YTD unique ≈ sum of monthly unique (good enough for ops)
        $ytdUnique = 0;
        for ($m = 1; $m <= $thisM; $m++) {
            $j = Customer::query()
                ->whereNotNull('join_date')
                ->whereYear('join_date', $thisY)
                ->whereMonth('join_date', $m)
                ->count();
            $ytdUnique += $j + $this->appliedStagingExtraCount($thisY, $m);
        }

        return [
            'this_month_join' => $joinThis,
            'this_month_applied' => $appThis,
            'this_month_extra' => $extraThis,
            'this_month' => $joinThis + $extraThis,
            'last_month_join' => $joinLast,
            'last_month_applied' => $appLast,
            'last_month_extra' => $extraLast,
            'last_month' => $joinLast + $extraLast,
            'ytd' => $ytdUnique,
            'total' => $joinAll, // baseline all-time: scraper join (staging extras rare historically)
            'note' => 'total unik = join + applied_extra (applied yang sudah join di bulan sama tidak dijumlah 2x)',
        ];
    }

    private function refreshResolvedFields(?int $onlyId = null): int
    {
        $candidates = DB::table('psb_import_candidates')
            ->whereNull('applied_customer_id')
            ->when($onlyId, fn ($query) => $query->where('id', $onlyId))
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $areas = DB::table('areas')->get(['id', 'name']);
        $areaNameById = $areas->pluck('name', 'id');
        $routers = DB::table('routers')->get(['id', 'name']);
        $routerById = $routers->keyBy('id');
        $allPackages = DB::table('packages')
            ->where('is_active', true)
            ->get(['id', 'name', 'price', 'router_id', 'mikrotik_profile']);
        $packagesById = $allPackages->keyBy('id');
        $packagesByPrice = $allPackages->groupBy(fn ($package) => (string) (float) $package->price);

        $existingByCode = [];
        $existingByPppoe = [];
        foreach (DB::table('customers')->get(['id', 'code', 'pppoe_user']) as $customer) {
            $existingByCode[$this->key($customer->code)] = (int) $customer->id;
            if ($customer->pppoe_user) {
                $existingByPppoe[$this->key($customer->pppoe_user)] = (int) $customer->id;
            }
        }

        $count = 0;
        foreach ($candidates as $candidate) {
            $existingId = $existingByCode[$this->key($candidate->customer_code)]
                ?? $existingByPppoe[$this->key($candidate->pppoe_user ?: $candidate->customer_code)]
                ?? null;
            $existing = $existingId ? (object) ['id' => $existingId] : null;

            $area = ($candidate->resolved_area_id ?? null)
                ? $areas->firstWhere('id', (int) $candidate->resolved_area_id)
                : null;
            $area ??= $this->resolveArea($candidate, $areas);

            $router = ($candidate->resolved_router_id ?? null)
                ? $routerById->get((int) $candidate->resolved_router_id)
                : null;
            $router ??= $this->resolveRouter($candidate, $area?->id, $routers, $routerById, $areaNameById);

            $packages = $packagesByPrice->get((string) (float) ($candidate->price ?? 0), collect());
            $package = ($candidate->resolved_package_id ?? null)
                ? $packagesById->get((int) $candidate->resolved_package_id)
                : null;
            $package ??= $this->resolvePackage($candidate, $router?->id, $packages);
            $ready = ! $existing && $area && $package;
            $status = $existing ? 'existing_customer' : ($ready ? 'staged' : 'needs_mapping');

            DB::table('psb_import_candidates')->where('id', $candidate->id)->update([
                'matched_customer_id' => $existing?->id ?: ($candidate->matched_customer_id ?? null),
                'resolved_area_id' => $area?->id,
                'resolved_router_id' => $router?->id,
                'resolved_package_id' => $package?->id,
                'status' => $status,
                'match_status' => $existing ? 'existing_customer' : $candidate->match_status,
                'match_notes' => $this->notes($candidate, $area, $router, $package, (bool) $existing),
                'updated_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    private function resolveArea(object $candidate, $areas): ?object
    {
        $name = $this->canonicalArea($candidate->area_name);
        $target = $this->key($name);

        return $areas->first(
            fn ($area) => $this->key($area->name) === $target
        );
    }

    private function resolveRouter(object $candidate, ?int $areaId, $routers, $routerById, $areaNameById): ?object
    {
        if ($candidate->matched_router_id ?? null) {
            return $routerById->get($candidate->matched_router_id);
        }

        $areaName = $areaId ? ($areaNameById[$areaId] ?? null) : $this->canonicalArea($candidate->area_name);
        $term = $this->key(str_replace('SKYNET-', '', strtoupper((string) $areaName)));

        return $routers->first(
            fn ($router) => $term !== '' && str_contains($this->key($router->name), $term)
        );
    }

    private function resolvePackage(object $candidate, ?int $routerId, $packages): ?object
    {
        $packageName = $this->key($candidate->package_name);
        $speed = $this->speedNumber($candidate->speed ?: $candidate->package_name);
        $areaTerm = str_replace('SKYNET', '', $this->key($this->canonicalArea($candidate->area_name)));

        return $packages->first(fn ($package) => $this->key($package->name) === $packageName)
            ?: $packages->first(fn ($package) => $routerId && (int) $package->router_id === $routerId && $speed && str_contains($this->key($package->mikrotik_profile.' '.$package->name), (string) $speed))
            ?: $packages->first(fn ($package) => $areaTerm !== '' && str_contains($this->key($package->name), $areaTerm) && ($speed === null || str_contains($this->key($package->name.' '.$package->mikrotik_profile), (string) $speed)));
    }

    private function notes(object $candidate, ?object $area, ?object $router, ?object $package, bool $existing): string
    {
        $notes = [];
        if ($existing) {
            $notes[] = 'Sudah ada customer di eBilling';
        }
        if (! $area) {
            $notes[] = 'Area belum termapping';
        }
        if (! $package) {
            $notes[] = 'Paket belum termapping';
        }
        if (! $router) {
            $notes[] = 'Router belum termapping';
        }

        return $notes ? implode('; ', $notes) : 'Siap apply sebagai customer baru';
    }

    private function canonicalArea(?string $area): string
    {
        $key = $this->key($area);
        $map = [
            'DUASATUNETWORK' => 'SKYNET-GADANG',
            'RANDUAGUNG' => 'SKYNET-RANDUAGUNG',
            'SKYNETBEDALI' => 'SKYNET-BEDALI',
            'SKYNETKLAMPOK' => 'SKYNET-KLAMPOK',
        ];

        return $map[$key] ?? Str::of((string) $area)->replace(' - ', '-')->squish()->toString();
    }

    private function key(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) $value)) ?: '';
    }

    private function speedNumber(?string $value): ?int
    {
        preg_match('/(\d+)/', (string) $value, $match);

        return isset($match[1]) ? (int) $match[1] : null;
    }
}
