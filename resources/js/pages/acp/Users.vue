<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuTrigger,
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
} from '@/components/ui/dropdown-menu';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import {
    Users as UsersIcon,
    UserPlus,
    UserX,
    Activity,
    Ellipsis,
    Pencil,
    Trash2,
    Shield,
    MailCheck
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// Permission checks
const { hasPermission } = usePermissions();
const editUsers = computed(() => hasPermission('users.acp.edit'));
const deleteUsers = computed(() => hasPermission('users.acp.delete'));

// Define exactly the props we expect from the controller:
const props = defineProps<{
    users: {
        data: Array<{
            id: number;
            name: string;
            email: string;
            email_verified_at: string;
            roles: Array<{ name: string }>;
            created_at: string;
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
    userStats: {
        total: number;
        unverified: number;
        banned: number;
        online: number;
    };
}>();

// Breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users ACP', href: '/acp/users' },
];

// Search filtering
const searchQuery = ref('');
const filteredUsers = computed(() => {
    if (!searchQuery.value) {
        return props.users.data;
    }
    const q = searchQuery.value.toLowerCase();
    return props.users.data.filter(u =>
        u.name.toLowerCase().includes(q) ||
        u.email.toLowerCase().includes(q) ||
        u.roles.some(r => r.name.toLowerCase().includes(q))
    );
});

// Stats cards array
const stats = [
    { title: 'Total Users',      value: props.userStats.total,      icon: UsersIcon },
    { title: 'Unverified Users', value: props.userStats.unverified, icon: UserPlus },
    { title: 'Banned Users',     value: props.userStats.banned,     icon: UserX },
    { title: 'Online Users',     value: props.userStats.online,     icon: Activity },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Users ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div
                        v-for="(stat, i) in stats"
                        :key="i"
                        class="relative overflow-hidden rounded-lg border p-4 flex items-center"
                    >
                        <component :is="stat.icon" class="h-8 w-8 mr-3 text-gray-600"/>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-2xl font-bold">{{ stat.value }}</div>
                        </div>

                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Users Management Section -->
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

                    <!-- Users Table -->
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead class="text-center">Roles</TableHead>
                                    <TableHead class="text-center">Created</TableHead>
                                    <TableHead class="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="user in filteredUsers"
                                    :key="user.id"
                                >
                                    <TableCell>{{ user.id }}</TableCell>
                                    <TableCell>{{ user.name }}</TableCell>
                                    <TableCell>{{ user.email }}</TableCell>
                                    <TableCell class="text-center">
                      <span
                          v-for="role in user.roles"
                          :key="role.name"
                          class="inline-block rounded bg-gray-500 px-2 py-0.5 text-xs mr-1"
                      >
                        {{ role.name }}
                      </span>
                                    </TableCell>
                                    <TableCell class="text-center">{{ dayjs(user.created_at).fromNow() }}</TableCell>
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuGroup>
                                                    <Link v-if="editUsers" :href="route('acp.users.edit',   { user: user.id })">
                                                        <DropdownMenuItem class="text-blue-500">
                                                            <Pencil class="mr-2"/> Edit
                                                        </DropdownMenuItem>
                                                    </Link>
<!--                                                    <DropdownMenuItem>-->
<!--                                                        <Shield class="mr-2" /> Permissions-->
<!--                                                    </DropdownMenuItem>-->
                                                    <DropdownMenuItem v-if="editUsers && user.email_verified_at === null" class="text-green-500">
                                                        <MailCheck class="mr-2" /> Verify
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator v-if="deleteUsers" />
                                                    <DropdownMenuItem v-if="deleteUsers" class="text-red-500"
                                                        @click="$inertia.delete(route('acp.users.destroy',{ user: user.id }))"
                                                    >
                                                        <Trash2 class="mr-2"/> Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="filteredUsers.length === 0">
                                    <TableCell colspan="6" class="text-center text-gray-600 dark:text-gray-300">
                                        No users found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <!-- Bottom Pagination -->
                <div class="flex justify-center">
                    <Pagination
                        v-slot="{ page }"
                        :items-per-page="props.users.per_page"
                        :total="props.users.total"
                        :sibling-count="1"
                        show-edges
                        :default-page="props.users.current_page"
                    >
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    as-child
                                >
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis
                                    v-else
                                    :index="index"
                                />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
