<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';

dayjs.extend(relativeTime);

type RolePermission = {
    id: number;
    name: string;
    guard_name: string;
};

type RoleItem = {
    id: number;
    name: string;
    guard_name: string;
    created_at: string;
    permissions: RolePermission[];
};

type PermissionItem = {
    id: number;
    name: string;
    guard_name: string;
    created_at: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Permission ACP',
        href: '/acp/permissions',
    },
];

type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

const props = defineProps<{
    roles: {
        data: RoleItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    permissions: {
        data: PermissionItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    availablePermissions: RolePermission[];
}>();

const currentRolesPage = computed(() => props.roles.meta?.current_page ?? 1);
const currentPermissionsPage = computed(() => props.permissions.meta?.current_page ?? 1);

const {
    meta: rolesMeta,
    page: rolesPage,
    rangeLabel: rolesRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.roles.meta ?? null),
    itemsLength: computed(() => props.roles.data?.length ?? 0),
    defaultPerPage: 15,
    itemLabel: 'role',
    itemLabelPlural: 'roles',
    onNavigate: (page) => {
        router.get(
            route('acp.acl.index'),
            {
                roles_page: page,
                permissions_page: currentPermissionsPage.value,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

const {
    meta: permissionsMeta,
    page: permissionsPage,
    rangeLabel: permissionsRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.permissions.meta ?? null),
    itemsLength: computed(() => props.permissions.data?.length ?? 0),
    defaultPerPage: 15,
    itemLabel: 'permission',
    itemLabelPlural: 'permissions',
    onNavigate: (page) => {
        router.get(
            route('acp.acl.index'),
            {
                roles_page: currentRolesPage.value,
                permissions_page: page,
            },
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

// Permission checks
const { hasPermission } = usePermissions();
const canCreate = computed(() => hasPermission('acl.acp.create'));
const canEdit = computed(() => hasPermission('acl.acp.edit'));
const canDelete = computed(() => hasPermission('acl.acp.delete'));

const roleDialogOpen = ref(false);
const permissionDialogOpen = ref(false);

const selectedRole = ref<RoleItem | null>(null);
const selectedPermission = ref<PermissionItem | null>(null);

const roleForm = useForm({
    name: '',
    guard_name: 'web',
    permissions: [] as string[],
});

const permissionForm = useForm({
    name: '',
    guard_name: 'web',
});

const handleRoleDialogChange = (open: boolean) => {
    roleDialogOpen.value = open;

    if (!open) {
        roleForm.reset();
        roleForm.clearErrors();
        selectedRole.value = null;
    }
};

const handlePermissionDialogChange = (open: boolean) => {
    permissionDialogOpen.value = open;

    if (!open) {
        permissionForm.reset();
        permissionForm.clearErrors();
        selectedPermission.value = null;
    }
};

const toggleRolePermission = (permissionName: string, checked: boolean | string) => {
    const isChecked = checked === true || checked === 'indeterminate';

    if (isChecked) {
        if (!roleForm.permissions.includes(permissionName)) {
            roleForm.permissions.push(permissionName);
        }
    } else {
        roleForm.permissions = roleForm.permissions.filter(name => name !== permissionName);
    }
};

const openRoleDialog = (role: RoleItem) => {
    selectedRole.value = role;
    roleForm.clearErrors();
    roleForm.name = role.name;
    roleForm.guard_name = role.guard_name;
    roleForm.permissions = (role.permissions ?? []).map(permission => permission.name);
    handleRoleDialogChange(true);
};

const submitRoleUpdate = () => {
    if (!selectedRole.value) return;

    roleForm.put(route('acp.acl.roles.update', { role: selectedRole.value.id }), {
        preserveScroll: true,
        onSuccess: () => handleRoleDialogChange(false),
    });
};

const openPermissionDialog = (permission: PermissionItem) => {
    selectedPermission.value = permission;
    permissionForm.clearErrors();
    permissionForm.name = permission.name;
    permissionForm.guard_name = permission.guard_name;
    handlePermissionDialogChange(true);
};

const submitPermissionUpdate = () => {
    if (!selectedPermission.value) return;

    permissionForm.put(route('acp.acl.permissions.update', { permission: selectedPermission.value.id }), {
        preserveScroll: true,
        onSuccess: () => handlePermissionDialogChange(false),
    });
};

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
                                    <Link v-if="canCreate" :href="route('acp.acl.roles.create')">
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
                                            <TableCell class="text-center">
                                                {{ role.created_at ? dayjs(role.created_at).fromNow() : '—' }}
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <template v-if="canEdit || canDelete">
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger as-child>
                                                            <Button variant="outline" size="icon">
                                                                <Ellipsis class="h-8 w-8" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent>
                                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                            <DropdownMenuSeparator v-if="canEdit" />
                                                            <DropdownMenuGroup v-if="canEdit">
                                                                <DropdownMenuItem class="text-blue-500" @click="openRoleDialog(role)">
                                                                    <Pencil class="mr-2"/> Edit
                                                                </DropdownMenuItem>
                                                            </DropdownMenuGroup>
                                                            <DropdownMenuSeparator v-if="canDelete" />
                                                            <DropdownMenuItem v-if="canDelete" class="text-red-500"
                                                                @click="$inertia.delete(route('acp.acl.roles.destroy', { role: role.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </template>
                                                <span v-else class="text-sm text-muted-foreground">—</span>
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
                        <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                            <div class="text-sm text-muted-foreground text-center md:text-left">
                                {{ rolesRangeLabel }}
                            </div>
                            <Pagination
                                v-if="rolesMeta.total > 0"
                                v-slot="{ page, pageCount }"
                                v-model:page="rolesPage"
                                :items-per-page="Math.max(rolesMeta.per_page, 1)"
                                :total="rolesMeta.total"
                                :sibling-count="1"
                                show-edges
                            >
                                <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                    <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
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
                                </div>
                            </Pagination>
                        </div>
                    </TabsContent>

                    <TabsContent value="permissions">
                        <!-- Permissions Management Section -->
                        <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 mb-4">
                            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                                <h2 class="text-lg font-semibold mb-2 md:mb-0">Permission Management</h2>
                                <div class="relative flex justify-end space-x-2">
                                    <Input
                                        v-model="permissionSearchQuery"
                                        placeholder="Search Permissions..."
                                        class="w-full rounded-md max-w-sm"
                                    />
                                    <Link v-if="canCreate" :href="route('acp.acl.permissions.create')">
                                        <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                            Create Permission
                                        </Button>
                                    </Link>
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
                                            <TableHead class="text-center"></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="permission in filteredPermissions" :key="permission.id">
                                            <TableCell>{{ permission.id }}</TableCell>
                                            <TableCell>{{ permission.name }}</TableCell>
                                            <TableCell class="text-center">{{ permission.guard_name }}</TableCell>
                                            <TableCell class="text-center">
                                                {{ permission.created_at ? dayjs(permission.created_at).fromNow() : '—' }}
                                            </TableCell>
                                            <TableCell class="text-center">
                                                <template v-if="canEdit || canDelete">
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger as-child>
                                                            <Button variant="outline" size="icon">
                                                                <Ellipsis class="h-8 w-8" />
                                                            </Button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent>
                                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                            <DropdownMenuSeparator v-if="canEdit" />
                                                            <DropdownMenuGroup v-if="canEdit">
                                                                <DropdownMenuItem class="text-blue-500" @click="openPermissionDialog(permission)">
                                                                    <Pencil class="mr-2" /> Edit
                                                                </DropdownMenuItem>
                                                            </DropdownMenuGroup>
                                                            <DropdownMenuSeparator v-if="canDelete" />
                                                            <DropdownMenuItem v-if="canDelete" class="text-red-500"
                                                                @click="$inertia.delete(route('acp.acl.permissions.destroy', { permission: permission.id }))"
                                                            >
                                                                <Trash2 class="mr-2" /> Delete
                                                            </DropdownMenuItem>
                                                        </DropdownMenuContent>
                                                    </DropdownMenu>
                                                </template>
                                                <span v-else class="text-sm text-muted-foreground">—</span>
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

                        <!-- Bottom Pagination -->
                        <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                            <div class="text-sm text-muted-foreground text-center md:text-left">
                                {{ permissionsRangeLabel }}
                            </div>
                            <Pagination
                                v-if="permissionsMeta.total > 0"
                                v-slot="{ page, pageCount }"
                                v-model:page="permissionsPage"
                                :items-per-page="Math.max(permissionsMeta.per_page, 1)"
                                :total="permissionsMeta.total"
                                :sibling-count="1"
                                show-edges
                            >
                                <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                    <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
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
                                </div>
                            </Pagination>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>

            <Dialog :open="roleDialogOpen" @update:open="handleRoleDialogChange">
                <DialogContent class="sm:max-w-[520px]">
                    <form class="space-y-6" @submit.prevent="submitRoleUpdate">
                        <DialogHeader>
                            <DialogTitle>Edit role</DialogTitle>
                            <DialogDescription v-if="selectedRole">
                                Update the details for <span class="font-medium">{{ selectedRole.name }}</span>.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="role-name">Name</Label>
                                <Input id="role-name" v-model="roleForm.name" type="text" autocomplete="off" required />
                                <InputError :message="roleForm.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="role-guard">Guard name</Label>
                                <Input id="role-guard" v-model="roleForm.guard_name" type="text" required />
                                <InputError :message="roleForm.errors.guard_name" />
                            </div>

                            <div class="grid gap-3">
                                <Label>Permissions</Label>
                                <div
                                    v-if="props.availablePermissions.length === 0"
                                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                                >
                                    No permissions are currently defined. Create permissions before assigning them to roles.
                                </div>
                                <div v-else class="grid gap-2 max-h-60 overflow-y-auto pr-1">
                                    <div
                                        v-for="permission in props.availablePermissions"
                                        :key="permission.id"
                                        class="flex items-start gap-3 rounded-md border p-3"
                                    >
                                        <Checkbox
                                            :id="`dialog-permission-${permission.id}`"
                                            :checked="roleForm.permissions.includes(permission.name)"
                                            @update:checked="value => toggleRolePermission(permission.name, value)"
                                        />
                                        <div class="grid gap-1">
                                            <Label :for="`dialog-permission-${permission.id}`" class="font-medium leading-none">
                                                {{ permission.name }}
                                            </Label>
                                            <p class="text-xs text-muted-foreground">Guard: {{ permission.guard_name }}</p>
                                        </div>
                                    </div>
                                </div>
                                <InputError :message="roleForm.errors.permissions" />
                            </div>
                        </div>

                        <DialogFooter class="gap-2">
                            <Button type="button" variant="secondary" @click="handleRoleDialogChange(false)">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="roleForm.processing">
                                Save changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog :open="permissionDialogOpen" @update:open="handlePermissionDialogChange">
                <DialogContent class="sm:max-w-[480px]">
                    <form class="space-y-6" @submit.prevent="submitPermissionUpdate">
                        <DialogHeader>
                            <DialogTitle>Edit permission</DialogTitle>
                            <DialogDescription v-if="selectedPermission">
                                Update the details for <span class="font-medium">{{ selectedPermission.name }}</span>.
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label for="permission-name">Name</Label>
                                <Input id="permission-name" v-model="permissionForm.name" type="text" autocomplete="off" required />
                                <InputError :message="permissionForm.errors.name" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="permission-guard">Guard name</Label>
                                <Input id="permission-guard" v-model="permissionForm.guard_name" type="text" required />
                                <InputError :message="permissionForm.errors.guard_name" />
                            </div>
                        </div>

                        <DialogFooter class="gap-2">
                            <Button type="button" variant="secondary" @click="handlePermissionDialogChange(false)">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="permissionForm.processing">
                                Save changes
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AdminLayout>
    </AppLayout>
</template>
