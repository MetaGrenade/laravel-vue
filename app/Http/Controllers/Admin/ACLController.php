<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use App\Support\Audit\Audit;
use App\Support\Localization\DateFormatter;
use App\Support\Security\Administrators;
use Illuminate\Http\Request;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ACLController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Show the ACP Roles & Permissions index page.
     */
    public function index(Request $request): Response
    {
        $perPage = $this->requestedPerPage($request, 15);

        $formatter = DateFormatter::for($request->user());

        $roles = Role::query()
            ->with(['permissions:id,name,guard_name'])
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'roles_page')
            ->withQueryString();

        $permissions = Permission::query()
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'permissions_page')
            ->withQueryString();

        // What somebody who is not an administrator cannot take away is whatever somebody has (App\Support\Security\Administrators).
        $administrator = Administrators::is($request->user());
        $rolesInUse = $administrator ? [] : Administrators::rolesInUse($roles->getCollection()->modelKeys());
        $permissionsInUse = $administrator ? [] : Administrators::permissionsInUse($permissions->getCollection()->modelKeys());

        $roleItems = $roles->getCollection()
            ->map(function (Role $role) use ($formatter, $administrator, $rolesInUse) {
                $staffRole = ! $administrator && Administrators::isStaffRole($role->name);

                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'created_at' => $formatter->iso($role->created_at),
                    'can_edit' => ! $staffRole,
                    // Deleting it, or taking a permission off it.
                    'can_take_away' => ! $staffRole && ! in_array($role->id, $rolesInUse, true),
                    'permissions' => $role->permissions
                        ->map(fn (Permission $permission) => [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'guard_name' => $permission->guard_name,
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();

        $permissionItems = $permissions->getCollection()
            ->map(function (Permission $permission) use ($formatter, $permissionsInUse) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'created_at' => $formatter->iso($permission->created_at),
                    'can_delete' => ! in_array($permission->id, $permissionsInUse, true),
                ];
            })
            ->values()
            ->all();

        $availablePermissions = Permission::orderBy('name')
            ->get(['id', 'name', 'guard_name']);

        return inertia('acp/AccessControlLayer', [
            'roles' => array_merge([
                'data' => $roleItems,
            ], $this->inertiaPagination($roles)),
            'permissions' => array_merge([
                'data' => $permissionItems,
            ], $this->inertiaPagination($permissions)),
            'availablePermissions' => $availablePermissions,
            // An administrator renames roles and permissions; everybody else gives only the permissions named here (null: any).
            'canRename' => $administrator,
            'grantable' => Administrators::permissionsGivenBy($request->user()),
        ]);
    }

    /**
     * Show the form for creating a new blog post.
     */
    public function createRole(Request $request)
    {
        return inertia('acp/ACLRoleCreate', [
            'permissions' => Permission::orderBy('name')->get(['id', 'name', 'guard_name']),
            'grantable' => Administrators::permissionsGivenBy($request->user()),
        ]);
    }

    /**
     * Show the form for creating a new permission.
     */
    public function createPermission()
    {
        return inertia('acp/ACLPermissionCreate');
    }

    /**
     * Create a new Role.
     */
    public function storeRole(StoreRoleRequest $request)
    {
        Administrators::authorizeNewRole($request->user(), $request->validated('name'), $request->permissions ?? []);

        $role = Role::query()->create($request->validated());
        app(Audit::class)->tracking($role, 'permissions', fn () => $role->syncPermissions($this->permissionsNamed($request->permissions ?? [], $role->guard_name)));

        return redirect()->route('acp.acl.index')->with('success', 'Role created.');
    }

    /**
     * Update an existing Role.
     */
    public function updateRole(UpdateRoleRequest $request, Role $role)
    {
        Administrators::authorizeRoleChange(
            $request->user(),
            $role,
            $request->validated('name'),
            $request->validated('guard_name'),
            $request->permissions ?? [],
        );

        $role->update($request->validated());
        app(Audit::class)->tracking($role, 'permissions', fn () => $role->syncPermissions($this->permissionsNamed($request->permissions ?? [], $role->guard_name)));

        return back()->with('success', 'Role updated.');
    }

    /**
     * Delete a Role.
     */
    public function destroyRole(Request $request, Role $role)
    {
        Administrators::authorizeRoleDeletion($request->user(), $role);

        $role->delete();

        return back()->with('success', 'Role deleted.');
    }

    /**
     * Create a new Permission.
     */
    public function storePermission(StorePermissionRequest $request)
    {
        Permission::create($request->validated());

        return redirect()->route('acp.acl.index')->with('success', 'Permission created.');
    }

    /**
     * Update an existing Permission.
     */
    public function updatePermission(UpdatePermissionRequest $request, Permission $permission)
    {
        Administrators::authorizePermissionChange($request->user());

        $permission->update($request->validated());

        return back()->with('success', 'Permission updated.');
    }

    /**
     * Delete a Permission.
     */
    public function destroyPermission(Request $request, Permission $permission)
    {
        Administrators::authorizePermissionDeletion($request->user(), $permission);

        $permission->delete();

        return back()->with('success', 'Permission deleted.');
    }

    /**
     * The permissions with these names. Spatie is handed the permissions, not the names: it takes a name shaped like a UUID or a ULID
     * for a permission's number (App\Rules\NotShapedLikeAnId).
     *
     * @param  array<int, string>  $names
     * @return array<int, \Spatie\Permission\Contracts\Permission>
     */
    private function permissionsNamed(array $names, string $guard): array
    {
        return array_map(fn (string $name) => Permission::findByName($name, $guard), $names);
    }
}
