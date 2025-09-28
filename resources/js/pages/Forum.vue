<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';

interface ForumBoardSummary {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    thread_count: number;
    post_count: number;
    latest_thread: {
        id: number;
        title: string;
        slug: string;
        board_slug: string;
        author: string | null;
        last_reply_author: string | null;
        last_reply_at: string | null;
    } | null;
}

interface ForumCategorySummary {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    boards: ForumBoardSummary[];
}

interface TrendingThreadSummary {
    id: number;
    title: string;
    slug: string;
    board: {
        slug: string;
        title: string;
        category_title?: string | null;
    };
    author: string | null;
    views: number;
    replies: number;
    last_reply_at: string | null;
}

interface LatestPostSummary {
    id: number;
    title: string;
    thread_slug: string;
    board_slug: string;
    board_title: string;
    author: string | null;
    created_at: string;
    thread_id: number;
}

const props = defineProps<{
    categories: ForumCategorySummary[];
    trendingThreads: TrendingThreadSummary[];
    latestPosts: LatestPostSummary[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forum', href: '/forum' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forum" />
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
                        v-for="category in props.categories"
                        :key="category.id"
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
                                v-for="board in category.boards"
                                :key="board.id"
                                :href="route('forum.boards.show', { board: board.slug })"
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
                                    <h3 class="font-semibold hover:underline text-green-400 dark:hover:text-green-400">{{ board.title }}</h3>
                                </div>
                                <!-- Thread Count -->
                                <div class="w-20 text-center">
                                    <div class="font-bold">{{ board.thread_count }}</div>
                                    <div class="text-xs text-gray-500">Threads</div>
                                </div>
                                <!-- Post Count -->
                                <div class="w-20 text-center">
                                    <div class="font-bold">{{ board.post_count }}</div>
                                    <div class="text-xs text-gray-500">Posts</div>
                                </div>
                                <!-- Latest Post Information -->
                                <div class="w-60 text-right">
                                    <template v-if="board.latest_thread">
                                        <Link
                                            :href="route('forum.threads.show', { board: board.slug, thread: board.latest_thread.slug })"
                                            class="font-semibold text-sm hover:underline block"
                                        >
                                            {{ board.latest_thread.title }}
                                        </Link>
                                        <div class="text-xs text-gray-400 inline-block mr-1">by {{ board.latest_thread.last_reply_author ?? board.latest_thread.author ?? '—' }}</div>
                                        <div class="text-xs text-gray-500 inline-block">• {{ board.latest_thread.last_reply_at ?? 'No replies yet' }}</div>
                                    </template>
                                    <template v-else>
                                        <div class="text-xs text-gray-400">No threads yet</div>
                                    </template>
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
                            v-for="thread in props.trendingThreads"
                            :key="thread.id"
                            class="py-2 border-b border-sidebar-border/70 dark:border-sidebar-border/70 hover:bg-gray-100 dark:hover:bg-neutral-700/60 transition"
                        >
                            <Link :href="route('forum.threads.show', { board: thread.board.slug, thread: thread.slug })" class="block px-2">
                                <h4 class="font-semibold text-sm">{{ thread.title }}</h4>
                                <p class="text-xs text-gray-500">
                                    by {{ thread.author ?? 'Unknown' }}
                                    <span v-if="thread.last_reply_at">• {{ thread.last_reply_at }}</span>
                                    • {{ thread.replies }} replies
                                </p>
                                <div class="text-xs text-green-400">
                                    {{ thread.board.category_title ?? thread.board.title }}
                                </div>
                            </Link>
                        </div>
                    </div>
                    <!-- Latest Posts -->
                    <div class="rounded-lg border border-sidebar-border/70 p-4">
                        <h2 class="mb-2 text-lg font-semibold">Latest Posts</h2>
                        <div
                            v-for="post in props.latestPosts"
                            :key="post.id"
                            class="py-2 border-b border-sidebar-border/70 dark:border-sidebar-border/70 hover:bg-gray-100 dark:hover:bg-neutral-700/60 transition"
                        >
                            <Link :href="route('forum.threads.show', { board: post.board_slug, thread: post.thread_slug })" class="block px-2">
                                <h4 class="font-semibold text-sm">{{ post.title }}</h4>
                                <p class="text-xs text-gray-500">
                                    by {{ post.author ?? 'Unknown' }} • {{ post.created_at }}
                                </p>
                                <div class="text-xs text-green-400">{{ post.board_title }}</div>
                            </Link>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
