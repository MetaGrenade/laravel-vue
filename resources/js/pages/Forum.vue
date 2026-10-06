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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forum" />
        <div class="space-y-6 p-4">
            <!-- Forum Header -->
            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
                <h1 class="text-2xl font-bold">Forum</h1>
                <div class="flex w-full max-w-md space-x-2">
                    <Input default-value="Search Forum" />
                    <Button variant="secondary" class="cursor-pointer"> New Thread </Button>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
                <!-- Main Content: Forum Categories as Cards -->
                <main class="space-y-6 md:col-span-3">
                    <template v-if="props.categories.length">
                        <div
                            v-for="category in props.categories"
                            :key="category.id"
                            class="rounded-lg border border-sidebar-border/70 shadow-sm transition hover:shadow-lg"
                        >
                            <!-- Card Header -->
                            <div class="relative overflow-hidden rounded-t-lg p-4">
                                <h2 class="text-xl font-bold">{{ category.title }}</h2>
                                <PlaceholderPattern />
                            </div>
                            <!-- Card Body: Table of Subcategories -->
                            <div class="divide-y">
                                <template v-if="category.boards.length">
                                    <Link
                                        v-for="board in category.boards"
                                        :key="board.id"
                                        :href="route('forum.boards.show', { board: board.slug })"
                                        class="group flex items-center gap-4 p-4 transition-colors even:bg-muted/40 hover:bg-muted"
                                    >
                                        <!-- Subcategory Icon -->
                                        <div class="shrink-0">
                                            <div class="relative h-8 w-8 overflow-hidden rounded-full">
                                                <PlaceholderPattern />
                                            </div>
                                        </div>
                                        <!-- Subcategory Title -->
                                        <div class="min-w-0 flex-1">
                                            <h3 class="truncate font-semibold group-hover:text-primary">{{ board.title }}</h3>
                                        </div>
                                        <!-- Thread Count -->
                                        <div class="hidden w-20 shrink-0 text-center sm:block">
                                            <div class="font-bold">{{ board.thread_count }}</div>
                                            <div class="text-xs text-muted-foreground">Threads</div>
                                        </div>
                                        <!-- Post Count -->
                                        <div class="hidden w-20 shrink-0 text-center sm:block">
                                            <div class="font-bold">{{ board.post_count }}</div>
                                            <div class="text-xs text-muted-foreground">Posts</div>
                                        </div>
                                        <!-- Latest Post Information -->
                                        <div class="hidden w-60 shrink-0 text-right md:block">
                                            <template v-if="board.latest_thread">
                                                <Link
                                                    :href="route('forum.threads.show', { board: board.slug, thread: board.latest_thread.slug })"
                                                    class="block text-sm font-semibold hover:underline"
                                                >
                                                    {{ board.latest_thread.title }}
                                                </Link>
                                                <div class="mr-1 inline-block text-xs text-muted-foreground">
                                                    by {{ board.latest_thread.last_reply_author ?? board.latest_thread.author ?? '—' }}
                                                </div>
                                                <div class="inline-block text-xs text-muted-foreground">
                                                    • {{ board.latest_thread.last_reply_at ?? 'No replies yet' }}
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div class="text-xs text-muted-foreground">No threads yet</div>
                                            </template>
                                        </div>
                                    </Link>
                                </template>
                                <p v-else class="p-4 text-sm text-muted-foreground">No boards have been created for this category yet.</p>
                            </div>
                        </div>
                    </template>
                    <div v-else class="rounded-lg border border-dashed border-sidebar-border/70 p-8 text-center text-sm text-muted-foreground">
                        No forum categories are available yet. Run the forum demo seeder or create categories in the admin panel to get started.
                    </div>
                </main>

                <!-- Sidebar -->
                <aside class="space-y-6 md:col-span-1">
                    <!-- Trending Threads -->
                    <div class="rounded-lg border border-sidebar-border/70 p-4">
                        <h2 class="mb-2 text-lg font-semibold">Trending Threads</h2>
                        <template v-if="props.trendingThreads.length">
                            <div
                                v-for="thread in props.trendingThreads"
                                :key="thread.id"
                                class="border-b border-sidebar-border/70 py-2 transition hover:bg-muted dark:border-sidebar-border/70"
                            >
                                <Link :href="route('forum.threads.show', { board: thread.board.slug, thread: thread.slug })" class="block px-2">
                                    <h4 class="text-sm font-semibold">{{ thread.title }}</h4>
                                    <p class="text-xs text-muted-foreground">
                                        by {{ thread.author ?? 'Unknown' }}
                                        <span v-if="thread.last_reply_at">• {{ thread.last_reply_at }}</span>
                                        • {{ thread.replies }} replies
                                    </p>
                                    <div class="text-xs text-primary">
                                        {{ thread.board.category_title ?? thread.board.title }}
                                    </div>
                                </Link>
                            </div>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">No trending threads yet.</p>
                    </div>
                    <!-- Latest Posts -->
                    <div class="rounded-lg border border-sidebar-border/70 p-4">
                        <h2 class="mb-2 text-lg font-semibold">Latest Posts</h2>
                        <template v-if="props.latestPosts.length">
                            <div
                                v-for="post in props.latestPosts"
                                :key="post.id"
                                class="border-b border-sidebar-border/70 py-2 transition hover:bg-muted dark:border-sidebar-border/70"
                            >
                                <Link :href="route('forum.threads.show', { board: post.board_slug, thread: post.thread_slug })" class="block px-2">
                                    <h4 class="text-sm font-semibold">{{ post.title }}</h4>
                                    <p class="text-xs text-muted-foreground">by {{ post.author ?? 'Unknown' }} • {{ post.created_at }}</p>
                                    <div class="text-xs text-primary">{{ post.board_title }}</div>
                                </Link>
                            </div>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">No posts have been made yet.</p>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
