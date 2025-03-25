<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users ACP',
        href: '/acp/users',
    },
];

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    created_at: string;
}

// Dummy data for users
const users = ref<User[]>([
    { id: 1, name: 'Alice Johnson', email: 'alice@example.com', role: 'Admin', created_at: '2022-01-01' },
    { id: 2, name: 'Bob Smith', email: 'bob@example.com', role: 'User', created_at: '2022-02-15' },
    { id: 3, name: 'Charlie Brown', email: 'charlie@example.com', role: 'Moderator', created_at: '2022-03-10' },
    { id: 4, name: 'Diana Prince', email: 'diana@example.com', role: 'User', created_at: '2022-04-05' },
    { id: 5, name: 'Ethan Hunt', email: 'ethan@example.com', role: 'User', created_at: '2022-05-20' },
]);

// Search query
const searchQuery = ref('');

// Filtered users computed property
const filteredUsers = computed(() => {
    if (!searchQuery.value) return users.value;
    const q = searchQuery.value.toLowerCase();
    return users.value.filter(user =>
        user.name.toLowerCase().includes(q) ||
        user.email.toLowerCase().includes(q) ||
        user.role.toLowerCase().includes(q)
    );
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Users ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Search Bar -->
                <div class="mb-4">
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search users..."
                        class="w-full rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                    />
                </div>

                <!-- Users Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse">
                        <thead class="bg-gray-100 dark:bg-gray-800">
                        <tr>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Name</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Email</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Role</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Created At</th>
                            <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                        >
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ user.id }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ user.name }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ user.email }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ user.role }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ user.created_at }}</td>
                            <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                <button class="text-blue-500 hover:underline">Edit</button>
                                <button class="ml-2 text-red-500 hover:underline">Delete</button>
                            </td>
                        </tr>
                        <tr v-if="filteredUsers.length === 0">
                            <td colspan="6" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                No users found.
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
