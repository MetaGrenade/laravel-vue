<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import InputError from '@/components/InputError.vue';
import { Textarea } from '@/components/ui/textarea';
import { toast } from 'vue-sonner';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { useDebounceFn } from '@vueuse/core';
import { ShieldCheck, FileDown } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Trust & Safety',
        href: '/acp/trust-safety',
    },
];

type PaginationLinks = {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
};

type UserSummary = {
    id: number;
    nickname: string;
    email: string;
};

type ExportItem = {
    id: number;
    status: string;
    format: string;
    file_path: string | null;
    failure_reason: string | null;
    created_at: string | null;
    completed_at: string | null;
    updated_at: string | null;
    user: UserSummary | null;
};

type ErasureItem = {
    id: number;
    status: string;
    created_at: string | null;
    processed_at: string | null;
    updated_at: string | null;
    user: UserSummary | null;
};

const props = defineProps<{
    exports: {
        data: ExportItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    erasureRequests: {
        data: ErasureItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters: {
        search: string | null;
        export_status: string | null;
        erasure_status: string | null;
        per_page: number;
    };
    counts: {
        exports: Record<string, number>;
        erasure: Record<string, number>;
    };
    statusOptions: {
        exports: string[];
        erasure: string[];
    };
}>();

const searchQuery = ref(props.filters.search ?? '');
const exportStatus = ref(props.filters.export_status ?? 'pending');
const erasureStatus = ref(props.filters.erasure_status ?? 'pending');
const perPage = ref(props.filters.per_page ?? 15);

const perPageOptions = [10, 15, 25, 50, 100];

const normalizedExportCounts = computed(() => {
    const counts: Record<string, number> = {};

    for (const status of props.statusOptions.exports) {
        counts[status] = Number(props.counts.exports?.[status] ?? 0);
    }

    return counts;
});

const normalizedErasureCounts = computed(() => {
    const counts: Record<string, number> = {};

    for (const status of props.statusOptions.erasure) {
        counts[status] = Number(props.counts.erasure?.[status] ?? 0);
    }

    return counts;
});

const exportPagination = useInertiaPagination({
    meta: computed(() => props.exports.meta ?? null),
    defaultPerPage: perPage,
    onNavigate: (pageNumber) => {
        applyFilters({ export_page: pageNumber });
    },
});

const erasurePagination = useInertiaPagination({
    meta: computed(() => props.erasureRequests.meta ?? null),
    defaultPerPage: perPage,
    onNavigate: (pageNumber) => {
        applyFilters({ erasure_page: pageNumber });
    },
});

const exportDialogOpen = ref(false);
const erasureDialogOpen = ref(false);

const activeExport = ref<ExportItem | null>(null);
const activeErasure = ref<ErasureItem | null>(null);

const exportForm = useForm({
    status: '',
    file_path: '',
    failure_reason: '',
    completed_at: '',
});

const erasureForm = useForm({
    status: '',
    processed_at: '',
});

const statusLabel = (status: string) => {
    switch (status) {
        case 'pending':
            return 'Pending';
        case 'processing':
            return 'Processing';
        case 'completed':
            return 'Completed';
        case 'failed':
            return 'Failed';
        case 'rejected':
            return 'Rejected';
        default:
            return status;
    }
};

const statusToneClass = (status: string) => {
    switch (status) {
        case 'completed':
            return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400';
        case 'failed':
        case 'rejected':
            return 'border-destructive/40 bg-destructive/10 text-destructive';
        case 'processing':
            return 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400';
        default:
            return 'border-yellow-500/30 bg-yellow-500/10 text-yellow-600 dark:text-yellow-400';
    }
};

const formatDateTime = (value: string | null) => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
};

const toDateTimeLocal = (value: string | null) => {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    const pad = (input: number) => `${input}`.padStart(2, '0');

    return [
        `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`,
        `${pad(date.getHours())}:${pad(date.getMinutes())}`,
    ].join('T');
};

const applyFilters = (overrides: Partial<{ export_page: number; erasure_page: number }> = {}) => {
    const query: Record<string, unknown> = {};
    const trimmedSearch = searchQuery.value.trim();

    if (trimmedSearch !== '') {
        query.search = trimmedSearch;
    }

    if (exportStatus.value === 'all') {
        query.export_status = 'all';
    } else if (exportStatus.value) {
        query.export_status = exportStatus.value;
    }

    if (erasureStatus.value === 'all') {
        query.erasure_status = 'all';
    } else if (erasureStatus.value) {
        query.erasure_status = erasureStatus.value;
    }

    query.per_page = perPage.value;

    const exportPageCurrent = overrides.export_page ?? exportPagination.meta.value.current_page ?? 1;
    const erasurePageCurrent = overrides.erasure_page ?? erasurePagination.meta.value.current_page ?? 1;

    if (exportPageCurrent > 1) {
        query.export_page = exportPageCurrent;
    }

    if (erasurePageCurrent > 1) {
        query.erasure_page = erasurePageCurrent;
    }

    router.get(route('acp.trust-safety.index'), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const debouncedSearch = useDebounceFn(() => {
    applyFilters({ export_page: 1, erasure_page: 1 });
}, 400);

watch(searchQuery, () => {
    debouncedSearch();
});

watch(
    () => props.filters.search,
    (value) => {
        const normalized = value ?? '';

        if (normalized !== searchQuery.value) {
            searchQuery.value = normalized;
        }
    },
);

watch(
    () => props.filters.export_status,
    (value) => {
        const normalized = value ?? 'pending';

        if (normalized !== exportStatus.value) {
            exportStatus.value = normalized;
        }
    },
);

watch(
    () => props.filters.erasure_status,
    (value) => {
        const normalized = value ?? 'pending';

        if (normalized !== erasureStatus.value) {
            erasureStatus.value = normalized;
        }
    },
);

watch(
    () => props.filters.per_page,
    (value) => {
        const normalized = Number(value ?? 15);

        if (normalized !== perPage.value) {
            perPage.value = normalized;
        }
    },
);

const onExportStatusChange = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    exportStatus.value = target.value;
    applyFilters({ export_page: 1 });
};

const onErasureStatusChange = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    erasureStatus.value = target.value;
    applyFilters({ erasure_page: 1 });
};

const onPerPageChange = (event: Event) => {
    const target = event.target as HTMLSelectElement;
    const parsed = Number.parseInt(target.value, 10);

    if (!Number.isNaN(parsed)) {
        perPage.value = parsed;
        applyFilters({ export_page: 1, erasure_page: 1 });
    }
};

const resetExportDialog = () => {
    exportDialogOpen.value = false;
    activeExport.value = null;
    exportForm.reset();
    exportForm.clearErrors();
};

const resetErasureDialog = () => {
    erasureDialogOpen.value = false;
    activeErasure.value = null;
    erasureForm.reset();
    erasureForm.clearErrors();
};

const handleExportDialogChange = (open: boolean) => {
    if (!open) {
        resetExportDialog();
    } else {
        exportDialogOpen.value = true;
    }
};

const handleErasureDialogChange = (open: boolean) => {
    if (!open) {
        resetErasureDialog();
    } else {
        erasureDialogOpen.value = true;
    }
};

const openExportDialog = (item: ExportItem) => {
    activeExport.value = item;
    exportForm.status = item.status;
    exportForm.file_path = item.file_path ?? '';
    exportForm.failure_reason = item.failure_reason ?? '';
    exportForm.completed_at = toDateTimeLocal(item.completed_at);
    exportDialogOpen.value = true;
};

const openErasureDialog = (item: ErasureItem) => {
    activeErasure.value = item;
    erasureForm.status = item.status;
    erasureForm.processed_at = toDateTimeLocal(item.processed_at);
    erasureDialogOpen.value = true;
};

const submitExportForm = () => {
    const target = activeExport.value;

    if (!target) {
        return;
    }

    exportForm
        .transform((data) => ({
            status: data.status,
            file_path: data.file_path?.trim() || null,
            failure_reason: data.failure_reason?.trim() || null,
            completed_at: data.completed_at ? data.completed_at : null,
        }))
        .patch(route('acp.trust-safety.exports.update', { export: target.id }), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Data export updated successfully.');
                resetExportDialog();
            },
            onError: () => {
                toast.error('Unable to update the export. Please review the form and try again.');
            },
        });
};

const submitErasureForm = () => {
    const target = activeErasure.value;

    if (!target) {
        return;
    }

    erasureForm
        .transform((data) => ({
            status: data.status,
            processed_at: data.processed_at ? data.processed_at : null,
        }))
        .patch(route('acp.trust-safety.erasure.update', { erasureRequest: target.id }), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Data erasure request updated successfully.');
                resetErasureDialog();
            },
            onError: () => {
                toast.error('Unable to update the erasure request. Please review the form and try again.');
            },
        });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Trust &amp; Safety Console" />

        <AdminLayout>
            <div class="flex w-full flex-1 flex-col gap-6 pb-6">
                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-lg border bg-card p-4 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Data export queue</p>
                                <p class="mt-2 text-3xl font-semibold text-foreground">
                                    {{ normalizedExportCounts.pending + normalizedExportCounts.processing }}
                                </p>
                            </div>
                            <div class="rounded-full bg-emerald-500/10 p-2 text-emerald-600 dark:text-emerald-400">
                                <FileDown class="h-5 w-5" />
                            </div>
                        </div>
                        <dl class="mt-4 space-y-2 text-sm text-muted-foreground">
                            <div class="flex items-center justify-between">
                                <dt>Pending</dt>
                                <dd>{{ normalizedExportCounts.pending ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Processing</dt>
                                <dd>{{ normalizedExportCounts.processing ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Completed</dt>
                                <dd>{{ normalizedExportCounts.completed ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Failed</dt>
                                <dd>{{ normalizedExportCounts.failed ?? 0 }}</dd>
                            </div>
                        </dl>
                    </div>

                    <div class="rounded-lg border bg-card p-4 shadow-sm">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Erasure requests</p>
                                <p class="mt-2 text-3xl font-semibold text-foreground">
                                    {{ normalizedErasureCounts.pending + normalizedErasureCounts.processing }}
                                </p>
                            </div>
                            <div class="rounded-full bg-sky-500/10 p-2 text-sky-600 dark:text-sky-400">
                                <ShieldCheck class="h-5 w-5" />
                            </div>
                        </div>
                        <dl class="mt-4 space-y-2 text-sm text-muted-foreground">
                            <div class="flex items-center justify-between">
                                <dt>Pending</dt>
                                <dd>{{ normalizedErasureCounts.pending ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Processing</dt>
                                <dd>{{ normalizedErasureCounts.processing ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Completed</dt>
                                <dd>{{ normalizedErasureCounts.completed ?? 0 }}</dd>
                            </div>
                            <div class="flex items-center justify-between">
                                <dt>Rejected</dt>
                                <dd>{{ normalizedErasureCounts.rejected ?? 0 }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <div class="rounded-lg border bg-card p-4 shadow-sm">
                    <div class="grid gap-4 md:grid-cols-[2fr_1fr_1fr_1fr] md:items-end">
                        <div>
                            <label for="trust-safety-search" class="text-sm font-medium text-muted-foreground">Search</label>
                            <Input
                                id="trust-safety-search"
                                v-model="searchQuery"
                                type="search"
                                class="mt-1"
                                placeholder="Search by email, nickname, or request ID"
                            />
                        </div>

                        <div>
                            <label for="export-status" class="text-sm font-medium text-muted-foreground">Export status</label>
                            <select id="export-status" :value="exportStatus" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm mt-1" @change="onExportStatusChange">
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="failed">Failed</option>
                                <option value="all">All statuses</option>
                            </select>
                        </div>

                        <div>
                            <label for="erasure-status" class="text-sm font-medium text-muted-foreground">Erasure status</label>
                            <select id="erasure-status" :value="erasureStatus" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm mt-1" @change="onErasureStatusChange">
                                <option value="pending">Pending</option>
                                <option value="processing">Processing</option>
                                <option value="completed">Completed</option>
                                <option value="rejected">Rejected</option>
                                <option value="all">All statuses</option>
                            </select>
                        </div>

                        <div>
                            <label for="per-page" class="text-sm font-medium text-muted-foreground">Rows per page</label>
                            <select id="per-page" :value="perPage" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm mt-1" @change="onPerPageChange">
                                <option v-for="option in perPageOptions" :key="option" :value="option">
                                    {{ option }} per page
                                </option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="space-y-6">
                    <section class="rounded-lg border bg-card shadow-sm">
                        <header class="flex flex-col gap-2 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-foreground">Erasure requests</h2>
                                <p class="text-sm text-muted-foreground">
                                    Track account deletion requests and document their processing.
                                </p>
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ erasurePagination.rangeLabel }}
                            </p>
                        </header>

                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="min-w-[160px]">User</TableHead>
                                        <TableHead class="min-w-[120px]">Status</TableHead>
                                        <TableHead class="min-w-[160px]">Requested</TableHead>
                                        <TableHead class="min-w-[160px]">Processed</TableHead>
                                        <TableHead class="min-w-[200px]">Notes</TableHead>
                                        <TableHead class="min-w-[100px] text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="props.erasureRequests.data.length === 0">
                                        <TableCell colspan="6" class="py-6 text-center text-sm text-muted-foreground">
                                            No erasure requests found.
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-for="item in props.erasureRequests.data" :key="item.id">
                                        <TableCell>
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium text-foreground">
                                                    <Link
                                                        v-if="item.user"
                                                        :href="route('acp.users.edit', { user: item.user.id })"
                                                        class="hover:underline"
                                                    >
                                                        {{ item.user.nickname }}
                                                    </Link>
                                                    <span v-else>Unknown user</span>
                                                </span>
                                                <span class="text-xs text-muted-foreground">
                                                    {{ item.user?.email ?? '—' }}
                                                </span>
                                                <span class="text-xs text-muted-foreground">Request #{{ item.id }}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="statusToneClass(item.status)">
                                                {{ statusLabel(item.status) }}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <span class="text-sm text-foreground">{{ formatDateTime(item.created_at) }}</span>
                                        </TableCell>
                                        <TableCell>
                                            <span class="text-sm text-foreground">{{ formatDateTime(item.processed_at) }}</span>
                                        </TableCell>
                                        <TableCell>
                                            <p class="text-xs text-muted-foreground">
                                                Last updated {{ formatDateTime(item.updated_at) }}
                                            </p>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <Button size="sm" variant="outline" @click="openErasureDialog(item)">
                                                Manage
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <footer class="flex flex-col items-start justify-between gap-4 border-t px-4 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center">
                            <span>{{ erasurePagination.rangeLabel }}</span>
                            <div class="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="erasurePagination.meta.current_page <= 1"
                                    @click="erasurePagination.setPage(erasurePagination.meta.current_page - 1)"
                                >
                                    Previous
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="erasurePagination.meta.current_page >= erasurePagination.pageCount"
                                    @click="erasurePagination.setPage(erasurePagination.meta.current_page + 1)"
                                >
                                    Next
                                </Button>
                            </div>
                        </footer>
                    </section>

                    <section class="rounded-lg border bg-card shadow-sm">
                        <header class="flex flex-col gap-2 border-b px-4 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-foreground">Data export requests</h2>
                                <p class="text-sm text-muted-foreground">
                                    Review user export requests and update their fulfillment status.
                                </p>
                            </div>
                            <p class="text-sm text-muted-foreground">
                                {{ exportPagination.rangeLabel }}
                            </p>
                        </header>

                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="min-w-[160px]">User</TableHead>
                                        <TableHead class="min-w-[120px]">Status</TableHead>
                                        <TableHead class="min-w-[160px]">Requested</TableHead>
                                        <TableHead class="min-w-[160px]">Completed</TableHead>
                                        <TableHead class="min-w-[200px]">File path / Failure reason</TableHead>
                                        <TableHead class="min-w-[100px] text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="props.exports.data.length === 0">
                                        <TableCell colspan="6" class="py-6 text-center text-sm text-muted-foreground">
                                            No data export requests found.
                                        </TableCell>
                                    </TableRow>
                                    <TableRow v-for="item in props.exports.data" :key="item.id">
                                        <TableCell>
                                            <div class="flex flex-col">
                                                <span class="text-sm font-medium text-foreground">
                                                    <Link
                                                        v-if="item.user"
                                                        :href="route('acp.users.edit', { user: item.user.id })"
                                                        class="hover:underline"
                                                    >
                                                        {{ item.user.nickname }}
                                                    </Link>
                                                    <span v-else>Unknown user</span>
                                                </span>
                                                <span class="text-xs text-muted-foreground">
                                                    {{ item.user?.email ?? '—' }}
                                                </span>
                                                <span class="text-xs text-muted-foreground">Request #{{ item.id }}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium" :class="statusToneClass(item.status)">
                                                {{ statusLabel(item.status) }}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <span class="text-sm text-foreground">{{ formatDateTime(item.created_at) }}</span>
                                        </TableCell>
                                        <TableCell>
                                            <span class="text-sm text-foreground">{{ formatDateTime(item.completed_at) }}</span>
                                        </TableCell>
                                        <TableCell>
                                            <div class="space-y-1 text-xs text-muted-foreground">
                                                <p v-if="item.file_path" class="font-medium text-foreground">Path: {{ item.file_path }}</p>
                                                <p v-if="item.failure_reason">Failure: {{ item.failure_reason }}</p>
                                                <p v-if="!item.file_path && !item.failure_reason" class="italic">No additional details</p>
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <Button size="sm" variant="outline" @click="openExportDialog(item)">
                                                Manage
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <footer class="flex flex-col items-start justify-between gap-4 border-t px-4 py-4 text-sm text-muted-foreground sm:flex-row sm:items-center">
                            <span>{{ exportPagination.rangeLabel }}</span>
                            <div class="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="exportPagination.meta.current_page <= 1"
                                    @click="exportPagination.setPage(exportPagination.meta.current_page - 1)"
                                >
                                    Previous
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    :disabled="exportPagination.meta.current_page >= exportPagination.pageCount"
                                    @click="exportPagination.setPage(exportPagination.meta.current_page + 1)"
                                >
                                    Next
                                </Button>
                            </div>
                        </footer>
                    </section>
                </div>
            </div>
        </AdminLayout>

        <Dialog :open="exportDialogOpen" @update:open="handleExportDialogChange">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Update data export</DialogTitle>
                    <DialogDescription>
                        Adjust the status and delivery details for this export request.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4">
                    <div>
                        <label for="export-status-field" class="text-sm font-medium text-muted-foreground">Status</label>
                        <select
                            id="export-status-field"
                            v-model="exportForm.status"
                            class="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="completed">Completed</option>
                            <option value="failed">Failed</option>
                        </select>
                        <InputError :message="exportForm.errors.status" class="mt-1" />
                    </div>

                    <div>
                        <label for="export-file-path" class="text-sm font-medium text-muted-foreground">File path</label>
                        <Input
                            id="export-file-path"
                            v-model="exportForm.file_path"
                            type="text"
                            class="mt-1"
                            placeholder="storage/app/exports/filename.zip"
                        />
                        <InputError :message="exportForm.errors.file_path" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">
                            Required when marking an export as completed.
                        </p>
                    </div>

                    <div>
                        <label for="export-completed-at" class="text-sm font-medium text-muted-foreground">Completed at</label>
                        <Input
                            id="export-completed-at"
                            v-model="exportForm.completed_at"
                            type="datetime-local"
                            class="mt-1"
                        />
                        <InputError :message="exportForm.errors.completed_at" class="mt-1" />
                    </div>

                    <div>
                        <label for="export-failure" class="text-sm font-medium text-muted-foreground">Failure reason</label>
                        <Textarea
                            id="export-failure"
                            v-model="exportForm.failure_reason"
                            rows="3"
                            class="mt-1"
                            placeholder="Add diagnostic notes if the export failed."
                        />
                        <InputError :message="exportForm.errors.failure_reason" class="mt-1" />
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="resetExportDialog">Cancel</Button>
                    <Button type="button" :disabled="exportForm.processing" @click="submitExportForm">
                        <span v-if="exportForm.processing">Saving…</span>
                        <span v-else>Save changes</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="erasureDialogOpen" @update:open="handleErasureDialogChange">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Update erasure request</DialogTitle>
                    <DialogDescription>
                        Record the processing outcome for this account deletion request.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-4">
                    <div>
                        <label for="erasure-status-field" class="text-sm font-medium text-muted-foreground">Status</label>
                        <select
                            id="erasure-status-field"
                            v-model="erasureForm.status"
                            class="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="pending">Pending</option>
                            <option value="processing">Processing</option>
                            <option value="completed">Completed</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <InputError :message="erasureForm.errors.status" class="mt-1" />
                    </div>

                    <div>
                        <label for="erasure-processed-at" class="text-sm font-medium text-muted-foreground">Processed at</label>
                        <Input
                            id="erasure-processed-at"
                            v-model="erasureForm.processed_at"
                            type="datetime-local"
                            class="mt-1"
                        />
                        <InputError :message="erasureForm.errors.processed_at" class="mt-1" />
                        <p class="mt-1 text-xs text-muted-foreground">
                            Automatically set to the current time when marking a request as completed or rejected.
                        </p>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="ghost" @click="resetErasureDialog">Cancel</Button>
                    <Button type="button" :disabled="erasureForm.processing" @click="submitErasureForm">
                        <span v-if="erasureForm.processing">Saving…</span>
                        <span v-else>Save changes</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
