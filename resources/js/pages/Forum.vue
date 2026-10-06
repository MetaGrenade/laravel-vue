<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Clock, Flame, MessageSquare, MessagesSquare, Search } from '@lucide/vue';
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
            <!-- Forum header -->
            <header class="flex flex-col justify-between gap-4 rounded-xl border bg-card p-5 shadow-xs md:flex-row md:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <MessagesSquare class="size-5" />
                    </span>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Community forum</h1>
                        <p class="text-sm text-muted-foreground">Ask questions, share ideas and follow the conversations that matter to you.</p>
                    </div>
                </div>
                <form :action="route('search.results')" method="get" class="flex w-full max-w-md gap-2" role="search">
                    <input type="hidden" name="types[]" value="forum_threads" />
                    <label class="flex-1">
                        <span class="sr-only">Search the forum</span>
                        <Input name="q" type="search" placeholder="Search threads" minlength="2" />
                    </label>
                    <Button type="submit">
                        <Search />
                        Search
                    </Button>
                </form>
            </header>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-4">
                <!-- Main Content: Forum Categories as Cards -->
                <main class="space-y-6 md:col-span-3">
                    <template v-if="props.categories.length">
                        <section
                            v-for="category in props.categories"
                            :key="category.id"
                            class="overflow-hidden rounded-xl border bg-card shadow-xs transition-shadow hover:shadow-md"
                        >
                            <!-- Card Header -->
                            <div class="relative overflow-hidden border-b bg-muted/40 px-5 py-4">
                                <div class="pointer-events-none absolute inset-0 bg-stripes opacity-70" aria-hidden="true" />
                                <h2 class="relative text-lg font-semibold tracking-tight">{{ category.title }}</h2>
                                <p v-if="category.description" class="relative mt-0.5 text-sm text-muted-foreground">{{ category.description }}</p>
                            </div>
                            <!-- Card Body: Table of Subcategories -->
                            <div class="divide-y">
                                <template v-if="category.boards.length">
                                    <Link
                                        v-for="board in category.boards"
                                        :key="board.id"
                                        :href="route('forum.boards.show', { board: board.slug })"
                                        class="group flex items-center gap-4 bg-card p-4 transition-colors hover:bg-muted/60"
                                    >
                                        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                                            <MessageSquare class="size-4" />
                                        </span>
                                        <div class="min-w-0 flex-1">
                                            <h3 class="truncate font-semibold group-hover:text-primary">{{ board.title }}</h3>
                                            <p v-if="board.description" class="truncate text-xs text-muted-foreground">{{ board.description }}</p>
                                        </div>
                                        <div class="hidden w-20 shrink-0 text-center sm:block">
                                            <div class="font-semibold tabular-nums">{{ board.thread_count }}</div>
                                            <div class="text-xs text-muted-foreground">Threads</div>
                                        </div>
                                        <div class="hidden w-20 shrink-0 text-center sm:block">
                                            <div class="font-semibold tabular-nums">{{ board.post_count }}</div>
                                            <div class="text-xs text-muted-foreground">Posts</div>
                                        </div>
                                        <div class="hidden w-60 shrink-0 text-right md:block">
                                            <template v-if="board.latest_thread">
                                                <span class="block truncate text-sm font-medium">{{ board.latest_thread.title }}</span>
                                                <span class="block truncate text-xs text-muted-foreground">
                                                    by {{ board.latest_thread.last_reply_author ?? board.latest_thread.author ?? '—' }} •
                                                    {{ board.latest_thread.last_reply_at ?? 'No replies yet' }}
                                                </span>
                                            </template>
                                            <span v-else class="text-xs text-muted-foreground">No threads yet</span>
                                        </div>
                                    </Link>
                                </template>
                                <p v-else class="p-4 text-sm text-muted-foreground">No boards have been created for this category yet.</p>
                            </div>
                        </section>
                    </template>
                    <div v-else class="rounded-xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">
                        No forum categories are available yet. Run the forum demo seeder or create categories in the admin panel to get started.
                    </div>
                </main>

                <!-- Sidebar -->
                <aside class="space-y-6 md:col-span-1">
                    <section class="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <h2 class="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                            <Flame class="size-4 text-highlight" />
                            Trending threads
                        </h2>
                        <ul v-if="props.trendingThreads.length" class="divide-y">
                            <li v-for="thread in props.trendingThreads" :key="thread.id">
                                <Link
                                    :href="route('forum.threads.show', { board: thread.board.slug, thread: thread.slug })"
                                    class="block px-4 py-3 transition-colors hover:bg-muted/60"
                                >
                                    <h3 class="text-sm leading-snug font-medium">{{ thread.title }}</h3>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        by {{ thread.author ?? 'Unknown' }}
                                        <span v-if="thread.last_reply_at">• {{ thread.last_reply_at }}</span>
                                        • {{ thread.replies }} {{ thread.replies === 1 ? 'reply' : 'replies' }}
                                    </p>
                                    <span class="mt-1 inline-block text-xs font-medium text-primary">
                                        {{ thread.board.category_title ?? thread.board.title }}
                                    </span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="p-4 text-sm text-muted-foreground">No trending threads yet.</p>
                    </section>

                    <section class="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <h2 class="flex items-center gap-2 border-b px-4 py-3 text-sm font-semibold">
                            <Clock class="size-4 text-primary" />
                            Latest posts
                        </h2>
                        <ul v-if="props.latestPosts.length" class="divide-y">
                            <li v-for="post in props.latestPosts" :key="post.id">
                                <Link
                                    :href="route('forum.threads.show', { board: post.board_slug, thread: post.thread_slug })"
                                    class="block px-4 py-3 transition-colors hover:bg-muted/60"
                                >
                                    <h3 class="text-sm leading-snug font-medium">{{ post.title }}</h3>
                                    <p class="mt-1 text-xs text-muted-foreground">by {{ post.author ?? 'Unknown' }} • {{ post.created_at }}</p>
                                    <span class="mt-1 inline-block text-xs font-medium text-primary">{{ post.board_title }}</span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="p-4 text-sm text-muted-foreground">No posts have been made yet.</p>
                    </section>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>
