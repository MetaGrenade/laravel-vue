<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
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

interface BlogFiltersPayload {
    category: string | null;
    tag: string | null;
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

const activeCategory = computed(() => props.filters?.category ?? null);
const activeTag = computed(() => props.filters?.tag ?? null);
const hasActiveFilters = computed(() => Boolean(activeCategory.value || activeTag.value));

const applyFilters = (category: string | null, tag: string | null) => {
    const params: Record<string, unknown> = {};

    if (category) {
        params.category = category;
    }

    if (tag) {
        params.tag = tag;
    }

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

    applyFilters(null, null);
};

const buildPaginationParams = (page: number) => {
    const params: Record<string, unknown> = { page };

    if (activeCategory.value) {
        params.category = activeCategory.value;
    }

    if (activeTag.value) {
        params.tag = activeTag.value;
    }

    return params;
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
        router.get(
            route('blogs.index'),
            buildPaginationParams(page),
            {
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    },
});
</script>

<template>
    <Head title="Blog" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 space-y-6">
            <!-- Featured Post Section -->
            <section v-if="featuredBlog">
                <Link
                    :href="route('blogs.view', { slug: featuredBlog.slug })"
                    :aria-label="`Read featured blog: ${featuredBlog.title}`"
                    class="group relative block h-64 overflow-hidden rounded-xl border border-sidebar-border/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background dark:border-sidebar-border"
                >
                    <img
                        :src="featuredBlog.cover_image || '/images/default-cover.jpg'"
                        alt="Featured blog cover"
                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                    />
                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent p-4">
                        <div class="flex flex-wrap gap-2 text-xs">
                            <span
                                v-for="category in featuredBlog.categories"
                                :key="`featured-category-${category.id}`"
                                class="inline-flex items-center rounded-full bg-primary/80 px-3 py-1 font-medium text-white"
                            >
                                {{ category.name }}
                            </span>
                            <span
                                v-for="tag in featuredBlog.tags"
                                :key="`featured-tag-${tag.id}`"
                                class="inline-flex items-center rounded-full bg-black/50 px-3 py-1 font-medium text-white"
                            >
                                #{{ tag.name }}
                            </span>
                        </div>
                        <h2 class="text-xl font-bold text-white">{{ featuredBlog.title }}</h2>
                        <p v-if="featuredBlog.excerpt" class="mt-1 text-sm text-white line-clamp-2">
                            {{ featuredBlog.excerpt }}
                        </p>
                        <span class="sr-only">Read more about {{ featuredBlog.title }}</span>
                    </div>
                </Link>
            </section>

            <!-- Filters -->
            <section class="space-y-4 rounded-xl border border-sidebar-border/70 bg-background/60 p-4 shadow-sm dark:border-sidebar-border">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h2 class="text-base font-semibold">Browse the library</h2>
                        <p class="text-sm text-muted-foreground">Focus on categories and tags to surface relevant posts.</p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="!hasActiveFilters"
                        @click="clearFilters"
                    >
                        Clear filters
                    </Button>
                </div>

                <div class="space-y-3">
                    <div v-if="props.categories.length" class="flex flex-wrap gap-2">
                        <button
                            v-for="category in props.categories"
                            :key="`category-filter-${category.id}`"
                            type="button"
                            class="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium transition"
                            :class="[
                                activeCategory === category.slug
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-muted-foreground/30 text-muted-foreground hover:border-primary/50 hover:text-primary',
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
                            class="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-medium transition"
                            :class="[
                                activeTag === tag.slug
                                    ? 'border-amber-500/60 bg-amber-500/10 text-amber-700 dark:text-amber-200'
                                    : 'border-muted-foreground/30 text-muted-foreground hover:border-amber-400/70 hover:text-amber-600 dark:hover:text-amber-300',
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
                    <span v-if="activeTag" class="font-medium text-foreground">tag: {{ activeTag }}</span>.
                </div>
            </section>

            <!-- Pagination -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
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
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    as-child
                                >
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                    <div v-for="blog in props.blogs.data" :key="blog.id" class="flex flex-col space-y-2">
                        <Link :href="route('blogs.view', { slug: blog.slug })" class="block">
                            <div class="relative h-40 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                <img :src="blog.cover_image || '/images/default-cover.jpg'" alt="Blog cover" class="object-cover w-full h-full" />
                            </div>
                            <h3 class="mt-3 text-lg font-semibold line-clamp-2">{{ blog.title }}</h3>
                        </Link>
                        <p v-if="blog.excerpt" class="text-sm text-neutral-600 dark:text-neutral-400 line-clamp-3">
                            {{ blog.excerpt }}
                        </p>
                        <div v-if="blog.categories.length || blog.tags.length" class="flex flex-wrap gap-2 text-xs">
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
                    </div>
                </div>
            </section>

            <section v-else class="text-center text-muted-foreground">
                No blog posts to display yet. Check back soon!
            </section>

            <!-- Pagination -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
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
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    as-child
                                >
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
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
