<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import Input from '@/components/ui/input/Input.vue';
import Label from '@/components/ui/label/Label.vue';
import Button from '@/components/ui/button/Button.vue';
import Badge from '@/components/ui/badge/Badge.vue';
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
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { type BreadcrumbItem } from '@/types';

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

interface ActivityActor {
    id: number;
    nickname: string | null;
    email: string | null;
}

interface ActivitySubject {
    type: string | null;
    id: number | string | null;
    label: string | null;
}

type ActivityProperties = Record<string, unknown> | unknown[];

interface ActivityItem {
    id: number;
    description: string | null;
    event: string | null;
    log_name: string | null;
    causer: ActivityActor | null;
    subject: ActivitySubject | null;
    properties: ActivityProperties;
    created_at: string | null;
    time: string | null;
}

const props = defineProps<{
    activities: {
        data: ActivityItem[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters: {
        search: string | null;
        event: string | null;
        log: string | null;
        causer_id: string | null;
    };
    events: Array<{ value: string; label: string }>;
    logs: Array<{ value: string; label: string }>;
    actors: Array<{ value: string; label: string }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Audit Log', href: '#' },
];

const searchQuery = ref(props.filters.search ?? '');
const eventFilter = ref(props.filters.event ?? 'all');
const logFilter = ref(props.filters.log ?? 'all');
const actorFilter = ref(props.filters.causer_id ?? 'all');

const { formatDate, fromNow } = useUserTimezone();

const eventLabelMap = computed(() => {
    const map = new Map<string, string>();
    for (const option of props.events) {
        map.set(option.value, option.label);
    }
    return map;
});

const activities = computed(() => props.activities.data ?? []);

const { page, setPage, pageCount, rangeLabel } = useInertiaPagination({
    meta: () => props.activities.meta ?? null,
    onNavigate: (value) => applyFilters({ page: value }),
});

const hasActiveFilters = computed(() => {
    return (
        searchQuery.value.trim() !== '' ||
        eventFilter.value !== 'all' ||
        logFilter.value !== 'all' ||
        actorFilter.value !== 'all'
    );
});

const applySearch = useDebounceFn(() => {
    setPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
}, 300);

let skipSearchWatch = true;
let skipEventWatch = true;
let skipLogWatch = true;
let skipActorWatch = true;

watch(
    () => props.filters,
    (filters) => {
        skipSearchWatch = true;
        skipEventWatch = true;
        skipLogWatch = true;
        skipActorWatch = true;

        searchQuery.value = filters.search ?? '';
        eventFilter.value = filters.event ?? 'all';
        logFilter.value = filters.log ?? 'all';
        actorFilter.value = filters.causer_id ?? 'all';
    },
    { deep: true }
);

watch(searchQuery, () => {
    if (skipSearchWatch) {
        skipSearchWatch = false;
        return;
    }

    applySearch();
});

watch(eventFilter, () => {
    if (skipEventWatch) {
        skipEventWatch = false;
        return;
    }

    setPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

watch(logFilter, () => {
    if (skipLogWatch) {
        skipLogWatch = false;
        return;
    }

    setPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

watch(actorFilter, () => {
    if (skipActorWatch) {
        skipActorWatch = false;
        return;
    }

    setPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
});

function applyFilters(options: { page?: number } = {}) {
    const query: Record<string, unknown> = {};
    const trimmedSearch = searchQuery.value.trim();

    if (trimmedSearch !== '') {
        query.search = trimmedSearch;
    }

    if (eventFilter.value !== 'all') {
        query.event = eventFilter.value;
    }

    if (logFilter.value !== 'all') {
        query.log = logFilter.value;
    }

    if (actorFilter.value !== 'all') {
        query.causer_id = actorFilter.value;
    }

    if (options.page && options.page > 0) {
        query.page = options.page;
    }

    router.get(route('acp.audit-log.index'), query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function resetFilters() {
    skipSearchWatch = true;
    skipEventWatch = true;
    skipLogWatch = true;
    skipActorWatch = true;

    searchQuery.value = '';
    eventFilter.value = 'all';
    logFilter.value = 'all';
    actorFilter.value = 'all';

    setPage(1, { emitNavigate: false });
    applyFilters({ page: 1 });
}

function eventLabel(event: string | null): string | null {
    if (!event) {
        return null;
    }

    return eventLabelMap.value.get(event) ?? titleCase(event);
}

function titleCase(value: string): string {
    return value
        .split(/[._]/)
        .filter(Boolean)
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
}

function formatActor(actor: ActivityActor | null): { primary: string; secondary: string | null } {
    if (!actor) {
        return { primary: '—', secondary: null };
    }

    const primary = actor.nickname ?? actor.email ?? `User #${actor.id}`;
    const secondary = actor.email && actor.email !== primary ? actor.email : null;

    return { primary, secondary };
}

function formatSubject(subject: ActivitySubject | null): { primary: string; secondary: string | null } {
    if (!subject) {
        return { primary: '—', secondary: null };
    }

    const primary = subject.label ?? subject.type ?? 'Record';
    const metaParts: string[] = [];

    if (subject.type) {
        metaParts.push(subject.type);
    }

    if (subject.id !== null && subject.id !== undefined) {
        metaParts.push(`#${subject.id}`);
    }

    const secondary = metaParts.length > 0 ? metaParts.join(' • ') : null;

    return { primary, secondary };
}

function hasProperties(properties: ActivityProperties): boolean {
    if (Array.isArray(properties)) {
        return properties.length > 0;
    }

    return Object.keys(properties ?? {}).length > 0;
}

function formatProperties(properties: ActivityProperties): string {
    try {
        return JSON.stringify(properties, null, 2);
    } catch (error) {
        console.error('Failed to serialise activity properties', error);
        return '';
    }
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Audit Log" />
        <AdminLayout>
            <div class="flex w-full flex-col space-y-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
                    <div class="space-y-1">
                        <h1 class="text-3xl font-bold">Audit Log</h1>
                        <p class="text-muted-foreground">
                            Review who changed what across the platform for full traceability.
                        </p>
                    </div>
                    <div class="flex flex-col gap-2 md:flex-row md:items-center">
                        <div class="flex flex-col">
                            <Label for="audit-search">Search</Label>
                            <Input
                                id="audit-search"
                                v-model="searchQuery"
                                type="search"
                                placeholder="Search descriptions or properties..."
                                class="md:w-72"
                            />
                        </div>
                        <div class="flex flex-col">
                            <Label for="event-filter">Event</Label>
                            <select
                                id="event-filter"
                                v-model="eventFilter"
                                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="all">All events</option>
                                <option v-for="event in props.events" :key="event.value" :value="event.value">
                                    {{ event.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex flex-col">
                            <Label for="log-filter">Log</Label>
                            <select
                                id="log-filter"
                                v-model="logFilter"
                                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="all">All logs</option>
                                <option v-for="log in props.logs" :key="log.value" :value="log.value">
                                    {{ log.label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex flex-col">
                            <Label for="actor-filter">Actor</Label>
                            <select
                                id="actor-filter"
                                v-model="actorFilter"
                                class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            >
                                <option value="all">All actors</option>
                                <option v-for="actor in props.actors" :key="actor.value" :value="actor.value">
                                    {{ actor.label }}
                                </option>
                            </select>
                        </div>
                        <Button
                            v-if="hasActiveFilters"
                            variant="outline"
                            class="self-start md:self-end"
                            @click="resetFilters"
                        >
                            Reset
                        </Button>
                    </div>
                </div>

                <div class="rounded-lg border bg-background">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="min-w-[220px]">Description</TableHead>
                                <TableHead class="w-32">Event</TableHead>
                                <TableHead class="w-40">Actor</TableHead>
                                <TableHead class="w-48">Subject</TableHead>
                                <TableHead class="w-48">Logged At</TableHead>
                                <TableHead class="w-28">Log</TableHead>
                                <TableHead>Properties</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="activity in activities" :key="activity.id">
                                <TableCell>
                                    <div class="font-medium">
                                        {{ activity.description ?? 'Activity recorded' }}
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        ID: {{ activity.id }}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge v-if="activity.event" variant="secondary">
                                        {{ eventLabel(activity.event) }}
                                    </Badge>
                                    <span v-else class="text-sm text-muted-foreground">—</span>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">
                                            {{ formatActor(activity.causer).primary }}
                                        </span>
                                        <span v-if="formatActor(activity.causer).secondary" class="text-xs text-muted-foreground">
                                            {{ formatActor(activity.causer).secondary }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">
                                            {{ formatSubject(activity.subject).primary }}
                                        </span>
                                        <span v-if="formatSubject(activity.subject).secondary" class="text-xs text-muted-foreground">
                                            {{ formatSubject(activity.subject).secondary }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <div class="flex flex-col">
                                        <span>
                                            {{ activity.created_at ? formatDate(activity.created_at) : '—' }}
                                        </span>
                                        <span v-if="activity.created_at" class="text-xs text-muted-foreground">
                                            {{ fromNow(activity.created_at) }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">
                                        {{ activity.log_name ?? 'default' }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <template v-if="hasProperties(activity.properties)">
                                        <details class="group">
                                            <summary class="cursor-pointer text-sm text-primary hover:underline">
                                                View properties
                                            </summary>
                                            <pre class="mt-2 overflow-auto rounded-md bg-muted p-3 text-xs">
                                                {{ formatProperties(activity.properties) }}
                                            </pre>
                                        </details>
                                    </template>
                                    <template v-else>
                                        <span class="text-sm text-muted-foreground">—</span>
                                    </template>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="activities.length === 0">
                                <TableCell colspan="7" class="text-center text-sm text-muted-foreground">
                                    No activity has been recorded yet.
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                    <span class="text-sm text-muted-foreground">{{ rangeLabel }}</span>
                    <Pagination v-if="pageCount > 1">
                        <PaginationList class="flex items-center gap-1">
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
                                <span class="px-2 text-sm font-medium">Page {{ page }} of {{ pageCount }}</span>
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
            </div>
        </AdminLayout>
    </AppLayout>
</template>
