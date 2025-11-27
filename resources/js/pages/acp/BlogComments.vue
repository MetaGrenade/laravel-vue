<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import Input from '@/components/ui/input/Input.vue';
import { Textarea } from '@/components/ui/textarea';
import { Checkbox } from '@/components/ui/checkbox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
import { useDebounceFn } from '@vueuse/core';
import { Ban, CheckCircle, Edit3, Filter, Flag, Trash2, XCircle } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

const props = defineProps<{
    comments: {
        data: Array<{
            id: number;
            body: string;
            body_preview: string;
            status: string;
            is_flagged: boolean;
            created_at: string | null;
            updated_at: string | null;
            user: {
                id: number;
                nickname: string;
                email: string;
                is_banned: boolean;
            } | null;
            blog: {
                id: number;
                title: string;
                slug: string;
                status: string;
            } | null;
            can: {
                update: boolean;
                review: boolean;
                delete: boolean;
            };
        }>;
        meta: PaginationMeta;
        links: {
            first: string | null;
            last: string | null;
            prev: string | null;
            next: string | null;
        };
    };
    filters: {
        status: string;
        flagged: boolean | null;
        user_id: number | null;
        search: string | null;
        per_page: number;
    };
    statuses: string[];
}>();

const filterState = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'all',
    flagged: props.filters.flagged === true ? 'flagged' : props.filters.flagged === false ? 'unflagged' : 'all',
    userId: props.filters.user_id ? String(props.filters.user_id) : '',
    perPage: String(props.filters.per_page ?? 25),
});

const { hasPermission } = usePermissions();
const canBanUsers = computed(() => hasPermission('users.acp.ban'));

const buildFilters = (overrides: Partial<{ page: number } & typeof filterState> = {}) => {
    const params: Record<string, unknown> = {};

    const searchValue = overrides.search ?? filterState.search;
    if (searchValue && searchValue.trim() !== '') {
        params.search = searchValue.trim();
    }

    const statusValue = overrides.status ?? filterState.status;
    if (statusValue && statusValue !== 'all') {
        params.status = statusValue;
    }

    const flaggedValue = overrides.flagged ?? filterState.flagged;
    if (flaggedValue === 'flagged') {
        params.flagged = true;
    } else if (flaggedValue === 'unflagged') {
        params.flagged = false;
    }

    const userIdValue = overrides.userId ?? filterState.userId;
    if (userIdValue && userIdValue.trim() !== '') {
        const parsed = Number.parseInt(userIdValue, 10);
        if (!Number.isNaN(parsed)) {
            params.user_id = parsed;
        }
    }

    const perPageValue = overrides.perPage ?? filterState.perPage;
    const parsedPerPage = Number.parseInt(perPageValue, 10);
    if (!Number.isNaN(parsedPerPage) && parsedPerPage > 0) {
        params.per_page = parsedPerPage;
    }

    if (typeof overrides.page === 'number' && overrides.page > 1) {
        params.page = overrides.page;
    }

    return params;
};

const visitWithFilters = (overrides: Partial<{ page: number } & typeof filterState> = {}) => {
    router.get(route('acp.blog-comments.index'), buildFilters(overrides), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const clearFilters = () => {
    filterState.search = '';
    filterState.status = 'all';
    filterState.flagged = 'all';
    filterState.userId = '';
    filterState.perPage = '25';
    visitWithFilters();
};

const debouncedSearch = useDebounceFn(() => visitWithFilters(), 300);
watch(() => filterState.search, debouncedSearch);

const { meta: paginationMeta, rangeLabel, setPage } = useInertiaPagination({
    meta: computed(() => props.comments.meta ?? null),
    itemsLength: computed(() => props.comments.data?.length ?? 0),
    defaultPerPage: props.filters.per_page ?? 25,
    itemLabel: 'comment',
    itemLabelPlural: 'comments',
    onNavigate: (page) => visitWithFilters({ page }),
});

const editingComment = ref<typeof props.comments.data[number] | null>(null);
const editForm = useForm({
    body: '',
    status: '',
    is_flagged: false,
});

const openEditor = (comment: typeof props.comments.data[number]) => {
    editingComment.value = comment;
    editForm.body = comment.body;
    editForm.status = comment.status;
    editForm.is_flagged = comment.is_flagged;
};

const submitEdit = () => {
    if (!editingComment.value) return;

    editForm.put(route('acp.blog-comments.update', editingComment.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingComment.value = null;
        },
    });
};

const quickStatusUpdate = (comment: typeof props.comments.data[number], status: string) => {
    editForm.body = comment.body;
    editForm.status = status;
    editForm.is_flagged = comment.is_flagged;
    editingComment.value = comment;
    submitEdit();
};

const confirmOpen = ref(false);
const deleteTarget = ref<typeof props.comments.data[number] | null>(null);

const requestDelete = (comment: typeof props.comments.data[number]) => {
    deleteTarget.value = comment;
    confirmOpen.value = true;
};

const performDelete = () => {
    if (!deleteTarget.value) return;

    router.delete(route('acp.blog-comments.destroy', deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
            confirmOpen.value = false;
        },
    });
};

const blockUser = (comment: typeof props.comments.data[number]) => {
    if (!comment.user || !canBanUsers.value) return;

    router.put(route('acp.users.ban', comment.user.id), {}, { preserveScroll: true });
};
</script>

<template>
    <Head title="Blog Comments ACP" />

    <AppLayout>
        <AdminLayout>
            <div class="w-full space-y-6">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <p class="text-sm text-muted-foreground">Moderate blog feedback and address abuse.</p>
                        <h1 class="text-3xl font-bold">Blog Comments</h1>
                    </div>

                    <Badge variant="outline" class="h-10 items-center justify-center">
                        {{ props.comments.meta?.total ?? 0 }} total
                    </Badge>
                </div>

                <div class="rounded-lg border bg-card p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-foreground" for="search">Search body</label>
                            <Input
                                id="search"
                                v-model="filterState.search"
                                type="text"
                                placeholder="Find keywords in comments"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="status">Status</label>
                            <select
                                id="status"
                                v-model="filterState.status"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="visitWithFilters"
                            >
                                <option value="all">All</option>
                                <option v-for="statusOption in props.statuses" :key="statusOption" :value="statusOption">
                                    {{ statusOption.charAt(0).toUpperCase() + statusOption.slice(1) }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="flagged">Flagged</label>
                            <select
                                id="flagged"
                                v-model="filterState.flagged"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="visitWithFilters"
                            >
                                <option value="all">All</option>
                                <option value="flagged">Flagged</option>
                                <option value="unflagged">Not flagged</option>
                            </select>
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="user">User ID</label>
                            <Input
                                id="user"
                                v-model="filterState.userId"
                                type="text"
                                placeholder="Any"
                                @keyup.enter="visitWithFilters"
                            />
                        </div>

                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="perPage">Per page</label>
                            <select
                                id="perPage"
                                v-model="filterState.perPage"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="visitWithFilters"
                            >
                                <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button variant="secondary" size="sm" class="gap-2" @click="visitWithFilters">
                            <Filter class="h-4 w-4" />
                            Apply filters
                        </Button>
                        <Button variant="ghost" size="sm" @click="clearFilters">Reset</Button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-lg border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Preview</TableHead>
                                <TableHead>User</TableHead>
                                <TableHead>Blog</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="comment in props.comments.data" :key="comment.id">
                                <TableCell class="font-medium">#{{ comment.id }}</TableCell>
                                <TableCell>
                                    <p class="max-w-xl text-sm text-muted-foreground">{{ comment.body_preview }}</p>
                                    <div class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>Created: {{ comment.created_at ?? 'N/A' }}</span>
                                        <span>Updated: {{ comment.updated_at ?? 'N/A' }}</span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div v-if="comment.user" class="flex flex-col text-sm">
                                        <span class="font-medium">{{ comment.user.nickname }}</span>
                                        <span class="text-muted-foreground">{{ comment.user.email }}</span>
                                        <span v-if="comment.user.is_banned" class="text-xs text-destructive">Banned</span>
                                    </div>
                                    <span v-else class="text-sm text-muted-foreground">Guest</span>
                                </TableCell>
                                <TableCell>
                                    <div v-if="comment.blog" class="flex flex-col text-sm">
                                        <span class="font-medium">{{ comment.blog.title }}</span>
                                        <span class="text-muted-foreground">Status: {{ comment.blog.status }}</span>
                                    </div>
                                    <span v-else class="text-sm text-muted-foreground">Detached</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-col gap-2">
                                        <Badge :variant="comment.status === 'approved' ? 'default' : comment.status === 'pending' ? 'secondary' : 'destructive'">
                                            {{ comment.status }}
                                        </Badge>
                                        <Badge v-if="comment.is_flagged" variant="destructive" class="w-fit gap-1">
                                            <Flag class="h-3 w-3" />
                                            Flagged
                                        </Badge>
                                    </div>
                                </TableCell>
                                <TableCell class="space-y-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="comment.can.review && comment.status !== 'approved'"
                                            variant="outline"
                                            size="sm"
                                            class="gap-1"
                                            @click="quickStatusUpdate(comment, 'approved')"
                                        >
                                            <CheckCircle class="h-4 w-4" />
                                            Approve
                                        </Button>
                                        <Button
                                            v-if="comment.can.review && comment.status !== 'rejected'"
                                            variant="outline"
                                            size="sm"
                                            class="gap-1"
                                            @click="quickStatusUpdate(comment, 'rejected')"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Reject
                                        </Button>
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="comment.can.update"
                                            variant="secondary"
                                            size="sm"
                                            class="gap-1"
                                            @click="openEditor(comment)"
                                        >
                                            <Edit3 class="h-4 w-4" />
                                            Edit
                                        </Button>
                                        <Button
                                            v-if="comment.can.delete"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1 text-destructive"
                                            @click="requestDelete(comment)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </Button>
                                        <Button
                                            v-if="comment.user && !comment.user.is_banned && canBanUsers && comment.can.review"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1 text-destructive"
                                            @click="blockUser(comment)"
                                        >
                                            <Ban class="h-4 w-4" />
                                            Block user
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <div class="flex flex-col items-center justify-between gap-4 border-t px-4 py-3 sm:flex-row">
                        <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                        <Pagination v-if="paginationMeta">
                            <PaginationList v-slot="{ items }">
                                <PaginationFirst :disabled="paginationMeta.current_page === 1" @click="setPage(1)" />
                                <PaginationPrev :disabled="paginationMeta.current_page === 1" @click="setPage(paginationMeta.current_page - 1)" />
                                <template v-for="(item, index) in items" :key="index">
                                    <PaginationListItem
                                        v-if="item.type === 'page'"
                                        :value="item.value"
                                        :active="item.value === paginationMeta.current_page"
                                        @click="setPage(item.value)"
                                    >
                                        {{ item.value }}
                                    </PaginationListItem>
                                    <PaginationEllipsis v-else />
                                </template>
                                <PaginationNext
                                    :disabled="paginationMeta.current_page === paginationMeta.last_page"
                                    @click="setPage(paginationMeta.current_page + 1)"
                                />
                                <PaginationLast
                                    :disabled="paginationMeta.current_page === paginationMeta.last_page"
                                    @click="setPage(paginationMeta.last_page)"
                                />
                            </PaginationList>
                        </Pagination>
                    </div>
                </div>

                <div v-if="editingComment" class="rounded-lg border bg-card p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-xl font-semibold">Editing comment #{{ editingComment.id }}</h2>
                            <p class="text-sm text-muted-foreground">Update content, status, or flags.</p>
                        </div>
                        <Button variant="ghost" size="sm" @click="editingComment = null">Close</Button>
                    </div>

                    <div class="mt-4 space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="body">Body</label>
                            <Textarea id="body" v-model="editForm.body" rows="4" placeholder="Comment body" />
                            <p v-if="editForm.errors.body" class="mt-1 text-sm text-destructive">{{ editForm.errors.body }}</p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-foreground" for="editStatus">Status</label>
                                <select
                                    id="editStatus"
                                    v-model="editForm.status"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                >
                                    <option v-for="statusOption in props.statuses" :key="statusOption" :value="statusOption">
                                        {{ statusOption.charAt(0).toUpperCase() + statusOption.slice(1) }}
                                    </option>
                                </select>
                                <p v-if="editForm.errors.status" class="mt-1 text-sm text-destructive">{{ editForm.errors.status }}</p>
                            </div>

                            <div class="flex items-center gap-2 pt-6">
                                <Checkbox id="flagged" v-model:checked="editForm.is_flagged" />
                                <label for="flagged" class="text-sm font-medium">Mark as flagged</label>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button :disabled="editForm.processing" class="gap-2" @click="submitEdit">
                                <Edit3 class="h-4 w-4" />
                                Save changes
                            </Button>
                            <Button variant="ghost" @click="editingComment = null">Cancel</Button>
                        </div>
                    </div>
                </div>

                <ConfirmDialog
                    v-model:open="confirmOpen"
                    title="Delete comment?"
                    description="This action cannot be undone. The comment will be permanently removed."
                    confirm-label="Delete"
                    @confirm="performDelete"
                    @cancel="() => (confirmOpen = false)"
                />
            </div>
        </AdminLayout>
    </AppLayout>
</template>
