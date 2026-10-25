<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useConfirmDialog } from '@/composables/useConfirmDialog';
import type { PaginationMeta } from '@/composables/useInertiaPagination';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { HeldItem } from '@/types/moderation';
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, ExternalLink, ShieldAlert, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * What the forum's automatic moderation has held for a moderator (docs/automod.md): replies and threads that nobody but their author and
 * the moderators can see until one of them approves it, or rejects it and it goes. The one that has waited longest is first.
 */
const props = defineProps<{
    type: 'replies' | 'threads';
    items: {
        data: HeldItem[];
        meta: PaginationMeta;
        links: { first: string | null; last: string | null; prev: string | null; next: string | null };
    };
    counts: { replies: number; threads: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forums', href: route('acp.forums.index') },
    { title: 'Review queue', href: route('acp.forums.review.index') },
];

const { confirmDialogState, confirmDialogDescription, openConfirmDialog, handleConfirmDialogConfirm, handleConfirmDialogCancel } = useConfirmDialog();

const kinds = computed(() => (props.type === 'replies' ? 'replies' : 'threads'));
const selected = ref<number[]>([]);

// What was selected on a page is not selected on the next one, or in the other tab.
watch(
    () => [props.type, props.items.meta.current_page],
    () => (selected.value = []),
);

const all = computed(() => props.items.data.length > 0 && selected.value.length === props.items.data.length);

function toggleAll(value: boolean | 'indeterminate') {
    selected.value = value === true ? props.items.data.map((item) => item.id) : [];
}

function toggle(id: number, value: boolean | 'indeterminate') {
    selected.value = value === true ? [...new Set([...selected.value, id])] : selected.value.filter((existing) => existing !== id);
}

const when = (value: string | null) => (value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '');

function accountAge(value: string | null): string {
    if (!value) {
        return '';
    }

    const days = Math.floor((Date.now() - new Date(value).getTime()) / 86_400_000);

    return days < 1
        ? 'account made today'
        : days === 1
          ? 'account 1 day old'
          : days < 60
            ? `account ${days} days old`
            : `account made ${new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' })}`;
}

const approveUrl = (item: HeldItem) =>
    item.kind === 'reply'
        ? route('acp.forums.review.replies.approve', { post: item.id })
        : route('acp.forums.review.threads.approve', { thread: item.id });

const rejectUrl = (item: HeldItem) =>
    item.kind === 'reply'
        ? route('acp.forums.review.replies.reject', { post: item.id })
        : route('acp.forums.review.threads.reject', { thread: item.id });

const approve = (item: HeldItem) => router.post(approveUrl(item), {}, { preserveScroll: true });

function reject(item: HeldItem) {
    openConfirmDialog({
        title: `Reject this ${item.kind}`,
        description: `It is removed, and ${item.author?.nickname ?? 'its author'} is not told. Use the link to its author's page first if they should be warned or banned.`,
        confirmLabel: 'Reject',
        onConfirm: () => router.delete(rejectUrl(item), { preserveScroll: true }),
    });
}

function bulk(action: 'approve' | 'reject') {
    const send = () =>
        router.post(
            route('acp.forums.review.bulk'),
            { action, [kinds.value]: selected.value },
            { preserveScroll: true, onSuccess: () => (selected.value = []) },
        );

    if (action === 'approve') {
        send();

        return;
    }

    openConfirmDialog({
        title: `Reject ${selected.value.length} ${selected.value.length === 1 ? 'item' : 'items'}`,
        description: 'They are removed, and their authors are not told.',
        confirmLabel: 'Reject them',
        onConfirm: send,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Review queue" />

        <AdminLayout>
            <div class="flex w-full flex-1 flex-col gap-6">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Review queue</h1>
                    <p class="text-sm text-muted-foreground">
                        What the automod rules have held. Nobody but its author and the moderators can see it until you approve it, which posts it as
                        if it had never been held.
                    </p>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <nav class="flex gap-2" aria-label="What is held">
                        <Button as-child :variant="type === 'replies' ? 'default' : 'outline'" size="sm">
                            <Link :href="route('acp.forums.review.index', { type: 'replies' })">Replies ({{ counts.replies }})</Link>
                        </Button>
                        <Button as-child :variant="type === 'threads' ? 'default' : 'outline'" size="sm">
                            <Link :href="route('acp.forums.review.index', { type: 'threads' })">Threads ({{ counts.threads }})</Link>
                        </Button>
                    </nav>

                    <div v-if="selected.length" class="flex flex-wrap items-center gap-2">
                        <span class="text-sm text-muted-foreground">{{ selected.length }} selected</span>
                        <Button type="button" size="sm" @click="bulk('approve')"><Check class="mr-1 size-4" aria-hidden="true" /> Approve</Button>
                        <Button type="button" size="sm" variant="destructive" @click="bulk('reject')"
                            ><Trash2 class="mr-1 size-4" aria-hidden="true" /> Reject</Button
                        >
                    </div>
                </div>

                <Card>
                    <CardContent class="p-0">
                        <p v-if="items.data.length === 0" class="p-6 text-sm text-muted-foreground">
                            Nothing is waiting for review. When a rule holds a post, it will be here.
                        </p>
                        <Table v-else>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-10"
                                        ><Checkbox :model-value="all" aria-label="Select everything on this page" @update:model-value="toggleAll"
                                    /></TableHead>
                                    <TableHead>What was posted</TableHead>
                                    <TableHead>Who</TableHead>
                                    <TableHead>Why it was held</TableHead>
                                    <TableHead class="w-48" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="item in items.data" :key="`${item.kind}-${item.id}`" class="align-top">
                                    <TableCell>
                                        <Checkbox
                                            :model-value="selected.includes(item.id)"
                                            :aria-label="`Select the ${item.kind} by ${item.author?.nickname ?? 'an unknown member'}`"
                                            @update:model-value="(value) => toggle(item.id, value)"
                                        />
                                    </TableCell>
                                    <TableCell class="max-w-md whitespace-normal">
                                        <p class="font-medium">
                                            {{ item.title ?? 'A thread that is gone' }}
                                            <a
                                                v-if="item.url"
                                                :href="item.url"
                                                target="_blank"
                                                rel="noopener"
                                                class="ml-1 inline-flex align-middle text-muted-foreground"
                                                aria-label="Open it"
                                                ><ExternalLink class="size-3.5"
                                            /></a>
                                        </p>
                                        <p v-if="item.board" class="text-xs text-muted-foreground">{{ item.board }}</p>
                                        <p class="mt-1 text-sm break-words">{{ item.excerpt }}</p>
                                    </TableCell>
                                    <TableCell class="whitespace-normal">
                                        <p class="font-medium">{{ item.author?.nickname ?? 'A deleted account' }}</p>
                                        <p v-if="item.author" class="text-xs text-muted-foreground">
                                            {{ accountAge(item.author.account_created_at) }}<br />{{ item.author.approved_posts }} approved
                                            {{ item.author.approved_posts === 1 ? 'post' : 'posts' }}
                                        </p>
                                        <Link
                                            v-if="item.moderate_url"
                                            :href="item.moderate_url"
                                            class="mt-1 inline-flex items-center gap-1 text-xs underline"
                                        >
                                            <ShieldAlert class="size-3" aria-hidden="true" /> Warn or ban
                                        </Link>
                                    </TableCell>
                                    <TableCell class="max-w-xs text-sm whitespace-normal">
                                        {{ item.reason ?? 'The rule that held it has been deleted.' }}
                                        <p class="text-xs text-muted-foreground">{{ when(item.held_at) }}</p>
                                    </TableCell>
                                    <TableCell>
                                        <div class="flex justify-end gap-2">
                                            <Button type="button" size="sm" @click="approve(item)"
                                                ><Check class="mr-1 size-4" aria-hidden="true" /> Approve</Button
                                            >
                                            <Button type="button" size="sm" variant="outline" @click="reject(item)"
                                                ><Trash2 class="mr-1 size-4" aria-hidden="true" /> Reject</Button
                                            >
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <div v-if="items.meta.last_page > 1" class="flex items-center justify-between text-sm text-muted-foreground">
                    <span>Showing {{ items.meta.from }}–{{ items.meta.to }} of {{ items.meta.total }}</span>
                    <div class="flex gap-2">
                        <Button v-if="items.links.prev" as-child variant="outline" size="sm"
                            ><Link :href="items.links.prev" preserve-scroll>Previous</Link></Button
                        >
                        <Button v-if="items.links.next" as-child variant="outline" size="sm"
                            ><Link :href="items.links.next" preserve-scroll>Next</Link></Button
                        >
                    </div>
                </div>
            </div>

            <ConfirmDialog
                v-model:open="confirmDialogState.open"
                :title="confirmDialogState.title"
                :description="confirmDialogDescription"
                :confirm-label="confirmDialogState.confirmLabel"
                :confirm-variant="confirmDialogState.confirmVariant"
                @confirm="handleConfirmDialogConfirm"
                @cancel="handleConfirmDialogCancel"
            />
        </AdminLayout>
    </AppLayout>
</template>
