<?php

namespace App\Http\Controllers;

use App\Http\Requests\AreaRequest;
use App\Models\Area;
use App\Models\AreaAlias;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class AreaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = Area::query();

        if ($request->search) {
            $query->where(function ($sub) use ($request) {
                $sub->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $allowedSorts = ['name', 'code', 'customers_count', 'olts_count', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $limit = min(max((int) $request->input('limit', 20), 1), 100);

        $areas = $query->withCount(['customers', 'olts'])
            ->orderBy($sort, $direction)
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Areas/Index', [
            'areas' => $areas,
            'filters' => [
                'search' => $request->search,
                'limit' => $limit,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Areas/Create');
    }

    /**
     * Display the specified resource.
     */
    public function show(Area $area)
    {
        $area->loadCount(['customers', 'users']);

        return Inertia::render('Areas/Show', [
            'area' => $area,
            'aliases' => $area->aliases()->pluck('alias')->all(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(AreaRequest $request)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request): void {
            $area = Area::create(Arr::only($validated, ['name', 'code']));
            $this->syncAliases($area, $validated['aliases'] ?? null, $request->user()?->id);
        });

        return redirect()->route('areas.index')
            ->with('success', 'Area created successfully.');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Area $area)
    {
        return Inertia::render('Areas/Edit', [
            'area' => $area,
            'aliases' => $area->aliases()->pluck('alias')->all(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(AreaRequest $request, Area $area)
    {
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $request, $area): void {
            $area->update(Arr::only($validated, ['name', 'code']));
            if ($request->exists('aliases')) {
                $this->syncAliases($area, $validated['aliases'] ?? null, $request->user()?->id);
            }
        });

        return redirect()->route('areas.index')
            ->with('success', 'Area updated successfully.');
    }

    private function syncAliases(Area $area, ?string $input, ?int $userId): void
    {
        $wanted = AreaAlias::parseList($input);

        $area->aliases()->whereNotIn('alias_key', array_keys($wanted))->delete();
        $existing = $area->aliases()->pluck('alias_key')->all();

        foreach ($wanted as $key => $alias) {
            if (in_array($key, $existing, true)) {
                continue;
            }
            $area->aliases()->create(['alias' => $alias, 'alias_key' => $key, 'created_by' => $userId]);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Area $area)
    {
        if ($area->customers()->ebilling()->exists()) {
            return back()->with('error', 'Cannot delete area with associated customers.');
        }

        $area->delete();

        return redirect()->route('areas.index')
            ->with('success', 'Area deleted successfully.');
    }

    public function migration()
    {
        return Inertia::render('Areas/Migration', [
            'areas' => Area::withCount('customers')->orderBy('name')->get(['id', 'name', 'code']),
        ]);
    }

    public function migrate(Request $request)
    {
        $validated = $request->validate([
            'from_area_id' => ['required', 'integer', 'exists:areas,id', 'different:to_area_id'],
            'to_area_id' => ['required', 'integer', 'exists:areas,id'],
        ]);

        $from = Area::findOrFail($validated['from_area_id']);
        $to = Area::findOrFail($validated['to_area_id']);
        $count = 0;

        DB::transaction(function () use ($from, $to, &$count, $request): void {
            $customers = $from->customers()->ebilling()->get(['id', 'area_id']);
            $count = $customers->count();
            if ($count === 0) {
                return;
            }

            $from->customers()->ebilling()->update(['area_id' => $to->id]);
            foreach ($customers as $customer) {
                activity()
                    ->causedBy($request->user())
                    ->performedOn($customer)
                    ->withProperties(['from_area_id' => $from->id, 'to_area_id' => $to->id])
                    ->log('customer_area_migrated');
            }
        });

        return redirect()->route('areas.index')->with('success', "{$count} pelanggan dipindah dari {$from->name} ke {$to->name}.");
    }
}
