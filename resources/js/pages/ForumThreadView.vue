<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
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
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
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
    Pin,
    PinOff,
    Ellipsis,
    Eye,
    EyeOff,
    Pencil,
    Trash2,
    Lock,
    LockOpen,
    Flag,
    MessageSquareLock,
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

interface ThreadPermissions {
    canModerate: boolean;
    canEdit: boolean;
    canReport: boolean;
    canReply: boolean;
}

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
    is_locked: boolean;
    is_pinned: boolean;
    is_published: boolean;
    views: number;
    author: string | null;
    last_posted_at: string | null;
    permissions: ThreadPermissions;
}

interface PostAuthor {
    id: number | null;
    nickname: string | null;
    joined_at: string | null;
    forum_posts_count: number;
    primary_role: string | null;
    avatar_url: string | null;
}

interface PostPermissions {
    canReport: boolean;
    canEdit: boolean;
    canDelete: boolean;
    canModerate: boolean;
}

interface ThreadPost {
    id: number;
    body: string;
    body_raw: string;
    created_at: string;
    edited_at: string | null;
    number: number;
    signature: string | null;
    author: PostAuthor;
    permissions: PostPermissions;
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

interface ReportReasonOption {
    value: string;
    label: string;
    description?: string | null;
}

const props = defineProps<{
    board: BoardSummary;
    thread: ThreadSummary;
    posts: PostsPayload;
    reportReasons: ReportReasonOption[];
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

const threadPermissions = computed(() => props.thread.permissions);
const reportReasons = computed(() => props.reportReasons ?? []);
const defaultReportReason = computed(() => reportReasons.value[0]?.value ?? '');
const hasReportReasons = computed(() => reportReasons.value.length > 0);

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
const threadActionLoading = ref(false);
const activePostActionId = ref<number | null>(null);
const threadReportDialogOpen = ref(false);
const postReportDialogOpen = ref(false);
const postReportTarget = ref<ThreadPost | null>(null);

const threadReportForm = useForm({
    reason_category: '',
    reason: '',
    evidence_url: '',
    page: postsMeta.value.current_page,
});

const postReportForm = useForm({
    reason_category: '',
    reason: '',
    evidence_url: '',
    page: postsMeta.value.current_page,
});

const selectedThreadReason = computed(() =>
    reportReasons.value.find((option) => option.value === threadReportForm.reason_category) ?? null,
);

const selectedPostReason = computed(() =>
    reportReasons.value.find((option) => option.value === postReportForm.reason_category) ?? null,
);

watch(
    () => postsMeta.value.current_page,
    (page) => {
        paginationPage.value = page;
        threadReportForm.page = page;
        postReportForm.page = page;
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

watch(threadReportDialogOpen, (open) => {
    if (open) {
        if (!threadReportForm.reason_category && defaultReportReason.value) {
            threadReportForm.reason_category = defaultReportReason.value;
        }
    } else {
        threadReportForm.reset('reason_category', 'reason', 'evidence_url');
        threadReportForm.clearErrors();
    }
});

watch(postReportDialogOpen, (open) => {
    if (open) {
        if (!postReportForm.reason_category && defaultReportReason.value) {
            postReportForm.reason_category = defaultReportReason.value;
        }
    } else {
        postReportTarget.value = null;
        postReportForm.reset('reason_category', 'reason', 'evidence_url');
        postReportForm.clearErrors();
    }
});

const performThreadAction = (
    method: 'put' | 'post',
    routeName: string,
    payload: Record<string, unknown> = {},
) => {
    threadActionLoading.value = true;

    const url = route(routeName, { board: props.board.slug, thread: props.thread.slug });
    const data = {
        ...payload,
        redirect_to_thread: true,
        page: postsMeta.value.current_page,
    };

    const options = {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => {
            threadActionLoading.value = false;
        },
    } as const;

    if (method === 'post') {
        router.post(url, data, options);
    } else {
        router.put(url, data, options);
    }
};
const openThreadReportDialog = () => {
    if (!threadPermissions.value?.canReport || !hasReportReasons.value) {
        return;
    }

    threadReportDialogOpen.value = true;
};

const submitThreadReport = () => {
    if (!threadPermissions.value?.canReport || threadReportForm.processing || !hasReportReasons.value) {
        return;
    }

    threadReportForm.page = postsMeta.value.current_page;

    threadReportForm.post(route('forum.threads.report', { board: props.board.slug, thread: props.thread.slug }), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onSuccess: () => {
            threadReportDialogOpen.value = false;
        },
    });
};

const publishThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.publish');
};

const unpublishThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unpublish');
};

const lockThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.lock');
};

const unlockThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unlock');
};

const pinThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.pin');
};

const unpinThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    performThreadAction('put', 'forum.threads.unpin');
};

const renameThread = () => {
    if (!threadPermissions.value?.canEdit || threadActionLoading.value) {
        return;
    }

    const newTitle = window.prompt('Update thread title', props.thread.title);

    if (newTitle === null) {
        return;
    }

    const trimmed = newTitle.trim();

    if (trimmed === '' || trimmed === props.thread.title) {
        return;
    }

    performThreadAction('put', 'forum.threads.update', { title: trimmed });
};

const deleteThread = () => {
    if (!threadPermissions.value?.canModerate || threadActionLoading.value) {
        return;
    }

    if (!window.confirm(`Are you sure you want to delete "${props.thread.title}"? This action cannot be undone.`)) {
        return;
    }

    threadActionLoading.value = true;

    const url = route('forum.threads.destroy', { board: props.board.slug, thread: props.thread.slug });

    router.delete(url, {}, {
        preserveScroll: false,
        preserveState: false,
        onFinish: () => {
            threadActionLoading.value = false;
        },
    });
};

const performPostAction = (
    post: ThreadPost,
    method: 'put' | 'delete' | 'post',
    routeName: string,
    payload: Record<string, unknown> = {},
) => {
    activePostActionId.value = post.id;

    const url = route(routeName, { board: props.board.slug, thread: props.thread.slug, post: post.id });
    const data = {
        ...payload,
        page: postsMeta.value.current_page,
    };

    const options = {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => {
            activePostActionId.value = null;
        },
    } as const;

    if (method === 'delete') {
        router.delete(url, data, options);
    } else if (method === 'put') {
        router.put(url, data, options);
    } else {
        router.post(url, data, options);
    }
};

const openPostReportDialog = (post: ThreadPost) => {
    if (!post.permissions.canReport || !hasReportReasons.value) {
        return;
    }

    postReportTarget.value = post;
    postReportDialogOpen.value = true;
};

const submitPostReport = () => {
    const target = postReportTarget.value;

    if (!target || !target.permissions.canReport || postReportForm.processing || !hasReportReasons.value) {
        return;
    }

    postReportForm.page = postsMeta.value.current_page;

    postReportForm.post(route('forum.posts.report', { board: props.board.slug, thread: props.thread.slug, post: target.id }), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onSuccess: () => {
            postReportDialogOpen.value = false;
        },
    });
};

const editPost = (post: ThreadPost) => {
    if (!post.permissions.canEdit || activePostActionId.value === post.id) {
        return;
    }

    const updated = window.prompt('Update your post content', post.body_raw);

    if (updated === null) {
        return;
    }

    const trimmed = updated.trim();

    if (trimmed === '' || trimmed === post.body_raw) {
        return;
    }

    performPostAction(post, 'put', 'forum.posts.update', { body: trimmed });
};

const deletePost = (post: ThreadPost) => {
    if (!post.permissions.canDelete || activePostActionId.value === post.id) {
        return;
    }

    if (!window.confirm('Are you sure you want to delete this post? This action cannot be undone.')) {
        return;
    }

    performPostAction(post, 'delete', 'forum.posts.destroy');
};

const replyText = ref('');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Forum • ${props.thread.title}`" />
        <Dialog v-model:open="threadReportDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Report thread</DialogTitle>
                    <DialogDescription>
                        Let the moderation team know why this discussion needs attention. Provide as much
                        context as you can so we can review it quickly.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitThreadReport">
                    <div class="space-y-2">
                        <Label for="thread_report_reason">Reason</Label>
                        <select
                            id="thread_report_reason"
                            v-model="threadReportForm.reason_category"
                            class="w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus:outline-none focus:ring-2"
                            :class="threadReportForm.errors.reason_category
                                ? 'border-destructive focus:ring-destructive/40'
                                : 'focus:ring-primary/40'"
                            :disabled="!hasReportReasons"
                            required
                        >
                            <option disabled value="">Select a reason…</option>
                            <option v-for="option in reportReasons" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="selectedThreadReason?.description" class="text-xs text-muted-foreground">
                            {{ selectedThreadReason.description }}
                        </p>
                        <p v-if="!hasReportReasons" class="text-xs text-destructive">
                            Reporting options are temporarily unavailable. Please reach out to the support team.
                        </p>
                        <p v-if="threadReportForm.errors.reason_category" class="text-sm text-destructive">
                            {{ threadReportForm.errors.reason_category }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="thread_report_details">Additional details</Label>
                        <Textarea
                            id="thread_report_details"
                            v-model="threadReportForm.reason"
                            placeholder="Share specific quotes, timeline, or any other details that explain the problem."
                            class="min-h-[120px]"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but detailed reports help moderators resolve issues faster.
                        </p>
                        <p v-if="threadReportForm.errors.reason" class="text-sm text-destructive">
                            {{ threadReportForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="thread_report_evidence">Supporting link (optional)</Label>
                        <Input
                            id="thread_report_evidence"
                            v-model="threadReportForm.evidence_url"
                            type="url"
                            placeholder="https://example.com/screenshot-or-proof"
                        />
                        <p class="text-xs text-muted-foreground">
                            Share a link to screenshots, logs, or other evidence that supports your report.
                        </p>
                        <p v-if="threadReportForm.errors.evidence_url" class="text-sm text-destructive">
                            {{ threadReportForm.errors.evidence_url }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="threadReportForm.processing"
                            @click="threadReportDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-orange-500 hover:bg-orange-600"
                            :disabled="threadReportForm.processing || !hasReportReasons"
                        >
                            Submit report
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="postReportDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>
                        Report post
                        <template v-if="postReportTarget">
                            #{{ postReportTarget.number }} by {{ postReportTarget.author?.nickname ?? 'Unknown user' }}
                        </template>
                    </DialogTitle>
                    <DialogDescription>
                        Flag this reply for moderator review. We will notify you once a decision has been made.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="postReportTarget" class="rounded-md border border-muted bg-muted/20 p-3 text-sm">
                    <p class="text-xs uppercase text-muted-foreground">Post preview</p>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-foreground">
                        {{ postReportTarget.body_raw }}
                    </p>
                </div>
                <form class="mt-5 space-y-5" @submit.prevent="submitPostReport">
                    <div class="space-y-2">
                        <Label for="post_report_reason">Reason</Label>
                        <select
                            id="post_report_reason"
                            v-model="postReportForm.reason_category"
                            class="w-full rounded-md border border-input bg-background p-2 text-sm shadow-sm focus:outline-none focus:ring-2"
                            :class="postReportForm.errors.reason_category
                                ? 'border-destructive focus:ring-destructive/40'
                                : 'focus:ring-primary/40'"
                            :disabled="!hasReportReasons"
                            required
                        >
                            <option disabled value="">Select a reason…</option>
                            <option v-for="option in reportReasons" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <p v-if="selectedPostReason?.description" class="text-xs text-muted-foreground">
                            {{ selectedPostReason.description }}
                        </p>
                        <p v-if="!hasReportReasons" class="text-xs text-destructive">
                            Reporting options are temporarily unavailable. Please reach out to the support team.
                        </p>
                        <p v-if="postReportForm.errors.reason_category" class="text-sm text-destructive">
                            {{ postReportForm.errors.reason_category }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="post_report_details">Additional details</Label>
                        <Textarea
                            id="post_report_details"
                            v-model="postReportForm.reason"
                            placeholder="Explain what is wrong with this reply and why it breaks the rules."
                            class="min-h-[120px]"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but context helps moderators resolve issues faster.
                        </p>
                        <p v-if="postReportForm.errors.reason" class="text-sm text-destructive">
                            {{ postReportForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="post_report_evidence">Supporting link (optional)</Label>
                        <Input
                            id="post_report_evidence"
                            v-model="postReportForm.evidence_url"
                            type="url"
                            placeholder="https://example.com/screenshot-or-proof"
                        />
                        <p class="text-xs text-muted-foreground">
                            Share a link to screenshots, logs, or other evidence that supports your report.
                        </p>
                        <p v-if="postReportForm.errors.evidence_url" class="text-sm text-destructive">
                            {{ postReportForm.errors.evidence_url }}
                        </p>
                    </div>
                    <DialogFooter class="gap-2 sm:gap-3">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="postReportForm.processing"
                            @click="postReportDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            class="bg-orange-500 hover:bg-orange-600"
                            :disabled="postReportForm.processing || !hasReportReasons"
                        >
                            Submit report
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
        <div class="container mx-auto p-4 space-y-8">
            <!-- Thread Title -->
            <div class="mb-4">
                <h1 id="thread_title" class="text-3xl font-bold text-green-500">
                    <Pin v-if="props.thread.is_pinned" class="h-8 w-8 inline-block mr-2" />
                    {{ props.thread.title }}
                    <Lock
                        v-if="props.thread.is_locked"
                        class="h-8 w-8 inline-block ml-2 text-muted-foreground"
                    />
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
                        <Button variant="secondary" class="cursor-pointer" :disabled="!props.thread.permissions.canReply">
                            Post Reply
                        </Button>
                    </a>
                    <DropdownMenu
                        v-if="
                            threadPermissions.canReport ||
                            threadPermissions.canModerate ||
                            threadPermissions.canEdit
                        "
                    >
                        <DropdownMenuTrigger as-child>
                            <Button variant="outline" size="icon">
                                <Ellipsis class="h-8 w-8" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent>
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuGroup v-if="threadPermissions.canReport">
                                <DropdownMenuItem
                                    class="text-orange-500"
                                    :disabled="threadActionLoading || threadReportForm.processing || !hasReportReasons"
                                    @select="openThreadReportDialog"
                                >
                                    <Flag class="h-8 w-8" />
                                    <span>Report</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <template v-if="threadPermissions.canModerate">
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>Mod Actions</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuGroup>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_published"
                                        :disabled="threadActionLoading"
                                        @select="publishThread"
                                    >
                                        <Eye class="h-8 w-8" />
                                        <span>Publish</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_published"
                                        :disabled="threadActionLoading"
                                        @select="unpublishThread"
                                    >
                                        <EyeOff class="h-8 w-8" />
                                        <span>Unpublish</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_locked"
                                        :disabled="threadActionLoading"
                                        @select="lockThread"
                                    >
                                        <Lock class="h-8 w-8" />
                                        <span>Lock</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_locked"
                                        :disabled="threadActionLoading"
                                        @select="unlockThread"
                                    >
                                        <LockOpen class="h-8 w-8" />
                                        <span>Unlock</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="!props.thread.is_pinned"
                                        :disabled="threadActionLoading"
                                        @select="pinThread"
                                    >
                                        <Pin class="h-8 w-8" />
                                        <span>Pin</span>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem
                                        v-if="props.thread.is_pinned"
                                        :disabled="threadActionLoading"
                                        @select="unpinThread"
                                    >
                                        <PinOff class="h-8 w-8" />
                                        <span>Unpin</span>
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                            </template>
                            <DropdownMenuSeparator v-if="threadPermissions.canEdit" />
                            <DropdownMenuGroup v-if="threadPermissions.canEdit">
                                <DropdownMenuItem
                                    class="text-blue-500"
                                    :disabled="threadActionLoading"
                                    @select="renameThread"
                                >
                                    <Pencil class="h-8 w-8" />
                                    <span>Edit Title</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator v-if="threadPermissions.canModerate" />
                            <DropdownMenuItem
                                v-if="threadPermissions.canModerate"
                                class="text-red-500"
                                :disabled="threadActionLoading"
                                @select="deleteThread"
                            >
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
                            <div class="flex items-center gap-2">
                                <div class="text-sm font-medium text-gray-500">#{{ post.number }}</div>
                                <DropdownMenu
                                    v-if="
                                        post.permissions.canReport ||
                                        post.permissions.canEdit ||
                                        post.permissions.canDelete
                                    "
                                >
                                    <DropdownMenuTrigger as-child>
                                        <Button variant="ghost" size="icon" class="h-8 w-8">
                                            <Ellipsis class="h-5 w-5" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent>
                                        <DropdownMenuLabel>Post Actions</DropdownMenuLabel>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuGroup v-if="post.permissions.canReport">
                                            <DropdownMenuItem
                                                class="text-orange-500"
                                                :disabled="activePostActionId === post.id || postReportForm.processing || !hasReportReasons"
                                                @select="openPostReportDialog(post)"
                                            >
                                                <Flag class="h-4 w-4" />
                                                <span>Report</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuGroup v-if="post.permissions.canEdit">
                                            <DropdownMenuItem
                                                class="text-blue-500"
                                                :disabled="activePostActionId === post.id"
                                                @select="editPost(post)"
                                            >
                                                <Pencil class="h-4 w-4" />
                                                <span>Edit</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuGroup>
                                        <DropdownMenuSeparator
                                            v-if="
                                                post.permissions.canDelete &&
                                                (post.permissions.canReport || post.permissions.canEdit)
                                            "
                                        />
                                        <DropdownMenuItem
                                            v-if="post.permissions.canDelete"
                                            class="text-red-500"
                                            :disabled="activePostActionId === post.id"
                                            @select="deletePost(post)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                            <span>Delete</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
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
                    <Textarea
                        v-model="replyText"
                        placeholder="Write your reply here..."
                        class="w-full rounded-md"
                        :disabled="!props.thread.permissions.canReply"
                    />

                    <Button
                        variant="secondary"
                        class="cursor-pointer bg-green-500 hover:bg-green-600"
                        :disabled="!props.thread.permissions.canReply"
                    >
                        Submit Reply
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
