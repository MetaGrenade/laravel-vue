<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
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
import {
    Pin, Ellipsis, Eye, EyeOff, Pencil, Trash2, Lock, LockOpen, Flag
} from 'lucide-vue-next';

interface BoardSummary {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    category?: {
        title: string | null;
        slug: string | null;
    } | null;
}

interface ThreadPermissions {
    canReport: boolean;
    canModerate: boolean;
}

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
    author: string | null;
    replies: number;
    views: number;
    is_pinned: boolean;
    is_locked: boolean;
    is_published: boolean;
    last_reply_author: string | null;
    last_reply_at: string | null;
    permissions: ThreadPermissions;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
}

interface ThreadsPayload {
    data: ThreadSummary[];
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
    threads: ThreadsPayload;
    filters: {
        search?: string;
    };
    permissions: {
        canModerate: boolean;
    };
    reportReasons: ReportReasonOption[];
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const trail: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];
    if (props.board.category?.title) {
        trail.push({ title: props.board.category.title, href: '/forum' });
    }
    trail.push({ title: props.board.title, href: `/forum/${props.board.slug}` });
    return trail;
});

const searchQuery = ref(props.filters.search ?? '');

const reportReasons = computed(() => props.reportReasons ?? []);
const defaultReportReason = computed(() => reportReasons.value[0]?.value ?? '');
const hasReportReasons = computed(() => reportReasons.value.length > 0);

const threadsMetaFallback = computed<PaginationMeta>(() => {
    const total = props.threads.data?.length ?? 0;

    return {
        current_page: 1,
        last_page: 1,
        per_page: total > 0 ? total : 15,
        total,
        from: total > 0 ? 1 : null,
        to: total > 0 ? total : null,
    };
});

const threadsMeta = computed<PaginationMeta>(() => {
    return {
        ...threadsMetaFallback.value,
        ...(props.threads.meta ?? {}),
    };
});

const threadsPageCount = computed(() => {
    const meta = threadsMeta.value;
    const derived = Math.ceil(meta.total / Math.max(meta.per_page, 1));
    return Math.max(meta.last_page, derived || 1, 1);
});

const threadsRangeLabel = computed(() => {
    const meta = threadsMeta.value;

    if (meta.total === 0) {
        return 'No threads to display';
    }

    const from = meta.from ?? ((meta.current_page - 1) * meta.per_page + 1);
    const to = meta.to ?? Math.min(meta.current_page * meta.per_page, meta.total);

    const threadWord = meta.total === 1 ? 'thread' : 'threads';

    return `Showing ${from}-${to} of ${meta.total} ${threadWord}`;
});

const paginationPage = ref(threadsMeta.value.current_page);
const activeActionThreadId = ref<number | null>(null);
const threadReportDialogOpen = ref(false);
const threadReportTarget = ref<ThreadSummary | null>(null);
const threadReportForm = useForm({
    reason_category: '',
    reason: '',
    evidence_url: '',
    page: threadsMeta.value.current_page,
});

const selectedThreadReason = computed(() =>
    reportReasons.value.find((option) => option.value === threadReportForm.reason_category) ?? null,
);

const showActionColumn = computed(() =>
    props.permissions.canModerate ||
    props.threads.data.some((thread) => thread.permissions.canReport),
);

watch(() => threadsMeta.value.current_page, (page) => {
    paginationPage.value = page;
    threadReportForm.page = page;
});

let searchTimeout: ReturnType<typeof setTimeout> | undefined;

watch(searchQuery, (value) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        paginationPage.value = 1;
        threadReportForm.page = 1;
        router.get(route('forum.boards.show', { board: props.board.slug }), {
            search: value || undefined,
        }, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    }, 300);
});

watch(paginationPage, (page) => {
    const safePage = Math.min(Math.max(page, 1), threadsPageCount.value);

    if (safePage !== page) {
        paginationPage.value = safePage;
        return;
    }

    if (safePage === threadsMeta.value.current_page) return;

    threadReportForm.page = safePage;
    router.get(route('forum.boards.show', { board: props.board.slug }), {
        search: searchQuery.value || undefined,
        page: safePage,
    }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
});

onBeforeUnmount(() => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
});

watch(threadReportDialogOpen, (open) => {
    if (open) {
        if (!threadReportForm.reason_category && defaultReportReason.value) {
            threadReportForm.reason_category = defaultReportReason.value;
        }
    } else {
        threadReportTarget.value = null;
        threadReportForm.reset('reason_category', 'reason', 'evidence_url');
        threadReportForm.clearErrors();
    }
});

const openThreadReportDialog = (thread: ThreadSummary) => {
    if (!thread.permissions.canReport || !hasReportReasons.value) {
        return;
    }

    threadReportTarget.value = thread;
    threadReportDialogOpen.value = true;
};

const submitThreadReport = () => {
    const target = threadReportTarget.value;

    if (!target || !target.permissions.canReport || threadReportForm.processing || !hasReportReasons.value) {
        return;
    }

    threadReportForm.page = threadsMeta.value.current_page;

    threadReportForm.post(route('forum.threads.report', { board: props.board.slug, thread: target.slug }), {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onSuccess: () => {
            threadReportDialogOpen.value = false;
        },
    });
};

const performThreadAction = (
    thread: ThreadSummary,
    method: 'put' | 'delete',
    routeName: string,
    payload: Record<string, unknown> = {},
) => {
    activeActionThreadId.value = thread.id;

    const url = route(routeName, { board: props.board.slug, thread: thread.slug });
    const options = {
        preserveScroll: true,
        preserveState: false,
        replace: true,
        onFinish: () => {
            activeActionThreadId.value = null;
        },
    } as const;

    if (method === 'delete') {
        router.delete(url, {}, {
            ...options,
        });
    } else {
        router.put(url, payload, {
            ...options,
        });
    }
};

const publishThread = (thread: ThreadSummary) => {
    performThreadAction(thread, 'put', 'forum.threads.publish');
};

const unpublishThread = (thread: ThreadSummary) => {
    performThreadAction(thread, 'put', 'forum.threads.unpublish');
};

const lockThread = (thread: ThreadSummary) => {
    performThreadAction(thread, 'put', 'forum.threads.lock');
};

const unlockThread = (thread: ThreadSummary) => {
    performThreadAction(thread, 'put', 'forum.threads.unlock');
};

const renameThread = (thread: ThreadSummary) => {
    const newTitle = window.prompt('Update thread title', thread.title)?.trim();

    if (!newTitle || newTitle === thread.title) {
        return;
    }

    performThreadAction(thread, 'put', 'forum.threads.update', { title: newTitle });
};

const deleteThread = (thread: ThreadSummary) => {
    if (!window.confirm(`Are you sure you want to delete "${thread.title}"? This action cannot be undone.`)) {
        return;
    }

    performThreadAction(thread, 'delete', 'forum.threads.destroy');
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Forum • ${props.board.title}`" />
        <Dialog v-model:open="threadReportDialogOpen">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Report thread</DialogTitle>
                    <DialogDescription>
                        Let the moderation team know why
                        <span v-if="threadReportTarget" class="font-semibold">
                            &ldquo;{{ threadReportTarget.title }}&rdquo;
                        </span>
                        <span v-else>this discussion</span>
                        needs attention. Provide as much context as you can so we can review it quickly.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-5" @submit.prevent="submitThreadReport">
                    <div class="space-y-2">
                        <Label for="board_thread_report_reason">Reason</Label>
                        <select
                            id="board_thread_report_reason"
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
                        <Label for="board_thread_report_details">Additional details</Label>
                        <Textarea
                            id="board_thread_report_details"
                            v-model="threadReportForm.reason"
                            placeholder="Share specific quotes, timeline, or any other details that explain the problem."
                            class="min-h-[120px]"
                            :disabled="threadReportForm.processing"
                        />
                        <p class="text-xs text-muted-foreground">
                            Optional, but detailed reports help moderators resolve issues faster.
                        </p>
                        <p v-if="threadReportForm.errors.reason" class="text-sm text-destructive">
                            {{ threadReportForm.errors.reason }}
                        </p>
                    </div>
                    <div class="space-y-2">
                        <Label for="board_thread_report_evidence">Supporting link (optional)</Label>
                        <Input
                            id="board_thread_report_evidence"
                            v-model="threadReportForm.evidence_url"
                            type="url"
                            placeholder="https://example.com/screenshot-or-proof"
                            :disabled="threadReportForm.processing"
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
        <div class="p-4 space-y-6">
            <!-- Forum Header -->
            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
                <h1 class="text-2xl font-bold text-green-500">{{ props.board.title }}</h1>
                <div class="flex w-full max-w-md space-x-2">
                    <Input
                        v-model="searchQuery"
                        :placeholder="`Search ${props.board.title}...`"
                    />
                    <Button variant="secondary" class="cursor-pointer">
                        New Thread
                    </Button>
                </div>
            </header>
            <!-- Top Pagination and Search -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ threadsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(threadsMeta.per_page, 1)"
                    :total="threadsMeta.total"
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
            </div>

            <!-- Threads Table -->
            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Thread Title</TableHead>
                            <TableHead class="text-center">Replies</TableHead>
                            <TableHead class="text-center">Views</TableHead>
                            <TableHead>Last Reply</TableHead>
                            <TableHead v-if="showActionColumn" class="text-center">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="thread in props.threads.data"
                            :key="thread.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                        >
                            <TableCell>
                                <Link
                                    :href="route('forum.threads.show', { board: props.board.slug, thread: thread.slug })"
                                    :class="{'font-semibold': thread.is_pinned, 'font-normal': !thread.is_pinned}"
                                    class="hover:underline"
                                >
                                    {{ thread.title }}
                                    <Pin v-if="thread.is_pinned" class="h-4 w-4 text-green-500 inline-block ml-1" />
                                    <Lock
                                        v-if="thread.is_locked"
                                        class="h-4 w-4 text-muted-foreground inline-block ml-1"
                                    />
                                </Link>
                                <div class="flex items-center gap-2 text-xs text-gray-500">
                                    <span>By {{ thread.author ?? 'Unknown' }}</span>
                                    <span
                                        v-if="!thread.is_published"
                                        class="rounded bg-amber-200 px-1.5 py-0.5 text-[0.625rem] font-semibold uppercase text-amber-900"
                                    >
                                        Unpublished
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell class="text-center">{{ thread.replies }}</TableCell>
                            <TableCell class="text-center">{{ thread.views }}</TableCell>
                            <TableCell>
                                <div class="text-sm">{{ thread.last_reply_author ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ thread.last_reply_at ?? '—' }}</div>
                            </TableCell>
                            <TableCell v-if="showActionColumn" class="text-center">
                                <template v-if="thread.permissions.canReport || props.permissions.canModerate">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="outline" size="icon">
                                                <Ellipsis class="h-8 w-8" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent>
                                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuGroup v-if="thread.permissions.canReport">
                                                <DropdownMenuItem
                                                    class="text-orange-500"
                                                    :disabled="threadReportForm.processing || !hasReportReasons"
                                                    @select="openThreadReportDialog(thread)"
                                                >
                                                    <Flag class="h-8 w-8" />
                                                    <span>Report</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <template v-if="props.permissions.canModerate">
                                                <DropdownMenuSeparator v-if="thread.permissions.canReport" />
                                                <DropdownMenuLabel>Mod Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem
                                                        v-if="!thread.is_published"
                                                        :disabled="activeActionThreadId === thread.id"
                                                        @select="publishThread(thread)"
                                                    >
                                                        <Eye class="h-8 w-8" />
                                                        <span>Publish</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="thread.is_published"
                                                        :disabled="activeActionThreadId === thread.id"
                                                        @select="unpublishThread(thread)"
                                                    >
                                                        <EyeOff class="h-8 w-8" />
                                                        <span>Unpublish</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="!thread.is_locked"
                                                        :disabled="activeActionThreadId === thread.id"
                                                        @select="lockThread(thread)"
                                                    >
                                                        <Lock class="h-8 w-8" />
                                                        <span>Lock</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="thread.is_locked"
                                                        :disabled="activeActionThreadId === thread.id"
                                                        @select="unlockThread(thread)"
                                                    >
                                                        <LockOpen class="h-8 w-8" />
                                                        <span>Unlock</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuGroup>
                                                    <DropdownMenuItem
                                                        class="text-blue-500"
                                                        :disabled="activeActionThreadId === thread.id"
                                                        @select="renameThread(thread)"
                                                    >
                                                        <Pencil class="h-8 w-8" />
                                                        <span>Edit Title</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    class="text-red-500"
                                                    :disabled="activeActionThreadId === thread.id"
                                                    @select="deleteThread(thread)"
                                                >
                                                    <Trash2 class="h-8 w-8" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </template>
                                <span v-else class="text-sm text-muted-foreground">—</span>
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="props.threads.data.length === 0">
                            <TableCell
                                :colspan="showActionColumn ? 5 : 4"
                                class="text-center text-sm text-gray-600 dark:text-gray-300"
                            >
                                No threads found.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Bottom Pagination -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <div class="text-sm text-muted-foreground text-center md:text-left">
                    {{ threadsRangeLabel }}
                </div>
                <Pagination
                    v-slot="{ page, pageCount }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(threadsMeta.per_page, 1)"
                    :total="threadsMeta.total"
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
            </div>
        </div>
    </AppLayout>
</template>
