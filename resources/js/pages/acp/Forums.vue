<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import {
    Folder, MessageSquare, CheckCircle, Ellipsis, EyeOff, Shield,
    Trash2, MoveUp, MoveDown, Pencil, MessageSquareShare, Lock
} from 'lucide-vue-next';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';

// Permission checks
const { hasPermission } = usePermissions();
const createForums = computed(() => hasPermission('forums.acp.create'));
const editForums = computed(() => hasPermission('forums.acp.edit'));
const lockForums = computed(() => hasPermission('forums.acp.lock'));
const migrateForums = computed(() => hasPermission('forums.acp.migrate'));
const moveForums = computed(() => hasPermission('forums.acp.move'));
const publishForums = computed(() => hasPermission('forums.acp.publish'));
const deleteForums = computed(() => hasPermission('forums.acp.delete'));
const permissionsForums = computed(() => hasPermission('forums.acp.permissions'));

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
                        <Button v-if="createForums" variant="success" class="text-sm text-white bg-green-500 hover:bg-green-600">
                            Create Category
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
                                    <DropdownMenuSeparator v-if="moveForums||publishForums" />
                                    <DropdownMenuGroup v-if="moveForums">
                                        <DropdownMenuItem>
                                            <MoveUp class="h-8 w-8" />
                                            <span>Move Up</span>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem>
                                            <MoveDown class="h-8 w-8" />
                                            <span>Move Down</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuGroup v-if="publishForums">
                                        <DropdownMenuItem>
                                            <EyeOff class="h-8 w-8" />
                                            <span>Unpublish</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="editForums||permissionsForums" />
                                    <DropdownMenuGroup v-if="editForums">
                                        <DropdownMenuItem class="text-blue-500">
                                            <Pencil class="h-8 w-8" />
                                            <span>Edit</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuGroup v-if="permissionsForums">
                                        <DropdownMenuItem>
                                            <Shield class="h-8 w-8" />
                                            <span>Permissions</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="migrateForums" />
                                    <DropdownMenuGroup v-if="migrateForums">
                                        <DropdownMenuItem>
                                            <MessageSquareShare class="h-8 w-8" />
                                            <span>Migrate Children</span>
                                        </DropdownMenuItem>
                                    </DropdownMenuGroup>
                                    <DropdownMenuSeparator v-if="deleteForums" />
                                    <DropdownMenuItem v-if="deleteForums" class="text-red-500" disabled>
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
                                        <Link
                                            :href="sub.slug ? route('forum.boards.show', { board: sub.slug }) : '#'"
                                            class="font-semibold hover:underline"
                                        >
                                            {{ sub.title }}
                                        </Link>
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
                                            <DropdownMenuSeparator v-if="moveForums||publishForums" />
                                            <DropdownMenuGroup v-if="moveForums">
                                                <DropdownMenuItem>
                                                    <MoveUp class="h-8 w-8" />
                                                    <span>Move Up</span>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem>
                                                    <MoveDown class="h-8 w-8" />
                                                    <span>Move Down</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuGroup v-if="publishForums">
                                                <DropdownMenuItem>
                                                    <EyeOff class="h-8 w-8" />
                                                    <span>Unpublish</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator v-if="editForums" />
                                            <DropdownMenuGroup v-if="editForums">
                                                <DropdownMenuItem class="text-blue-500">
                                                    <Pencil class="h-8 w-8" />
                                                    <span>Edit Category</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuGroup v-if="permissionsForums">
                                                <DropdownMenuItem>
                                                    <Shield class="h-8 w-8" />
                                                    <span>Permissions</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator v-if="lockForums||migrateForums" />
                                            <DropdownMenuGroup v-if="lockForums">
                                                <DropdownMenuItem>
                                                    <Lock class="h-8 w-8" />
                                                    <span>Lock Threads</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuGroup v-if="migrateForums">
                                                <DropdownMenuItem>
                                                    <MessageSquareShare class="h-8 w-8" />
                                                    <span>Migrate Threads</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuGroup>
                                            <DropdownMenuSeparator v-if="deleteForums" />
                                            <DropdownMenuItem v-if="deleteForums" class="text-red-500" disabled>
                                                <Trash2 class="h-8 w-8" />
                                                <span>Delete</span>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
