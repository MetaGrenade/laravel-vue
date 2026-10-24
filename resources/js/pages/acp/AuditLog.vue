<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
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
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, QueryParams } from '@/types';
import type { AuditChange, AuditEntry } from '@/types/audit-log';
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

interface FiltersPayload {
    search?: string | null;
    event?: string | null;
    outcome?: string | null;
    actor?: string | null;
    account?: string | null;
    from?: string | null;
    to?: string | null;
    per_page?: number | null;
}

const props = defineProps<{
    entries: {
        data: AuditEntry[];
        meta?: PaginationMeta | null;
        links?: { first: string | null; last: string | null; prev: string | null; next: string | null } | null;
    };
    filters?: FiltersPayload | null;
    /** The person whose account is being looked at, when the page was opened from it. */
    account: { id: number; nickname: string; email: string } | null;
    events: { value: string; label: string }[];
    actors: { id: number; label: string }[];
    outcomes: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: route('acp.audit-log.index') }];

const entries = computed(() => props.entries.data ?? []);
const PER_PAGE_OPTIONS = [10, 25, 50, 100];

const filterState = reactive({
    search: props.filters?.search ?? '',
    event: props.filters?.event ?? '',
    outcome: props.filters?.outcome ?? '',
    actor: props.filters?.actor ?? '',
    from: props.filters?.from ?? '',
    to: props.filters?.to ?? '',
    per_page: props.filters?.per_page ?? props.entries.meta?.per_page ?? 25,
});

watch(
    () => props.filters,
    (filters) => {
        filterState.search = filters?.search ?? '';
        filterState.event = filters?.event ?? '';
        filterState.outcome = filters?.outcome ?? '';
        filterState.actor = filters?.actor ?? '';
        filterState.from = filters?.from ?? '';
        filterState.to = filters?.to ?? '';

        if (typeof filters?.per_page === 'number' && filters.per_page > 0) {
            filterState.per_page = filters.per_page;
        }
    },
    { deep: true },
);

function cleanQuery(query: QueryParams) {
    return Object.fromEntries(
        Object.entries(query).filter(([, value]) => {
            if (value === null || value === undefined) {
                return false;
            }

            return typeof value === 'string' ? value.trim() !== '' : true;
        }),
    );
}

function buildQuery(overrides: QueryParams = {}) {
    return cleanQuery({
        page: page.value,
        per_page: filterState.per_page,
        search: filterState.search,
        event: filterState.event,
        outcome: filterState.outcome,
        actor: filterState.actor,
        // The account being looked at stays until it is cleared: it is not one of the boxes.
        account: props.filters?.account,
        from: filterState.from,
        to: filterState.to,
        ...overrides,
    });
}

const { meta, page, setPage, pageCount, rangeLabel } = useInertiaPagination({
    meta: computed(() => props.entries.meta ?? null),
    itemsLength: computed(() => entries.value.length),
    defaultPerPage: filterState.per_page || 25,
    itemLabel: 'entry',
    itemLabelPlural: 'entries',
    onNavigate: (newPage) => {
        router.get(route('acp.audit-log.index'), buildQuery({ page: newPage }), { preserveScroll: true, preserveState: true, replace: true });
    },
});

const applyFilters = () => {
    setPage(1, { emitNavigate: false });

    router.get(route('acp.audit-log.index'), buildQuery({ page: 1 }), { preserveScroll: true, preserveState: true, replace: true });
};

const resetFilters = () => {
    filterState.search = '';
    filterState.event = '';
    filterState.outcome = '';
    filterState.actor = '';
    filterState.from = '';
    filterState.to = '';
    filterState.per_page = 25;

    applyFilters();
};

const clearAccount = () => {
    router.get(route('acp.audit-log.index'), buildQuery({ page: 1, account: null }), { preserveScroll: true, preserveState: true, replace: true });
};

// --- Showing an entry ---------------------------------------------------------------------------------------------------------------

const open = ref(new Set<number>());

const toggle = (id: number) => {
    const next = new Set(open.value);

    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    open.value = next;
};

const OUTCOMES = {
    succeeded: { label: 'Succeeded', variant: 'secondary' },
    failed: { label: 'Failed', variant: 'highlight' },
    denied: { label: 'Denied', variant: 'destructive' },
} as const;

const outcome = (entry: AuditEntry) => OUTCOMES[entry.outcome] ?? { label: entry.outcome, variant: 'outline' as const };

const ACTIONS: Record<string, string> = {
    created: 'Created',
    updated: 'Changed',
    deleted: 'Deleted',
    'roles.attached': 'Roles added',
    'roles.detached': 'Roles removed',
    'permissions.attached': 'Permissions added',
    'permissions.detached': 'Permissions removed',
};

const actionLabel = (change: AuditChange) => ACTIONS[change.action] ?? change.action;

/** The attributes that changed, with what each was and became. */
const fieldsOf = (change: AuditChange) =>
    Array.from(new Set([...Object.keys(change.old ?? {}), ...Object.keys(change.new ?? {})])).map((name) => ({
        name,
        before: change.old?.[name],
        after: change.new?.[name],
        hasBefore: change.old !== undefined && name in change.old,
        hasAfter: change.new !== undefined && name in change.new,
    }));

const show = (value: unknown) => {
    if (value === null || value === undefined) {
        return '—';
    }

    return typeof value === 'string' ? value : JSON.stringify(value);
};

const formatDateTime = (value: string | null) => {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Audit log" />

        <AdminLayout>
            <section class="flex w-full flex-col space-y-6">
                <HeadingSmall
                    title="Audit log"
                    description="Every change made in the admin area: who made it, what it was done to, what changed, and whether it worked. Entries are kept as they were and cannot be changed or removed from here."
                />

                <div
                    v-if="account"
                    class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border bg-muted/40 p-3 text-sm"
                    role="status"
                >
                    <span>
                        Showing what was done on
                        <span class="font-medium">{{ account.nickname }}</span>
                        <span class="text-muted-foreground"> ({{ account.email }})</span>'s account.
                    </span>
                    <Button type="button" variant="outline" size="sm" @click="clearAccount">Show everything</Button>
                </div>

                <form
                    class="grid gap-3 rounded-lg border border-border bg-card p-4 shadow-xs md:grid-cols-3 md:items-end lg:grid-cols-4"
                    @submit.prevent="applyFilters()"
                >
                    <div class="flex flex-col gap-2">
                        <label for="audit-search" class="text-sm font-medium text-foreground">Search</label>
                        <Input id="audit-search" v-model="filterState.search" type="search" placeholder="Person, record, action or address" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-event" class="text-sm font-medium text-foreground">Action</label>
                        <select
                            id="audit-event"
                            v-model="filterState.event"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden"
                        >
                            <option value="">Any action</option>
                            <option v-for="event in events" :key="event.value" :value="event.value">{{ event.label }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-actor" class="text-sm font-medium text-foreground">Done by</label>
                        <select
                            id="audit-actor"
                            v-model="filterState.actor"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden"
                        >
                            <option value="">Anybody</option>
                            <option v-for="actor in actors" :key="actor.id" :value="String(actor.id)">{{ actor.label }}</option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-outcome" class="text-sm font-medium text-foreground">Result</label>
                        <select
                            id="audit-outcome"
                            v-model="filterState.outcome"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden"
                        >
                            <option value="">Any result</option>
                            <option v-for="name in outcomes" :key="name" :value="name">
                                {{ OUTCOMES[name as keyof typeof OUTCOMES]?.label ?? name }}
                            </option>
                        </select>
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-from" class="text-sm font-medium text-foreground">From</label>
                        <Input id="audit-from" v-model="filterState.from" type="date" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-to" class="text-sm font-medium text-foreground">To</label>
                        <Input id="audit-to" v-model="filterState.to" type="date" />
                    </div>

                    <div class="flex flex-col gap-2">
                        <label for="audit-per-page" class="text-sm font-medium text-foreground">Rows per page</label>
                        <select
                            id="audit-per-page"
                            v-model.number="filterState.per_page"
                            class="h-10 rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs focus:ring-2 focus:ring-ring focus:outline-hidden"
                        >
                            <option v-for="option in PER_PAGE_OPTIONS" :key="option" :value="option">{{ option }}</option>
                        </select>
                    </div>

                    <div class="flex gap-2 md:col-span-3 md:justify-end lg:col-span-4">
                        <Button type="submit">Apply filters</Button>
                        <Button type="button" variant="outline" @click="resetFilters">Reset</Button>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-xs">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="w-8"><span class="sr-only">Details</span></TableHead>
                                <TableHead>When</TableHead>
                                <TableHead>Done by</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>To</TableHead>
                                <TableHead>Result</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="entries.length === 0">
                                <TableCell colspan="6" class="text-center text-sm text-muted-foreground"
                                    >Nothing has been recorded that matches.</TableCell
                                >
                            </TableRow>

                            <template v-for="entry in entries" :key="entry.id">
                                <TableRow>
                                    <TableCell>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            class="size-7"
                                            :aria-expanded="open.has(entry.id)"
                                            :aria-label="`${open.has(entry.id) ? 'Hide' : 'Show'} the details of entry ${entry.id}`"
                                            @click="toggle(entry.id)"
                                        >
                                            <ChevronDown v-if="open.has(entry.id)" class="size-4" />
                                            <ChevronRight v-else class="size-4" />
                                        </Button>
                                    </TableCell>
                                    <TableCell class="text-sm whitespace-nowrap">{{ formatDateTime(entry.created_at) }}</TableCell>
                                    <TableCell class="text-sm">{{ entry.actor.label ?? 'Unknown' }}</TableCell>
                                    <TableCell class="text-sm">
                                        <div class="flex flex-col">
                                            <span class="font-medium">{{ entry.event_label }}</span>
                                            <code class="text-xs text-muted-foreground">{{ entry.event }}</code>
                                        </div>
                                    </TableCell>
                                    <TableCell class="text-sm">
                                        <div v-if="entry.subject" class="flex flex-col">
                                            <span>{{ entry.subject.label ?? `#${entry.subject.id}` }}</span>
                                            <span class="text-xs text-muted-foreground">{{ entry.subject.type }}</span>
                                        </div>
                                        <span v-else class="text-xs text-muted-foreground">—</span>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="outcome(entry).variant">{{ outcome(entry).label }}</Badge>
                                    </TableCell>
                                </TableRow>

                                <TableRow v-if="open.has(entry.id)" class="bg-muted/30 hover:bg-muted/30">
                                    <TableCell colspan="6">
                                        <div class="space-y-4 py-2 text-sm">
                                            <p v-if="entry.outcome !== 'succeeded'" class="text-xs text-muted-foreground">
                                                {{
                                                    entry.outcome === 'denied'
                                                        ? 'This was refused: the person did not have permission, so nothing was changed.'
                                                        : 'This did not go through (it was not valid, or something went wrong). What is listed below, if anything, may not have been saved.'
                                                }}
                                            </p>

                                            <p v-if="entry.accounts_count > 1" class="text-xs text-muted-foreground">
                                                This concerned {{ entry.accounts_count }} accounts, and is in the history of each of them.
                                            </p>

                                            <div v-if="entry.changes.length" class="space-y-3">
                                                <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">What changed</h3>
                                                <div v-for="(change, index) in entry.changes" :key="index" class="space-y-1.5">
                                                    <p class="font-medium">
                                                        {{ actionLabel(change) }}
                                                        <span class="text-muted-foreground">{{ change.model }}</span>
                                                        {{ change.label }}
                                                    </p>

                                                    <p v-if="change.values" class="text-muted-foreground">
                                                        <Badge v-for="value in change.values" :key="value" variant="outline" class="mr-1">{{
                                                            value
                                                        }}</Badge>
                                                    </p>

                                                    <div v-else class="overflow-x-auto rounded-md border border-border bg-card">
                                                        <table class="w-full text-xs">
                                                            <thead>
                                                                <tr class="text-left text-muted-foreground">
                                                                    <th class="px-3 py-1.5 font-medium">Field</th>
                                                                    <th class="px-3 py-1.5 font-medium">Before</th>
                                                                    <th class="px-3 py-1.5 font-medium">After</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <tr
                                                                    v-for="field in fieldsOf(change)"
                                                                    :key="field.name"
                                                                    class="border-t border-border"
                                                                >
                                                                    <td class="px-3 py-1.5 font-mono">{{ field.name }}</td>
                                                                    <td class="px-3 py-1.5 font-mono break-all">
                                                                        {{ field.hasBefore ? show(field.before) : '' }}
                                                                    </td>
                                                                    <td class="px-3 py-1.5 font-mono break-all">
                                                                        {{ field.hasAfter ? show(field.after) : '' }}
                                                                    </td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                                <p v-if="entry.meta.changes_not_kept" class="text-xs text-muted-foreground">
                                                    And {{ entry.meta.changes_not_kept }} more that are not listed here.
                                                </p>
                                            </div>
                                            <p v-else class="text-xs text-muted-foreground">No change to a record was noted for this request.</p>

                                            <dl class="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                                                <div class="flex gap-1.5">
                                                    <dt class="text-muted-foreground">Request</dt>
                                                    <dd class="font-mono break-all">
                                                        {{ entry.meta.method }} {{ entry.meta.path }} ({{ entry.meta.status }})
                                                    </dd>
                                                </div>
                                                <div class="flex gap-1.5">
                                                    <dt class="text-muted-foreground">Route</dt>
                                                    <dd class="font-mono">{{ entry.meta.route ?? '—' }}</dd>
                                                </div>
                                                <div class="flex gap-1.5">
                                                    <dt class="text-muted-foreground">From</dt>
                                                    <dd class="font-mono">{{ entry.ip_address ?? '—' }}</dd>
                                                </div>
                                                <div class="flex gap-1.5">
                                                    <dt class="text-muted-foreground">Sent</dt>
                                                    <dd class="font-mono break-all">
                                                        {{ entry.meta.fields?.length ? entry.meta.fields.join(', ') : '—' }}
                                                    </dd>
                                                </div>
                                                <div class="flex gap-1.5 sm:col-span-2">
                                                    <dt class="text-muted-foreground">Browser</dt>
                                                    <dd class="break-all">{{ entry.user_agent ?? '—' }}</dd>
                                                </div>
                                            </dl>

                                            <p v-if="entry.actor.id" class="text-xs">
                                                <Link
                                                    :href="route('acp.audit-log.index', { actor: entry.actor.id })"
                                                    class="text-primary underline-offset-4 hover:underline"
                                                >
                                                    Everything this person has done
                                                </Link>
                                            </p>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </template>
                        </TableBody>
                    </Table>
                </div>

                <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <p class="text-sm text-muted-foreground">{{ rangeLabel }}</p>
                    <Pagination
                        v-if="pageCount > 1"
                        v-slot="{ page: currentPage }"
                        v-model:page="page"
                        :items-per-page="Math.max(meta.per_page, 1)"
                        :total="meta.total"
                        :sibling-count="1"
                        show-edges
                    >
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === currentPage ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis v-else :index="index" />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>
            </section>
        </AdminLayout>
    </AppLayout>
</template>
