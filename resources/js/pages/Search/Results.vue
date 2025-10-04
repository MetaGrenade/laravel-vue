<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import Button from '@/components/ui/button/Button.vue';
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
import { Separator } from '@/components/ui/separator';
import { useGlobalSearchQuery } from '@/composables/useGlobalSearchQuery';
import { useInertiaPagination, type PaginationMeta } from '@/composables/useInertiaPagination';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const MIN_QUERY_LENGTH_FALLBACK = 2;

type SearchGroupKey = 'blogs' | 'forum_threads' | 'faqs';

type SearchResultItem = {
    id: number | string;
    title: string;
    description: string | null;
    url: string;
};

type SearchResultGroup = {
    items: SearchResultItem[];
    meta: PaginationMeta;
};

type SearchResultsPayload = Record<SearchGroupKey, SearchResultGroup>;

type QueryParamOverrides = Partial<{
    per_page: number;
    types: SearchGroupKey[];
    blogs_page: number;
    forum_threads_page: number;
    faqs_page: number;
}>;

const props = defineProps<{
    query: string;
    filters: { types: SearchGroupKey[]; per_page: number };
    pages: Record<SearchGroupKey, number>;
    results: SearchResultsPayload | null;
    available_types: Record<SearchGroupKey, string>;
    min_query_length: number | null;
}>();

const sharedQuery = useGlobalSearchQuery();

const syncSharedQuery = (value: string) => {
    if (sharedQuery.value !== value) {
        sharedQuery.value = value;
    }
};

watch(
    () => props.query,
    (value) => {
        syncSharedQuery(value ?? '');
    },
    { immediate: true },
);

const searchInput = computed({
    get: () => sharedQuery.value,
    set: (value: string) => {
        sharedQuery.value = value;
    },
});

const trimmedQuery = computed(() => searchInput.value.trim());
const minQueryLength = computed(() => props.min_query_length ?? MIN_QUERY_LENGTH_FALLBACK);
const isQueryTooShort = computed(
    () => trimmedQuery.value.length > 0 && trimmedQuery.value.length < minQueryLength.value,
);

const typeOrder = computed(() => Object.keys(props.available_types) as SearchGroupKey[]);
const normalizeTypes = (types: SearchGroupKey[]) =>
    typeOrder.value.filter((type) => types.includes(type));

const selectedTypes = ref<SearchGroupKey[]>(normalizeTypes(props.filters.types));
const perPage = ref<number>(props.filters.per_page);

watch(
    () => props.filters,
    (filters) => {
        selectedTypes.value = normalizeTypes(filters.types);
        perPage.value = filters.per_page;
    },
    { deep: true },
);

const selectedTypeSet = computed(() => new Set(selectedTypes.value));
const typeOptions = computed(() =>
    typeOrder.value.map((type) => ({ key: type, label: props.available_types[type] })),
);

const basePerPageOptions = [5, 10, 15, 25, 50];
const perPageOptions = computed(() => {
    const options = new Set(basePerPageOptions);

    if (perPage.value) {
        options.add(perPage.value);
    }

    return Array.from(options).sort((a, b) => a - b);
});

const pageParamMap: Record<SearchGroupKey, keyof QueryParamOverrides> = {
    blogs: 'blogs_page',
    forum_threads: 'forum_threads_page',
    faqs: 'faqs_page',
};

function navigate(overrides: QueryParamOverrides = {}, options: { resetPages?: boolean } = {}) {
    const params: Record<string, unknown> = {};
    const trimmed = trimmedQuery.value;

    if (trimmed.length > 0) {
        params.q = trimmed;
    }

    const nextPerPage = overrides.per_page ?? perPage.value;
    params.per_page = nextPerPage;

    const nextTypes = overrides.types ?? selectedTypes.value;
    const orderedTypes = normalizeTypes(nextTypes);

    if (orderedTypes.length === 0) {
        orderedTypes.push(typeOrder.value[0]);
    }

    params.types = [...orderedTypes];

    const shouldResetPages = Boolean(options.resetPages) || overrides.types !== undefined || overrides.per_page !== undefined;

    (['blogs', 'forum_threads', 'faqs'] as SearchGroupKey[]).forEach((group) => {
        const overrideKey = pageParamMap[group];
        const overrideValue = overrides[overrideKey];

        let pageValue: number;

        if (typeof overrideValue === 'number') {
            pageValue = overrideValue;
        } else if (shouldResetPages) {
            pageValue = 1;
        } else {
            pageValue = props.pages[group];
        }

        if (pageValue > 1) {
            params[`${group}_page`] = pageValue;
        }
    });

    router.get(route('search.results'), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function goToPage(group: SearchGroupKey, page: number) {
    const overrideKey = pageParamMap[group];
    navigate({ [overrideKey]: page } as QueryParamOverrides);
}

function submitSearch() {
    navigate({}, { resetPages: true });
}

function toggleType(type: SearchGroupKey) {
    const current = new Set(selectedTypes.value);

    if (current.has(type)) {
        if (current.size === 1) {
            return;
        }

        current.delete(type);
    } else {
        current.add(type);
    }

    const next = normalizeTypes(Array.from(current) as SearchGroupKey[]);
    selectedTypes.value = next;

    navigate({ types: next }, { resetPages: true });
}

function onPerPageChange(event: Event) {
    const target = event.target as HTMLSelectElement | null;
    const value = target ? Number(target.value) : perPage.value;

    if (!Number.isFinite(value)) {
        return;
    }

    const sanitized = Math.max(1, Math.min(Math.round(value), 50));

    if (perPage.value === sanitized) {
        return;
    }

    perPage.value = sanitized;
    navigate({ per_page: sanitized }, { resetPages: true });
}

const pagination = {
    blogs: useInertiaPagination({
        meta: computed(() => props.results?.blogs?.meta ?? null),
        itemsLength: computed(() => props.results?.blogs?.items.length ?? 0),
        defaultPerPage: computed(() => perPage.value),
        itemLabel: 'blog post',
        itemLabelPlural: 'blog posts',
        onNavigate: (page) => goToPage('blogs', page),
    }),
    forum_threads: useInertiaPagination({
        meta: computed(() => props.results?.forum_threads?.meta ?? null),
        itemsLength: computed(() => props.results?.forum_threads?.items.length ?? 0),
        defaultPerPage: computed(() => perPage.value),
        itemLabel: 'thread',
        itemLabelPlural: 'threads',
        onNavigate: (page) => goToPage('forum_threads', page),
    }),
    faqs: useInertiaPagination({
        meta: computed(() => props.results?.faqs?.meta ?? null),
        itemsLength: computed(() => props.results?.faqs?.items.length ?? 0),
        defaultPerPage: computed(() => perPage.value),
        itemLabel: 'FAQ',
        itemLabelPlural: 'FAQs',
        onNavigate: (page) => goToPage('faqs', page),
    }),
} satisfies Record<SearchGroupKey, ReturnType<typeof useInertiaPagination>>;

const buildMeta = (meta: PaginationMeta | null | undefined): PaginationMeta => ({
    current_page: meta?.current_page ?? 1,
    last_page: meta?.last_page ?? 1,
    per_page: meta?.per_page ?? perPage.value,
    total: meta?.total ?? 0,
    from: meta?.from ?? null,
    to: meta?.to ?? null,
});

const groups = computed(() => {
    if (!props.results) {
        return [] as Array<{
            key: SearchGroupKey;
            title: string;
            items: SearchResultItem[];
            meta: PaginationMeta;
            pagination: ReturnType<typeof useInertiaPagination>;
        }>;
    }

    const selected = new Set(selectedTypes.value);

    return (typeOrder.value as SearchGroupKey[])
        .filter((key) => selected.has(key))
        .map((key) => ({
            key,
            title: props.available_types[key],
            items: props.results?.[key]?.items ?? [],
            meta: buildMeta(props.results?.[key]?.meta),
            pagination: pagination[key],
        }));
});

const hasAnyResults = computed(() => groups.value.some((group) => group.items.length > 0));
</script>

<template>
    <AppLayout>
        <Head title="Search" />

        <div class="mx-auto w-full max-w-5xl space-y-8 py-10">
            <div class="space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight">Search</h1>
                <p class="text-muted-foreground">
                    Find content across blog posts, forum threads, and FAQs.
                </p>
            </div>

            <form class="flex flex-col gap-3 sm:flex-row" @submit.prevent="submitSearch">
                <div class="flex-1">
                    <label class="sr-only" for="global-search-input">Search term</label>
                    <Input
                        id="global-search-input"
                        v-model="searchInput"
                        type="search"
                        placeholder="Search blogs, forum threads, and FAQs"
                        autocomplete="off"
                        class="w-full"
                    />
                </div>
                <Button type="submit" class="sm:w-auto">Search</Button>
            </form>

            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-muted-foreground">Filter by</span>
                <Button
                    v-for="type in typeOptions"
                    :key="type.key"
                    variant="outline"
                    size="sm"
                    :class="selectedTypeSet.has(type.key) ? 'border-primary text-primary' : ''"
                    type="button"
                    @click="toggleType(type.key)"
                >
                    {{ type.label }}
                </Button>
            </div>

            <div class="flex flex-wrap items-center gap-3 text-sm text-muted-foreground">
                <label class="font-medium" for="search-per-page">Results per page</label>
                <select
                    id="search-per-page"
                    class="h-9 rounded-md border border-input bg-background px-3 text-sm focus:outline-none focus:ring-2 focus:ring-ring"
                    :value="perPage"
                    @change="onPerPageChange"
                >
                    <option v-for="option in perPageOptions" :key="option" :value="option">
                        {{ option }}
                    </option>
                </select>
            </div>

            <Separator />

            <div v-if="trimmedQuery.length === 0" class="rounded-md border border-dashed border-muted-foreground/40 bg-muted/40 p-6 text-sm text-muted-foreground">
                Start typing to search the knowledge base.
            </div>
            <div v-else-if="isQueryTooShort" class="rounded-md border border-dashed border-muted-foreground/40 bg-muted/40 p-6 text-sm text-muted-foreground">
                Type at least {{ minQueryLength }} characters to search.
            </div>
            <template v-else>
                <div v-if="!props.results" class="text-sm text-muted-foreground">
                    Preparing your results…
                </div>
                <div v-else-if="hasAnyResults" class="space-y-10">
                    <section v-for="group in groups" :key="group.key" class="space-y-4">
                        <header class="flex flex-wrap items-center justify-between gap-3">
                            <div class="space-y-1">
                                <h2 class="text-xl font-semibold tracking-tight">{{ group.title }}</h2>
                                <p class="text-sm text-muted-foreground">{{ group.pagination.rangeLabel }}</p>
                            </div>
                        </header>

                        <ul class="divide-y divide-border rounded-md border border-border/60 bg-card">
                            <li v-for="item in group.items" :key="`${group.key}-${item.id}`">
                                <Link
                                    :href="item.url"
                                    class="block px-4 py-3 transition hover:bg-muted focus:bg-muted focus:outline-none"
                                >
                                    <h3 class="text-base font-medium text-foreground">{{ item.title }}</h3>
                                    <p v-if="item.description" class="mt-1 text-sm text-muted-foreground">
                                        {{ item.description }}
                                    </p>
                                </Link>
                            </li>
                        </ul>

                        <Pagination
                            v-if="group.meta.total > group.meta.per_page"
                            v-model:page="group.pagination.page"
                            :items-per-page="Math.max(group.meta.per_page, 1)"
                            :total="group.meta.total"
                            :sibling-count="1"
                            show-edges
                        >
                            <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                                <span class="text-sm text-muted-foreground">
                                    Page {{ group.pagination.page }} of {{ Math.max(group.pagination.pageCount, 1) }}
                                </span>
                                <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                                    <PaginationFirst />
                                    <PaginationPrev />

                                    <template v-for="(item, index) in items" :key="index">
                                        <PaginationListItem
                                            v-if="item.type === 'page'"
                                            :value="item.value"
                                            as-child
                                        >
                                            <Button class="h-9 w-9 p-0" :variant="item.value === group.pagination.page ? 'default' : 'outline'">
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
                    </section>
                </div>
                <div v-else class="rounded-md border border-dashed border-muted-foreground/40 bg-muted/40 p-6 text-sm text-muted-foreground">
                    No results for “{{ trimmedQuery }}”. Try adjusting your filters or search term.
                </div>
            </template>
        </div>
    </AppLayout>
</template>
