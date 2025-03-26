<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import {
    Folder, MessageSquare, CheckCircle, Ellipsis, Eye, EyeOff, Shield,
    Trash2, MoveUp, MoveDown, Pencil, MessageSquareShare, Lock
} from 'lucide-vue-next';
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

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Forums ACP',
        href: '/acp/forums',
    },
];

// Dummy forum statistics
const forumStats = [
    { title: 'Total Categories', value: '2', icon: Folder },
    { title: 'Total Subcategories', value: '4', icon: MessageSquare },
    { title: 'Total Threads', value: '388', icon: CheckCircle },
    { title: 'Total Posts', value: '7712', icon: MessageSquare },
];

// Dummy data for forum categories with subcategories
const forumCategories = [
    {
        title: 'Gaming',
        subCategories: [
            {
                title: 'PC Games',
                threadCount: 123,
                postCount: 4567,
                latestPost: {
                    title: 'Latest PC game discussion',
                    author: 'GamerOne',
                    date: '2 hours ago',
                },
            },
            {
                title: 'Console Games',
                threadCount: 89,
                postCount: 2345,
                latestPost: {
                    title: 'Upcoming console releases',
                    author: 'ConsoleFan',
                    date: '3 hours ago',
                },
            },
        ],
    },
    {
        title: 'Hardware',
        subCategories: [
            {
                title: 'PC Hardware',
                threadCount: 101,
                postCount: 500,
                latestPost: {
                    title: 'Best GPU deals',
                    author: 'TechGuru',
                    date: '1 day ago',
                },
            },
            {
                title: 'Peripherals',
                threadCount: 75,
                postCount: 300,
                latestPost: {
                    title: 'Mechanical keyboard reviews',
                    author: 'KeyMaster',
                    date: '5 hours ago',
                },
            },
        ],
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Forums ACP" />

        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Forum Stats Section -->
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in forumStats"
                        :key="index"
                        class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Forum Categories Management Section -->
                <div>
                    <div class="flex items-center justify-between pb-4">
                        <h2 class="mb-4 text-xl font-bold">Manage Forum Categories</h2>
                        <Button variant="success" class="text-sm text-white bg-green-500 hover:bg-green-600">
                            New Category
                        </Button>
                    </div>
                    <div
                        v-for="(category, catIndex) in forumCategories"
                        :key="catIndex"
                        class="mb-6 rounded-lg border border-sidebar-border/70 shadow hover:shadow-lg transition"
                    >
                        <!-- Category Card Header -->
                        <div class="flex items-center justify-between p-4 bg-gray-100 dark:bg-neutral-900 rounded-t-lg">
                            <h3 class="text-xl font-bold">{{ category.title }}</h3>
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
                                            <MoveUp class="h-8 w-8" />
                                            <span>Move Up</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem>
                                            <MoveDown class="h-8 w-8" />
                                            <span>Move Down</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem>
                                            <EyeOff class="h-8 w-8" />
                                            <span>Unpublish</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuGroup>
                                        <DropdownMenuItem class="text-blue-500">
                                            <Pencil class="h-8 w-8" />
                                            <span>Edit</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem>
                                            <Shield class="h-8 w-8" />
                                            <span>Permissions</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuGroup>
                                        <DropdownMenuItem>
                                            <MessageSquareShare class="h-8 w-8" />
                                            <span>Migrate Children</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem class="text-red-500" disabled>
                                        <Trash2 class="h-8 w-8" />
                                        <span>Delete</span>
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                        <!-- Subcategories Table -->
                        <div class="divide-y">
                            <div
                                v-for="(sub, subIndex) in category.subCategories"
                                :key="subIndex"
                                class="flex items-center p-4 hover:bg-gray-50 dark:hover:bg-neutral-800 transition even:bg-gray-50 dark:even:bg-neutral-900"
                            >
                                <!-- Subcategory Icon -->
                                <div class="mr-4">
                                    <Folder class="h-8 w-8 text-gray-600" />
                                </div>
                                <!-- Subcategory Details -->
                                <div class="flex-1">
                                    <h4 class="font-semibold text-lg">
                                        <a href="#" class="hover:underline">{{ sub.title }}</a>
                                    </h4>
                                    <div class="text-xs text-gray-500">
                                        Latest: <span class="font-medium">{{ sub.latestPost.title }}</span> by
                                        {{ sub.latestPost.author }} <span>({{ sub.latestPost.date }})</span>
                                    </div>
                                </div>
                                <!-- Thread & Post Counts -->
                                <div class="w-24 text-center">
                                    <div class="font-bold">{{ sub.threadCount }}</div>
                                    <div class="text-xs text-gray-500">Threads</div>
                                </div>
                                <div class="w-24 text-center">
                                    <div class="font-bold">{{ sub.postCount }}</div>
                                    <div class="text-xs text-gray-500">Posts</div>
                                </div>
                                <!-- Actions -->
                                <div class="w-32 text-right">
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
                                                    <MoveUp class="h-8 w-8" />
                                                    <span>Move Up</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem>
                                                    <MoveDown class="h-8 w-8" />
                                                    <span>Move Down</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem>
                                                    <EyeOff class="h-8 w-8" />
                                                    <span>Unpublish</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuGroup>
                                                <DropdownMenuItem class="text-blue-500">
                                                    <Pencil class="h-8 w-8" />
                                                    <span>Edit Category</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem>
                                                    <Shield class="h-8 w-8" />
                                                    <span>Permissions</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuGroup>
                                                <DropdownMenuItem>
                                                    <Lock class="h-8 w-8" />
                                                    <span>Lock Threads</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem>
                                                    <MessageSquareShare class="h-8 w-8" />
                                                    <span>Migrate Threads</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem class="text-red-500" disabled>
                                                <Trash2 class="h-8 w-8" />
                                                <span>Delete</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </div>
                        <!-- Create New Subcategory Button -->
                        <div class="p-4">
                            <Button variant="success" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                New {{ category.title }} Category
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
