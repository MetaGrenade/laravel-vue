<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';

// Import shadcn‑vue components
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
    { title: 'General Gaming', href: '/forum/general-gaming' },
    { title: 'PC Gaming', href: '/forum/general-gaming/pc-gaming' },
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
                <Input
                    v-model="searchQuery"
                    placeholder="Search threads..."
                    class="w-full md:w-1/3"
                />
            </div>

            <!-- Threads Table -->
            <div class="overflow-x-auto">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Thread Title</TableHead>
                            <TableHead>Author</TableHead>
                            <TableHead>Replies</TableHead>
                            <TableHead>Views</TableHead>
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
                                    class="font-semibold hover:underline"
                                >
                                    <Pin v-if="thread.pinned" class="mr-2 h-4 w-4 text-green-500 inline-block" />
                                    {{ thread.title }}
                                </Link>
                            </TableCell>
                            <TableCell>{{ thread.author }}</TableCell>
                            <TableCell>{{ thread.replies }}</TableCell>
                            <TableCell>{{ thread.views }}</TableCell>
                            <TableCell>
                                <div class="text-sm">{{ thread.lastReplyAuthor }}</div>
                                <div class="text-xs text-gray-500">{{ thread.lastReplyTime }}</div>
                            </TableCell>
                            <TableCell class="text-center">
<!--                                <span v-if="thread.unread" class="text-blue-500 font-medium">Unread</span>-->
<!--                                <span v-else class="text-gray-500">Read</span>-->
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
            <div class="flex justify-center">
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
