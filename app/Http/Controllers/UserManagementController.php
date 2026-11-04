<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\Area;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;

class UserManagementController extends Controller
{
    public function index(Request $request)
    {
        $allowedSorts = ['name', 'email', 'role', 'created_at'];
        $sort = in_array($request->input('sort'), $allowedSorts, true) ? $request->input('sort') : 'name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $limit = min(max((int) $request->input('limit', 20), 1), 100);

        $users = User::query()
            ->with('areas:id,name,code')
            ->when($request->search, function ($query, $search) {
                $query->where(function ($sub) use ($search) {
                    $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->when($request->role, fn ($query, $role) => $query->where('role', $role))
            ->orderBy($sort, $direction)
            ->paginate($limit)
            ->withQueryString();

        return Inertia::render('Users/Index', [
            'users' => $users,
            'filters' => [
                ...$request->only(['search', 'role']),
                'limit' => $limit,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Create', [
            'areas' => Area::select('id', 'name', 'code')->orderBy('name')->get(),
        ]);
    }

    public function store(UserStoreRequest $request)
    {
        $validated = $request->validated();

        // `role` is not mass assignable (S17); set it explicitly. (email_verified_at
        // was never fillable, so the old `now()` entry was a silent no-op.)
        $user = new User([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone' => $validated['phone'] ?? null,
        ]);
        $user->forceFill(['role' => $validated['role']])->save();

        $this->syncAreas($user, $validated['area_ids'] ?? []);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        $user->load('areas:id,name,code');

        return Inertia::render('Users/Edit', [
            'managedUser' => $user,
            'areas' => Area::select('id', 'name', 'code')->orderBy('name')->get(),
        ]);
    }

    public function update(UserUpdateRequest $request, User $user)
    {
        $validated = $request->validated();

        if ($user->isSuperAdmin() && $validated['role'] !== 'superadmin' && $this->superAdminCount() <= 1) {
            return back()->with('error', 'Cannot demote the last superadmin.');
        }

        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
        ];

        if (! empty($validated['password'])) {
            $payload['password'] = Hash::make($validated['password']);
        }

        // `role` is not mass assignable (S17); set it explicitly.
        $user->fill($payload)->forceFill(['role' => $validated['role']])->save();
        $this->syncAreas($user, $validated['area_ids'] ?? []);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        if ($user->isSuperAdmin() && $this->superAdminCount() <= 1) {
            return back()->with('error', 'Cannot delete the last superadmin.');
        }

        if ($user->is(auth()->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }

    private function syncAreas(User $user, array $areaIds): void
    {
        if ($user->isAdmin()) {
            $user->areas()->sync($areaIds);
            $user->forgetAreaScopeMemo();

            return;
        }

        $user->areas()->detach();
        $user->forgetAreaScopeMemo();
    }

    private function superAdminCount(): int
    {
        return User::where('role', 'superadmin')->count();
    }
}
