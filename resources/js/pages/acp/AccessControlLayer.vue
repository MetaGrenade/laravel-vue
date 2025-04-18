<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
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
import { Ellipsis, Trash2, Pencil } from 'lucide-vue-next';
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
import { usePermissions } from '@/composables/usePermissions';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// Permission checks
const { hasPermission } = usePermissions();
const createRoles = computed(() => hasPermission('acl.acp.create'));
const editRoles = computed(() => hasPermission('acl.acp.edit'));
const deleteRoles = computed(() => hasPermission('acl.acp.delete'));

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Permission ACP',
        href: '/acp/permissions',
    },
];

const props = defineProps<{
    roles: {
        data: Array<{
            id: number;
            name: string;
            guard_name: string;
            created_at: string
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
    permissions: {
        data: Array<{
            id: number;
            name: string;
            guard_name: string;
            created_at: string
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
}>();

// Search queries for each section
const roleSearchQuery = ref('');
const permissionSearchQuery = ref('');

// Computed filtered data for roles and permissions
const filteredRoles = computed(() => {
    if (!roleSearchQuery.value) return props.roles.data;
    const q = roleSearchQuery.value.toLowerCase();
    return props.roles.data.filter(role =>
        role.name.toLowerCase().includes(q) ||
        role.guard_name.toLowerCase().includes(q)
    );
});

const filteredPermissions = computed(() => {
    if (!permissionSearchQuery.value) return props.permissions.data;
    const q = permissionSearchQuery.value.toLowerCase();
    return props.permissions.data.filter(permission =>
        permission.name.toLowerCase().includes(q) ||
        permission.guard_name.toLowerCase().includes(q)
    );
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Permissions ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <Tabs default-value="roles" class="w-full">
                    <TabsList>
                        <TabsTrigger value="roles">Roles</TabsTrigger>
                        <TabsTrigger value="permissions">Permissions</TabsTrigger>
                    </TabsList>

                    <TabsContent value="roles">
                        <!-- Roles Management Section -->
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 mb-4">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">Role Management</h2>
                                <div class="relative flex justify-end space-x-2">
                                    <Input
                                        v-model="roleSearchQuery"
                                        type="text"
                                        placeholder="Search Roles..."
                                        class="w-full pr-10 max-w-sm"
                                    />
                                    <Link v-if="createRoles" :href="route('acp.acl.roles.create')">
                                        <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                            Create Role
                                        </Button>
                                    </Link>

                                </div>
                            </div>
                            <!-- Roles Table using Table Components -->
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>ID</TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead class="text-center">Guard Name</TableHead>
                                            <TableHead class="text-center">Created At</TableHead>
                                            <TableHead class="text-center"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="role in filteredRoles" :key="role.id">
                                            <TableCell>{{ role.id }}</TableCell>
                                            <TableCell>{{ role.name }}</TableCell>
                                            <TableCell class="text-center">{{ role.guard_name }}</TableCell>
                                            <TableCell class="text-center">{{ dayjs(role.created_at).fromNow() }}</TableCell>
                                            <TableCell class="text-center">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button variant="outline" size="icon">
                                                            <Ellipsis class="h-8 w-8" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent>
                                                        <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                        <DropdownMenuSeparator v-if="editRoles" />
                                                        <DropdownMenuGroup v-if="editRoles">
                                                            <Link v-if="editRoles" :href="route('acp.acl.roles.update', { role: role.id })">
                                                                <DropdownMenuItem class="text-blue-500">
                                                                    <Pencil class="mr-2"/> Edit
                                                                </DropdownMenuItem>
                                                            </Link>
                                                        </DropdownMenuGroup>
                                                        <DropdownMenuSeparator v-if="deleteRoles" />
                                                        <DropdownMenuItem v-if="deleteRoles" class="text-red-500"
                                                            @click="$inertia.delete(route('acp.acl.roles.destroy', { role: role.id }))"
                                                        >
                                                            <Trash2 class="mr-2" /> Delete
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

                        <!-- Bottom Pagination -->
                        <div class="flex justify-center">
                            <Pagination
                                v-slot="{ page }"
                                :items-per-page="props.roles.per_page"
                                :total="props.roles.total"
                                :sibling-count="1"
                                show-edges
                                :default-page="props.roles.current_page"
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
                    </TabsContent>

                    <TabsContent value="permissions">
                        <!-- Permissions Management Section -->
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 mb-4">
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
                                            <TableHead class="text-center">Guard Name</TableHead>
                                            <TableHead class="text-center">Created At</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="permission in filteredPermissions" :key="permission.id">
                                            <TableCell>{{ permission.id }}</TableCell>
                                            <TableCell>{{ permission.name }}</TableCell>
                                            <TableCell class="text-center">{{ permission.guard_name }}</TableCell>
                                            <TableCell class="text-center">{{ dayjs(permission.created_at).fromNow() }}</TableCell>
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

                        <!-- Bottom Pagination -->
                        <div class="flex justify-center">
                            <Pagination
                                v-slot="{ page }"
                                :items-per-page="props.permissions.per_page"
                                :total="props.permissions.total"
                                :sibling-count="1"
                                show-edges
                                :default-page="props.permissions.current_page"
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
                    </TabsContent>
                </Tabs>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
