<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import Label from '@/components/ui/label/Label.vue';
import { useDebounceFn } from '@vueuse/core';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import type { CheckboxRootProps } from 'radix-vue';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    Users as UsersIcon,
    UserPlus,
    UserX,
    UserCheck,
    Activity,
    Ellipsis,
    Pencil,
    Trash2,
    MailCheck,
} from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import type { ButtonVariants } from '@/components/ui/button';

// dayjs composable for human readable dates
const { fromNow } = useUserTimezone();

// Permission checks
const { hasPermission } = usePermissions();
const editUsers = computed(() => hasPermission('users.acp.edit'));
const deleteUsers = computed(() => hasPermission('users.acp.delete'));
const verifyUsers = computed(() => hasPermission('users.acp.verify'));
const banUsers = computed(() => hasPermission('users.acp.ban'));

// Expect that the admin controller passes a "users" (paginated collection) & "userStats" prop
type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

const props = defineProps<{
    users: {
        data: Array<{
            id: number;
            nickname: string;
            email: string;
            email_verified_at: string | null;
            last_activity_at: string | null;
            roles: Array<{ name: string }>;
            created_at: string | null;
            is_banned: boolean;
            banned_at: string | null;
            banned_by: { id: number; nickname: string } | null;
        }>;
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    userStats: {
        total: number;
        unverified: number;
        banned: number;
        online: number;
    };
    filters: {
        search: string | null;
        role?: string | null;
        verification?: string | null;
        banned?: string | null;
        activity_window?: number | null;
    };
    availableRoles: string[];
}>();

const searchQuery = ref(props.filters.search ?? '');
let skipSearchWatch = false;

const roleFilter = ref(props.filters.role ?? 'all');
let skipRoleWatch = false;

const verificationFilter = ref(props.filters.verification ?? 'all');
let skipVerificationWatch = false;

const bannedFilter = ref(props.filters.banned ?? 'all');
let skipBannedWatch = false;

const activityFilter = ref(props.filters.activity_window ? String(props.filters.activity_window) : 'all');
let skipActivityWatch = false;

const quickVisitOptions = {
    preserveScroll: true,
    preserveState: true,
    replace: true,
} as const;

const buildQuery = (overrides: Record<string, unknown> = {}) => {
    const query: Record<string, unknown> = {};

    const trimmedSearch = searchQuery.value.trim();
    if (trimmedSearch !== '') {
        query.search = trimmedSearch;
    }

    if (roleFilter.value !== 'all') {
        query.role = roleFilter.value;
    }

    if (verificationFilter.value !== 'all') {
        query.verification = verificationFilter.value;
    }

    if (bannedFilter.value !== 'all') {
        query.banned = bannedFilter.value;
    }

    if (activityFilter.value !== 'all') {
        const parsed = Number.parseInt(activityFilter.value, 10);

        if (!Number.isNaN(parsed) && parsed > 0) {
            query.activity_window = parsed;
        }
    }

    return { ...query, ...overrides };
};

const applyFilters = (overrides: Record<string, unknown> = {}) => {
    router.get(route('acp.users.index'), buildQuery(overrides), quickVisitOptions);
};

const {
    meta: usersMeta,
    page: usersPage,
    setPage: setUsersPage,
    rangeLabel: usersRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.users.meta ?? null),
    itemsLength: computed(() => props.users.data?.length ?? 0),
    defaultPerPage: 15,
    itemLabel: 'user',
    itemLabelPlural: 'users',
    onNavigate: (page) => {
        router.get(route('acp.users.index'), buildQuery({ page }), quickVisitOptions);
    },
});

// Breadcrumbs
const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Users ACP', href: '/acp/users' },
];

// Search filtering
const debouncedSearch = useDebounceFn(() => {
    applyFilters({ page: 1 });
}, 300);

watch(
    () => props.filters.search ?? '',
    (value) => {
        if (searchQuery.value === value) {
            return;
        }

        skipSearchWatch = true;
        searchQuery.value = value;
    },
);

watch(searchQuery, () => {
    if (skipSearchWatch) {
        skipSearchWatch = false;
        return;
    }

    setUsersPage(1, { emitNavigate: false });
    debouncedSearch();
});

watch(
    () => props.filters.role ?? 'all',
    (value) => {
        if (roleFilter.value === value) {
            return;
        }

        skipRoleWatch = true;
        roleFilter.value = value;
    },
);

watch(roleFilter, () => {
    if (skipRoleWatch) {
        skipRoleWatch = false;
        return;
    }

    setUsersPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

watch(
    () => props.filters.verification ?? 'all',
    (value) => {
        if (verificationFilter.value === value) {
            return;
        }

        skipVerificationWatch = true;
        verificationFilter.value = value;
    },
);

watch(verificationFilter, () => {
    if (skipVerificationWatch) {
        skipVerificationWatch = false;
        return;
    }

    setUsersPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

watch(
    () => props.filters.banned ?? 'all',
    (value) => {
        if (bannedFilter.value === value) {
            return;
        }

        skipBannedWatch = true;
        bannedFilter.value = value;
    },
);

watch(bannedFilter, () => {
    if (skipBannedWatch) {
        skipBannedWatch = false;
        return;
    }

    setUsersPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

watch(
    () => (props.filters.activity_window ? String(props.filters.activity_window) : 'all'),
    (value) => {
        if (activityFilter.value === value) {
            return;
        }

        skipActivityWatch = true;
        activityFilter.value = value;
    },
);

watch(activityFilter, () => {
    if (skipActivityWatch) {
        skipActivityWatch = false;
        return;
    }

    setUsersPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

const banUser = (userId: number) => {
    router.put(
        route('acp.users.ban', { user: userId }),
        {},
        {
            preserveScroll: true,
            preserveState: false,
        },
    );
};

const unbanUser = (userId: number) => {
    router.put(
        route('acp.users.unban', { user: userId }),
        {},
        {
            preserveScroll: true,
            preserveState: false,
        },
    );
};

const destroyUser = (userId: number) => {
    router.delete(route('acp.users.destroy', { user: userId }), {
        preserveScroll: true,
        preserveState: false,
    });
};

type ConfirmDialogOptions = {
    title: string;
    description?: string;
    confirmLabel: string;
    confirmVariant?: ButtonVariants['variant'];
    onConfirm: () => void;
};

const confirmDialogState = reactive({
    open: false,
    title: '',
    description: null as string | null,
    confirmLabel: 'Confirm',
    confirmVariant: 'destructive' as ButtonVariants['variant'],
    onConfirm: null as null | (() => void),
});

const confirmDialogDescription = computed(() => confirmDialogState.description ?? undefined);

const openConfirmDialog = (options: ConfirmDialogOptions) => {
    confirmDialogState.title = options.title;
    confirmDialogState.description = options.description ?? null;
    confirmDialogState.confirmLabel = options.confirmLabel;
    confirmDialogState.confirmVariant = options.confirmVariant ?? 'destructive';
    confirmDialogState.onConfirm = options.onConfirm;
    confirmDialogState.open = true;
};

const closeConfirmDialog = () => {
    confirmDialogState.open = false;
    confirmDialogState.onConfirm = null;
};

const handleConfirmDialogConfirm = () => {
    confirmDialogState.onConfirm?.();
    closeConfirmDialog();
};

const handleConfirmDialogCancel = () => {
    closeConfirmDialog();
};

// Stats cards array
const stats = [
    { title: 'Total Users',      value: props.userStats.total,      icon: UsersIcon },
    { title: 'Unverified Users', value: props.userStats.unverified, icon: UserPlus },
    { title: 'Banned Users',     value: props.userStats.banned,     icon: UserX },
    { title: 'Online Users',     value: props.userStats.online,     icon: Activity },
];

const userItems = computed(() => props.users.data ?? []);

type CheckboxState = CheckboxRootProps['checked'];
type BulkAction = 'verify' | 'ban' | 'unban' | 'delete';

const selectedUserIds = ref<number[]>([]);

watch(
    userItems,
    (items) => {
        const validIds = new Set(items.map((item) => item.id));
        selectedUserIds.value = selectedUserIds.value.filter((id) => validIds.has(id));
    },
    { immediate: true },
);

const hasUserSelection = computed(() => selectedUserIds.value.length > 0);
const allUsersSelected = computed(
    () => userItems.value.length > 0 && selectedUserIds.value.length === userItems.value.length,
);

const userHeaderCheckboxState = computed<CheckboxState>(() => {
    if (allUsersSelected.value) {
        return true;
    }

    if (selectedUserIds.value.length > 0) {
        return 'indeterminate';
    }

    return false;
});

const userSelectionLabel = computed(() => {
    const count = selectedUserIds.value.length;

    if (count === 0) {
        return 'Select users to enable bulk actions.';
    }

    return count === 1 ? '1 user selected.' : `${count} users selected.`;
});

const bulkActionForm = useForm<{ ids: number[]; action: BulkAction }>({
    ids: [],
    action: 'verify',
});

const updateUserSelection = (userId: number, checked: boolean) => {
    if (checked) {
        if (!selectedUserIds.value.includes(userId)) {
            selectedUserIds.value = [...selectedUserIds.value, userId];
        }

        return;
    }

    selectedUserIds.value = selectedUserIds.value.filter((id) => id !== userId);
};

const toggleAllUsers = (checked: boolean) => {
    if (checked) {
        selectedUserIds.value = userItems.value.map((item) => item.id);

        return;
    }

    selectedUserIds.value = [];
};

const submitBulkAction = (action: BulkAction) => {
    const ids = Array.from(new Set(selectedUserIds.value));

    if (ids.length === 0) {
        return;
    }

    bulkActionForm.ids = ids;
    bulkActionForm.action = action;

    bulkActionForm.patch(route('acp.users.bulk-update'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedUserIds.value = [];
        },
    });
};

const hasBulkActions = computed(() => verifyUsers.value || banUsers.value || deleteUsers.value);
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
                    <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                        <div class="flex w-full flex-col gap-2 md:max-w-sm">
                            <h2 class="text-lg font-semibold">User Management</h2>
                            <Input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search users by nickname, email or role..."
                                class="w-full pr-10"
                            />
                        </div>

                        <div class="flex flex-wrap items-end gap-3">
                            <div class="flex flex-col gap-1">
                                <Label for="filter-role" class="text-xs uppercase tracking-wide text-muted-foreground">Role</Label>
                                <select
                                    id="filter-role"
                                    v-model="roleFilter"
                                    class="flex h-10 min-w-[10rem] rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="all">All roles</option>
                                    <option v-for="role in props.availableRoles" :key="role" :value="role">
                                        {{ role }}
                                    </option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1">
                                <Label for="filter-verification" class="text-xs uppercase tracking-wide text-muted-foreground">Verification</Label>
                                <select
                                    id="filter-verification"
                                    v-model="verificationFilter"
                                    class="flex h-10 min-w-[10rem] rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="all">All users</option>
                                    <option value="verified">Verified</option>
                                    <option value="unverified">Unverified</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1">
                                <Label for="filter-ban" class="text-xs uppercase tracking-wide text-muted-foreground">Ban status</Label>
                                <select
                                    id="filter-ban"
                                    v-model="bannedFilter"
                                    class="flex h-10 min-w-[10rem] rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="all">All users</option>
                                    <option value="banned">Banned</option>
                                    <option value="not_banned">Not banned</option>
                                </select>
                            </div>

                            <div class="flex flex-col gap-1">
                                <Label for="filter-activity" class="text-xs uppercase tracking-wide text-muted-foreground">Activity</Label>
                                <select
                                    id="filter-activity"
                                    v-model="activityFilter"
                                    class="flex h-10 min-w-[10rem] rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="all">Any time</option>
                                    <option value="5">Active in last 5 minutes</option>
                                    <option value="15">Active in last 15 minutes</option>
                                    <option value="60">Active in last hour</option>
                                    <option value="1440">Active in last day</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <p class="text-sm text-muted-foreground">{{ userSelectionLabel }}</p>

                        <DropdownMenu v-if="hasBulkActions">
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="outline"
                                    :disabled="!hasUserSelection || bulkActionForm.processing"
                                >
                                    Bulk actions
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-52">
                                <DropdownMenuLabel>Apply to selected</DropdownMenuLabel>
                                <DropdownMenuItem
                                    v-if="verifyUsers"
                                    :disabled="bulkActionForm.processing"
                                    @select="submitBulkAction('verify')"
                                >
                                    <MailCheck class="mr-2 h-4 w-4" />
                                    <span>Verify email</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator v-if="verifyUsers && (banUsers || deleteUsers)" />
                                <DropdownMenuItem
                                    v-if="banUsers"
                                    :disabled="bulkActionForm.processing"
                                    @select="submitBulkAction('ban')"
                                >
                                    <UserX class="mr-2 h-4 w-4" />
                                    <span>Ban users</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    v-if="banUsers"
                                    :disabled="bulkActionForm.processing"
                                    @select="submitBulkAction('unban')"
                                >
                                    <UserCheck class="mr-2 h-4 w-4" />
                                    <span>Unban users</span>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator v-if="deleteUsers && (verifyUsers || banUsers)" />
                                <DropdownMenuItem
                                    v-if="deleteUsers"
                                    class="text-red-500"
                                    :disabled="bulkActionForm.processing"
                                    @select="submitBulkAction('delete')"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    <span>Delete users</span>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    <!-- Users Table -->
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-12">
                                        <Checkbox
                                            :checked="userHeaderCheckboxState"
                                            :disabled="userItems.length === 0"
                                            aria-label="Select all users"
                                            @update:checked="toggleAllUsers"
                                        />
                                    </TableHead>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Email</TableHead>
                                    <TableHead class="text-center">Roles</TableHead>
                                    <TableHead class="text-center">Last Active</TableHead>
                                    <TableHead class="text-center">Created</TableHead>
                                    <TableHead class="text-center">Status</TableHead>
                                    <TableHead class="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="user in userItems"
                                    :key="user.id"
                                >
                                    <TableCell class="align-middle">
                                        <Checkbox
                                            :checked="selectedUserIds.includes(user.id)"
                                            aria-label="Select user"
                                            @update:checked="(checked) => updateUserSelection(user.id, checked)"
                                        />
                                    </TableCell>
                                    <TableCell>{{ user.id }}</TableCell>
                                    <TableCell>
                                        <Link
                                            v-if="editUsers"
                                            :href="route('acp.users.edit', { user: user.id })"
                                            class="font-medium text-primary transition hover:underline"
                                        >
                                            {{ user.nickname }}
                                        </Link>
                                        <span v-else>{{ user.nickname }}</span>
                                    </TableCell>
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
                                    <TableCell class="text-center">
                                        <span v-if="user.last_activity_at" :title="user.last_activity_at">
                                            {{ fromNow(user.last_activity_at) }}
                                        </span>
                                        <span v-else class="text-muted-foreground">Never</span>
                                    </TableCell>
                                    <TableCell class="text-center">{{ fromNow(user.created_at) }}</TableCell>
                                    <TableCell class="text-center">
                                        <span
                                            v-if="user.is_banned"
                                            class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700 dark:bg-red-500/20 dark:text-red-300"
                                        >
                                            Banned
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300"
                                        >
                                            Active
                                        </span>
                                    </TableCell>
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
                                                    <DropdownMenuItem v-if="verifyUsers && !user.email_verified_at" class="text-green-500"
                                                        @click="$inertia.put(route('acp.users.verify', { user: user.id }))"
                                                    >
                                                        <MailCheck class="mr-2" /> Verify
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="banUsers && !user.is_banned"
                                                        class="text-amber-600"
                                                        @click="
                                                            openConfirmDialog({
                                                                title: `Ban ${user.nickname}`,
                                                                description: `Banning ${user.nickname} will immediately revoke their access.`,
                                                                confirmLabel: 'Ban user',
                                                                onConfirm: () => banUser(user.id),
                                                            })
                                                        "
                                                    >
                                                        <UserX class="mr-2" /> Ban
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="banUsers && user.is_banned"
                                                        class="text-emerald-600"
                                                        @click="
                                                            openConfirmDialog({
                                                                title: `Unban ${user.nickname}`,
                                                                description: `Unbanning ${user.nickname} will restore their access.`,
                                                                confirmLabel: 'Unban user',
                                                                confirmVariant: 'default',
                                                                onConfirm: () => unbanUser(user.id),
                                                            })
                                                        "
                                                    >
                                                        <UserCheck class="mr-2" /> Unban
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator v-if="deleteUsers" />
                                                    <DropdownMenuItem
                                                        v-if="deleteUsers"
                                                        class="text-red-500"
                                                        @click="
                                                            openConfirmDialog({
                                                                title: `Delete ${user.nickname}`,
                                                                description: `This will permanently remove ${user.nickname}'s account and associated data.`,
                                                                confirmLabel: 'Delete user',
                                                                onConfirm: () => destroyUser(user.id),
                                                            })
                                                        "
                                                    >
                                                        <Trash2 class="mr-2"/> Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="userItems.length === 0">
                                    <TableCell colspan="9" class="text-center text-gray-600 dark:text-gray-300">
                                        No users found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <!-- Bottom Pagination -->
                <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                    <div class="text-sm text-muted-foreground text-center md:text-left">
                        {{ usersRangeLabel }}
                    </div>
                    <Pagination
                        v-if="usersMeta.total > 0"
                        v-slot="{ page, pageCount }"
                        v-model:page="usersPage"
                        :items-per-page="Math.max(usersMeta.per_page, 1)"
                        :total="usersMeta.total"
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
            </div>
            <ConfirmDialog
                v-model:open="confirmDialogState.open"
                :title="confirmDialogState.title"
                :description="confirmDialogDescription"
                :confirm-label="confirmDialogState.confirmLabel"
                :confirm-variant="confirmDialogState.confirmVariant"
                @confirm="handleConfirmDialogConfirm"
                @cancel="handleConfirmDialogCancel"
            />
        </AdminLayout>
    </AppLayout>
</template>
