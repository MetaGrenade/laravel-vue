<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ACLController extends Controller
{
    /**
     * Show the ACP Roles & Permissions index page.
     */
    public function index(Request $request)
    {
        $perPage = $request->get('per_page', 15);

        $roles = Role::with('permissions')->orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        $permissions = Permission::orderBy('name')
            ->paginate($perPage)
            ->withQueryString();

        return inertia('acp/AccessControlLayer', compact('roles','permissions'));
    }


    /**
     * Show the form for creating a new blog post.
     */
    public function createRole()
    {
        return inertia('acp/ACLRoleCreate');
    }

    /**
     * Create a new Role.
     */
    public function storeRole(StoreRoleRequest $request)
    {
        $role = Role::create($request->validated());
        $role->syncPermissions($request->permissions ?? []);
        return back()->with('success', 'Role created.');
    }

    /**
     * Update an existing Role.
     */
    public function updateRole(UpdateRoleRequest $request, Role $role)
    {
        $role->update($request->validated());
        $role->syncPermissions($request->permissions ?? []);
        return back()->with('success', 'Role updated.');
    }

    /**
     * Delete a Role.
     */
    public function destroyRole(Role $role)
    {
        $role->delete();
        return back()->with('success', 'Role deleted.');
    }

    /**
     * Create a new Permission.
     */
    public function storePermission(StorePermissionRequest $request)
    {
        Permission::create($request->validated());
        return back()->with('success', 'Permission created.');
    }

    /**
     * Update an existing Permission.
     */
    public function updatePermission(UpdatePermissionRequest $request, Permission $permission)
    {
        $permission->update($request->validated());
        return back()->with('success', 'Permission updated.');
    }

    /**
     * Delete a Permission.
     */
    public function destroyPermission(Permission $permission)
    {
        $permission->delete();
        return back()->with('success', 'Permission deleted.');
    }
}
