<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import Input from '@/components/ui/input/Input.vue';
import { Ellipsis, Shield, Trash2, Pencil } from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { usePermissions } from '@/composables/usePermissions';

// Permission checks
const { hasPermission } = usePermissions();
const createPermissions = computed(() => hasPermission('permissions.acp.create'));
const editPermissions = computed(() => hasPermission('permissions.acp.edit'));
const deletePermissions = computed(() => hasPermission('permissions.acp.delete'));

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Permission ACP',
        href: '/acp/permissions',
    },
];

// Define interfaces for Role and Permission
interface Role {
    id: number;
    name: string;
    description: string;
    created_at: string;
}

interface Permission {
    id: number;
    name: string;
    description: string;
    created_at: string;
}

// Dummy data for Roles and Permissions
const roles = ref<Role[]>([
    { id: 1, name: 'Admin', description: 'Full access to system', created_at: '2022-01-01' },
    { id: 2, name: 'Editor', description: 'Can edit content', created_at: '2022-02-01' },
    { id: 3, name: 'User', description: 'Regular user access', created_at: '2022-03-01' },
]);

const permissions = ref<Permission[]>([
    { id: 1, name: 'create-post', description: 'Create a post', created_at: '2022-01-05' },
    { id: 2, name: 'edit-post', description: 'Edit a post', created_at: '2022-02-10' },
    { id: 3, name: 'delete-post', description: 'Delete a post', created_at: '2022-03-15' },
]);

// Search queries for each section
const roleSearchQuery = ref('');
const permissionSearchQuery = ref('');

// Computed filtered data for roles and permissions
const filteredRoles = computed(() => {
    if (!roleSearchQuery.value) return roles.value;
    const q = roleSearchQuery.value.toLowerCase();
    return roles.value.filter(role =>
        role.name.toLowerCase().includes(q) ||
        role.description.toLowerCase().includes(q)
    );
});

const filteredPermissions = computed(() => {
    if (!permissionSearchQuery.value) return permissions.value;
    const q = permissionSearchQuery.value.toLowerCase();
    return permissions.value.filter(permission =>
        permission.name.toLowerCase().includes(q) ||
        permission.description.toLowerCase().includes(q)
    );
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Permissions ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Roles Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">Role Management</h2>
                        <div class="relative flex justify-end space-x-2">
                            <Input
                                v-model="roleSearchQuery"
                                type="text"
                                placeholder="Search Roles..."
                                class="w-full pr-10 max-w-sm"
                            />
                            <Button v-if="createPermissions" variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                Create Role
                            </Button>
                        </div>
                    </div>
                    <!-- Roles Table using Table Components -->
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="role in filteredRoles" :key="role.id" class="hover:bg-gray-50 dark:hover:bg-gray-900">
                                    <TableCell>{{ role.id }}</TableCell>
                                    <TableCell>{{ role.name }}</TableCell>
                                    <TableCell>{{ role.description }}</TableCell>
                                    <TableCell>{{ role.created_at }}</TableCell>
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis class="h-8 w-8" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator v-if="editPermissions" />
                                                <DropdownMenuGroup v-if="editPermissions">
                                                    <DropdownMenuItem class="text-blue-500">
                                                        <Pencil class="h-8 w-8" />
                                                        <span>Edit</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem>
                                                        <Shield class="h-8 w-8" />
                                                        <span>Permissions</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="deletePermissions" />
                                                <DropdownMenuItem v-if="deletePermissions" class="text-red-500" disabled>
                                                    <Trash2 class="h-8 w-8" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="filteredRoles.length === 0">
                                    <TableCell colspan="5" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No roles found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <!-- Permissions Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">Permission Management</h2>
                        <div class="flex space-x-2">
                            <Input
                                v-model="permissionSearchQuery"
                                placeholder="Search Permissions..."
                                class="w-full rounded-md"
                            />
                        </div>
                    </div>
                    <!-- Permissions Table using Table Components -->
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead></TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="permission in filteredPermissions" :key="permission.id" class="hover:bg-gray-50 dark:hover:bg-gray-900">
                                    <TableCell>{{ permission.id }}</TableCell>
                                    <TableCell>{{ permission.name }}</TableCell>
                                    <TableCell>{{ permission.description }}</TableCell>
                                    <TableCell>{{ permission.created_at }}</TableCell>
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis class="h-8 w-8" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator v-if="editPermissions" />
                                                <DropdownMenuGroup v-if="editPermissions">
                                                    <DropdownMenuItem class="text-blue-500">
                                                        <Pencil class="h-8 w-8" />
                                                        <span>Edit</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="deletePermissions" />
                                                <DropdownMenuItem v-if="deletePermissions" class="text-red-500" disabled>
                                                    <Trash2 class="h-8 w-8" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="filteredPermissions.length === 0">
                                    <TableCell colspan="5" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No permissions found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
