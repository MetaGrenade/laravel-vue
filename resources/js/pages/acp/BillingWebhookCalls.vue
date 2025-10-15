<script setup lang="ts">
import { computed, reactive, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import type { BreadcrumbItem } from '@/types';

interface WebhookUser {
    id: number;
    nickname: string;
    email: string;
}

interface WebhookCall {
    id: number;
    stripe_id: string | null;
    type: string;
    user: WebhookUser | null;
    processed_at: string | null;
    created_at: string | null;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

interface FiltersPayload {
    search?: string | null;
    type?: string | null;
    processed?: string | null;
    per_page?: number | null;
}

interface Props {
    calls: {
        data: WebhookCall[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters?: FiltersPayload | null;
    availableTypes: string[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing webhooks', href: route('acp.billing.webhooks.index') },
];

const calls = computed(() => props.calls.data ?? []);
const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const filterState = reactive({
    search: props.filters?.search ?? '',
    type: props.filters?.type ?? '',
    processed: props.filters?.processed ?? '',
    per_page: props.filters?.per_page ?? (props.calls.meta?.per_page ?? 25),
});

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters?.search ?? '';
        filterState.type = filters?.type ?? '';
        filterState.processed = filters?.processed ?? '';

        if (typeof filters?.per_page === 'number' && filters.per_page > 0) {
            filterState.per_page = filters.per_page;
        }
    },
    { deep: true },
);

watch(
    () => props.calls.meta?.per_page,
    (perPage) => {
        if (typeof perPage === 'number' && perPage > 0) {
            filterState.per_page = perPage;
        }
    },
);

const typeOptions = computed(() => {
    const types = new Set<string>(props.availableTypes ?? []);

    if (filterState.type) {
        types.add(filterState.type);
    }

    return Array.from(types).sort((a, b) => a.localeCompare(b));
});

const { page, setPage, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.calls.meta ?? null),
    itemsLength: computed(() => calls.value.length),
    defaultPerPage: filterState.per_page || 25,
    itemLabel: 'webhook call',
    itemLabelPlural: 'webhook calls',
    onNavigate: (newPage) => {
        router.get(
            route('acp.billing.webhooks.index'),
            buildQuery({ page: newPage }),
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});

function cleanQuery(query: Record<string, unknown>) {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => {
            if (value === null || value === undefined) {
                return false;
            }

            if (typeof value === 'string') {
                return value.trim() !== '';
            }

            return true;
        }),
    );
}

function buildQuery(overrides: Record<string, unknown> = {}) {
    return cleanQuery({
        page: page.value,
        per_page: filterState.per_page,
        search: filterState.search,
        type: filterState.type,
        processed: filterState.processed,
        ...overrides,
    });
}

const applyFilters = () => {
    setPage(1, { emitNavigate: false });

    router.get(
        route('acp.billing.webhooks.index'),
        buildQuery({ page: 1 }),
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
};

const resetFilters = () => {
    filterState.search = '';
    filterState.type = '';
    filterState.processed = '';
    filterState.per_page = 25;

    applyFilters();
};

const formatDateTime = (value: string | null) => {
    if (! value) {
        return '—';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Billing webhooks" />

        <AdminLayout>
            <section class="flex w-full flex-col space-y-6">
                <HeadingSmall
                    title="Stripe webhook archive"
                    description="Audit historical webhook deliveries and reprocess payloads when billing gets out of sync."
                />

                <form
                    class="grid gap-3 rounded-lg border border-border bg-card p-4 shadow-sm md:grid-cols-4 md:items-end"
                    @submit.prevent="applyFilters"
                >
                    <div class="flex flex-col gap-2">
                        <label for="search" class="text-sm font-medium text-foreground">Search</label>
                        <Input
                            id="search"
                            v-model="filterState.search"
                            type="search"
                            placeholder="Search by Stripe ID or type"
                        />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="type" class="text-sm font-medium text-foreground">Event type</label>
                        <select
                            id="type"
                            v-model="filterState.type"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        >
                            <option value="">All types</option>
                            <option v-for="type in typeOptions" :key="type" :value="type">{{ type }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="processed" class="text-sm font-medium text-foreground">Processing state</label>
                        <select
                            id="processed"
                            v-model="filterState.processed"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        >
                            <option value="">All</option>
                            <option value="processed">Processed</option>
                            <option value="pending">Pending</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="per_page" class="text-sm font-medium text-foreground">Rows per page</label>
                        <select
                            id="per_page"
                            v-model.number="filterState.per_page"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring"
                        >
                            <option v-for="option in PER_PAGE_OPTIONS" :key="option" :value="option">{{ option }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2 md:col-span-4 md:flex-row md:justify-end">
                        <div class="flex gap-2">
                            <Button type="submit">Apply filters</Button>
                            <Button type="button" variant="outline" @click="resetFilters">Reset</Button>
                        </div>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Stripe ID</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>User</TableHead>
                                <TableHead>Recorded</TableHead>
                                <TableHead>Processed</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="calls.length === 0">
                                <TableCell colspan="6" class="text-center text-sm text-muted-foreground">
                                    No webhook calls archived yet.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="call in calls" :key="call.id">
                                <TableCell class="font-mono text-xs">{{ call.stripe_id ?? '—' }}</TableCell>
                                <TableCell class="text-sm">{{ call.type }}</TableCell>
                                <TableCell>
                                    <div v-if="call.user" class="flex flex-col text-sm">
                                        <span class="font-medium">{{ call.user.nickname }}</span>
                                        <span class="text-xs text-muted-foreground">{{ call.user.email }}</span>
                                    </div>
                                    <span v-else class="text-xs text-muted-foreground">Unknown</span>
                                </TableCell>
                                <TableCell class="text-sm">{{ formatDateTime(call.created_at) }}</TableCell>
                                <TableCell class="text-sm">
                                    <span v-if="call.processed_at">{{ formatDateTime(call.processed_at) }}</span>
                                    <span v-else class="text-xs font-medium uppercase tracking-wide text-amber-600">Pending</span>
                                </TableCell>
                                <TableCell class="text-right">
                                    <Button variant="outline" size="sm" as-child>
                                        <Link :href="route('acp.billing.webhooks.show', call.id)">View details</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination v-if="pageCount > 1">
                        <PaginationList>
                            <PaginationListItem>
                                <PaginationFirst :disabled="page <= 1" @click="setPage(1)" />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationPrev :disabled="page <= 1" @click="setPage(page - 1)" />
                            </PaginationListItem>
                            <PaginationListItem v-if="page > 2">
                                <PaginationEllipsis />
                            </PaginationListItem>
                            <PaginationListItem>
                                <Button variant="outline" class="h-8 min-w-[2rem] px-3" disabled>{{ page }}</Button>
                            </PaginationListItem>
                            <PaginationListItem v-if="page < pageCount - 1">
                                <PaginationEllipsis />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationNext :disabled="page >= pageCount" @click="setPage(page + 1)" />
                            </PaginationListItem>
                            <PaginationListItem>
                                <PaginationLast :disabled="page >= pageCount" @click="setPage(pageCount)" />
                            </PaginationListItem>
                        </PaginationList>
                    </Pagination>
                </div>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
