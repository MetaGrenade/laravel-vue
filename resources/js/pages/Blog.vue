<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';

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

            <!-- Pagination (Placeholder Buttons) -->
            <section class="flex justify-center space-x-2">
                <button class="rounded border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">
                    Previous
                </button>
                <button class="rounded border border-gray-300 px-3 py-1 text-sm hover:bg-gray-100">
                    Next
                </button>
            </section>
        </div>
    </AppLayout>
</template>
