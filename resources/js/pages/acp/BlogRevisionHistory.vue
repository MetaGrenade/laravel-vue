<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import Button from '@/components/ui/button/Button.vue';
import { type BreadcrumbItem } from '@/types';
import { ArrowLeft, RotateCcw } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { useConfirmDialog } from '@/composables/useConfirmDialog';

interface BlogCategorySummary {
    id: number;
    name: string;
    slug: string;
}

interface BlogTagSummary {
    id: number;
    name: string;
    slug: string;
}

interface BlogAuthorSummary {
    id: number;
    nickname: string;
}

interface BlogMetadataSummary {
    views?: number | null;
    last_viewed_at?: string | null;
    preview_token?: string | null;
    author_id?: number | null;
}

interface BlogSummary {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    body: string;
    status: string;
    cover_image: string | null;
    cover_image_url: string | null;
    created_at: string | null;
    updated_at: string | null;
    published_at: string | null;
    scheduled_for: string | null;
    metadata: BlogMetadataSummary | null;
    author: BlogAuthorSummary | null;
    categories: BlogCategorySummary[];
    tags: BlogTagSummary[];
}

interface RevisionSummary {
    id: number;
    title: string;
    slug: string;
    excerpt: string | null;
    body: string;
    cover_image: string | null;
    cover_image_url: string | null;
    status: string;
    published_at: string | null;
    scheduled_for: string | null;
    edited_at: string | null;
    created_at: string | null;
    category_ids: number[];
    tag_ids: number[];
    categories: BlogCategorySummary[];
    tags: BlogTagSummary[];
    metadata: BlogMetadataSummary | null;
    editor: BlogAuthorSummary | null;
}

interface RevisionPermissions {
    canRestore: boolean;
}

const props = defineProps<{
    blog: BlogSummary;
    revisions: RevisionSummary[];
    permissions: RevisionPermissions;
}>();

const { formatDate, fromNow } = useUserTimezone();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Blogs ACP', href: route('acp.blogs.index') },
    { title: props.blog.title, href: route('acp.blogs.edit', { blog: props.blog.id }) },
    { title: 'Revision history', href: '#' },
]);

const canRestore = computed(() => props.permissions?.canRestore ?? false);
const blog = computed(() => props.blog);
const revisions = computed(() => props.revisions);
const backUrl = computed(() => route('acp.blogs.edit', { blog: props.blog.id }));

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

const metadataEntries = (metadata: BlogMetadataSummary | null | undefined) => {
    if (!metadata || typeof metadata !== 'object') {
        return [] as Array<{ label: string; value: string }>;
    }

    const entries: Array<{ label: string; value: string }> = [];

    if (typeof metadata.views === 'number') {
        entries.push({ label: 'Views at save', value: metadata.views.toLocaleString() });
    }

    if (typeof metadata.last_viewed_at === 'string' && metadata.last_viewed_at) {
        const exact = formatExact(metadata.last_viewed_at) ?? metadata.last_viewed_at;
        const relative = formatRelative(metadata.last_viewed_at);
        entries.push({
            label: 'Last viewed',
            value: relative ? `${exact} (${relative})` : exact,
        });
    }

    if (typeof metadata.preview_token === 'string' && metadata.preview_token) {
        entries.push({
            label: 'Preview token',
            value: metadata.preview_token,
        });
    }

    if (typeof metadata.author_id === 'number') {
        entries.push({
            label: 'Author ID',
            value: metadata.author_id.toString(),
        });
    }

    return entries;
};

const restoreRevision = (revisionId: number) => {
    if (!canRestore.value) {
        return;
    }

    router.put(
        route('acp.blogs.revisions.restore', {
            blog: props.blog.id,
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
        description: 'The current content will be stored as a new revision before restoring.',
        confirmLabel: 'Restore revision',
        confirmVariant: 'default',
        onConfirm: () => restoreRevision(revisionId),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Blog Revision History" />

        <AdminLayout>
            <div class="container mx-auto space-y-8 p-4">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div class="flex items-center gap-3">
                        <Link :href="backUrl">
                            <Button variant="outline" size="icon">
                                <ArrowLeft class="h-5 w-5" />
                            </Button>
                        </Link>
                        <div>
                            <h1 class="text-3xl font-bold">Blog revision history</h1>
                            <p class="text-sm text-muted-foreground">
                                {{ blog.value.title }} · Status: {{ blog.value.status }}
                            </p>
                        </div>
                    </div>
                    <div class="text-sm text-muted-foreground md:text-right space-y-1">
                        <p v-if="blog.value.author">Author: {{ blog.value.author.nickname }}</p>
                        <p v-if="formatExact(blog.value.created_at)">
                            Created {{ formatExact(blog.value.created_at) }}
                        </p>
                        <p v-if="formatExact(blog.value.updated_at)">
                            Updated {{ formatExact(blog.value.updated_at) }}
                            <span v-if="formatRelative(blog.value.updated_at)">
                                ({{ formatRelative(blog.value.updated_at) }})
                            </span>
                        </p>
                        <p v-if="formatExact(blog.value.published_at)">
                            Published {{ formatExact(blog.value.published_at) }}
                        </p>
                        <p v-if="formatExact(blog.value.scheduled_for)">
                            Scheduled for {{ formatExact(blog.value.scheduled_for) }}
                        </p>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <div class="space-y-6">
                        <div class="rounded-xl border p-6 shadow-sm">
                            <div class="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                                <div>
                                    <h2 class="text-xl font-semibold">Current version</h2>
                                    <p class="text-sm text-muted-foreground">
                                        Last updated {{ formatExact(blog.value.updated_at ?? blog.value.created_at) ?? 'Unknown' }}
                                        <span v-if="formatRelative(blog.value.updated_at ?? blog.value.created_at)">
                                            ({{ formatRelative(blog.value.updated_at ?? blog.value.created_at) }})
                                        </span>
                                    </p>
                                </div>
                                <div class="text-sm text-muted-foreground md:text-right">
                                    <p v-if="!canRestore">You do not have permission to restore revisions.</p>
                                    <p v-else>Restoring a revision will archive this version automatically.</p>
                                </div>
                            </div>

                            <div class="mt-4 space-y-4">
                                <div class="rounded-lg border bg-muted/30 p-4">
                                    <h3 class="font-semibold">Metadata</h3>
                                    <ul class="mt-2 space-y-1 text-sm">
                                        <li v-if="!metadataEntries(blog.value.metadata).length" class="text-muted-foreground">
                                            No metadata captured for this version.
                                        </li>
                                        <li v-for="entry in metadataEntries(blog.value.metadata)" :key="entry.label">
                                            <span class="font-medium">{{ entry.label }}:</span>
                                            <span>{{ entry.value }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div v-if="blog.value.categories.length" class="rounded-lg border bg-muted/30 p-4">
                                    <h3 class="font-semibold">Categories</h3>
                                    <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                                        <li
                                            v-for="category in blog.value.categories"
                                            :key="category.id"
                                            class="rounded-full bg-background px-3 py-1 shadow"
                                        >
                                            {{ category.name }}
                                        </li>
                                    </ul>
                                </div>

                                <div v-if="blog.value.tags.length" class="rounded-lg border bg-muted/30 p-4">
                                    <h3 class="font-semibold">Tags</h3>
                                    <ul class="mt-2 flex flex-wrap gap-2 text-sm">
                                        <li
                                            v-for="tag in blog.value.tags"
                                            :key="tag.id"
                                            class="rounded-full bg-background px-3 py-1 shadow"
                                        >
                                            {{ tag.name }}
                                        </li>
                                    </ul>
                                </div>

                                <div class="rounded-lg border bg-background p-4 shadow-sm">
                                    <h3 class="font-semibold">Excerpt</h3>
                                    <p class="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">
                                        {{ blog.value.excerpt ?? '—' }}
                                    </p>
                                </div>

                                <div class="rounded-lg border bg-background p-4 shadow-sm">
                                    <h3 class="font-semibold">Body</h3>
                                    <div class="prose prose-sm mt-4 max-w-none" v-html="blog.value.body" />
                                </div>
                            </div>
                        </div>

                        <div class="rounded-xl border p-6 shadow-sm">
                            <div class="flex items-center justify-between gap-4">
                                <h2 class="text-xl font-semibold">Revision history</h2>
                                <span class="text-sm text-muted-foreground">
                                    {{ revisions.value.length }}
                                    {{ revisions.value.length === 1 ? 'revision stored' : 'revisions stored' }}
                                </span>
                            </div>

                            <div
                                v-if="revisions.value.length === 0"
                                class="mt-6 rounded-lg border border-dashed p-6 text-center text-muted-foreground"
                            >
                                No revisions recorded yet. Updates to this blog will appear here automatically.
                            </div>

                            <div v-else class="mt-6 space-y-4">
                                <div
                                    v-for="revision in revisions"
                                    :key="revision.id"
                                    class="rounded-lg border p-4 shadow-sm"
                                >
                                    <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                        <div>
                                            <p class="font-semibold">Saved {{ formatExact(revision.created_at) ?? 'Unknown time' }}</p>
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

                                    <div class="mt-4 grid gap-4 lg:grid-cols-2">
                                        <div class="space-y-3">
                                            <div class="rounded-lg border bg-muted/30 p-3 text-sm">
                                                <p><span class="font-medium">Status:</span> {{ revision.status }}</p>
                                                <p v-if="formatExact(revision.published_at)">
                                                    <span class="font-medium">Published:</span>
                                                    {{ formatExact(revision.published_at) }}
                                                    <span v-if="formatRelative(revision.published_at)">
                                                        ({{ formatRelative(revision.published_at) }})
                                                    </span>
                                                </p>
                                                <p v-if="formatExact(revision.scheduled_for)">
                                                    <span class="font-medium">Scheduled:</span>
                                                    {{ formatExact(revision.scheduled_for) }}
                                                    <span v-if="formatRelative(revision.scheduled_for)">
                                                        ({{ formatRelative(revision.scheduled_for) }})
                                                    </span>
                                                </p>
                                            </div>

                                            <div class="rounded-lg border bg-muted/30 p-3 text-sm">
                                                <h4 class="font-semibold">Metadata</h4>
                                                <ul class="mt-2 space-y-1">
                                                    <li v-if="!metadataEntries(revision.metadata).length" class="text-muted-foreground">
                                                        No metadata recorded for this revision.
                                                    </li>
                                                    <li v-for="entry in metadataEntries(revision.metadata)" :key="entry.label">
                                                        <span class="font-medium">{{ entry.label }}:</span>
                                                        <span>{{ entry.value }}</span>
                                                    </li>
                                                </ul>
                                            </div>

                                            <div v-if="revision.categories.length" class="rounded-lg border bg-muted/30 p-3 text-sm">
                                                <h4 class="font-semibold">Categories</h4>
                                                <ul class="mt-2 flex flex-wrap gap-2">
                                                    <li
                                                        v-for="category in revision.categories"
                                                        :key="category.id"
                                                        class="rounded-full bg-background px-3 py-1 shadow"
                                                    >
                                                        {{ category.name }}
                                                    </li>
                                                </ul>
                                            </div>

                                            <div v-if="revision.tags.length" class="rounded-lg border bg-muted/30 p-3 text-sm">
                                                <h4 class="font-semibold">Tags</h4>
                                                <ul class="mt-2 flex flex-wrap gap-2">
                                                    <li
                                                        v-for="tag in revision.tags"
                                                        :key="tag.id"
                                                        class="rounded-full bg-background px-3 py-1 shadow"
                                                    >
                                                        {{ tag.name }}
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <div class="space-y-3">
                                            <div class="rounded-lg border bg-background p-3 shadow-sm">
                                                <h4 class="font-semibold">Excerpt</h4>
                                                <p class="mt-2 whitespace-pre-wrap text-sm text-muted-foreground">
                                                    {{ revision.excerpt ?? '—' }}
                                                </p>
                                            </div>
                                            <div class="rounded-lg border bg-background p-3 shadow-sm">
                                                <h4 class="font-semibold">Body</h4>
                                                <div class="prose prose-sm mt-3 max-w-none" v-html="revision.body" />
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <aside class="space-y-4">
                        <div v-if="blog.value.cover_image_url" class="overflow-hidden rounded-lg border shadow-sm">
                            <img :src="blog.value.cover_image_url" alt="Blog cover" class="h-full w-full object-cover" />
                        </div>
                        <div class="rounded-lg border bg-muted/40 p-4 text-sm text-muted-foreground">
                            <p>
                                Need to investigate an older version? Use the restore button on any revision. We'll keep a
                                copy of the current content so you can undo the change if needed.
                            </p>
                        </div>
                    </aside>
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
