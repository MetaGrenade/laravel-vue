<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
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

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forum', href: '/forum' },
    { title: 'General Gaming', href: '/forum' },
    { title: 'PC Gaming', href: '/forum/threads' },
];

// Define interface for a Thread
interface Thread {
    id: number;
    title: string;
    author: string;
    replies: number;
    views: number;
    lastReplyAuthor: string;
    lastReplyTime: string;
    pinned: boolean;
    unread: boolean;
}

// Dummy data for forum threads
const threads = ref<Thread[]>([
    {
        id: 1,
        title: 'Welcome to PC Gaming',
        author: 'Admin',
        replies: 10,
        views: 150,
        lastReplyAuthor: 'User1',
        lastReplyTime: '2023-07-28 09:00',
        pinned: true,
        unread: false,
    },
    {
        id: 2,
        title: 'Latest Game Releases',
        author: 'Moderator',
        replies: 25,
        views: 300,
        lastReplyAuthor: 'User2',
        lastReplyTime: '2023-07-28 10:30',
        pinned: false,
        unread: true,
    },
    {
        id: 3,
        title: 'Tips and Tricks for Competitive Gaming',
        author: 'GamerX',
        replies: 15,
        views: 250,
        lastReplyAuthor: 'User3',
        lastReplyTime: '2023-07-28 11:00',
        pinned: false,
        unread: false,
    },
    // Add more threads as needed...
]);

// Search query for filtering threads
const searchQuery = ref('');
const filteredThreads = computed(() => {
    if (!searchQuery.value) return threads.value;
    const q = searchQuery.value.toLowerCase();
    return threads.value.filter(thread =>
        thread.title.toLowerCase().includes(q) ||
        thread.author.toLowerCase().includes(q)
    );
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forum • PC Gaming" />
        <div class="p-4 space-y-6">
            <!-- Forum Header -->
            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
                <h1 class="text-2xl font-bold text-green-500">PC Games</h1>
                <div class="flex w-full max-w-md space-x-2">
                    <Input
                        v-model="searchQuery"
                        placeholder="Search PC Games..."
                    />
                    <Button variant="secondary" class="cursor-pointer">
                        New Thread
                    </Button>
                </div>
            </header>
            <!-- Top Pagination and Search -->
            <div class="flex flex-col items-center justify-between gap-4 md:flex-row">
                <Pagination v-slot="{ page }" :items-per-page="10" :total="100" :sibling-count="1" show-edges :default-page="1">
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
                            v-for="thread in filteredThreads"
                            :key="thread.id"
                            class="hover:bg-gray-50 dark:hover:bg-gray-900"
                        >
                            <TableCell>
                                <Link
                                    :href="route('forum.thread.view', { id: thread.id })"
                                    :class="{'font-semibold': thread.unread, 'font-normal': !thread.unread}"
                                    class="hover:underline"
                                >
                                    {{ thread.title }}
                                    <Pin v-if="thread.pinned" class="h-4 w-4 text-green-500 inline-block" />
                                </Link>
                                <div class="text-xs text-gray-500">By {{ thread.author }}</div>
                            </TableCell>
                            <TableCell class="text-center">{{ thread.replies }}</TableCell>
                            <TableCell class="text-center">{{ thread.views }}</TableCell>
                            <TableCell>
                                <div class="text-sm">{{ thread.lastReplyAuthor }}</div>
                                <div class="text-xs text-gray-500">{{ thread.lastReplyTime }}</div>
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
                        <TableRow v-if="filteredThreads.length === 0">
                            <TableCell colspan="7" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                No threads found.
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <!-- Bottom Pagination -->
            <div class="flex">
                <Pagination v-slot="{ page }" :items-per-page="10" :total="100" :sibling-count="1" show-edges :default-page="1">
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
