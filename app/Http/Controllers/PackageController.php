<?php

namespace App\Http\Controllers;

use App\Enums\CustomerStatus;
use App\Http\Requests\PackageRequest;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PackageController extends Controller
{
    /**
     * Display a listing of packages
     */
    /**
     * Display a listing of packages
     */
    public function index(Request $request)
    {
        $limit = min(max((int) $request->input('limit', 20), 1), 100);

        $allowedSorts = ['name', 'price', 'mikrotik_profile', 'customers_count', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'price';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';

        $normalizedSearch = $request->search
            ? strtoupper(preg_replace('/[\s\-_]+/', '', (string) $request->search))
            : null;

        $packages = Package::with('router:id,name')
            ->withCount('customers')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('mikrotik_profile', 'like', "%{$search}%")
                        ->orWhere('rate_limit', 'like', "%{$search}%");
                });
            })
            ->when($normalizedSearch, function ($query, $search) {
                $query->orWhereRaw("REPLACE(REPLACE(REPLACE(UPPER(name), ' ', ''), '-', ''), '_', '') LIKE ?", ["%{$search}%"])
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(UPPER(mikrotik_profile), ' ', ''), '-', ''), '_', '') LIKE ?", ["%{$search}%"]);
            })
            ->orderBy($sort, $direction)
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Packages/Index', [
            'packages' => $packages,
            'filters' => [
                'search' => $request->search,
                'limit' => $limit,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Show the form for creating a new package
     */
    public function create()
    {
        return Inertia::render('Packages/Create', [
            'routers' => Router::query()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Store a newly created package
     */
    public function store(PackageRequest $request)
    {
        $validated = $request->validated();

        Package::create($validated);

        return redirect()->route('packages.index')
            ->with('success', 'Package created successfully.');
    }

    /**
     * Display the specified package
     */
    public function show(Package $package)
    {
        // Same population as the customer table on this page: e-billing customers, soft-deleted excluded.
        $package->load(['router:id,name'])->loadCount([
            'customers' => fn ($q) => $q->ebilling(),
            'customers as billable_customers_count' => fn ($q) => $q->ebilling()->whereIn('status', CustomerStatus::billable()),
        ]);

        return Inertia::render('Packages/Show', [
            'package' => $package,
        ]);
    }

    /**
     * Paginated customers on this package (JSON for the detail page table).
     * Soft-deleted customers are excluded; same shape as the router detail list.
     */
    public function customers(Request $request, Package $package)
    {
        $query = $package->customers()->ebilling()->with('area:id,name');

        // Query params can arrive as arrays (?search[]=x): treat anything but a string as empty.
        $searchInput = $request->input('search');
        $search = is_string($searchInput) ? trim($searchInput) : '';
        $statusInput = $request->input('status');
        $statusInput = is_string($statusInput) ? $statusInput : '';

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('pppoe_user', 'like', "%{$search}%");
            });
        }

        $allowedStatuses = ['pending_installation', 'active', 'isolated', 'suspended', 'terminated', 'inactive'];
        $statuses = array_values(array_intersect(
            explode(',', $statusInput),
            $allowedStatuses,
        ));
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $customers = $query
            ->select(['id', 'name', 'code', 'pppoe_user', 'status', 'package_id', 'area_id', 'is_online'])
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(min(max((int) $request->input('limit', 20), 1), 100));

        return response()->json($customers);
    }

    /**
     * Show the form for editing the specified package
     */
    public function edit(Package $package)
    {
        return Inertia::render('Packages/Edit', [
            'package' => $package,
            'routers' => Router::query()->select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified package
     */
    public function update(PackageRequest $request, Package $package)
    {
        $validated = $request->validated();

        if (($validated['is_active'] ?? false) === true) {
            if ((float) ($validated['price'] ?? 0) <= 0) {
                return back()->withErrors(['price' => 'Paket harus memiliki harga lebih dari Rp0 sebelum diaktifkan.']);
            }

            $profile = trim((string) ($validated['mikrotik_profile'] ?? ''));
            if ($profile === '') {
                return back()->withErrors(['mikrotik_profile' => 'Paket aktif wajib memiliki MikroTik Profile.']);
            }

            $duplicate = Package::query()
                ->whereKeyNot($package->id)
                ->where('router_id', $validated['router_id'] ?? $package->router_id)
                ->where('mikrotik_profile', $profile)
                ->where('is_active', true)
                ->exists();

            if ($duplicate) {
                return back()->withErrors(['mikrotik_profile' => 'Profile ini sudah memiliki paket aktif pada router tersebut.']);
            }
        }

        DB::transaction(function () use ($package, $validated) {
            $package->update($validated);

            $customerIds = $package->customers()->ebilling()->pluck('customers.id');
            Invoice::query()
                ->whereIn('customer_id', $customerIds)
                ->where('status', 'unpaid')
                ->whereDoesntHave('transactions')
                ->update(['amount' => $package->price]);
        });

        // TODO: Log this change with spatie/laravel-activitylog
        // activity()->performedOn($package)->log('updated package price');

        return redirect()->route('packages.index')
            ->with('success', 'Package updated successfully.');
    }

    /**
     * Remove the specified package
     */
    public function destroy(Package $package)
    {
        // Prevent deletion if package has customers
        if ($package->customers()->ebilling()->count() > 0) {
            return back()->with('error', 'Cannot delete package with active customers.');
        }

        $package->delete();

        return redirect()->route('packages.index')
            ->with('success', 'Package deleted successfully.');
    }
}
