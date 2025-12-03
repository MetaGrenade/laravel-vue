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
import { Filter, ShieldCheck, ShieldAlert, CheckCircle, XCircle, Trash2, Pencil, Flag } from 'lucide-vue-next';
import { usePermissions } from '@/composables/usePermissions';

interface ReportComment {
    id: number;
    body?: string;
    body_preview: string;
    status: string;
    is_flagged: boolean;
    can: {
        update: boolean;
        review: boolean;
        delete: boolean;
    };
    user?: {
        id: number;
        nickname: string;
        email: string;
        is_banned: boolean;
    } | null;
}

interface LatestReportMeta {
    status: string;
    reason_category: string;
    reason?: string | null;
    evidence_url?: string | null;
    created_at?: string | null;
    reporter?: { id: number; nickname: string; email: string } | null;
    reviewer?: { id: number; nickname: string; email: string } | null;
}

interface ReportItem {
    id: number;
    reports_count: number;
    total_reports_count: number;
    pending_reports_count: number;
    latest_reported_at?: string | null;
    report_ids: number[];
    latest_report?: LatestReportMeta | null;
    reporters: Array<{ id: number; nickname: string; email: string }>;
    comment?: ReportComment | null;
    blog?: { id: number; title: string; slug: string; status: string } | null;
    author?: { id: number; nickname: string; email: string; is_banned: boolean } | null;
}

interface ReportReasonOption {
    value: string;
    label: string;
}

const props = defineProps<{
    reports: {
        data: ReportItem[];
        meta: PaginationMeta;
        links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    };
    filters: {
        status: string;
        reason_category: string | null;
        search: string | null;
        per_page: number;
        sort: string;
    };
    statuses: string[];
    reportReasons: ReportReasonOption[];
    commentStatuses: string[];
}>();

const { hasPermission } = usePermissions();
const canBanUsers = computed(() => hasPermission('users.acp.ban'));

const filterState = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? 'pending',
    reasonCategory: props.filters.reason_category ?? 'all',
    perPage: String(props.filters.per_page ?? 25),
    sort: props.filters.sort ?? 'newest',
});

const buildFilters = (overrides: Partial<typeof filterState & { page: number }> = {}) => {
    const params: Record<string, unknown> = {};

    const searchValue = overrides.search ?? filterState.search;
    if (searchValue && searchValue.trim() !== '') {
        params.search = searchValue.trim();
    }

    const statusValue = overrides.status ?? filterState.status;
    if (statusValue && statusValue !== 'all') {
        params.status = statusValue;
    }

    const reasonValue = overrides.reasonCategory ?? filterState.reasonCategory;
    if (reasonValue && reasonValue !== 'all') {
        params.reason_category = reasonValue;
    }

    const perPageValue = overrides.perPage ?? filterState.perPage;
    const parsedPerPage = Number.parseInt(perPageValue, 10);
    if (!Number.isNaN(parsedPerPage) && parsedPerPage > 0) {
        params.per_page = parsedPerPage;
    }

    const sortValue = overrides.sort ?? filterState.sort;
    if (sortValue && sortValue !== 'newest') {
        params.sort = sortValue;
    }

    if (typeof overrides.page === 'number' && overrides.page > 1) {
        params.page = overrides.page;
    }

    return params;
};

const visitWithFilters = (overrides: Partial<typeof filterState & { page: number }> = {}) => {
    router.get(route('acp.blog-comments.index'), buildFilters(overrides), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const debouncedSearch = useDebounceFn(() => visitWithFilters(), 300);
watch(() => filterState.search, debouncedSearch);

const { meta: paginationMeta, page: paginationPage, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.reports.meta ?? null),
    itemsLength: computed(() => props.reports.data?.length ?? 0),
    defaultPerPage: props.filters.per_page ?? 25,
    itemLabel: 'report',
    itemLabelPlural: 'reports',
    onNavigate: (page) => visitWithFilters({ page }),
});

const selectedCommentIds = ref<number[]>([]);
const hasSelection = computed(() => selectedCommentIds.value.length > 0);

const findReportRow = (id: number) => props.reports.data.find((report) => report.id === id);

const reportIdsForSelection = (ids: number[]) => {
    const uniqueIds = new Set<number>();

    ids.forEach((commentId) => {
        const row = findReportRow(commentId);
        row?.report_ids?.forEach((reportId) => uniqueIds.add(reportId));
    });

    return Array.from(uniqueIds.values());
};

const toggleReportSelection = (id: number, checked: boolean) => {
    if (checked) {
        if (!selectedCommentIds.value.includes(id)) {
            selectedCommentIds.value = [...selectedCommentIds.value, id];
        }
    } else {
        selectedCommentIds.value = selectedCommentIds.value.filter((value) => value !== id);
    }
};

const toggleAllReports = (checked: boolean) => {
    if (checked) {
        selectedCommentIds.value = props.reports.data.map((report) => report.id);
    } else {
        selectedCommentIds.value = [];
    }
};

const bulkForm = useForm<{ status: string; reports: number[] }>({
    status: props.filters.status ?? 'pending',
    reports: [],
});

const submitBulkStatus = (status: string, reportIds?: number[]) => {
    const payload = reportIds ?? reportIdsForSelection(selectedCommentIds.value);
    if (payload.length === 0) return;

    bulkForm.status = status;
    bulkForm.reports = payload;

    bulkForm.patch(route('acp.blog-comment-reports.bulk-status'), {
        preserveScroll: true,
        onSuccess: () => {
            selectedCommentIds.value = [];
        },
    });
};

const editingComment = ref<ReportComment | null>(null);
const editForm = useForm({
    body: '',
    status: '',
    is_flagged: false,
});

const openEditor = (comment: ReportComment | null | undefined) => {
    if (!comment || !comment.can.update) return;

    editingComment.value = comment;
    editForm.body = comment.body ?? comment.body_preview;
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

const quickReportStatus = (report: ReportItem, status: string) => submitBulkStatus(status, report.report_ids);

const confirmOpen = ref(false);
const deleteTarget = ref<ReportComment | null>(null);

const requestDelete = (comment: ReportComment | null | undefined) => {
    if (!comment?.can.delete) return;
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

const blockUser = (comment: ReportComment | null | undefined) => {
    if (!comment?.user || !canBanUsers.value || comment.user.is_banned) return;

    router.put(route('acp.users.ban', comment.user.id), {}, { preserveScroll: true });
};

const reportReasonLabel = (value: string) =>
    props.reportReasons.find((reason) => reason.value === value)?.label ?? value;

const statusBadges: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    pending: 'secondary',
    reviewed: 'default',
    dismissed: 'outline',
};

const statusIcons: Record<string, typeof ShieldCheck | typeof ShieldAlert | typeof Flag> = {
    pending: ShieldAlert,
    reviewed: ShieldCheck,
    dismissed: Flag,
};

const hasReports = computed(() => (props.reports.data?.length ?? 0) > 0);
</script>

<template>
    <Head title="Blog Comment Reports" />

    <AppLayout>
        <AdminLayout>
            <div class="w-full space-y-6">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div>
                        <p class="text-sm text-muted-foreground">Review and resolve reports from blog comments.</p>
                        <h1 class="text-3xl font-bold">Blog Comment Reports</h1>
                    </div>

                    <Badge variant="outline" class="h-10 items-center justify-center">
                        {{ props.reports.meta?.total ?? 0 }} total
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
                            <label class="mb-1 block text-sm font-medium text-foreground" for="reason">Reason</label>
                            <select
                                id="reason"
                                v-model="filterState.reasonCategory"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="visitWithFilters"
                            >
                                <option value="all">All</option>
                                <option v-for="reason in props.reportReasons" :key="reason.value" :value="reason.value">
                                    {{ reason.label }}
                                </option>
                            </select>
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

                        <div>
                            <label class="mb-1 block text-sm font-medium text-foreground" for="sortBy">Sort by</label>
                            <select
                                id="sortBy"
                                v-model="filterState.sort"
                                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                                @change="visitWithFilters"
                            >
                                <option value="newest">Newest reports</option>
                                <option value="oldest">Oldest reports</option>
                                <option value="most_reported">Most reported</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <Button variant="secondary" size="sm" class="gap-2" @click="visitWithFilters">
                            <Filter class="h-4 w-4" />
                            Apply filters
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="
                                () => {
                                    filterState.search = '';
                                    filterState.status = props.filters.status ?? 'pending';
                                    filterState.reasonCategory = 'all';
                                    filterState.perPage = String(props.filters.per_page ?? 25);
                                    filterState.sort = props.filters.sort ?? 'newest';
                                    visitWithFilters();
                                }
                            "
                        >
                            Reset
                        </Button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2" v-if="hasSelection">
                    <span class="text-sm text-muted-foreground">Bulk actions:</span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="bulkForm.processing"
                        class="gap-1"
                        @click="submitBulkStatus('reviewed')"
                    >
                        <CheckCircle class="h-4 w-4" /> Review
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="bulkForm.processing"
                        class="gap-1"
                        @click="submitBulkStatus('dismissed')"
                    >
                        <XCircle class="h-4 w-4" /> Dismiss
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="bulkForm.processing"
                        class="gap-1"
                        @click="submitBulkStatus('pending')"
                    >
                        <ShieldAlert class="h-4 w-4" /> Mark pending
                    </Button>
                </div>

                <div class="overflow-hidden rounded-lg border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-12 text-center">
                                    <Checkbox
                                        :checked="selectedCommentIds.length === props.reports.data.length && props.reports.data.length > 0"
                                        :indeterminate="
                                            selectedCommentIds.length > 0 &&
                                            selectedCommentIds.length < props.reports.data.length
                                        "
                                        @update:checked="toggleAllReports"
                                    />
                                </TableHead>
                                <TableHead>Comment</TableHead>
                                <TableHead class="w-56">Reports</TableHead>
                                <TableHead class="w-48">Reporters</TableHead>
                                <TableHead class="w-48">Comment author</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="report in props.reports.data" :key="report.id">
                                <TableCell class="text-center">
                                    <Checkbox
                                        :checked="selectedCommentIds.includes(report.id)"
                                        @update:checked="(checked) => toggleReportSelection(report.id, checked)"
                                    />
                                </TableCell>
                                <TableCell>
                                    <div class="space-y-2 text-sm">
                                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                            <Badge variant="secondary">#{{ report.id }}</Badge>
                                            <span v-if="report.blog">Blog: {{ report.blog.title }}</span>
                                            <span
                                                v-if="report.comment?.is_flagged"
                                                class="flex items-center gap-1 text-destructive"
                                            >
                                                <Flag class="h-3 w-3" /> Flagged
                                            </span>
                                        </div>
                                        <p class="font-medium text-foreground">{{ report.comment?.body_preview ?? 'Removed' }}</p>
                                        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <span>Latest: {{ report.latest_reported_at ?? 'N/A' }}</span>
                                            <span class="flex items-center gap-1">
                                                <ShieldAlert class="h-3.5 w-3.5" />
                                                Pending: {{ report.pending_reports_count }}
                                            </span>
                                        </div>
                                        <div v-if="report.latest_report" class="space-y-1 text-xs text-muted-foreground">
                                            <div class="flex items-center gap-2">
                                                <Badge :variant="statusBadges[report.latest_report.status] ?? 'secondary'" class="gap-1">
                                                    <component
                                                        :is="statusIcons[report.latest_report.status] ?? ShieldAlert"
                                                        class="h-3.5 w-3.5"
                                                    />
                                                    {{ report.latest_report.status }}
                                                </Badge>
                                                <span>{{ reportReasonLabel(report.latest_report.reason_category) }}</span>
                                            </div>
                                            <p v-if="report.latest_report.reason">{{ report.latest_report.reason }}</p>
                                            <a
                                                v-if="report.latest_report.evidence_url"
                                                :href="report.latest_report.evidence_url"
                                                class="text-primary underline"
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Evidence
                                            </a>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="space-y-1 text-sm">
                                        <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                            <Badge variant="outline">{{ report.reports_count }} filtered</Badge>
                                            <Badge variant="outline">{{ report.total_reports_count }} total</Badge>
                                        </div>
                                        <div class="text-xs text-muted-foreground">
                                            Latest by: {{ report.latest_report?.reporter?.nickname ?? 'Unknown' }}
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="space-y-1 text-sm">
                                        <div
                                            v-for="reporter in report.reporters"
                                            :key="reporter.id"
                                            class="rounded-md border border-sidebar-border/60 px-2 py-1"
                                        >
                                            <p class="font-medium">{{ reporter.nickname }}</p>
                                            <p class="text-xs text-muted-foreground">{{ reporter.email }}</p>
                                        </div>
                                        <p v-if="report.reporters.length === 0" class="text-xs text-muted-foreground">No reporters listed</p>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="space-y-1 text-sm">
                                        <p class="font-medium">{{ report.author?.nickname ?? 'Unknown' }}</p>
                                        <p class="text-xs text-muted-foreground">{{ report.author?.email ?? 'N/A' }}</p>
                                        <span v-if="report.author?.is_banned" class="text-xs text-destructive">Author banned</span>
                                    </div>
                                </TableCell>
                                <TableCell class="space-y-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="gap-1"
                                            :disabled="bulkForm.processing || !report.report_ids?.length"
                                            @click="quickReportStatus(report, 'reviewed')"
                                        >
                                            <CheckCircle class="h-4 w-4" />
                                            Review
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="gap-1"
                                            :disabled="bulkForm.processing || !report.report_ids?.length"
                                            @click="quickReportStatus(report, 'dismissed')"
                                        >
                                            <XCircle class="h-4 w-4" />
                                            Dismiss
                                        </Button>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            class="gap-1"
                                            :disabled="bulkForm.processing || !report.report_ids?.length"
                                            @click="quickReportStatus(report, 'pending')"
                                        >
                                            <ShieldAlert class="h-4 w-4" />
                                            Pending
                                        </Button>
                                    </div>
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="report.comment?.can.update"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1"
                                            @click="openEditor(report.comment)">
                                            <Pencil class="h-4 w-4" />
                                            Edit comment
                                        </Button>
                                        <Button
                                            v-if="report.comment?.can.delete"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1 text-destructive"
                                            @click="requestDelete(report.comment)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </Button>
                                        <Button
                                            v-if="report.comment?.user && canBanUsers && !report.comment.user.is_banned"
                                            variant="ghost"
                                            size="sm"
                                            class="gap-1 text-destructive"
                                            @click="blockUser(report.comment)">
                                            <ShieldAlert class="h-4 w-4" />
                                            Block user
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="!hasReports">
                                <TableCell colspan="6" class="text-center text-sm text-muted-foreground">No reports found.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <div class="flex flex-col items-center justify-between gap-4 border-t px-4 py-3 sm:flex-row">
                        <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                        <Pagination
                            v-if="paginationMeta.total > 0"
                            v-slot="{ page, pageCount }"
                            v-model:page="paginationPage"
                            :items-per-page="Math.max(paginationMeta.per_page, 1)"
                            :total="paginationMeta.total"
                            :sibling-count="1"
                            show-edges
                        >
                            <div class="flex flex-col items-center gap-2 sm:flex-row sm:items-center sm:gap-3">
                                <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                                <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                    <PaginationFirst />
                                    <PaginationPrev />

                                    <template v-for="(item, index) in items" :key="index">
                                        <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                            <Button
                                                class="h-9 w-9 p-0"
                                                :variant="item.value === page ? 'default' : 'outline'"
                                            >
                                                {{ item.value }}
                                            </Button>
                                        </PaginationListItem>
                                        <PaginationEllipsis v-else :index="index" />
                                    </template>

                                    <PaginationNext />
                                    <PaginationLast />
                                </PaginationList>
                            </div>
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
                                    <option
                                        v-for="statusOption in props.commentStatuses"
                                        :key="statusOption"
                                        :value="statusOption"
                                    >
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
                                <Pencil class="h-4 w-4" />
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
