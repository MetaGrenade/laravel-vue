<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import { computed } from 'vue';
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
import { useInertiaPagination, type PaginationPayload } from '@/composables/useInertiaPagination';

interface BlogSummary {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    cover_image?: string | null;
}

type BlogsPayload = PaginationPayload<BlogSummary>;

const props = defineProps<{ blogs: BlogsPayload }>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blog', href: '/blogs' },
];

const blogsPagination = useInertiaPagination(() => props.blogs, {
    fallbackPerPage: 10,
    labels: {
        singular: 'post',
        plural: 'posts',
        empty: 'No blog posts to display',
    },
    onNavigate: (page) => {
        router.get(route('blogs.index'), { page }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    },
});

const paginationPage = blogsPagination.page;
const blogsMeta = blogsPagination.meta;
const blogsHasMultiplePages = blogsPagination.hasMultiplePages;
const blogsRangeLabel = blogsPagination.rangeLabel;
const blogsItemsPerPage = blogsPagination.itemsPerPage;
const blogsData = blogsPagination.items;

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
        <div v-if="blogsHasMultiplePages" class="flex flex-col items-center gap-3 pb-6">
            <span class="text-sm text-muted-foreground text-center">{{ blogsRangeLabel }}</span>
            <Pagination
                v-slot="{ page, pageCount }"
                v-model:page="paginationPage"
                :items-per-page="blogsItemsPerPage"
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
