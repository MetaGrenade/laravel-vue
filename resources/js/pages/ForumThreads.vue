<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
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
import {
    Pin, Ellipsis, Eye, EyeOff, Pencil, Trash2, Lock, LockOpen
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

interface ThreadSummary {
    id: number;
    title: string;
    slug: string;
    author: string | null;
    replies: number;
    views: number;
    is_pinned: boolean;
    is_locked: boolean;
    last_reply_author: string | null;
    last_reply_at: string | null;
}

interface PaginationMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

interface ThreadsPayload {
    data: ThreadSummary[];
    meta: PaginationMeta;
}

const props = defineProps<{
    board: BoardSummary;
    threads: ThreadsPayload;
    filters: {
        search?: string;
    };
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
const paginationPage = ref(props.threads.meta.current_page);

watch(() => props.threads.meta.current_page, (page) => {
    paginationPage.value = page;
});

let searchTimeout: ReturnType<typeof setTimeout> | undefined;

watch(searchQuery, (value) => {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        paginationPage.value = 1;
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
    if (page === props.threads.meta.current_page) return;

    router.get(route('forum.boards.show', { board: props.board.slug }), {
        search: searchQuery.value || undefined,
        page,
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
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Forum • ${props.board.title}`" />
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
                <Pagination
                    v-slot="{ page }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(props.threads.meta.per_page, 1)"
                    :total="props.threads.meta.total"
                    :sibling-count="1"
                    show-edges
                >
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
                            <TableHead></TableHead>
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
                                    <Pin v-if="thread.is_pinned" class="h-4 w-4 text-green-500 inline-block" />
                                </Link>
                                <div class="text-xs text-gray-500">By {{ thread.author ?? 'Unknown' }}</div>
                            </TableCell>
                            <TableCell class="text-center">{{ thread.replies }}</TableCell>
                            <TableCell class="text-center">{{ thread.views }}</TableCell>
                            <TableCell>
                                <div class="text-sm">{{ thread.last_reply_author ?? '—' }}</div>
                                <div class="text-xs text-gray-500">{{ thread.last_reply_at ?? '—' }}</div>
                            </TableCell>
                            <TableCell class="text-center">
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
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="props.threads.data.length === 0">
                            <TableCell colspan="7" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                No threads found.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Bottom Pagination -->
            <div class="flex">
                <Pagination
                    v-slot="{ page }"
                    v-model:page="paginationPage"
                    :items-per-page="Math.max(props.threads.meta.per_page, 1)"
                    :total="props.threads.meta.total"
                    :sibling-count="1"
                    show-edges
                >
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
                </Pagination>
            </div>
        </div>
    </AppLayout>
</template>
