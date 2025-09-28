<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import { computed, ref, watch } from 'vue';
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

interface BlogSummary {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    cover_image?: string | null;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface BlogsPayload {
    data: BlogSummary[];
    meta?: Partial<PaginationMeta> | null;
}

const props = defineProps<{ blogs: BlogsPayload }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blog', href: '/blogs' },
];

const blogsData = computed(() => props.blogs?.data ?? []);

const blogsMetaFallback = computed<PaginationMeta>(() => {
    const total = blogsData.value.length;

    return {
        current_page: 1,
        last_page: 1,
        per_page: total > 0 ? total : 10,
        total,
        from: total > 0 ? 1 : null,
        to: total > 0 ? total : null,
    };
});

const blogsMeta = computed<PaginationMeta>(() => {
    const fallback = blogsMetaFallback.value;
    const meta = props.blogs?.meta ?? {};

    return {
        ...fallback,
        ...(meta as Partial<PaginationMeta>),
        current_page: Number((meta as Partial<PaginationMeta>).current_page ?? fallback.current_page),
        last_page: Number((meta as Partial<PaginationMeta>).last_page ?? fallback.last_page),
        per_page: Number((meta as Partial<PaginationMeta>).per_page ?? fallback.per_page),
        total: Number((meta as Partial<PaginationMeta>).total ?? fallback.total),
        from: (meta as Partial<PaginationMeta>).from ?? fallback.from,
        to: (meta as Partial<PaginationMeta>).to ?? fallback.to,
    };
});

const blogsPageCount = computed(() => {
    const meta = blogsMeta.value;
    const derived = Math.ceil(meta.total / Math.max(meta.per_page, 1));

    return Math.max(meta.last_page, derived || 1, 1);
});

const blogsRangeLabel = computed(() => {
    const meta = blogsMeta.value;

    if (meta.total === 0) {
        return 'No blog posts to display';
    }

    const from = meta.from ?? ((meta.current_page - 1) * meta.per_page + 1);
    const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total);
    const label = meta.total === 1 ? 'post' : 'posts';

    return `Showing ${from}-${to} of ${meta.total} ${label}`;
});

const paginationPage = ref(blogsMeta.value.current_page);

watch(
    () => blogsMeta.value.current_page,
    (page) => {
        paginationPage.value = page;
    },
);

watch(paginationPage, (page) => {
    const safePage = Math.min(Math.max(page, 1), blogsPageCount.value);

    if (safePage !== page) {
        paginationPage.value = safePage;
        return;
    }

    if (safePage === blogsMeta.value.current_page) {
        return;
    }

    router.get(route('blogs.index'), { page: safePage }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
});

const featuredBlog = computed(() => blogsData.value[0] ?? null);
</script>

<template>
    <Head title="Blog" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 space-y-6">
            <!-- Featured Post Section -->
            <section v-if="featuredBlog">
                <div class="relative h-64 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <!-- Display the cover image if available -->
                    <img :src="featuredBlog.cover_image || '/images/default-cover.jpg'" alt="Featured blog cover" class="object-cover w-full h-full" />
                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-4">
                        <h2 class="text-xl font-bold text-white">{{ featuredBlog.title }}</h2>
                        <p class="mt-1 text-sm text-white">{{ featuredBlog.excerpt }}</p>
                    </div>
                </div>
            </section>

            <!-- Blog Posts Grid -->
            <section>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                    <div v-for="blog in blogsData" :key="blog.id" class="flex flex-col space-y-2">
                        <Link :href="route('blogs.view', { slug: blog.slug })" class="block">
                            <div class="relative h-40 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                                <img :src="blog.cover_image || '/images/default-cover.jpg'" alt="Blog cover" class="object-cover w-full h-full" />
                            </div>
                            <h3 class="text-lg font-semibold">{{ blog.title }}</h3>
                        </Link>
                        <p class="text-sm text-neutral-600 dark:text-neutral-400">{{ blog.excerpt }}</p>
                    </div>
                </div>
            </section>
        </div>

        <!-- Bottom Pagination -->
        <div v-if="blogsPageCount > 1" class="flex flex-col items-center gap-3 pb-6">
            <span class="text-sm text-muted-foreground text-center">{{ blogsRangeLabel }}</span>
            <Pagination
                v-slot="{ page, pageCount }"
                v-model:page="paginationPage"
                :items-per-page="Math.max(blogsMeta.per_page, 1)"
                :total="blogsMeta.total"
                :sibling-count="1"
                show-edges
            >
                <div class="flex flex-col items-center gap-2 sm:flex-row sm:items-center sm:gap-3">
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
                            <PaginationEllipsis
                                v-else
                                :index="index"
                            />
                        </template>

                        <PaginationNext />
                        <PaginationLast />
                    </PaginationList>
                </div>
            </Pagination>
        </div>
    </AppLayout>
</template>
