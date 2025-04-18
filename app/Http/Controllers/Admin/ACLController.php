<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        $roles = Role::orderBy('name')
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
    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255|unique:roles,name',
            'guard_name' => 'required|string|max:255',
        ]);

        Role::create($data);

        return back()->with('success', 'Role created.');
    }

    /**
     * Update an existing Role.
     */
    public function updateRole(Request $request, Role $role)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255|unique:roles,name,' . $role->id,
            'guard_name'  => 'required|string|max:255',
        ]);

        $role->update($data);

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
    public function storePermission(Request $request)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255|unique:permissions,name',
            'guard_name'  => 'required|string|max:255',
        ]);

        Permission::create($data);

        return back()->with('success', 'Permission created.');
    }

    /**
     * Update an existing Permission.
     */
    public function updatePermission(Request $request, Permission $permission)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:255|unique:permissions,name,' . $permission->id,
            'guard_name'  => 'required|string|max:255',
        ]);

        $permission->update($data);

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
