<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forum', href: '/forum' },
];

// Dummy data for primary forum categories with subcategories
const forumCategories = [
    {
        title: 'Gaming',
        subCategories: [
            {
                title: 'PC Games',
                threadCount: 123,
                postCount: 4567,
                latestPost: {
                    title: 'Latest PC game discussion',
                    author: 'GamerOne',
                    date: '2 hours ago',
                },
            },
            {
                title: 'Console Games',
                threadCount: 89,
                postCount: 2345,
                latestPost: {
                    title: 'Upcoming console releases',
                    author: 'ConsoleFan',
                    date: '3 hours ago',
                },
            },
        ],
    },
    {
        title: 'Hardware',
        subCategories: [
            {
                title: 'PC Hardware',
                threadCount: 101,
                postCount: 500,
                latestPost: {
                    title: 'Best GPU deals',
                    author: 'TechGuru',
                    date: '1 day ago',
                },
            },
            {
                title: 'Peripherals',
                threadCount: 75,
                postCount: 300,
                latestPost: {
                    title: 'Mechanical keyboard reviews',
                    author: 'KeyMaster',
                    date: '5 hours ago',
                },
            },
        ],
    },
];

// Dummy data for the Trending Threads sidebar
const trendingThreads = [
    {
        title: 'How to build a gaming PC',
        author: 'User1',
        date: '1h ago',
        replies: 12,
        subCategory: 'PC Games',
        subCategoryLink: '/forum/pc-games',
    },
    {
        title: 'Best new indie games',
        author: 'User2',
        date: '2h ago',
        replies: 8,
        subCategory: 'PC Games',
        subCategoryLink: '/forum/pc-games',
    },
];

// Dummy data for the Latest Posts sidebar
const latestPosts = [
    {
        title: 'Upcoming hardware releases',
        author: 'User3',
        date: '30 min ago',
        replies: 3,
        subCategory: 'PC Hardware',
        subCategoryLink: '/forum/pc-hardware',
    },
    {
        title: 'Tips for game streaming',
        author: 'User4',
        date: '45 min ago',
        replies: 5,
        subCategory: 'Gaming',
        subCategoryLink: '/forum/gaming',
    },
];
</script>

<template>
    <Head title="Forum" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="p-4 space-y-6">
            <!-- Forum Header -->
            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
                <h1 class="text-2xl font-bold">Forum</h1>
                <div class="flex w-full max-w-md space-x-2">
                    <Input default-value="Search Forum"/>
                    <Button variant="secondary" class="cursor-pointer">
                        New Thread
                    </Button>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
                <!-- Main Content: Forum Categories as Cards -->
                <main class="md:col-span-3 space-y-6">
                    <div
                        v-for="(category, catIndex) in forumCategories"
                        :key="catIndex"
                        class="rounded-lg border border-sidebar-border/70 shadow hover:shadow-lg transition"
                    >
                        <!-- Card Header -->
                        <div class="relative overflow-hidden p-4 rounded-t-lg">
                            <h2 class="text-xl font-bold">{{ category.title }}</h2>
                            <PlaceholderPattern />
                        </div>
                        <!-- Card Body: Table of Subcategories -->
                        <div class="divide-y">
                            <Link
                                v-for="(sub, subIndex) in category.subCategories"
                                :key="subIndex"
                                :href="route('forum.threads', { id: sub.id })"
                                class="flex items-center p-4 hover:bg-gray-100 transition even:bg-gray-50 dark:bg-neutral-950/60 dark:even:bg-neutral-800/60 dark:hover:bg-neutral-700/60"
                            >
                                <!-- Subcategory Icon -->
                                <div class="mr-4">
                                    <div class="relative overflow-hidden h-8 w-8 rounded-full">
                                        <PlaceholderPattern />
                                    </div>
                                </div>
                                <!-- Subcategory Title -->
                                <div class="flex-1">
                                    <h3 class="font-semibold hover:underline text-red-400 dark:hover:text-red-400">{{ sub.title }}</h3>
                                </div>
                                <!-- Thread Count -->
                                <div class="w-20 text-center">
                                    <div class="font-bold">{{ sub.threadCount }}</div>
                                    <div class="text-xs text-gray-500">Threads</div>
                                </div>
                                <!-- Post Count -->
                                <div class="w-20 text-center">
                                    <div class="font-bold">{{ sub.postCount }}</div>
                                    <div class="text-xs text-gray-500">Posts</div>
                                </div>
                                <!-- Latest Post Information -->
                                <div class="w-60 text-right">
                                    <Link
                                        :href="route('forum.thread.view', { id: sub.latestPost.id })"
                                        class="font-semibold text-sm hover:underline block"
                                    >
                                        {{ sub.latestPost.title }}
                                    </Link>
                                    <div class="text-xs text-gray-400 inline-block mr-1">by {{ sub.latestPost.author }}</div>
                                    <div class="text-xs text-gray-500 inline-block">• {{ sub.latestPost.date }}</div>
                                </div>
                            </Link>
                        </div>
                    </div>
                </main>

                <!-- Sidebar -->
                <aside class="md:col-span-1 space-y-6">
                    <!-- Trending Threads -->
                    <div class="rounded-lg border border-sidebar-border/70 p-4">
                        <h2 class="mb-2 text-lg font-semibold">Trending Threads</h2>
                        <div
                            v-for="(thread, index) in trendingThreads"
                            :key="index"
                            class="py-2 border-b border-sidebar-border/70 dark:border-sidebar-border/70 hover:bg-gray-100 dark:hover:bg-neutral-700/60 transition"
                        >
                            <a :href="thread.subCategoryLink" class="block px-2">
                                <h4 class="font-semibold text-sm">{{ thread.title }}</h4>
                                <p class="text-xs text-gray-500">
                                    by {{ thread.author }} • {{ thread.date }} • {{ thread.replies }} replies
                                </p>
                                <div class="text-xs text-red-400">
                                    <a :href="thread.subCategoryLink">{{ thread.subCategory }}</a>
                                </div>
                            </a>
                        </div>
                    </div>
                    <!-- Latest Posts -->
                    <div class="rounded-lg border border-sidebar-border/70 p-4">
                        <h2 class="mb-2 text-lg font-semibold">Latest Posts</h2>
                        <div
                            v-for="(post, index) in latestPosts"
                            :key="index"
                            class="py-2 border-b border-sidebar-border/70 dark:border-sidebar-border/70 hover:bg-gray-100 dark:hover:bg-neutral-700/60 transition"
                        >
                            <a :href="post.subCategoryLink" class="block px-2">
                                <h4 class="font-semibold text-sm">{{ post.title }}</h4>
                                <p class="text-xs text-gray-500">
                                    by {{ post.author }} • {{ post.date }} • {{ post.replies }} replies
                                </p>
                                <div class="text-xs text-red-400">{{ post.subCategory }}</div>
                            </a>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
