<script setup lang="ts">
import dayjs from 'dayjs';
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Pagination, PaginationEllipsis, PaginationFirst, PaginationLast, PaginationList, PaginationListItem, PaginationNext, PaginationPrev } from '@/components/ui/pagination';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';

interface SearchAggregate {
    term: string;
    total_count: number;
    zero_result_count: number;
    total_results: number;
    click_through_rate: number;
    average_results: number;
    last_ran_at?: string | null;
}

interface RecentSearch {
    term: string;
    result_count: number;
    created_at?: string | null;
}

interface PaginationLinks {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
}

const props = defineProps<{
    summary: {
        total_searches: number;
        unique_terms: number;
        overall_ctr: number;
        zero_result_rate: number;
        average_results: number;
    };
    topQueries: SearchAggregate[];
    failedQueries: SearchAggregate[];
    recentSearches: {
        data: RecentSearch[];
        meta?: PaginationMeta | null;
        links?: PaginationLinks | null;
    };
    filters: {
        term?: string | null;
        date_from?: string | null;
        date_to?: string | null;
        per_page?: number | null;
    };
    exportLinks: {
        aggregates: string;
        searches: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Search Analytics', href: '/acp/search-analytics' },
];

const filters = reactive({
    term: props.filters.term ?? '',
    date_from: props.filters.date_from ?? '',
    date_to: props.filters.date_to ?? '',
    per_page: props.filters.per_page ?? props.recentSearches.meta?.per_page ?? 20,
});

const {
    page: currentPage,
    setPage,
    pageCount: totalPages,
} = useInertiaPagination({
    meta: computed(() => props.recentSearches.meta ?? null),
    itemsLength: computed(() => props.recentSearches.data?.length ?? 0),
    defaultPerPage: filters.per_page,
    onNavigate: (page) => applyFilters({ page }),
});

const paginationPages = computed<(number | '...')[]>(() => {
    const total = totalPages.value;
    const current = currentPage.value;

    if (total <= 7) {
        return Array.from({ length: total }, (_, index) => index + 1);
    }

    const pages: (number | '...')[] = [1];
    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    if (start > 2) {
        pages.push('...');
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    if (end < total - 1) {
        pages.push('...');
    }

    pages.push(total);

    return pages;
});

const formatNumber = (value: number) => new Intl.NumberFormat().format(value);
const formatPercent = (value: number) => `${value.toFixed(2)}%`;
const formatDateTime = (value?: string | null) => (value ? dayjs(value).format('MMM D, YYYY h:mm A') : '—');

const applyFilters = (overrides: Record<string, unknown> = {}) => {
    router.get(route('acp.search-analytics.index'), { ...filters, ...overrides }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const resetFilters = () => {
    filters.term = '';
    filters.date_from = '';
    filters.date_to = '';
    filters.per_page = 20;
    applyFilters({ page: 1 });
};

const summaryCards = computed(() => [
    { title: 'Total Searches', value: formatNumber(props.summary.total_searches) },
    { title: 'Unique Terms', value: formatNumber(props.summary.unique_terms) },
    { title: 'Click-Through Rate', value: formatPercent(props.summary.overall_ctr) },
    { title: 'Average Results', value: props.summary.average_results.toFixed(2) },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Search Analytics" />
        <AdminLayout>
            <div class="flex flex-col w-full gap-6 rounded-xl pb-4">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h1 class="text-2xl font-semibold tracking-tight">Search Analytics</h1>
                    <div class="flex gap-2">
                        <Button as-child variant="secondary">
                            <a :href="exportLinks.aggregates">Export Aggregates</a>
                        </Button>
                        <Button as-child>
                            <a :href="exportLinks.searches">Export Recent Searches</a>
                        </Button>
                    </div>
                </div>

                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card v-for="card in summaryCards" :key="card.title">
                        <CardHeader>
                            <CardTitle class="text-base">{{ card.title }}</CardTitle>
                            <CardDescription class="text-2xl font-semibold text-foreground">
                                {{ card.value }}
                            </CardDescription>
                        </CardHeader>
                    </Card>
                </div>

                <div class="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Top Queries</CardTitle>
                            <CardDescription>Most frequently run searches with engagement signals.</CardDescription>
                        </CardHeader>
                        <CardContent class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Term</TableHead>
                                        <TableHead class="text-right">Runs</TableHead>
                                        <TableHead class="text-right">CTR</TableHead>
                                        <TableHead class="text-right">Avg Results</TableHead>
                                        <TableHead>Last Search</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="topQueries.length === 0">
                                        <TableCell colspan="5" class="text-center text-sm text-muted-foreground">No search history yet.</TableCell>
                                    </TableRow>
                                    <TableRow v-for="query in topQueries" :key="query.term">
                                        <TableCell class="font-medium">{{ query.term }}</TableCell>
                                        <TableCell class="text-right">{{ formatNumber(query.total_count) }}</TableCell>
                                        <TableCell class="text-right">{{ formatPercent(query.click_through_rate) }}</TableCell>
                                        <TableCell class="text-right">{{ query.average_results.toFixed(2) }}</TableCell>
                                        <TableCell>{{ formatDateTime(query.last_ran_at) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Failed Queries</CardTitle>
                            <CardDescription>Searches that returned zero results most frequently.</CardDescription>
                        </CardHeader>
                        <CardContent class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Term</TableHead>
                                        <TableHead class="text-right">Zero Results</TableHead>
                                        <TableHead class="text-right">Total Runs</TableHead>
                                        <TableHead class="text-right">CTR</TableHead>
                                        <TableHead>Last Search</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="failedQueries.length === 0">
                                        <TableCell colspan="5" class="text-center text-sm text-muted-foreground">No failed searches recorded.</TableCell>
                                    </TableRow>
                                    <TableRow v-for="query in failedQueries" :key="query.term">
                                        <TableCell class="font-medium">{{ query.term }}</TableCell>
                                        <TableCell class="text-right">{{ formatNumber(query.zero_result_count) }}</TableCell>
                                        <TableCell class="text-right">{{ formatNumber(query.total_count) }}</TableCell>
                                        <TableCell class="text-right">{{ formatPercent(query.click_through_rate) }}</TableCell>
                                        <TableCell>{{ formatDateTime(query.last_ran_at) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent Searches</CardTitle>
                        <CardDescription>Live feed of search activity with filters.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-4">
                            <div class="space-y-2 md:col-span-2">
                                <Label for="term">Search term</Label>
                                <Input id="term" v-model="filters.term" placeholder="e.g. billing" @keydown.enter.prevent="applyFilters({ page: 1 })" />
                            </div>
                            <div class="space-y-2">
                                <Label for="date_from">From</Label>
                                <Input id="date_from" v-model="filters.date_from" type="date" />
                            </div>
                            <div class="space-y-2">
                                <Label for="date_to">To</Label>
                                <Input id="date_to" v-model="filters.date_to" type="date" />
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="flex items-center gap-2">
                                <Label for="per_page" class="text-sm text-muted-foreground">Per page</Label>
                                <Input id="per_page" v-model.number="filters.per_page" type="number" min="5" max="100" class="w-24" />
                            </div>
                            <div class="flex gap-2">
                                <Button size="sm" @click="applyFilters({ page: 1 })">Apply filters</Button>
                                <Button size="sm" variant="secondary" @click="resetFilters">Reset</Button>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Term</TableHead>
                                        <TableHead class="text-right">Results</TableHead>
                                        <TableHead>Ran At</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="recentSearches.data.length === 0">
                                        <TableCell colspan="3" class="text-center text-sm text-muted-foreground">No searches found for the selected filters.</TableCell>
                                    </TableRow>
                                    <TableRow v-for="search in recentSearches.data" :key="`${search.term}-${search.created_at}`">
                                        <TableCell class="font-medium">{{ search.term }}</TableCell>
                                        <TableCell class="text-right">{{ formatNumber(search.result_count) }}</TableCell>
                                        <TableCell>{{ formatDateTime(search.created_at) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>

                        <Pagination v-if="(recentSearches.meta?.last_page ?? 1) > 1" class="justify-end">
                            <PaginationList>
                                <PaginationFirst :disabled="currentPage <= 1" @click="setPage(1)" />
                                <PaginationPrev :disabled="currentPage <= 1" @click="setPage(currentPage - 1)" />
                                <PaginationListItem
                                    v-for="(pageNumber, index) in paginationPages"
                                    :key="`${pageNumber}-${index}`"
                                    :value="pageNumber"
                                    :active="pageNumber === currentPage"
                                    :disabled="pageNumber === '...'"
                                    @click="pageNumber !== '...' && setPage(pageNumber)"
                                >
                                    <PaginationEllipsis v-if="pageNumber === '...'" />
                                    <span v-else>{{ pageNumber }}</span>
                                </PaginationListItem>
                                <PaginationNext :disabled="currentPage >= (totalPages ?? 1)" @click="setPage(currentPage + 1)" />
                                <PaginationLast :disabled="currentPage >= (totalPages ?? 1)" @click="setPage(totalPages ?? 1)" />
                            </PaginationList>
                        </Pagination>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
