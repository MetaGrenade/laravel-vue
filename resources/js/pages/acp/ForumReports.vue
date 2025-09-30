<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Ellipsis, ShieldAlert, ShieldCheck, ShieldX } from 'lucide-vue-next';

const props = defineProps<{
    reports: {
        data: Array<{
            id: number;
            type: 'thread' | 'post';
            status: 'pending' | 'reviewed' | 'dismissed';
            reason_category: string | null;
            reason: string | null;
            evidence_url: string | null;
            created_at: string | null;
            reviewed_at: string | null;
            reporter: {
                id: number;
                nickname: string;
                email: string;
            } | null;
            reviewer: {
                id: number;
                nickname: string;
                email: string;
            } | null;
            thread: {
                id: number;
                title: string;
                slug: string;
                is_locked: boolean;
                is_published: boolean;
                board: {
                    id: number;
                    title: string;
                    slug: string;
                } | null;
            } | null;
            post?: {
                id: number;
                body_preview: string | null;
                author: {
                    id: number;
                    nickname: string;
                    email: string;
                } | null;
            } | null;
        }>;
        meta?: PaginationMeta | null;
        links?: {
            first: string | null;
            last: string | null;
            prev: string | null;
            next: string | null;
        } | null;
    };
    filters: {
        type: string | null;
        status: string | null;
        reason_category: string | null;
        board_id: number | null;
        search: string | null;
        per_page: number | null;
    };
    reportReasons: Array<{ value: string; label: string }>;
    boards: Array<{ id: number; title: string; slug: string }>;
    statusSummary: Record<string, { threads: number; posts: number; total: number }>;
}>();

type Report = (typeof props.reports.data)[number];

type ModerationStatus = 'reviewed' | 'dismissed';

type ModerationAction =
    | 'none'
    | 'lock_thread'
    | 'unlock_thread'
    | 'unpublish_thread'
    | 'republish_thread'
    | 'delete_post';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin Control Panel', href: '/acp/dashboard' },
    { title: 'Forum Reports', href: '/acp/forums/reports' },
];

const quickVisitOptions = {
    preserveScroll: true,
    preserveState: true,
    replace: true,
} as const;

const filterState = reactive({
    type: 'all',
    status: 'pending',
    reason_category: '',
    board_id: '',
    search: '',
    per_page: String(props.filters.per_page ?? 25),
});

watch(
    () => props.filters,
    (filters) => {
        filterState.type = filters.type ?? 'all';
        filterState.status = filters.status ?? 'pending';
        filterState.reason_category = filters.reason_category ?? '';
        filterState.board_id = filters.board_id ? String(filters.board_id) : '';
        filterState.search = filters.search ?? '';
        filterState.per_page = String(filters.per_page ?? 25);
    },
    { immediate: true },
);

const { fromNow } = useUserTimezone();

const reasonLookup = computed<Record<string, string>>(() => {
    const lookup: Record<string, string> = {};

    for (const reason of props.reportReasons) {
        lookup[reason.value] = reason.label;
    }

    return lookup;
});

const statusLabels: Record<Report['status'], string> = {
    pending: 'Pending review',
    reviewed: 'Reviewed',
    dismissed: 'Dismissed',
};

const typeLabels: Record<Report['type'], string> = {
    thread: 'Thread',
    post: 'Post',
};

const statusIcons: Record<Report['status'], typeof ShieldAlert> = {
    pending: ShieldAlert,
    reviewed: ShieldCheck,
    dismissed: ShieldX,
};

const statusSummary = computed(() => props.statusSummary ?? {});

const pendingTotals = computed(() => statusSummary.value['pending'] ?? { threads: 0, posts: 0, total: 0 });
const reviewedTotals = computed(() => statusSummary.value['reviewed'] ?? { threads: 0, posts: 0, total: 0 });
const dismissedTotals = computed(() => statusSummary.value['dismissed'] ?? { threads: 0, posts: 0, total: 0 });

const buildQuery = (overrides: Record<string, unknown> = {}) => {
    const query: Record<string, unknown> = {
        type: filterState.type,
        status: filterState.status,
        per_page: Number.parseInt(filterState.per_page, 10) || undefined,
        ...overrides,
    };

    const reasonCategory = filterState.reason_category.trim();
    if (reasonCategory !== '') {
        query.reason_category = reasonCategory;
    }

    const boardId = filterState.board_id.trim();
    if (boardId !== '') {
        const parsed = Number.parseInt(boardId, 10);
        if (!Number.isNaN(parsed)) {
            query.board_id = parsed;
        }
    }

    const search = filterState.search.trim();
    if (search !== '') {
        query.search = search;
    }

    return query;
};

const applyFilters = (overrides: Record<string, unknown> = {}) => {
    router.get(
        route('acp.forums.reports.index'),
        buildQuery({ page: 1, ...overrides }),
        quickVisitOptions,
    );
};

const clearFilters = () => {
    filterState.type = 'all';
    filterState.status = 'pending';
    filterState.reason_category = '';
    filterState.board_id = '';
    filterState.search = '';
    filterState.per_page = '25';
    applyFilters();
};

const {
    meta: reportsMeta,
    rangeLabel: reportsRangeLabel,
    setPage: setReportsPage,
} = useInertiaPagination({
    meta: computed(() => props.reports.meta ?? null),
    itemsLength: computed(() => props.reports.data?.length ?? 0),
    defaultPerPage: props.filters.per_page ?? 25,
    itemLabel: 'report',
    itemLabelPlural: 'reports',
    onNavigate: (page) => {
        router.get(
            route('acp.forums.reports.index'),
            buildQuery({ page }),
            quickVisitOptions,
        );
    },
});

const moderationDialogOpen = ref(false);
const moderationTarget = ref<Report | null>(null);
const moderationStatus = ref<ModerationStatus>('reviewed');
const moderationAction = ref<ModerationAction>('none');

const closeModerationDialog = () => {
    moderationDialogOpen.value = false;
    moderationTarget.value = null;
    moderationAction.value = 'none';
};

const openModerationDialog = (report: Report, status: ModerationStatus) => {
    moderationTarget.value = report;
    moderationStatus.value = status;
    moderationAction.value = 'none';
    moderationDialogOpen.value = true;
};

const moderationOptions = computed(() => {
    if (!moderationTarget.value) {
        return [] as Array<{ value: ModerationAction; label: string }>;
    }

    if (moderationTarget.value.type === 'thread') {
        return [
            { value: 'none', label: 'No additional action' },
            { value: 'lock_thread', label: 'Lock thread' },
            { value: 'unlock_thread', label: 'Unlock thread' },
            { value: 'unpublish_thread', label: 'Unpublish thread' },
            { value: 'republish_thread', label: 'Republish thread' },
        ];
    }

    return [
        { value: 'none', label: 'No additional action' },
        { value: 'delete_post', label: 'Delete post' },
    ];
});

const submitModeration = () => {
    if (!moderationTarget.value) {
        return;
    }

    const report = moderationTarget.value;
    const routeName = report.type === 'thread'
        ? 'acp.forums.reports.threads.update'
        : 'acp.forums.reports.posts.update';

    const payload: Record<string, unknown> = {
        status: moderationStatus.value,
    };

    if (moderationAction.value !== 'none') {
        payload.moderation_action = moderationAction.value;
    }

    router.patch(route(routeName, { report: report.id }), payload, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => closeModerationDialog(),
        onFinish: () => {
            moderationAction.value = 'none';
        },
    });
};

const statusOptions = computed(() => [
    { value: 'pending', label: `Pending (${pendingTotals.value.total})` },
    { value: 'reviewed', label: `Reviewed (${reviewedTotals.value.total})` },
    { value: 'dismissed', label: `Dismissed (${dismissedTotals.value.total})` },
    { value: 'all', label: 'All statuses' },
]);

const typeOptions = computed(() => [
    { value: 'all', label: 'All content' },
    { value: 'thread', label: `Threads (${pendingTotals.value.threads})` },
    { value: 'post', label: `Posts (${pendingTotals.value.posts})` },
]);

const hasReports = computed(() => (props.reports.data?.length ?? 0) > 0);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forum Reports" />

        <AdminLayout>
            <div class="flex w-full flex-1 flex-col gap-6 pb-6">
                <section class="rounded-xl border bg-background p-6 shadow-sm">
                    <h1 class="text-2xl font-semibold">Forum moderation queue</h1>
                    <p class="mt-2 max-w-3xl text-sm text-muted-foreground">
                        Review reported threads and posts. Apply moderation actions as needed and mark reports once addressed.
                    </p>

                    <div class="mt-6 grid gap-4 md:grid-cols-3">
                        <div class="rounded-lg border bg-muted/20 p-4">
                            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <ShieldAlert class="h-4 w-4" /> Pending
                            </div>
                            <div class="mt-2 text-2xl font-semibold">{{ pendingTotals.total }}</div>
                            <p class="mt-1 text-xs text-muted-foreground">{{ pendingTotals.threads }} threads · {{ pendingTotals.posts }} posts</p>
                        </div>
                        <div class="rounded-lg border bg-muted/20 p-4">
                            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <ShieldCheck class="h-4 w-4" /> Reviewed
                            </div>
                            <div class="mt-2 text-2xl font-semibold">{{ reviewedTotals.total }}</div>
                            <p class="mt-1 text-xs text-muted-foreground">{{ reviewedTotals.threads }} threads · {{ reviewedTotals.posts }} posts</p>
                        </div>
                        <div class="rounded-lg border bg-muted/20 p-4">
                            <div class="flex items-center gap-2 text-sm font-medium text-muted-foreground">
                                <ShieldX class="h-4 w-4" /> Dismissed
                            </div>
                            <div class="mt-2 text-2xl font-semibold">{{ dismissedTotals.total }}</div>
                            <p class="mt-1 text-xs text-muted-foreground">{{ dismissedTotals.threads }} threads · {{ dismissedTotals.posts }} posts</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-xl border bg-background p-6 shadow-sm">
                    <div class="flex flex-col gap-6">
                        <form class="grid gap-4 md:grid-cols-5" @submit.prevent="applyFilters">
                            <div class="grid gap-2">
                                <Label for="filter-type">Content</Label>
                                <select
                                    id="filter-type"
                                    v-model="filterState.type"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="option in typeOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="grid gap-2">
                                <Label for="filter-status">Status</Label>
                                <select
                                    id="filter-status"
                                    v-model="filterState.status"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="grid gap-2">
                                <Label for="filter-reason">Reason</Label>
                                <select
                                    id="filter-reason"
                                    v-model="filterState.reason_category"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="">All reasons</option>
                                    <option v-for="reason in props.reportReasons" :key="reason.value" :value="reason.value">
                                        {{ reason.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="grid gap-2">
                                <Label for="filter-board">Board</Label>
                                <select
                                    id="filter-board"
                                    v-model="filterState.board_id"
                                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option value="">All boards</option>
                                    <option v-for="board in props.boards" :key="board.id" :value="String(board.id)">
                                        {{ board.title }}
                                    </option>
                                </select>
                            </div>

                            <div class="grid gap-2">
                                <Label for="filter-search">Search</Label>
                                <Input
                                    id="filter-search"
                                    v-model="filterState.search"
                                    type="search"
                                    placeholder="Thread title keywords"
                                    class="h-10"
                                />
                            </div>

                            <div class="flex items-end gap-2 md:col-span-5">
                                <Button type="submit" class="md:w-auto">Apply filters</Button>
                                <Button type="button" variant="ghost" class="md:w-auto" @click="clearFilters">Reset</Button>
                                <div class="ml-auto grid gap-1 text-sm text-muted-foreground">
                                    <Label for="filter-per-page" class="text-xs">Per page</Label>
                                    <select
                                        id="filter-per-page"
                                        v-model="filterState.per_page"
                                        @change="applyFilters({ per_page: Number.parseInt(filterState.per_page, 10) || 25 })"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option value="15">15</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </div>
                            </div>
                        </form>

                        <div v-if="hasReports" class="space-y-4">
                            <div class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="w-32">Type</TableHead>
                                            <TableHead>Content</TableHead>
                                            <TableHead class="w-40">Reason</TableHead>
                                            <TableHead class="w-48">Reporter</TableHead>
                                            <TableHead class="w-16 text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="report in props.reports.data" :key="`${report.type}-${report.id}`" class="align-top">
                                            <TableCell class="font-medium">
                                                <div class="flex items-center gap-2">
                                                    <component :is="statusIcons[report.status]" class="h-4 w-4" />
                                                    <div class="space-y-1">
                                                        <div>{{ typeLabels[report.type] }}</div>
                                                        <div class="text-xs text-muted-foreground">{{ statusLabels[report.status] }}</div>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div class="space-y-2">
                                                    <div v-if="report.thread" class="space-y-1">
                                                        <div class="font-semibold">
                                                            <Link
                                                                v-if="report.thread.board?.slug"
                                                                :href="route('forum.threads.show', {
                                                                    board: report.thread.board.slug,
                                                                    thread: report.thread.slug,
                                                                })"
                                                                class="hover:underline"
                                                            >
                                                                {{ report.thread.title }}
                                                            </Link>
                                                            <span v-else>{{ report.thread.title }}</span>
                                                        </div>
                                                        <div class="text-xs text-muted-foreground">
                                                            <span v-if="report.thread.board">{{ report.thread.board.title }}</span>
                                                            <span v-else>Board unavailable</span>
                                                            ·
                                                            <span v-if="!report.thread.is_published" class="text-amber-600">Unpublished</span>
                                                            <span v-else-if="report.thread.is_locked" class="text-amber-600">Locked</span>
                                                        </div>
                                                    </div>
                                                    <div v-else class="text-sm text-muted-foreground">Thread no longer available</div>

                                                    <div v-if="report.type === 'post'" class="rounded-md border bg-muted/40 p-3 text-sm">
                                                        <p class="font-medium text-muted-foreground">Post excerpt</p>
                                                        <p class="mt-1 whitespace-pre-wrap text-muted-foreground">
                                                            {{ report.post?.body_preview ?? 'Post deleted' }}
                                                        </p>
                                                    </div>

                                                    <div v-if="report.reason" class="rounded-md border border-dashed border-muted bg-muted/20 p-2 text-xs text-muted-foreground">
                                                        "{{ report.reason }}"
                                                    </div>

                                                    <div v-if="report.evidence_url" class="text-xs">
                                                        <a :href="report.evidence_url" class="text-primary hover:underline" target="_blank" rel="noopener">Evidence link</a>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div class="space-y-1 text-sm">
                                                    <div class="font-medium">
                                                        {{ reasonLookup[report.reason_category ?? ''] ?? 'Not specified' }}
                                                    </div>
                                                    <div v-if="report.reason_category" class="text-xs text-muted-foreground">
                                                        {{ report.reason_category }}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div class="space-y-1 text-sm">
                                                    <div v-if="report.reporter">
                                                        {{ report.reporter.nickname }}
                                                    </div>
                                                    <div v-else class="text-muted-foreground">Reporter removed</div>
                                                    <div v-if="report.reporter?.email" class="text-xs text-muted-foreground">
                                                        {{ report.reporter.email }}
                                                    </div>
                                                    <div v-if="report.created_at" class="text-xs text-muted-foreground">
                                                        Submitted {{ fromNow(report.created_at) }}
                                                    </div>
                                                    <div v-else class="text-xs text-muted-foreground">Submitted date unknown</div>
                                                    <div v-if="report.reviewed_at" class="text-xs text-muted-foreground">
                                                        Updated {{ fromNow(report.reviewed_at) }}
                                                    </div>
                                                    <div v-if="report.reviewer" class="text-xs text-muted-foreground">
                                                        Reviewed by {{ report.reviewer.nickname }}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell class="text-right">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger as-child>
                                                        <Button size="icon" variant="outline">
                                                            <Ellipsis class="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuLabel>Report actions</DropdownMenuLabel>
                                                        <DropdownMenuItem @select="openModerationDialog(report, 'reviewed')">
                                                            <ShieldCheck class="h-4 w-4" />
                                                            <span>Mark reviewed</span>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem @select="openModerationDialog(report, 'dismissed')">
                                                            <ShieldX class="h-4 w-4" />
                                                            <span>Dismiss</span>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>

                            <div class="flex flex-col items-start gap-2 md:flex-row md:items-center md:justify-between">
                                <p class="text-sm text-muted-foreground">{{ reportsRangeLabel }}</p>

                                <Pagination v-if="reportsMeta.total > reportsMeta.per_page" class="w-full justify-end md:w-auto">
                                    <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                        <PaginationFirst
                                            :href="props.reports.links?.first ?? undefined"
                                            :disabled="reportsMeta.current_page === 1"
                                            @click.prevent="setReportsPage(1)"
                                        />
                                        <PaginationPrev
                                            :href="props.reports.links?.prev ?? undefined"
                                            :disabled="reportsMeta.current_page === 1"
                                            @click.prevent="setReportsPage(Math.max(reportsMeta.current_page - 1, 1))"
                                        />
                                        <template v-for="(item, index) in items" :key="index">
                                            <PaginationListItem
                                                v-if="item.type === 'page'"
                                                :value="item.value"
                                                :is-active="item.value === reportsMeta.current_page"
                                                @click="setReportsPage(item.value)"
                                            >
                                                {{ item.value }}
                                            </PaginationListItem>
                                            <PaginationEllipsis v-else />
                                        </template>
                                        <PaginationNext
                                            :href="props.reports.links?.next ?? undefined"
                                            :disabled="reportsMeta.current_page >= reportsMeta.last_page"
                                            @click.prevent="setReportsPage(Math.min(reportsMeta.current_page + 1, reportsMeta.last_page))"
                                        />
                                        <PaginationLast
                                            :href="props.reports.links?.last ?? undefined"
                                            :disabled="reportsMeta.current_page >= reportsMeta.last_page"
                                            @click.prevent="setReportsPage(reportsMeta.last_page)"
                                        />
                                    </PaginationList>
                                </Pagination>
                            </div>
                        </div>

                        <div v-else class="rounded-lg border border-dashed bg-muted/30">
                            <PlaceholderPattern class="rounded-lg">
                                <div class="flex flex-col items-center justify-center gap-4 px-12 py-16 text-center">
                                    <ShieldCheck class="h-12 w-12 text-muted-foreground" />
                                    <div class="space-y-2">
                                        <h2 class="text-lg font-semibold">No reports match your filters</h2>
                                        <p class="text-sm text-muted-foreground">
                                            Adjust the filters above or check back later to review new forum reports.
                                        </p>
                                    </div>
                                    <Button variant="outline" @click="clearFilters">Reset filters</Button>
                                </div>
                            </PlaceholderPattern>
                        </div>
                    </div>
                </section>
            </div>
        </AdminLayout>

        <Dialog :open="moderationDialogOpen" @update:open="(open) => (open ? null : closeModerationDialog())">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        {{ moderationStatus === 'reviewed' ? 'Mark report as reviewed' : 'Dismiss report' }}
                    </DialogTitle>
                    <DialogDescription>
                        Select an optional moderation action to apply before updating the report status.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4">
                    <div>
                        <Label for="moderation-action">Moderation action</Label>
                        <select
                            id="moderation-action"
                            v-model="moderationAction"
                            class="mt-1 flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option v-for="option in moderationOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </div>

                    <div class="rounded-md border bg-muted/30 p-3 text-sm text-muted-foreground">
                        <p v-if="moderationTarget?.thread" class="font-medium">{{ moderationTarget.thread.title }}</p>
                        <p v-else>Associated content is no longer available.</p>
                    </div>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="closeModerationDialog">Cancel</Button>
                    <Button type="button" @click="submitModeration">
                        {{ moderationStatus === 'reviewed' ? 'Mark as reviewed' : 'Dismiss report' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
