<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Blog', href: '/blogs' }
];

// Expecting that the controller passes a "blogs" prop (paginated collection)
const props = defineProps({
    blogs: Object
});
</script>

<template>
    <Head title="Blog" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 space-y-6">
            <!-- Featured Post Section -->
            <section v-if="props.blogs.data && props.blogs.data.length">
                <div class="relative h-64 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <!-- Display the cover image if available -->
                    <img
                        :src="props.blogs.data[0].cover_image || '/images/default-cover.jpg'"
                        alt="Featured blog cover"
                        class="object-cover w-full h-full"
                    />
                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-4">
                        <h2 class="text-xl font-bold text-white">{{ props.blogs.data[0].title }}</h2>
                        <p class="mt-1 text-sm text-white">{{ props.blogs.data[0].excerpt }}</p>
                    </div>
                </div>
            </section>

            <!-- Blog Posts Grid -->
            <section>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                    <div v-for="blog in props.blogs.data" :key="blog.id" class="flex flex-col space-y-2">
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
        <div class="flex justify-center">
            <Pagination
                v-slot="{ page }"
                :items-per-page="props.blogs.per_page"
                :total="props.blogs.total"
                :sibling-count="1"
                show-edges
                :default-page="props.blogs.current_page"
            >
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
            </Pagination>
        </div>
    </AppLayout>
</template>
