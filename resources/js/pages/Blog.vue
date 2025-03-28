<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
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
} from '@/components/ui/pagination'

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blog',
        href: '/blog',
    },
];
</script>

<template>
    <Head title="Blog" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 space-y-6">
            <!-- Featured Post Section -->
            <section>
                <div class="relative h-64 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                    <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black to-transparent p-4">
                        <h2 class="text-xl font-bold text-white">Featured Blog Post Title</h2>
                        <p class="mt-1 text-sm text-white">
                            A short excerpt or summary of the featured post goes here.
                        </p>
                    </div>
                </div>
            </section>

            <!-- Blog Posts Grid -->
            <section>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 md:grid-cols-3">
                    <!-- Simulated list of posts -->
                    <Link
                        v-for="n in 6"
                        :key="n"
                        :href="route('blog.view', { id: n })"
                        class="flex flex-col space-y-2 rounded-lg border p-4 hover:shadow transition"
                    >
                        <div class="relative h-40 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                            <PlaceholderPattern />
                        </div>
                        <h3 class="text-lg font-semibold">Blog Post Title {{ n }}</h3>
                        <p class="text-sm text-neutral-600 dark:text-neutral-400">
                            Lorem ipsum dolor sit amet, consectetur adipiscing elit. Praesent ut...
                        </p>
                    </Link>
                </div>
            </section>
        </div>

        <!-- Bottom Pagination -->
        <div class="flex justify-center">
            <Pagination v-slot="{ page }" :items-per-page="10" :total="100" :sibling-count="1" show-edges :default-page="1">
                <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                    <PaginationFirst />
                    <PaginationPrev />

                    <template v-for="(item, index) in items">
                        <PaginationListItem v-if="item.type === 'page'" :key="index" :value="item.value" as-child>
                            <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                {{ item.value }}
                            </Button>
                        </PaginationListItem>
                        <PaginationEllipsis v-else :key="item.type" :index="index" />
                    </template>

                    <PaginationNext />
                    <PaginationLast />
                </PaginationList>
            </Pagination>
        </div>
    </AppLayout>
</template>
