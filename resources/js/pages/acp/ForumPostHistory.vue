<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import { ArrowLeft, RotateCcw } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useConfirmDialog } from '@/composables/useConfirmDialog';

interface BoardSummary {
    id: number;
    title: string;
    slug: string;
    category: {
        title: string | null;
        slug: string | null;
    } | null;
}

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
}

interface PostAuthorSummary {
    id: number;
    nickname: string;
}

interface PostPermissions {
    canRestore: boolean;
}

interface PostSummary {
    id: number;
    body: string;
    created_at: string | null;
    edited_at: string | null;
    author: PostAuthorSummary | null;
    permissions: PostPermissions;
}

interface RevisionSummary {
    id: number;
    body: string;
    created_at: string | null;
    edited_at: string | null;
    editor: PostAuthorSummary | null;
}

const props = defineProps<{
    board: BoardSummary;
    thread: ThreadSummary;
    post: PostSummary;
    revisions: RevisionSummary[];
}>();

const { formatDate, fromNow } = useUserTimezone();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Forum', href: route('forum.index') },
    { title: props.board.title, href: route('forum.boards.show', { board: props.board.slug }) },
    { title: props.thread.title, href: route('forum.threads.show', { board: props.board.slug, thread: props.thread.slug }) },
    { title: 'Post History', href: '#' },
]);

const post = computed(() => props.post);
const revisions = computed(() => props.revisions);
const canRestore = computed(() => post.value.permissions.canRestore);

const threadUrl = computed(() => route('forum.threads.show', {
    board: props.board.slug,
    thread: props.thread.slug,
}));

const formatExact = (value: string | null | undefined) => {
    if (!value) {
        return null;
    }

    return formatDate(value, 'MMM D, YYYY h:mm A');
};

const formatRelative = (value: string | null | undefined) => {
    if (!value) {
        return null;
    }

    return fromNow(value);
};

const restoreRevision = (revisionId: number) => {
    router.put(
        route('forum.posts.history.restore', {
            board: props.board.slug,
            thread: props.thread.slug,
            post: props.post.id,
            revision: revisionId,
        }),
        {},
        {
            preserveScroll: true,
        },
    );
};

const {
    confirmDialogState,
    confirmDialogDescription,
    openConfirmDialog,
    handleConfirmDialogConfirm,
    handleConfirmDialogCancel,
} = useConfirmDialog();

const requestRestore = (revisionId: number) => {
    if (!canRestore.value) {
        return;
    }

    openConfirmDialog({
        title: 'Restore this revision?',
        description: 'The current content will be saved as a new revision.',
        confirmLabel: 'Restore revision',
        confirmVariant: 'default',
        onConfirm: () => restoreRevision(revisionId),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Post Revision History" />

        <AdminLayout>
            <div class="container mx-auto space-y-8 p-4">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center gap-3">
                        <Link :href="threadUrl">
                            <Button variant="outline" size="icon">
                                <ArrowLeft class="h-5 w-5" />
                            </Button>
                        </Link>
                        <div>
                            <h1 class="text-3xl font-bold">Post Revision History</h1>
                            <p class="text-sm text-muted-foreground">
                                Thread: {{ thread.title }} · Board: {{ board.title }}
                            </p>
                        </div>
                    </div>
                    <div class="text-sm text-muted-foreground md:text-right">
                        <p v-if="post.author">
                            Post by {{ post.author.nickname }}
                        </p>
                        <p v-if="formatExact(post.created_at)">
                            Created {{ formatExact(post.created_at) }}
                        </p>
                        <p v-if="post.edited_at">
                            Last edited {{ formatExact(post.edited_at) }}
                            <span v-if="formatRelative(post.edited_at)">
                                ({{ formatRelative(post.edited_at) }})
                            </span>
                        </p>
                    </div>
                </div>

                <div class="grid gap-6">
                    <div class="rounded-xl border p-6 shadow-sm">
                        <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                            <div>
                                <h2 class="text-xl font-semibold">Current Version</h2>
                                <p class="text-sm text-muted-foreground">
                                    Last updated {{ formatExact(post.edited_at ?? post.created_at) ?? 'Unknown' }}
                                    <span v-if="formatRelative(post.edited_at ?? post.created_at)">
                                        ({{ formatRelative(post.edited_at ?? post.created_at) }})
                                    </span>
                                </p>
                            </div>
                            <div class="text-sm text-muted-foreground md:text-right">
                                <p v-if="!canRestore">You do not have permission to restore revisions.</p>
                                <p v-else>Restoring a revision will archive this version automatically.</p>
                            </div>
                        </div>
                        <div class="prose prose-sm mt-4 max-w-none" v-html="post.body" />
                    </div>

                    <div class="rounded-xl border p-6 shadow-sm">
                        <div class="flex items-center justify-between gap-4">
                            <h2 class="text-xl font-semibold">Revision History</h2>
                            <span class="text-sm text-muted-foreground">
                                {{ revisions.length }} {{ revisions.length === 1 ? 'revision' : 'revisions' }} stored
                            </span>
                        </div>

                        <div v-if="revisions.length === 0" class="mt-6 rounded-lg border border-dashed p-6 text-center text-muted-foreground">
                            No revisions recorded yet. Updates to this post will appear here automatically.
                        </div>

                        <div v-else class="mt-6 space-y-4">
                            <div
                                v-for="revision in revisions"
                                :key="revision.id"
                                class="rounded-lg border p-4 shadow-sm"
                            >
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <p class="font-semibold">
                                            Saved {{ formatExact(revision.created_at) ?? 'Unknown time' }}
                                        </p>
                                        <p class="text-sm text-muted-foreground">
                                            <span v-if="revision.editor">by {{ revision.editor.nickname }}</span>
                                            <span v-else>by Unknown user</span>
                                            <span v-if="revision.edited_at">
                                                · Original edit timestamp {{ formatExact(revision.edited_at) ?? 'Unknown' }}
                                            </span>
                                        </p>
                                        <p v-if="formatRelative(revision.created_at)" class="text-xs text-muted-foreground">
                                            {{ formatRelative(revision.created_at) }}
                                        </p>
                                    </div>
                                    <Button
                                        v-if="canRestore"
                                        variant="outline"
                                        size="sm"
                                        class="shrink-0"
                                        @click="requestRestore(revision.id)"
                                    >
                                        <RotateCcw class="mr-2 h-4 w-4" />
                                        Restore this version
                                    </Button>
                                </div>
                                <div class="prose prose-sm mt-4 max-w-none rounded-lg border bg-muted/40 p-4" v-html="revision.body" />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
        <ConfirmDialog
            v-model:open="confirmDialogState.open"
            :title="confirmDialogState.title"
            :description="confirmDialogDescription"
            :confirm-label="confirmDialogState.confirmLabel"
            :cancel-label="confirmDialogState.cancelLabel"
            :confirm-variant="confirmDialogState.confirmVariant"
            :confirm-disabled="confirmDialogState.confirmDisabled"
            @confirm="handleConfirmDialogConfirm"
            @cancel="handleConfirmDialogCancel"
        />
    </AppLayout>
</template>
