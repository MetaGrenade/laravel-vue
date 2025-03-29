<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';

// Import shadcn‑vue components
import Avatar from '@/components/ui/avatar/Avatar.vue';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
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
    Pin, PinOff, Ellipsis, Eye, EyeOff, Pencil, Trash2, Lock, LockOpen, Flag, MessageSquareLock
} from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Forum', href: '/forum' },
    { title: 'General Gaming', href: '/forum' },
    { title: 'PC Gaming', href: '/forum/threads' },
    { title: 'Thread Title', href: '#' },
];

// Dummy thread title
const threadTitle = ref("What are your all-time most played games at the end of 2024?");

// Dummy data for thread posts
interface Post {
    id: number;
    author: string;
    avatar: string;
    role: string;
    joinDate: string;
    postCount: number;
    postNumber: number;
    postedAt: string;
    content: string;
    signature: string;
}
const posts = ref<Post[]>([
    {
        id: 1,
        author: "Admin",
        avatar: "/images/avatar-admin.png",
        role: "Administrator",
        joinDate: "2022-01-01",
        postCount: 120,
        postNumber: 1,
        postedAt: "2023-07-28 08:30 AM",
        content: `<p>This is the original post of the thread. Lorem ipsum dolor sit amet, consectetur adipiscing elit. Vestibulum nec ligula vel arcu interdum malesuada.</p>`,
        signature: "Admin • Always here to help.",
    },
    {
        id: 2,
        author: "GamerX",
        avatar: "/images/avatar-gamerx.png",
        role: "Member",
        joinDate: "2022-03-15",
        postCount: 45,
        postNumber: 2,
        postedAt: "2023-07-28 09:15 AM",
        content: `<p>I totally agree! My most played game is XYZ, which I’ve been enjoying for years now.</p>`,
        signature: "GamerX • Keep on gaming!",
    },
    {
        id: 3,
        author: "PlayerOne",
        avatar: "/images/avatar-playerone.png",
        role: "Member",
        joinDate: "2023-01-10",
        postCount: 10,
        postNumber: 3,
        postedAt: "2023-07-28 09:45 AM",
        content: `<p>For me, it's all about strategy. I love games that challenge my tactical skills.</p>`,
        signature: "PlayerOne • Strategist at heart.",
    },
]);

// Reply text for new reply input
const replyText = ref("");

// Dummy function to simulate posting a reply
function postReply() {
    if (replyText.value.trim() === "") return;
    const newPostNumber = posts.value.length + 1;
    posts.value.push({
        id: Date.now(),
        author: "Current User",
        avatar: "/images/avatar-placeholder.png",
        role: "Member",
        joinDate: "2023-06-01",
        postCount: 1,
        postNumber: newPostNumber,
        postedAt: new Date().toLocaleString(),
        content: `<p>${replyText.value}</p>`,
        signature: "Current User • Happy to help.",
    });
    replyText.value = "";
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forum Thread View" />
        <div class="container mx-auto p-4 space-y-8">
            <!-- Thread Title -->
            <div class="mb-4">
                <h1 id="thread_title" class="text-3xl font-bold text-green-500">
                    <Pin class="h-8 w-8 inline-block" />
                    {{ threadTitle }}
                </h1>
            </div>

            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
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
                <div class="flex w-full max-w-md space-x-2 justify-end">
                    <Button variant="secondary" class="cursor-pointer text-yellow-500" disabled>
                        <Lock class="h-8 w-8" />
                        Locked
                    </Button>
                    <a href="#post_reply">
                        <Button variant="secondary" class="cursor-pointer">
                            Post Reply
                        </Button>
                    </a>
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
                                <DropdownMenuItem class="text-orange-500">
                                    <Flag class="h-8 w-8" />
                                    <span>Report</span>
                                </DropdownMenuItem>
                            </DropdownMenuGroup>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel>Mod Actions</DropdownMenuLabel>
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
                                <DropdownMenuItem>
                                    <Pin class="h-8 w-8" />
                                    <span>Pin</span>
                                </DropdownMenuItem>
                                <DropdownMenuItem>
                                    <PinOff class="h-8 w-8" />
                                    <span>Unpin</span>
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
                </div>
            </header>

            <!-- Posts List -->
            <div class="space-y-6">
                <div
                    v-for="post in posts"
                    :key="post.id"
                    class="flex flex-col md:flex-row gap-4 rounded-xl border p-4 shadow-sm"
                >
                    <!-- Left Side: User Info -->
                    <div class="flex-shrink-0 w-full md:w-1/5 border-r pr-4">
                        <Avatar :src="post.avatar" alt="User avatar" class="h-24 w-24 rounded-full mb-2" />
                        <div class="font-bold text-lg">{{ post.author }}</div>
                        <div class="text-sm text-gray-500">{{ post.role }}</div>
                        <div class="mt-2 text-xs text-gray-600">
                            Joined: <span class="font-medium">{{ post.joinDate }}</span>
                        </div>
                        <div class="mt-1 text-xs text-gray-600">
                            Posts: <span class="font-medium">{{ post.postCount }}</span>
                        </div>
                    </div>

                    <!-- Right Side: Post Content -->
                    <div class="flex-1">
                        <div class="flex justify-between items-center border-b pb-2 mb-4">
                            <div class="text-sm text-gray-500">{{ post.postedAt }}</div>
                            <div class="text-sm font-medium text-gray-500">#{{ post.postNumber }}</div>
                        </div>
                        <!-- Post Body -->
                        <div class="prose dark:prose-dark" v-html="post.content"></div>
                        <!-- Forum Signature -->
                        <div class="mt-4 border-t pt-2 text-xs text-gray-500">
                            {{ post.signature }}
                        </div>
                    </div>
                </div>
            </div>

            <header class="flex flex-col items-center justify-between space-y-4 md:flex-row md:space-y-0">
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
                <div class="flex w-full max-w-md space-x-2 justify-end">
                    <a href="#thread_title">
                        <Button variant="secondary" class="cursor-pointer">
                            Go To Top
                        </Button>
                    </a>
                </div>
            </header>

            <Alert variant="warning">
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
                    <Textarea v-model="replyText" placeholder="Write your reply here..." class="w-full rounded-md" />

                    <Button variant="secondary" class="cursor-pointer bg-green-500 hover:bg-green-600">
                        Submit Reply
                    </Button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
