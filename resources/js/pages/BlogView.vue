<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import Textarea from '@/components/ui/textarea/Textarea.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Skeleton from '@/components/ui/skeleton/Skeleton.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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

type CommentResponse = {
    data?: BlogComment[];
    meta?: Record<string, unknown> | null;
    links?: { next?: string | null } | null;
};

const props = defineProps<{ blog: BlogPayload }>();

const blog = computed(() => props.blog);
const page = usePage<SharedData>();
const { formatDate, fromNow } = useUserTimezone();

const currentUser = computed(() => page.props.auth?.user ?? null);
const isAuthenticated = computed(() => currentUser.value !== null);

const comments = ref<BlogComment[]>([]);
const isLoadingComments = ref(false);
const isLoadingMore = ref(false);
const loadError = ref<string | null>(null);
const isSubmittingComment = ref(false);
const submitError = ref<string | null>(null);
const newComment = ref('');
const nextPageUrl = ref<string | null>(null);

const csrfToken = ref<string>(
    document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
);

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

const commentCount = computed(() => comments.value.length);
const hasComments = computed(() => commentCount.value > 0);
const isSubmitDisabled = computed(
    () => isSubmittingComment.value || newComment.value.trim().length === 0,
);

const enhancedComments = computed(() =>
    comments.value.map((comment) => {
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

async function fetchComments(url?: string, options: { append?: boolean } = {}) {
    const { append = false } = options;

    if (!url && !blog.value?.slug) {
        comments.value = [];
        nextPageUrl.value = null;
        return;
    }

    const endpoint = url ?? route('api.blogs.comments.index', { blog: blog.value.slug });

    if (append) {
        isLoadingMore.value = true;
    } else {
        isLoadingComments.value = true;
        loadError.value = null;
    }

    try {
        const response = await fetch(endpoint, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error('Unable to load comments');
        }

        const payload = (await response.json()) as CommentResponse;
        const data = Array.isArray(payload.data) ? payload.data : [];

        comments.value = append ? [...comments.value, ...data] : data;
        const next = typeof payload.links?.next === 'string' ? payload.links.next : null;
        nextPageUrl.value = next && next.length > 0 ? next : null;
        loadError.value = null;
    } catch (error) {
        console.error(error);
        loadError.value = append
            ? "We couldn't load additional comments right now. Please try again."
            : "We couldn't load the discussion right now. Please try again.";
    } finally {
        if (append) {
            isLoadingMore.value = false;
        } else {
            isLoadingComments.value = false;
        }
    }
}

async function loadMoreComments() {
    if (!nextPageUrl.value) {
        return;
    }

    await fetchComments(nextPageUrl.value, { append: true });
}

async function postComment() {
    if (!isAuthenticated.value) {
        submitError.value = 'Please sign in to leave a comment.';
        return;
    }

    const message = newComment.value.trim();

    if (message.length === 0) {
        submitError.value = 'Please enter a comment before posting.';
        return;
    }

    isSubmittingComment.value = true;
    submitError.value = null;

    try {
        const response = await fetch(route('api.blogs.comments.store', { blog: blog.value.slug }), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken.value,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ body: message }),
        });

        if (response.status === 401) {
            submitError.value = 'Please sign in to leave a comment.';
            return;
        }

        if (response.status === 403) {
            submitError.value = 'Your account is not permitted to post comments.';
            return;
        }

        if (response.status === 422) {
            const payload = await response.json();
            const firstError = Object.values(payload.errors ?? {}).flat()[0];
            submitError.value =
                typeof firstError === 'string'
                    ? firstError
                    : 'Please fix the highlighted errors before posting.';
            return;
        }

        if (!response.ok) {
            throw new Error('Unable to post comment');
        }

        const payload = (await response.json()) as CommentResponse & { data: BlogComment };
        const newEntry = payload.data ?? (payload as unknown as BlogComment);

        comments.value = [...comments.value, newEntry];
        newComment.value = '';
    } catch (error) {
        console.error(error);
        submitError.value = 'Something went wrong while posting your comment. Please try again.';
    } finally {
        isSubmittingComment.value = false;
    }
}

onMounted(() => {
    void fetchComments();
});
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

                <div v-if="isAuthenticated" class="mb-6">
                    <h3 class="mb-2 text-lg font-semibold">Join the conversation</h3>
                    <Textarea
                        v-model="newComment"
                        placeholder="Write your comment here..."
                        class="w-full"
                    />
                    <div class="mt-3 flex flex-wrap items-center gap-3">
                        <Button :disabled="isSubmitDisabled" @click="postComment">
                            <Loader2 v-if="isSubmittingComment" class="mr-2 h-4 w-4 animate-spin" />
                            Post Comment
                        </Button>
                        <p v-if="submitError" class="text-sm text-destructive">
                            {{ submitError }}
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

                <Alert v-if="loadError" variant="warning" class="mb-6">
                    <AlertTitle>Unable to load comments</AlertTitle>
                    <AlertDescription>{{ loadError }}</AlertDescription>
                    <div class="mt-4">
                        <Button variant="outline" size="sm" @click="fetchComments">
                            Try again
                        </Button>
                    </div>
                </Alert>

                <div v-if="isLoadingComments" class="space-y-4">
                    <div v-for="index in 3" :key="index" class="flex items-start gap-4">
                        <Skeleton class="h-10 w-10 rounded-full" />
                        <div class="flex-1 space-y-2">
                            <Skeleton class="h-4 w-32" />
                            <Skeleton class="h-4 w-full" />
                            <Skeleton class="h-4 w-2/3" />
                        </div>
                    </div>
                </div>
                <div v-else-if="hasComments" class="space-y-6">
                    <div
                        v-for="comment in enhancedComments"
                        :key="comment.id"
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
                    <div v-if="nextPageUrl" class="flex justify-center">
                        <Button
                            variant="outline"
                            size="sm"
                            class="mt-2"
                            :disabled="isLoadingMore"
                            @click="loadMoreComments"
                        >
                            <Loader2 v-if="isLoadingMore" class="mr-2 h-4 w-4 animate-spin" />
                            <span>{{ isLoadingMore ? 'Loading…' : 'Load more comments' }}</span>
                        </Button>
                    </div>
                </div>
                <p v-else class="text-sm text-muted-foreground">
                    No comments yet. Be the first to share your thoughts.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
