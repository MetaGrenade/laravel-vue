<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

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

// Computed filtered data
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
                    <h2 class="mb-4 text-lg font-semibold">Role Management</h2>
                    <!-- Create Role Button -->
                    <div class="mb-4 flex justify-end">
                        <button class="rounded bg-blue-500 px-4 py-2 text-white hover:bg-blue-600">
                            Create Role
                        </button>
                    </div>
                    <!-- Search Bar -->
                    <div class="mb-4">
                        <input
                            v-model="roleSearchQuery"
                            type="text"
                            placeholder="Search roles..."
                            class="w-full rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <!-- Roles Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse">
                            <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Name</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Description</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Created At</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr
                                v-for="role in filteredRoles"
                                :key="role.id"
                                class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ role.id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ role.name }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ role.description }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ role.created_at }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <button class="text-blue-500 hover:underline">Edit</button>
                                    <button class="ml-2 text-red-500 hover:underline">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="filteredRoles.length === 0">
                                <td colspan="5" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                    No roles found.
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Permissions Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <h2 class="mb-4 text-lg font-semibold">Permission Management</h2>
                    <!-- Create Permission Button -->
                    <div class="mb-4 flex justify-end">
                        <button class="rounded bg-blue-500 px-4 py-2 text-white hover:bg-blue-600">
                            Create Permission
                        </button>
                    </div>
                    <!-- Search Bar -->
                    <div class="mb-4">
                        <input
                            v-model="permissionSearchQuery"
                            type="text"
                            placeholder="Search permissions..."
                            class="w-full rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                        />
                    </div>
                    <!-- Permissions Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse">
                            <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Name</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Description</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Created At</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr
                                v-for="permission in filteredPermissions"
                                :key="permission.id"
                                class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ permission.id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ permission.name }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ permission.description }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ permission.created_at }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <button class="text-blue-500 hover:underline">Edit</button>
                                    <button class="ml-2 text-red-500 hover:underline">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="filteredPermissions.length === 0">
                                <td colspan="5" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                    No permissions found.
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
