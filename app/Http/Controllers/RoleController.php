<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search'));

        $roles = Role::query()
            ->where('name', '!=', 'SuperAdmin')
            ->with('permissions')
            ->withCount('users')
            ->when($search !== '', function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();

        // Agrupar permisos por módulo (ej. 'users.view' -> módulo 'users')
        $permissionTree = Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => explode('.', $permission->name)[0]);

        $moduleLabels = config('permissions.modules', []);
        $actionLabels = config('permissions.actions', []);

        return view('roles.index', compact(
            'roles',
            'permissionTree',
            'moduleLabels',
            'actionLabels',
            'search'
        ));
    }

    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($validated['permissions'] ?? []);

        return redirect()
            ->route('roles.index')
            ->with('success', 'Rol creado correctamente.');
    }

    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        abort_if($role->name === 'SuperAdmin', 403);

        $validated = $request->validated();

        $role->update([
            'name' => $validated['name'],
        ]);

        $role->syncPermissions($validated['permissions'] ?? []);

        return redirect()
            ->route('roles.index')
            ->with('success', 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        abort_if($role->name === 'SuperAdmin', 403);

        if ($role->users()->exists()) {
            return redirect()
                ->route('roles.index')
                ->with('error', 'No se puede eliminar este rol porque tiene usuarios asignados.');
        }

        $role->delete();

        return redirect()
            ->route('roles.index')
            ->with('success', 'Rol eliminado correctamente.');
    }
}
