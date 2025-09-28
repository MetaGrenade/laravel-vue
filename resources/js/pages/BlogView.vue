<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import Textarea from '@/components/ui/textarea/Textarea.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Loader2, MessageSquare, Share2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';
import type { BlogComment, SharedData } from '@/types';

type BlogAuthor = {
    id?: number;
    name?: string | null;
    nickname?: string | null;
    avatar?: string | null;
};

type BlogPayload = {
    id: number;
    title: string;
    slug: string;
    excerpt?: string | null;
    body: string;
    published_at?: string | null;
    user?: BlogAuthor | null;
};

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface CommentsPayload {
    data: BlogComment[];
    meta?: Partial<PaginationMeta> | null;
}

const props = defineProps<{ blog: BlogPayload; comments: CommentsPayload }>();

const blog = computed(() => props.blog);
const page = usePage<SharedData>();
const { formatDate, fromNow } = useUserTimezone();

const currentUser = computed(() => page.props.auth?.user ?? null);
const isAuthenticated = computed(() => currentUser.value !== null);

const flashMessages = computed(() => (page.props as SharedData)?.flash ?? {});

const successMessage = computed(() => {
    const flash = flashMessages.value as Record<string, unknown>;

    return typeof flash?.success === 'string' ? flash.success : null;
});

const errorMessage = computed(() => {
    const flash = flashMessages.value as Record<string, unknown>;

    return typeof flash?.error === 'string' ? flash.error : null;
});

const authorName = computed(() => {
    const author = blog.value.user;

    if (!author) {
        return 'Unknown author';
    }

    return author.name ?? author.nickname ?? 'Unknown author';
});

const publishedAt = computed(() => {
    if (!blog.value.published_at) {
        return null;
    }

    return formatDate(blog.value.published_at, 'MMMM D, YYYY');
});

const commentsData = computed(() => props.comments?.data ?? []);

const commentsMetaFallback = computed<PaginationMeta>(() => {
    const total = commentsData.value.length;

    return {
        current_page: 1,
        last_page: 1,
        per_page: total > 0 ? total : 10,
        total,
        from: total > 0 ? 1 : null,
        to: total > 0 ? total : null,
    };
});

const commentsMeta = computed<PaginationMeta>(() => {
    const fallback = commentsMetaFallback.value;
    const meta = props.comments?.meta ?? {};

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

const commentsPageCount = computed(() => {
    const meta = commentsMeta.value;
    const derived = Math.ceil(meta.total / Math.max(meta.per_page, 1));

    return Math.max(meta.last_page, derived || 1, 1);
});

const commentsRangeLabel = computed(() => {
    const meta = commentsMeta.value;

    if (meta.total === 0) {
        return 'No comments to display';
    }

    const from = meta.from ?? ((meta.current_page - 1) * meta.per_page + 1);
    const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total);
    const label = meta.total === 1 ? 'comment' : 'comments';

    return `Showing ${from}-${to} of ${meta.total} ${label}`;
});

const commentCount = computed(() => commentsMeta.value.total);
const hasComments = computed(() => commentsData.value.length > 0);
const hasMultiplePages = computed(() => commentsPageCount.value > 1);

const paginationPage = ref(commentsMeta.value.current_page);

watch(
    () => commentsMeta.value.current_page,
    (pageNumber) => {
        paginationPage.value = pageNumber;
    },
);

watch(paginationPage, (pageNumber) => {
    const safePage = Math.min(Math.max(pageNumber, 1), commentsPageCount.value);

    if (safePage !== pageNumber) {
        paginationPage.value = safePage;
        return;
    }

    if (safePage === commentsMeta.value.current_page) {
        return;
    }

    router.get(
        route('blogs.view', { slug: blog.value.slug }),
        { page: safePage },
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
});

const enhancedComments = computed(() =>
    commentsData.value.map((comment) => {
        const author = comment.user;
        const name = author?.nickname ?? 'Unknown user';

        return {
            ...comment,
            authorName: name,
            initials: getInitials(name),
            absolute: comment.created_at
                ? formatDate(comment.created_at, 'MMMM D, YYYY h:mm A')
                : '',
            relative: comment.created_at ? fromNow(comment.created_at) : '',
        };
    }),
);

function getInitials(name: string): string {
    if (!name) {
        return '??';
    }

    return name
        .split(' ')
        .map((segment) => segment.charAt(0))
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

const commentForm = useForm({
    body: '',
    page: commentsMeta.value.current_page,
});

watch(
    () => commentsMeta.value.current_page,
    (pageNumber) => {
        commentForm.page = pageNumber;
    },
);

const isSubmitDisabled = computed(
    () => commentForm.processing || commentForm.body.trim().length === 0,
);

watch(
    () => commentForm.body,
    () => {
        if (commentForm.errors.body) {
            commentForm.clearErrors('body');
        }
    },
);

const submitComment = () => {
    if (!isAuthenticated.value || commentForm.processing) {
        return;
    }

    commentForm.page = paginationPage.value;

    commentForm.post(route('blogs.comments.store', { slug: blog.value.slug }), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onSuccess: () => {
            commentForm.reset('body');
        },
    });
};
</script>

<template>
    <AppLayout>
        <Head :title="blog.title" />
        <div class="container mx-auto px-4 py-8">
            <!-- Blog Post Content -->
            <div class="mb-8 rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
                <h1 class="mb-3 text-3xl font-bold">{{ blog.title }}</h1>
                <div class="mb-4 text-sm text-gray-500 dark:text-gray-400">
                    <span>By <span class="font-medium text-foreground">{{ authorName }}</span></span>
                    <span v-if="publishedAt"> | Published on {{ publishedAt }}</span>
                </div>
                <p v-if="blog.excerpt" class="mb-6 text-base text-gray-600 dark:text-gray-300">
                    {{ blog.excerpt }}
                </p>
                <div class="prose max-w-none" v-html="blog.body"></div>
            </div>

            <!-- Share Section -->
            <div class="mb-8 flex items-center justify-between rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                <span class="text-lg font-semibold">Share this post:</span>
                <div class="flex space-x-2">
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> Facebook
                    </Button>
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> Twitter
                    </Button>
                    <Button variant="ghost" class="flex items-center">
                        <Share2 class="mr-1 h-4 w-4" /> LinkedIn
                    </Button>
                </div>
            </div>

            <!-- Comments Section -->
            <div class="rounded-xl border border-sidebar-border/70 p-6 shadow dark:border-sidebar-border">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2">
                        <MessageSquare class="h-5 w-5 text-muted-foreground" />
                        <h2 class="text-2xl font-bold">Comments</h2>
                    </div>
                    <span class="text-sm text-muted-foreground">
                        {{ commentCount }} {{ commentCount === 1 ? 'comment' : 'comments' }}
                    </span>
                </div>

                <Alert v-if="successMessage" variant="default" class="mb-6">
                    <AlertTitle>Success</AlertTitle>
                    <AlertDescription>{{ successMessage }}</AlertDescription>
                </Alert>
                <Alert v-else-if="errorMessage" variant="destructive" class="mb-6">
                    <AlertTitle>Something went wrong</AlertTitle>
                    <AlertDescription>{{ errorMessage }}</AlertDescription>
                </Alert>

                <div v-if="isAuthenticated" class="mb-6">
                    <h3 class="mb-2 text-lg font-semibold">Join the conversation</h3>
                    <Textarea
                        v-model="commentForm.body"
                        placeholder="Write your comment here..."
                        class="w-full"
                        :disabled="commentForm.processing"
                    />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <Button :disabled="isSubmitDisabled" @click="submitComment">
                            <Loader2 v-if="commentForm.processing" class="mr-2 h-4 w-4 animate-spin" />
                            Post Comment
                        </Button>
                        <p v-if="commentForm.errors.body" class="text-sm text-destructive">
                            {{ commentForm.errors.body }}
                        </p>
                    </div>
                </div>
                <div
                    v-else
                    class="mb-6 rounded-lg border border-dashed border-sidebar-border/70 p-4 dark:border-sidebar-border"
                >
                    <h3 class="mb-2 text-lg font-semibold text-foreground">Sign in to join the discussion</h3>
                    <p class="mb-3 text-sm text-muted-foreground">
                        You need an account to leave a comment.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Log in
                        </Link>
                        <Link
                            :href="route('register')"
                            class="inline-flex items-center rounded-md border border-input px-4 py-2 text-sm font-medium text-foreground hover:bg-accent hover:text-accent-foreground"
                        >
                            Create an account
                        </Link>
                    </div>
                </div>

                <div v-if="hasComments" class="space-y-6">
                    <div
                        v-for="comment in enhancedComments"
                        :key="comment.id"
                        :id="`comment-${comment.id}`"
                        class="flex gap-4 border-b border-sidebar-border/70 pb-6 last:border-b-0 last:pb-0 dark:border-sidebar-border"
                    >
                        <Avatar size="sm">
                            <AvatarImage
                                v-if="comment.user?.avatar"
                                :src="comment.user?.avatar ?? undefined"
                                :alt="comment.authorName"
                            />
                            <AvatarFallback>{{ comment.initials }}</AvatarFallback>
                        </Avatar>
                        <div class="flex-1 space-y-2">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <span class="text-sm font-semibold text-foreground">{{ comment.authorName }}</span>
                                <span
                                    v-if="comment.relative"
                                    class="text-xs text-muted-foreground"
                                    :title="comment.absolute"
                                >
                                    {{ comment.relative }}
                                </span>
                            </div>
                            <p class="whitespace-pre-line text-sm leading-relaxed text-foreground">
                                {{ comment.body }}
                            </p>
                        </div>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No comments yet. Be the first to share your thoughts.
                </p>

                <div v-if="hasMultiplePages" class="mt-6 flex flex-col items-center gap-3">
                    <span class="text-sm text-muted-foreground text-center">{{ commentsRangeLabel }}</span>
                    <Pagination
                        v-slot="{ page, pageCount }"
                        v-model:page="paginationPage"
                        :items-per-page="Math.max(commentsMeta.per_page, 1)"
                        :total="commentsMeta.total"
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
            </div>
        </div>
    </AppLayout>
</template>
