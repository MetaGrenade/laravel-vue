<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Users, UserPlus, UserX, Activity, Ellipsis, Shield, Trash2, Pencil, MailCheck } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

// Permission checks
const { hasPermission } = usePermissions();
const editUsers = computed(() => hasPermission('users.acp.edit'));
const deleteUsers = computed(() => hasPermission('users.acp.delete'));

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

                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
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
                                    <TableHead></TableHead>
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
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis class="h-8 w-8" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator v-if="editUsers" />
                                                <DropdownMenuGroup v-if="editUsers">
                                                    <DropdownMenuItem class="text-blue-500">
                                                        <Pencil class="h-8 w-8" />
                                                        <span>Edit</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem>
                                                        <Shield class="h-8 w-8" />
                                                        <span>Permissions</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem class="text-green-500">
                                                        <MailCheck class="h-8 w-8" />
                                                        <span>Verify</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="deleteUsers" />
                                                <DropdownMenuItem v-if="deleteUsers" class="text-red-500">
                                                    <Trash2 class="h-8 w-8" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
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
            </div>
        </AdminLayout>
    </AppLayout>
</template>
