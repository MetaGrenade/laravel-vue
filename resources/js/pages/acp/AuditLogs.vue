<script setup lang="ts">
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { TableCaption } from '@/components/ui/table/TableCaption.vue';
import { TableEmpty } from '@/components/ui/table/TableEmpty.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Label } from '@/components/ui/label';
import {
    Pagination,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';

interface AuditActor {
    id: number;
    nickname: string;
    email: string | null;
}

interface AuditSubject {
    id: number;
    type: string;
    label: string | null;
}

interface AuditLogItem {
    id: number;
    event: string | null;
    description: string | null;
    properties: Record<string, unknown> | null;
    created_at: string | null;
    created_at_for_humans: string | null;
    causer: AuditActor | null;
    subject: AuditSubject | null;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

const props = defineProps<{
    logs: {
        data: AuditLogItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters: {
        event: string | null;
        search: string | null;
        causer: number | null;
        per_page: number | null;
    };
    events: string[];
    actors: AuditActor[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Audit logs', href: route('acp.audit-logs.index') },
];

const filterState = reactive({
    event: props.filters.event ?? '',
    search: props.filters.search ?? '',
    causer: props.filters.causer ? String(props.filters.causer) : '',
    per_page: String(props.filters.per_page ?? 25),
});

const quickVisitOptions = {
    preserveScroll: true,
    preserveState: true,
    replace: true,
} as const;

const buildQuery = (overrides: Record<string, unknown> = {}) => {
    const query: Record<string, unknown> = {
        per_page: Number.parseInt(filterState.per_page, 10) || props.filters.per_page || 25,
        ...overrides,
    };

    const event = filterState.event.trim();
    if (event !== '') {
        query.event = event;
    }

    const search = filterState.search.trim();
    if (search !== '') {
        query.search = search;
    }

    const causer = filterState.causer.trim();
    if (causer !== '') {
        const parsed = Number.parseInt(causer, 10);
        if (!Number.isNaN(parsed)) {
            query.causer = parsed;
        }
    }

    return query;
};

const applyFilters = (overrides: Record<string, unknown> = {}) => {
    router.get(
        route('acp.audit-logs.index'),
        buildQuery({ page: 1, ...overrides }),
        quickVisitOptions,
    );
};

const clearFilters = () => {
    filterState.event = '';
    filterState.search = '';
    filterState.causer = '';
    filterState.per_page = '25';
    applyFilters();
};

const { meta: paginationMeta, setPage } = useInertiaPagination({
    meta: computed(() => props.logs.meta ?? null),
    itemsLength: computed(() => props.logs.data.length),
    defaultPerPage: props.filters.per_page ?? 25,
    itemLabel: 'log entry',
    itemLabelPlural: 'log entries',
    onNavigate: (page) => {
        router.get(route('acp.audit-logs.index'), buildQuery({ page }), quickVisitOptions);
    },
});

const hasLogs = computed(() => props.logs.data.length > 0);

const formatValue = (value: unknown): string => {
    if (value === null || value === undefined) {
        return '—';
    }

    if (Array.isArray(value) || typeof value === 'object') {
        try {
            return JSON.stringify(value, null, 2);
        } catch (error) {
            return String(value);
        }
    }

    return String(value);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Audit logs" />

        <AdminLayout>
            <section class="flex flex-1 flex-col gap-6">
                <header class="flex flex-col gap-2">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Audit logs</h1>
                        <p class="text-sm text-muted-foreground">
                            Review authentication, moderation, and billing activities recorded across the platform.
                        </p>
                    </div>
                </header>

                <form
                    class="grid gap-4 rounded-lg border border-border bg-card p-4 md:grid-cols-5 md:items-end"
                    @submit.prevent="applyFilters()"
                >
                    <div class="grid gap-2">
                        <Label for="audit-filter-event">Event</Label>
                        <select
                            id="audit-filter-event"
                            v-model="filterState.event"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option value="">All events</option>
                            <option v-for="event in props.events" :key="event" :value="event">{{ event }}</option>
                        </select>
                    </div>

                    <div class="grid gap-2">
                        <Label for="audit-filter-actor">Actor</Label>
                        <select
                            id="audit-filter-actor"
                            v-model="filterState.causer"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                        >
                            <option value="">All users</option>
                            <option v-for="actor in props.actors" :key="actor.id" :value="String(actor.id)">
                                {{ actor.nickname }}
                            </option>
                        </select>
                    </div>

                    <div class="grid gap-2 md:col-span-2">
                        <Label for="audit-filter-search">Search</Label>
                        <Input
                            id="audit-filter-search"
                            v-model="filterState.search"
                            type="search"
                            placeholder="Search descriptions or details"
                            class="h-10"
                        />
                    </div>

                    <div class="grid gap-1 text-sm text-muted-foreground">
                        <Label for="audit-filter-per-page" class="text-xs">Per page</Label>
                        <select
                            id="audit-filter-per-page"
                            v-model="filterState.per_page"
                            class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                            @change="applyFilters({ per_page: Number.parseInt(filterState.per_page, 10) || 25 })"
                        >
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <div class="flex gap-2 pt-2 md:justify-end">
                            <Button type="submit" size="sm">Apply</Button>
                            <Button type="button" size="sm" variant="ghost" @click="clearFilters">Reset</Button>
                        </div>
                    </div>
                </form>

                <div class="space-y-4">
                    <div class="overflow-x-auto rounded-lg border border-border bg-card">
                        <Table>
                            <TableCaption>Recorded audit events</TableCaption>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-48">Timestamp</TableHead>
                                    <TableHead class="w-48">Actor</TableHead>
                                    <TableHead class="w-40">Event</TableHead>
                                    <TableHead>Description</TableHead>
                                    <TableHead class="w-80">Details</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody v-if="hasLogs">
                                <TableRow v-for="log in props.logs.data" :key="log.id" class="align-top">
                                    <TableCell>
                                        <div class="flex flex-col text-sm">
                                            <span class="font-medium">{{ log.created_at_for_humans ?? '—' }}</span>
                                            <span class="text-xs text-muted-foreground">{{ log.created_at ?? '—' }}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div v-if="log.causer" class="flex flex-col text-sm">
                                            <span class="font-medium">{{ log.causer.nickname }}</span>
                                            <span class="text-xs text-muted-foreground">{{ log.causer.email ?? '—' }}</span>
                                        </div>
                                        <span v-else class="text-sm text-muted-foreground">System</span>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex flex-col text-sm">
                                            <span class="font-medium">{{ log.event ?? '—' }}</span>
                                            <span v-if="log.subject" class="text-xs text-muted-foreground">
                                                Target: {{ log.subject.type }} #{{ log.subject.id }}
                                                <span v-if="log.subject.label">— {{ log.subject.label }}</span>
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <span class="text-sm">{{ log.description ?? '—' }}</span>
                                    </TableCell>
                                    <TableCell>
                                        <div v-if="log.properties && Object.keys(log.properties).length" class="space-y-2 text-xs">
                                            <div
                                                v-for="(value, key) in log.properties"
                                                :key="key"
                                                class="rounded border border-border bg-background p-2"
                                            >
                                                <p class="mb-1 font-medium">{{ key }}</p>
                                                <pre class="whitespace-pre-wrap break-words font-mono text-xs">
{{ formatValue(value) }}</pre>
                                            </div>
                                        </div>
                                        <span v-else class="text-xs text-muted-foreground">No additional context</span>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                            <TableEmpty v-else>No audit entries recorded yet.</TableEmpty>
                        </Table>
                    </div>

                    <div class="flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            {{ props.logs.meta?.total ?? 0 }} total entries
                        </p>
                        <Pagination v-if="paginationMeta.total > paginationMeta.per_page">
                            <PaginationList>
                                <PaginationFirst :disabled="paginationMeta.current_page === 1" @click="setPage(1)" />
                                <PaginationPrev :disabled="paginationMeta.current_page === 1" @click="setPage(paginationMeta.current_page - 1)" />
                                <PaginationListItem :is-active="true">
                                    Page {{ paginationMeta.current_page }} of {{ paginationMeta.last_page }}
                                </PaginationListItem>
                                <PaginationNext :disabled="paginationMeta.current_page === paginationMeta.last_page" @click="setPage(paginationMeta.current_page + 1)" />
                                <PaginationLast :disabled="paginationMeta.current_page === paginationMeta.last_page" @click="setPage(paginationMeta.last_page)" />
                            </PaginationList>
                        </Pagination>
                    </div>
                </div>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
