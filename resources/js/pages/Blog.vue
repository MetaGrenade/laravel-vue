<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type QueryParams } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
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

interface BlogAuthorSummary {
    id: number;
    nickname: string | null;
}

interface BlogTaxonomySummary {
    id: number;
    name: string;
    slug: string;
}

interface BlogSummary {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    cover_image: string | null;
    views: number;
    last_viewed_at: string | null;
    published_at: string | null;
    author: BlogAuthorSummary | null;
    categories: BlogTaxonomySummary[];
    tags: BlogTaxonomySummary[];
}

interface BlogsPayload {
    data: BlogSummary[];
    meta?: PaginationMeta | null;
    links?: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    } | null;
}

type BlogSortOption = 'latest' | 'oldest' | 'popular';

interface BlogFiltersPayload {
    category: string | null;
    tag: string | null;
    search: string | null;
    sort: BlogSortOption | null;
}

interface BlogTaxonomyOption extends BlogTaxonomySummary {
    count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Blog', href: '/blogs' }];

const props = defineProps<{
    blogs: BlogsPayload;
    filters: BlogFiltersPayload;
    categories: BlogTaxonomyOption[];
    tags: BlogTaxonomyOption[];
}>();

const hasBlogs = computed(() => (props.blogs.data?.length ?? 0) > 0);
const featuredBlog = computed(() => props.blogs.data?.[0] ?? null);

const defaultSort: BlogSortOption = 'latest';

const activeCategory = computed(() => props.filters?.category ?? null);
const activeTag = computed(() => props.filters?.tag ?? null);
const searchInput = ref(props.filters?.search ?? '');
const sortOrder = ref<BlogSortOption>(props.filters?.sort ?? defaultSort);
const { fromNow } = useUserTimezone();

const numberFormatter = new Intl.NumberFormat();
const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);
const formatLastViewed = (value: string | null | undefined) => (value ? fromNow(value) : null);

watch(
    () => props.filters,
    (filters) => {
        searchInput.value = filters?.search ?? '';
        sortOrder.value = filters?.sort ?? defaultSort;
    },
    { deep: true },
);

const hasActiveFilters = computed(() =>
    Boolean(activeCategory.value || activeTag.value || searchInput.value.trim().length > 0 || sortOrder.value !== defaultSort),
);

const buildQueryParams = (params: {
    category?: string | null;
    tag?: string | null;
    search?: string | null;
    sort?: BlogSortOption | null;
    page?: number;
}) => {
    const query: QueryParams = {};

    if (params.category) {
        query.category = params.category;
    }

    if (params.tag) {
        query.tag = params.tag;
    }

    if (params.search && params.search.trim().length > 0) {
        query.search = params.search.trim();
    }

    if (params.sort && params.sort !== defaultSort) {
        query.sort = params.sort;
    }

    if (params.page && params.page > 1) {
        query.page = params.page;
    }

    return query;
};

const applyFilters = (
    category: string | null,
    tag: string | null,
    search: string | null = searchInput.value,
    sort: BlogSortOption | null = sortOrder.value,
) => {
    const params = buildQueryParams({ category, tag, search, sort });

    router.get(route('blogs.index'), params, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const toggleCategory = (slug: string) => {
    const nextCategory = activeCategory.value === slug ? null : slug;
    applyFilters(nextCategory, activeTag.value);
};

const toggleTag = (slug: string) => {
    const nextTag = activeTag.value === slug ? null : slug;
    applyFilters(activeCategory.value, nextTag);
};

const clearFilters = () => {
    if (!hasActiveFilters.value) {
        return;
    }

    searchInput.value = '';
    sortOrder.value = defaultSort;

    applyFilters(null, null, '', defaultSort);
};

const buildPaginationParams = (page: number) =>
    buildQueryParams({
        page,
        category: activeCategory.value,
        tag: activeTag.value,
        search: searchInput.value,
        sort: sortOrder.value,
    });

const submitSearch = () => {
    applyFilters(activeCategory.value, activeTag.value, searchInput.value, sortOrder.value);
};

const updateSort = () => {
    applyFilters(activeCategory.value, activeTag.value, searchInput.value, sortOrder.value);
};

const {
    meta: blogsMeta,
    page: paginationPage,
    rangeLabel: blogsRangeLabel,
} = useInertiaPagination({
    meta: computed(() => props.blogs.meta ?? null),
    itemsLength: computed(() => props.blogs.data?.length ?? 0),
    defaultPerPage: 9,
    itemLabel: 'blog',
    itemLabelPlural: 'blogs',
    onNavigate: (page) => {
        router.get(route('blogs.index'), buildPaginationParams(page), {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    },
});
</script>

<template>
    <Head title="Blog" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <!-- Featured Post Section -->
            <section v-if="featuredBlog">
                <Link
                    :href="route('blogs.view', { slug: featuredBlog.slug })"
                    :aria-label="`Read featured blog: ${featuredBlog.title}`"
                    class="group relative block h-64 overflow-hidden rounded-xl border bg-card shadow-sm focus:outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background sm:h-72"
                >
                    <img
                        :src="featuredBlog.cover_image || '/images/default-cover.jpg'"
                        alt="Featured blog cover"
                        fetchpriority="high"
                        decoding="async"
                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                    />
                    <div class="absolute inset-x-0 bottom-0 bg-linear-to-t from-black/85 via-black/50 to-transparent p-5 pt-16">
                        <div class="mb-2 flex flex-wrap gap-2 text-xs">
                            <span class="inline-flex items-center rounded-full bg-highlight px-3 py-1 font-semibold text-highlight-foreground">
                                Featured
                            </span>
                            <span
                                v-for="category in featuredBlog.categories"
                                :key="`featured-category-${category.id}`"
                                class="inline-flex items-center rounded-full bg-primary px-3 py-1 font-medium text-primary-foreground"
                            >
                                {{ category.name }}
                            </span>
                            <span
                                v-for="tag in featuredBlog.tags"
                                :key="`featured-tag-${tag.id}`"
                                class="inline-flex items-center rounded-full bg-black/60 px-3 py-1 font-medium text-white"
                            >
                                #{{ tag.name }}
                            </span>
                        </div>
                        <h2 class="text-xl font-semibold tracking-tight text-white sm:text-2xl">{{ featuredBlog.title }}</h2>
                        <p v-if="featuredBlog.excerpt" class="mt-1 line-clamp-2 max-w-3xl text-sm text-white/90">
                            {{ featuredBlog.excerpt }}
                        </p>
                        <p class="mt-2 text-xs text-white/80">
                            {{ formatNumber(featuredBlog.views) }} views
                            <span v-if="formatLastViewed(featuredBlog.last_viewed_at)">
                                • Last read {{ formatLastViewed(featuredBlog.last_viewed_at) }}
                            </span>
                        </p>
                        <span class="sr-only">Read more about {{ featuredBlog.title }}</span>
                    </div>
                </Link>
            </section>

            <!-- Filters -->
            <section class="space-y-4 rounded-xl border bg-card p-4 shadow-xs sm:p-5">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <h1 class="text-lg font-semibold tracking-tight">Blog</h1>
                        <p class="text-sm text-muted-foreground">Guides, announcements and release notes. Filter by category or tag.</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <a
                            :href="route('blogs.feed')"
                            rel="alternate"
                            type="application/atom+xml"
                            target="_blank"
                            class="text-sm font-medium text-primary transition hover:text-primary/80"
                        >
                            Subscribe via RSS
                        </a>
                        <Button variant="outline" size="sm" :disabled="!hasActiveFilters" @click="clearFilters"> Clear filters </Button>
                    </div>
                </div>

                <div class="space-y-3">
                    <form class="flex flex-col gap-3 md:flex-row" @submit.prevent="submitSearch">
                        <label class="flex-1 text-sm">
                            <span class="sr-only">Search blog posts</span>
                            <input
                                v-model="searchInput"
                                type="search"
                                name="search"
                                placeholder="Search blog posts"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                            />
                        </label>
                        <label class="text-sm md:w-48">
                            <span class="sr-only">Sort blog posts</span>
                            <select
                                v-model="sortOrder"
                                name="sort"
                                class="w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-hidden"
                                @change="updateSort"
                            >
                                <option value="latest">Newest first</option>
                                <option value="oldest">Oldest first</option>
                                <option value="popular">Most discussed</option>
                            </select>
                        </label>
                        <Button type="submit" class="md:w-auto" variant="default">Search</Button>
                    </form>

                    <div v-if="props.categories.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="category in props.categories"
                            :key="`category-filter-${category.id}`"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="[
                                activeCategory === category.slug
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'bg-card text-muted-foreground hover:border-primary/50 hover:text-primary',
                            ]"
                            @click="toggleCategory(category.slug)"
                        >
                            {{ category.name }}
                            <span class="text-[10px] text-muted-foreground">({{ category.count }})</span>
                        </button>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No categories available yet.</p>

                    <div v-if="props.tags.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="tag in props.tags"
                            :key="`tag-filter-${tag.id}`"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium transition-colors"
                            :class="[
                                activeTag === tag.slug
                                    ? 'border-highlight bg-highlight/20 text-foreground'
                                    : 'bg-card text-muted-foreground hover:border-highlight hover:text-foreground',
                            ]"
                            @click="toggleTag(tag.slug)"
                        >
                            #{{ tag.name }}
                            <span class="text-[10px] text-muted-foreground">({{ tag.count }})</span>
                        </button>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">No tags available yet.</p>
                </div>

                <div v-if="hasActiveFilters" class="text-xs text-muted-foreground">
                    Showing posts filtered by
                    <span v-if="activeCategory" class="font-medium text-foreground">category: {{ activeCategory }}</span>
                    <span v-if="activeCategory && activeTag"> and </span>
                    <span v-if="activeTag" class="font-medium text-foreground">tag: {{ activeTag }}</span
                    >.
                </div>
            </section>

            <!-- Pagination -->
            <div class="flex flex-col items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 shadow-xs md:flex-row">
                <div class="text-center text-sm text-muted-foreground md:text-left">
                    {{ blogsRangeLabel }}
                </div>
                <Pagination
                    v-if="hasBlogs || blogsMeta.total > 0"
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(blogsMeta.per_page, 1)"
                    :total="blogsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
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

            <!-- Blog Posts Grid -->
            <section v-if="hasBlogs">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="blog in props.blogs.data"
                        :key="blog.id"
                        class="group flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs transition duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                    >
                        <Link :href="route('blogs.view', { slug: blog.slug })" class="block">
                            <div class="relative aspect-[16/7] overflow-hidden border-b bg-muted">
                                <img
                                    :src="blog.cover_image || '/images/default-cover.jpg'"
                                    alt="Blog cover"
                                    loading="lazy"
                                    decoding="async"
                                    class="h-full w-full object-cover"
                                />
                            </div>
                        </Link>
                        <div class="flex flex-1 flex-col gap-2 p-4">
                            <Link :href="route('blogs.view', { slug: blog.slug })" class="block">
                                <h3 class="line-clamp-2 text-lg leading-snug font-semibold group-hover:text-primary">{{ blog.title }}</h3>
                            </Link>
                            <p v-if="blog.excerpt" class="line-clamp-3 flex-1 text-sm text-muted-foreground">
                                {{ blog.excerpt }}
                            </p>
                            <div v-if="blog.categories.length || blog.tags.length" class="flex flex-wrap gap-2 pt-1 text-xs">
                                <span
                                    v-for="category in blog.categories"
                                    :key="`list-category-${blog.id}-${category.id}`"
                                    class="inline-flex items-center rounded-full bg-primary/10 px-2.5 py-1 font-medium text-primary"
                                >
                                    {{ category.name }}
                                </span>
                                <span
                                    v-for="tag in blog.tags"
                                    :key="`list-tag-${blog.id}-${tag.id}`"
                                    class="inline-flex items-center rounded-full bg-muted px-2.5 py-1 font-medium text-muted-foreground"
                                >
                                    #{{ tag.name }}
                                </span>
                            </div>
                            <p class="border-t pt-3 text-xs text-muted-foreground">
                                {{ formatNumber(blog.views) }} views
                                <span v-if="formatLastViewed(blog.last_viewed_at)"> • Last read {{ formatLastViewed(blog.last_viewed_at) }} </span>
                            </p>
                        </div>
                    </article>
                </div>
            </section>

            <section v-else class="rounded-xl border border-dashed bg-card p-10 text-center text-sm text-muted-foreground">
                No blog posts to display yet. Check back soon!
            </section>

            <!-- Pagination -->
            <div class="flex flex-col items-center justify-between gap-3 rounded-xl border bg-card px-4 py-3 shadow-xs md:flex-row">
                <div class="text-center text-sm text-muted-foreground md:text-left">
                    {{ blogsRangeLabel }}
                </div>
                <Pagination
                    v-if="hasBlogs || blogsMeta.total > 0"
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(blogsMeta.per_page, 1)"
                    :total="blogsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
                        <PaginationList v-slot="{ items }" class="flex flex-wrap items-center justify-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem v-if="item.type === 'page'" :value="item.value" as-child>
                                    <Button class="h-9 w-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
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
    </AppLayout>
</template>
