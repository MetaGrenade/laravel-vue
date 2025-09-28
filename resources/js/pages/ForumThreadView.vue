<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';

// Import shadcn‑vue components
import Avatar from '@/components/ui/avatar/Avatar.vue';
import Button from '@/components/ui/button/Button.vue';
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';
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
import { Textarea } from '@/components/ui/textarea'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Pin, PinOff, Ellipsis, Eye, EyeOff, Pencil, Trash2, Lock, LockOpen, Flag, MessageSquareLock
} from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

interface BoardSummary {
    title: string;
    slug: string;
    category?: {
        title: string | null;
        slug: string | null;
    } | null;
}

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
    is_locked: boolean;
    is_pinned: boolean;
    views: number;
    author: string | null;
    last_posted_at: string | null;
}

interface PostAuthor {
    id: number | null;
    nickname: string | null;
    joined_at: string | null;
    forum_posts_count: number;
    primary_role: string | null;
    avatar_url: string | null;
}

interface ThreadPost {
    id: number;
    body: string;
    created_at: string;
    edited_at: string | null;
    number: number;
    signature: string | null;
    author: PostAuthor;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface PostsPayload {
    data: ThreadPost[];
    meta?: PaginationMeta | null;
    links?: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    } | null;
}

const props = defineProps<{
    board: BoardSummary;
    thread: ThreadSummary;
    posts: PostsPayload;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const trail: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];
    if (props.board.category?.title) {
        trail.push({ title: props.board.category.title, href: '/forum' });
    }
    trail.push({ title: props.board.title, href: `/forum/${props.board.slug}` });
    trail.push({ title: props.thread.title, href: route('forum.threads.show', { board: props.board.slug, thread: props.thread.slug }) });
    return trail;
});

const postsMetaFallback = computed<PaginationMeta>(() => {
    const total = props.posts.data.length;

    return {
        current_page: 1,
        last_page: 1,
        per_page: total > 0 ? total : 10,
        total,
        from: total > 0 ? 1 : null,
        to: total > 0 ? total : null,
    };
});

const postsMeta = computed<PaginationMeta>(() => {
    return {
        ...postsMetaFallback.value,
        ...(props.posts.meta ?? {}),
    };
});

const postsPageCount = computed(() => {
    const meta = postsMeta.value;
    const derived = Math.ceil(meta.total / Math.max(meta.per_page, 1));
    return Math.max(meta.last_page, derived || 1, 1);
});

const postsRangeLabel = computed(() => {
    const meta = postsMeta.value;

    if (meta.total === 0) {
        return 'No posts to display';
    }

    const from = meta.from ?? ((meta.current_page - 1) * meta.per_page + 1);
    const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total);
    const postWord = meta.total === 1 ? 'post' : 'posts';

    return `Showing ${from}-${to} of ${meta.total} ${postWord}`;
});

const paginationPage = ref(postsMeta.value.current_page);

watch(
    () => postsMeta.value.current_page,
    (page) => {
        paginationPage.value = page;
    },
);

watch(paginationPage, (page) => {
    const safePage = Math.min(Math.max(page, 1), postsPageCount.value);

    if (safePage !== page) {
        paginationPage.value = safePage;
        return;
    }

    if (safePage === postsMeta.value.current_page) return;

    router.get(route('forum.threads.show', { board: props.board.slug, thread: props.thread.slug }), {
        page: safePage,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
});

const replyText = ref('');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Forum • ${props.thread.title}`" />
        <div class="container mx-auto p-4 space-y-8">
            <!-- Thread Title -->
            <div class="mb-4">
                <h1 id="thread_title" class="text-3xl font-bold text-green-500">
                    <Pin v-if="props.thread.is_pinned" class="h-8 w-8 inline-block" />
                    {{ props.thread.title }}
                </h1>
            </div>

            <header class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ postsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(postsMeta.per_page, 1)"
                    :total="postsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
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
                    </div>
                </Pagination>
                <div class="flex w-full max-w-md space-x-2 justify-end">
                    <Button v-if="props.thread.is_locked" variant="secondary" class="cursor-pointer text-yellow-500" disabled>
                        <Lock class="h-8 w-8" />
                        Locked
                    </Button>
                    <a href="#post_reply">
                        <Button variant="secondary" class="cursor-pointer" :disabled="props.thread.is_locked">
                            Post Reply
                        </Button>
                    </a>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="icon">
                                <Ellipsis class="h-8 w-8" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup>
                                <DropdownMenuItem class="text-orange-500">
                                    <Flag class="h-8 w-8" />
                                    <span>Report</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Mod Actions</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup>
                                <DropdownMenuItem>
                                    <Eye class="h-8 w-8" />
                                    <span>Publish</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <EyeOff class="h-8 w-8" />
                                    <span>Unpublish</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <Lock class="h-8 w-8" />
                                    <span>Lock</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <LockOpen class="h-8 w-8" />
                                    <span>Unlock</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <Pin class="h-8 w-8" />
                                    <span>Pin</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <PinOff class="h-8 w-8" />
                                    <span>Unpin</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup>
                                <DropdownMenuItem class="text-blue-500">
                                    <Pencil class="h-8 w-8" />
                                    <span>Edit Title</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem class="text-red-500">
                                <Trash2 class="h-8 w-8" />
                                <span>Delete</span>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <!-- Posts List -->
            <div class="space-y-6">
                <div
                    v-for="post in props.posts.data"
                    :key="post.id"
                    class="flex flex-col md:flex-row gap-4 rounded-xl border p-4 shadow-sm"
                >
                    <!-- Left Side: User Info -->
                    <div class="flex-shrink-0 w-full md:w-1/5 border-r pr-4">
                        <Avatar :src="post.author.avatar_url ?? undefined" alt="User avatar" class="h-24 w-24 rounded-full mb-2" />
                        <div class="font-bold text-lg">{{ post.author.nickname ?? 'Unknown' }}</div>
                        <div class="text-sm text-gray-500">{{ post.author.primary_role ?? 'Member' }}</div>
                        <div class="mt-2 text-xs text-gray-600">
                            Joined: <span class="font-medium">{{ post.author.joined_at ?? '—' }}</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-600">
                            Posts: <span class="font-medium">{{ post.author.forum_posts_count }}</span>
                        </div>
                    </div>

                    <!-- Right Side: Post Content -->
                    <div class="flex-1">
                        <div class="flex justify-between items-center border-b pb-2 mb-4">
                            <div class="text-sm text-gray-500">{{ post.created_at }}</div>
                            <div class="text-sm font-medium text-gray-500">#{{ post.number }}</div>
                        </div>
                        <!-- Post Body -->
                        <div class="prose dark:prose-dark" v-html="post.body"></div>
                        <!-- Forum Signature -->
                        <div v-if="post.signature" class="mt-4 border-t pt-2 text-xs text-gray-500">
                            {{ post.signature }}
                        </div>
                    </div>
                </div>
            </div>

            <header class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ postsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(postsMeta.per_page, 1)"
                    :total="postsMeta.total"
                    :sibling-count="1"
                    show-edges
                >
                    <div class="flex flex-col items-center gap-2 md:flex-row md:items-center md:gap-3">
                        <span class="text-sm text-muted-foreground">Page {{ page }} of {{ pageCount }}</span>
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
                    </div>
                </Pagination>
                <div class="flex w-full max-w-md justify-end">
                    <a href="#thread_title">
                        <Button variant="secondary" class="cursor-pointer">
                            Go To Top
                        </Button>
                    </a>
                </div>
            </header>

            <Alert v-if="props.thread.is_locked" variant="warning">
                <MessageSquareLock class="w-6 h-6" />
                <AlertTitle>Thread Locked</AlertTitle>
                <AlertDescription>
                    This thread has been locked by a moderator.
                </AlertDescription>
            </Alert>

            <!-- Reply Input Section -->
            <div class="mt-8 rounded-xl border p-6 shadow">
                <h2 id="post_reply" class="mb-4 text-xl font-bold">Leave a Reply</h2>
                <div class="flex flex-col gap-4">
                    <Textarea v-model="replyText" placeholder="Write your reply here..." class="w-full rounded-md" :disabled="props.thread.is_locked" />

                    <Button variant="secondary" class="cursor-pointer bg-green-500 hover:bg-green-600" :disabled="props.thread.is_locked">
                        Submit Reply
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
