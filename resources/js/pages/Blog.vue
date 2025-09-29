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

interface BlogSummary {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    cover_image: string | null;
    published_at: string | null;
    author: BlogAuthorSummary | null;
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Blog', href: '/blogs' }];

const props = defineProps<{
    blogs: BlogsPayload;
}>();

const hasBlogs = computed(() => (props.blogs.data?.length ?? 0) > 0);
const featuredBlog = computed(() => props.blogs.data?.[0] ?? null);

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
            { page },
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
                <div class="relative h-64 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <img
                        :src="featuredBlog.cover_image || '/images/default-cover.jpg'"
                        alt="Featured blog cover"
                        class="object-cover w-full h-full"
                    />
                    <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 via-black/40 to-transparent p-4">
                        <h2 class="text-xl font-bold text-white">{{ featuredBlog.title }}</h2>
                        <p v-if="featuredBlog.excerpt" class="mt-1 text-sm text-white line-clamp-2">
                            {{ featuredBlog.excerpt }}
                        </p>
                    </div>
                </div>
            </section>

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
                    </div>
                </div>
            </section>

            <section v-else class="text-center text-muted-foreground">
                No blog posts to display yet. Check back soon!
            </section>
        </div>
    </AppLayout>
</template>
