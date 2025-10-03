<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';

import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useUserTimezone } from '@/composables/useUserTimezone';

type CommentUser = {
    id: number;
    nickname?: string | null;
    avatar_url?: string | null;
    profile_bio?: string | null;
};

type BlogComment = {
    id: number;
    body: string;
    created_at?: string | null;
    updated_at?: string | null;
    user?: CommentUser | null;
};

type PaginationMeta = {
    current_page: number;
    from?: number | null;
    last_page: number;
    per_page: number;
    to?: number | null;
    total: number;
};

type PaginationLinks = {
    first?: string | null;
    last?: string | null;
    prev?: string | null;
    next?: string | null;
};

type PaginatedComments = {
    data: BlogComment[];
    meta: PaginationMeta;
    links: PaginationLinks;
};

const defaultMeta: PaginationMeta = {
    current_page: 1,
    from: null,
    last_page: 1,
    per_page: 10,
    to: null,
    total: 0,
};

const buildMeta = (meta?: Partial<PaginationMeta>): PaginationMeta => ({
    current_page: meta?.current_page ?? defaultMeta.current_page,
    from: meta?.from ?? defaultMeta.from,
    last_page: meta?.last_page ?? defaultMeta.last_page,
    per_page: meta?.per_page ?? defaultMeta.per_page,
    to: meta?.to ?? defaultMeta.to,
    total: meta?.total ?? defaultMeta.total,
});

type PageProps = {
    auth: {
        user: {
            id: number;
            nickname?: string | null;
            roles?: Array<{ name: string }>;
        } | null;
    };
};

const props = defineProps<{
    blogSlug: string;
    initialComments: PaginatedComments;
}>();

const page = usePage<PageProps>();
const authUser = computed(() => page.props.auth?.user ?? null);
const roleNames = computed(() => authUser.value?.roles?.map((role) => role.name) ?? []);
const canModerate = computed(() => roleNames.value.some((role) => ['admin', 'editor', 'moderator'].includes(role)));

const comments = ref<BlogComment[]>([...props.initialComments.data]);
const pagination = ref<PaginationMeta>(buildMeta(props.initialComments.meta));

const perPage = computed(() => pagination.value.per_page ?? defaultMeta.per_page);
const hasMore = computed(() => pagination.value.current_page < pagination.value.last_page);
const isLoadingMore = ref(false);
const loadMoreError = ref<string | null>(null);

updatePaginationTotals(pagination.value.total);

watch(
    () => props.initialComments,
    (value) => {
        comments.value = [...value.data];
        pagination.value = buildMeta(value.meta);
        updatePaginationTotals(value.meta.total);
        loadMoreError.value = null;
    },
);

const sortedComments = computed(() => {
    return [...comments.value].sort((a, b) => {
        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;

        return aTime - bTime;
    });
});

function updatePaginationTotals(total: number) {
    const previous = pagination.value;
    const perPageValue = perPage.value || defaultMeta.per_page;
    const lastPage = Math.max(1, Math.ceil(total / perPageValue));
    const loadedCount = comments.value.length;

    pagination.value = {
        ...previous,
        current_page:
            loadedCount >= total ? lastPage : Math.min(previous.current_page, lastPage),
        last_page: lastPage,
        total,
        from: loadedCount > 0 ? 1 : null,
        to: loadedCount > 0 ? loadedCount : null,
    };
}

const loadMore = async () => {
    loadMoreError.value = null;

    if (isLoadingMore.value || !hasMore.value) {
        return;
    }

    const nextPage = Math.min(pagination.value.current_page + 1, pagination.value.last_page);

    isLoadingMore.value = true;

    try {
        const response = await fetch(
            route('blogs.comments.index', {
                blog: props.blogSlug,
                page: nextPage,
                per_page: perPage.value,
            }),
            {
                headers: {
                    Accept: 'application/json',
                },
            },
        );

        if (!response.ok) {
            const message = await extractErrorMessage(response);
            loadMoreError.value = message;
            toast.error(message);
            return;
        }

        const payload: PaginatedComments = await response.json();

        const existingIds = new Set(comments.value.map((comment) => comment.id));
        const incoming = (payload.data ?? []).filter((comment) => !existingIds.has(comment.id));

        comments.value = [...comments.value, ...incoming];
        pagination.value = buildMeta(payload.meta);
        // Links are not stored because we derive pagination state locally.
        updatePaginationTotals(payload.meta.total);
    } catch (error) {
        console.error(error);
        loadMoreError.value = 'Unable to load more comments right now.';
        toast.error(loadMoreError.value);
    } finally {
        isLoadingMore.value = false;
    }
};

const newComment = ref('');
const submitError = ref<string | null>(null);
const isSubmitting = ref(false);

const editingCommentId = ref<number | null>(null);
const editingContent = ref('');
const editError = ref<string | null>(null);
const updatingCommentId = ref<number | null>(null);
const deletingCommentId = ref<number | null>(null);
const deleteDialogOpen = ref(false);
const pendingDeleteComment = ref<BlogComment | null>(null);
const deleteDialogTitle = computed(() => {
    const target = pendingDeleteComment.value;

    if (!target) {
        return 'Delete comment';
    }

    return `Delete comment from ${commentAuthor(target)}?`;
});

watch(
    () => deleteDialogOpen.value,
    (open) => {
        if (!open) {
            pendingDeleteComment.value = null;
        }
    },
);

const csrfToken = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

const { formatDate, fromNow } = useUserTimezone();

const commentAuthor = (comment: BlogComment) => {
    return comment.user?.nickname ?? 'Unknown user';
};

const commentInitials = (comment: BlogComment) => {
    const source = comment.user?.nickname ?? 'U';
    const parts = source
        .split(/\s+/)
        .filter(Boolean)
        .map((part) => part[0] ?? '')
        .join('');

    return parts.slice(0, 2).toUpperCase() || 'U';
};

const authorBioSnippet = (comment: BlogComment) => {
    const bio = comment.user?.profile_bio?.trim();

    if (!bio) {
        return '';
    }

    if (bio.length <= 160) {
        return bio;
    }

    return `${bio.slice(0, 157)}…`;
};

const formatCommentTimestamp = (comment: BlogComment) => {
    if (!comment.created_at) {
        return '';
    }

    return `${formatDate(comment.created_at)} · ${fromNow(comment.created_at)}`;
};

const isEdited = (comment: BlogComment) => {
    if (!comment.created_at || !comment.updated_at) {
        return false;
    }

    return comment.updated_at !== comment.created_at;
};

const canManageComment = (comment: BlogComment) => {
    if (!authUser.value) {
        return false;
    }

    return canModerate.value || comment.user?.id === authUser.value.id;
};

const extractErrorMessage = async (response: Response): Promise<string> => {
    try {
        const payload = await response.json();

        if (payload?.message) {
            return payload.message;
        }

        if (payload?.errors) {
            const firstError = Object.values(payload.errors)[0];

            if (Array.isArray(firstError) && firstError.length > 0) {
                return String(firstError[0]);
            }
        }
    } catch (error) {
        console.error(error);
    }

    if (response.status === 401) {
        return 'You need to sign in to continue.';
    }

    if (response.status === 403) {
        return 'You are not allowed to perform this action.';
    }

    return 'Something went wrong. Please try again.';
};

const submitComment = async () => {
    submitError.value = null;

    if (!authUser.value) {
        toast.error('You need to sign in to leave a comment.');
        return;
    }

    const body = newComment.value.trim();

    if (body === '') {
        submitError.value = 'Comment cannot be empty.';
        return;
    }

    isSubmitting.value = true;

    try {
        const response = await fetch(route('blogs.comments.store', { blog: props.blogSlug }), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ body }),
        });

        if (!response.ok) {
            submitError.value = await extractErrorMessage(response);
            toast.error(submitError.value ?? 'Unable to post your comment.');
            return;
        }

        const payload = await response.json();
        const created = payload?.data as BlogComment | undefined;

        if (created) {
            const existingIndex = comments.value.findIndex((comment) => comment.id === created.id);

            if (existingIndex === -1) {
                comments.value = [...comments.value, created];
                updatePaginationTotals(pagination.value.total + 1);
            } else {
                comments.value = comments.value.map((existing) =>
                    existing.id === created.id ? created : existing,
                );
            }
        }

        newComment.value = '';
        toast.success('Comment posted successfully.');
    } catch (error) {
        console.error(error);
        toast.error('Unable to post your comment right now.');
    } finally {
        isSubmitting.value = false;
    }
};

const startEditing = (comment: BlogComment) => {
    editingCommentId.value = comment.id;
    editingContent.value = comment.body;
    editError.value = null;
};

const cancelEditing = () => {
    editingCommentId.value = null;
    editingContent.value = '';
    editError.value = null;
    updatingCommentId.value = null;
};

const isUpdating = (id: number) => updatingCommentId.value === id;
const isDeleting = (id: number) => deletingCommentId.value === id;

const updateComment = async (commentId: number) => {
    if (!authUser.value) {
        toast.error('You need to sign in to update a comment.');
        return;
    }

    const body = editingContent.value.trim();

    if (body === '') {
        editError.value = 'Comment cannot be empty.';
        return;
    }

    updatingCommentId.value = commentId;
    editError.value = null;

    try {
        const response = await fetch(route('blogs.comments.update', { blog: props.blogSlug, comment: commentId }), {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ body }),
        });

        if (!response.ok) {
            const message = await extractErrorMessage(response);
            editError.value = message;
            toast.error(message);
            return;
        }

        const payload = await response.json();
        const updated = payload?.data as BlogComment | undefined;

        if (updated) {
            comments.value = comments.value.map((existing) =>
                existing.id === updated.id ? updated : existing,
            );
        }

        toast.success('Comment updated.');
        cancelEditing();
    } catch (error) {
        console.error(error);
        toast.error('Unable to update the comment right now.');
    } finally {
        updatingCommentId.value = null;
    }
};

const openDeleteDialog = (comment: BlogComment) => {
    if (!authUser.value) {
        toast.error('You need to sign in to remove a comment.');
        return;
    }

    if (!canManageComment(comment)) {
        toast.error('You are not allowed to remove this comment.');
        return;
    }

    pendingDeleteComment.value = comment;
    deleteDialogOpen.value = true;
};

const closeDeleteDialog = () => {
    deleteDialogOpen.value = false;
    pendingDeleteComment.value = null;
};

const confirmDeleteComment = async () => {
    const target = pendingDeleteComment.value;

    if (!target) {
        return;
    }

    if (!authUser.value) {
        toast.error('You need to sign in to remove a comment.');
        closeDeleteDialog();
        return;
    }

    if (!canManageComment(target)) {
        toast.error('You are not allowed to remove this comment.');
        closeDeleteDialog();
        return;
    }

    deletingCommentId.value = target.id;

    try {
        const response = await fetch(route('blogs.comments.destroy', { blog: props.blogSlug, comment: target.id }), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        if (!response.ok) {
            const message = await extractErrorMessage(response);
            toast.error(message);
            return;
        }

        comments.value = comments.value.filter((comment) => comment.id !== target.id);
        updatePaginationTotals(Math.max(pagination.value.total - 1, comments.value.length));
        toast.success('Comment removed.');

        if (editingCommentId.value === target.id) {
            cancelEditing();
        }
    } catch (error) {
        console.error(error);
        toast.error('Unable to delete the comment right now.');
    } finally {
        deletingCommentId.value = null;
        closeDeleteDialog();
    }
};
</script>

<template>
    <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-6 shadow">
        <h2 class="mb-4 text-2xl font-bold">Comments</h2>

        <div v-if="authUser" class="mb-8 space-y-3 rounded-lg border border-sidebar-border/70 dark:border-sidebar-border p-4">
            <h3 class="text-lg font-semibold">Join the conversation</h3>
            <Textarea
                v-model="newComment"
                rows="4"
                placeholder="Share your thoughts..."
                class="w-full"
            />
            <InputError :message="submitError" />
            <div class="flex justify-end">
                <Button :disabled="isSubmitting" @click="submitComment">
                    <span v-if="isSubmitting">Posting...</span>
                    <span v-else>Post Comment</span>
                </Button>
            </div>
        </div>
        <div
            v-else
            class="mb-8 rounded-lg border border-dashed border-sidebar-border/70 dark:border-sidebar-border p-4 text-sm text-muted-foreground"
        >
            <p>
                <a :href="route('login')" class="font-medium text-primary hover:underline">Sign in</a>
                to join the discussion.
            </p>
        </div>

        <div v-if="sortedComments.length === 0" class="text-sm text-muted-foreground">
            <p>No comments yet. Be the first to share your thoughts.</p>
        </div>
        <div v-else class="space-y-6">
            <div
                v-for="comment in sortedComments"
                :key="comment.id"
                class="rounded-lg border border-sidebar-border/50 dark:border-sidebar-border/80 p-4"
            >
                <div class="flex gap-4">
                    <Avatar size="sm" class="mt-1">
                        <AvatarImage
                            v-if="comment.user?.avatar_url"
                            :src="comment.user.avatar_url"
                            :alt="`${commentAuthor(comment)} avatar`"
                        />
                        <AvatarFallback>{{ commentInitials(comment) }}</AvatarFallback>
                    </Avatar>

                    <div class="min-w-0 flex-1 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-foreground">
                                    {{ commentAuthor(comment) }}
                                </p>
                                <p v-if="comment.created_at" class="text-xs text-muted-foreground">
                                    {{ formatCommentTimestamp(comment) }}
                                    <span v-if="isEdited(comment)" class="ml-1 italic">(edited)</span>
                                </p>
                                <p
                                    v-if="authorBioSnippet(comment)"
                                    class="mt-1 text-xs text-muted-foreground"
                                >
                                    {{ authorBioSnippet(comment) }}
                                </p>
                            </div>
                            <div v-if="canManageComment(comment)" class="flex flex-wrap items-center gap-2 text-xs">
                                <Button
                                    v-if="editingCommentId !== comment.id"
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 px-2"
                                    @click="startEditing(comment)"
                                >
                                    Edit
                                </Button>
                                <template v-else>
                                    <Button variant="ghost" size="sm" class="h-8 px-2" @click="cancelEditing">
                                        Cancel
                                    </Button>
                                    <Button
                                        size="sm"
                                        class="h-8 px-3"
                                        :disabled="isUpdating(comment.id)"
                                        @click="updateComment(comment.id)"
                                    >
                                        <span v-if="isUpdating(comment.id)">Saving...</span>
                                        <span v-else>Save</span>
                                    </Button>
                                </template>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    class="h-8 px-2 text-destructive hover:text-destructive"
                                    :disabled="isDeleting(comment.id)"
                                    @click="openDeleteDialog(comment)"
                                >
                                    <span v-if="isDeleting(comment.id)">Removing...</span>
                                    <span v-else>Delete</span>
                                </Button>
                            </div>
                        </div>

                        <div v-if="editingCommentId === comment.id" class="space-y-3">
                            <Textarea v-model="editingContent" rows="4" class="w-full" />
                            <InputError :message="editError" />
                        </div>
                        <p v-else class="whitespace-pre-line text-sm text-foreground">
                            {{ comment.body }}
                        </p>
                    </div>
                </div>
            </div>
            <p v-if="loadMoreError" class="text-sm text-destructive">{{ loadMoreError }}</p>
            <div v-if="hasMore" class="pt-2 text-center">
                <Button variant="outline" :disabled="isLoadingMore" @click="loadMore">
                    <span v-if="isLoadingMore">Loading...</span>
                    <span v-else>Load more comments</span>
                </Button>
            </div>
        </div>
    </div>
    <ConfirmDialog
        v-model:open="deleteDialogOpen"
        :title="deleteDialogTitle"
        description="This action cannot be undone."
        confirm-label="Delete"
        cancel-label="Cancel"
        :confirm-disabled="deletingCommentId !== null"
        @confirm="confirmDeleteComment"
        @cancel="closeDeleteDialog"
    />
</template>
