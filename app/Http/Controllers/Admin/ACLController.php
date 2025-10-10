<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\InteractsWithInertiaPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePermissionRequest;
use App\Http\Requests\Admin\StoreRoleRequest;
use App\Http\Requests\Admin\UpdatePermissionRequest;
use App\Http\Requests\Admin\UpdateRoleRequest;
use Illuminate\Http\Request;
use App\Support\Audit\AuditLogger;
use App\Support\Localization\DateFormatter;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class ACLController extends Controller
{
    use InteractsWithInertiaPagination;

    /**
     * Show the ACP Roles & Permissions index page.
     */
    public function index(Request $request): Response
    {
        $perPage = (int) $request->get('per_page', 15);

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

        $roleItems = $roles->getCollection()
            ->map(function (Role $role) use ($formatter) {
                return [
                    'id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'created_at' => $formatter->iso($role->created_at),
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
            ->map(function (Permission $permission) use ($formatter) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'guard_name' => $permission->guard_name,
                    'created_at' => $formatter->iso($permission->created_at),
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
        ]);
    }

    /**
     * Show the form for creating a new blog post.
     */
    public function createRole()
    {
        $permissions = Permission::orderBy('name')->get(['id', 'name', 'guard_name']);

        return inertia('acp/ACLRoleCreate', compact('permissions'));
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
        $role = Role::create($request->validated());
        $role->syncPermissions($request->permissions ?? []);

        $role->load('permissions');

        AuditLogger::log(
            'acl.role.created',
            'Role created',
            [
                'role_id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name')->sort()->values()->all(),
            ],
            $request->user(),
            $role,
        );

        return redirect()->route('acp.acl.index')->with('success', 'Role created.');
    }

    /**
     * Update an existing Role.
     */
    public function updateRole(UpdateRoleRequest $request, Role $role)
    {
        $originalName = $role->name;
        $originalGuard = $role->guard_name;
        $originalPermissions = $role->permissions->pluck('name')->sort()->values()->all();

        $role->update($request->validated());
        $role->syncPermissions($request->permissions ?? []);

        $role->load('permissions');

        $changes = [];

        if ($role->name !== $originalName) {
            $changes['name'] = [
                'from' => $originalName,
                'to' => $role->name,
            ];
        }

        if ($role->guard_name !== $originalGuard) {
            $changes['guard_name'] = [
                'from' => $originalGuard,
                'to' => $role->guard_name,
            ];
        }

        $currentPermissions = $role->permissions->pluck('name')->sort()->values()->all();
        $addedPermissions = array_values(array_diff($currentPermissions, $originalPermissions));
        $removedPermissions = array_values(array_diff($originalPermissions, $currentPermissions));

        if ($addedPermissions !== [] || $removedPermissions !== []) {
            $changes['permissions'] = [
                'added' => $addedPermissions,
                'removed' => $removedPermissions,
            ];
        }

        if ($changes !== []) {
            AuditLogger::log(
                'acl.role.updated',
                'Role updated',
                [
                    'role_id' => $role->id,
                    'name' => $role->name,
                    'guard_name' => $role->guard_name,
                    'changes' => $changes,
                ],
                $request->user(),
                $role,
            );
        }

        return back()->with('success', 'Role updated.');
    }

    /**
     * Delete a Role.
     */
    public function destroyRole(Request $request, Role $role)
    {
        AuditLogger::log(
            'acl.role.deleted',
            'Role deleted',
            [
                'role_id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
            ],
            $request->user(),
            $role,
        );

        $role->delete();
        return back()->with('success', 'Role deleted.');
    }

    /**
     * Create a new Permission.
     */
    public function storePermission(StorePermissionRequest $request)
    {
        $permission = Permission::create($request->validated());

        AuditLogger::log(
            'acl.permission.created',
            'Permission created',
            [
                'permission_id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ],
            $request->user(),
            $permission,
        );

        return redirect()->route('acp.acl.index')->with('success', 'Permission created.');
    }

    /**
     * Update an existing Permission.
     */
    public function updatePermission(UpdatePermissionRequest $request, Permission $permission)
    {
        $original = $permission->only(['name', 'guard_name']);

        $permission->update($request->validated());

        $changes = [];

        foreach (['name', 'guard_name'] as $attribute) {
            if ($permission->{$attribute} !== $original[$attribute]) {
                $changes[$attribute] = [
                    'from' => $original[$attribute],
                    'to' => $permission->{$attribute},
                ];
            }
        }

        if ($changes !== []) {
            AuditLogger::log(
                'acl.permission.updated',
                'Permission updated',
                [
                    'permission_id' => $permission->id,
                    'changes' => $changes,
                ],
                $request->user(),
                $permission,
            );
        }

        return back()->with('success', 'Permission updated.');
    }

    /**
     * Delete a Permission.
     */
    public function destroyPermission(Request $request, Permission $permission)
    {
        AuditLogger::log(
            'acl.permission.deleted',
            'Permission deleted',
            [
                'permission_id' => $permission->id,
                'name' => $permission->name,
                'guard_name' => $permission->guard_name,
            ],
            $request->user(),
            $permission,
        );

        $permission->delete();
        return back()->with('success', 'Permission deleted.');
    }
}
