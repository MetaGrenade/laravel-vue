<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';

// Import Table components from shadcn-vue
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';

// Import Lucide icons for user stats
import { Users, UserPlus, UserX, Activity, Search } from 'lucide-vue-next';

// Breadcrumbs for the page
const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users ACP',
        href: '/acp/users',
    },
];

// Dummy data for user statistics
const userStats = [
    { title: 'Total Users', value: '1,234', icon: Users },
    { title: 'Unverified Users', value: '234', icon: UserPlus },
    { title: 'Banned Users', value: '12', icon: UserX },
    { title: 'Online Users', value: '56', icon: Activity },
];

// Define interface for a User
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

// Search query for filtering users
const searchQuery = ref('');

// Computed property for filtered users
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
                <!-- User Stats Section -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in userStats"
                        :key="index"
                        class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Search Bar using Input Component -->
                <div class="relative w-full flex">
                    <h2 class="text-lg font-semibold mb-2 md:mb-0 w-full">User Management</h2>
                    <Input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search Users..."
                        class="w-full pr-10 max-w-sm"
                    />
                </div>

                <!-- Users Table using Table Components -->
                <div class="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Email</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead>Created At</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="user in filteredUsers"
                                :key="user.id"
                                class="hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <TableCell>{{ user.id }}</TableCell>
                                <TableCell>{{ user.name }}</TableCell>
                                <TableCell>{{ user.email }}</TableCell>
                                <TableCell>{{ user.role }}</TableCell>
                                <TableCell>{{ user.created_at }}</TableCell>
                            </TableRow>
                            <TableRow v-if="filteredUsers.length === 0">
                                <TableCell colspan="6" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                    No users found.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
