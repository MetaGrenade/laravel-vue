<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
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
import { usePermissions } from '@/composables/usePermissions';
import {
    Pagination,
    PaginationEllipsis,
    PaginationFirst,
    PaginationLast,
    PaginationList,
    PaginationListItem,
    PaginationNext,
    PaginationPrev,
} from '@/components/ui/pagination';
import {
    FileText, Edit3, MessageCircle, CheckCircle, Ellipsis,
    Eye, EyeOff, Trash2, Pencil, Archive, ArchiveRestore, Users as UsersIcon, UserPlus, UserX, Activity
} from 'lucide-vue-next';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';

dayjs.extend(relativeTime);

// Permission checks
const { hasPermission } = usePermissions();
const createBlogs = computed(() => hasPermission('blogs.acp.create'));
const editBlogs = computed(() => hasPermission('blogs.acp.edit'));
const publishBlogs = computed(() => hasPermission('blogs.acp.publish'));
const deleteBlogs = computed(() => hasPermission('blogs.acp.delete'));

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blogs ACP',
        href: '/acp/blogs',
    },
];

// Expect that the admin controller passes a "blogs" prop (paginated collection)
const props = defineProps<{
    blogs: {
        data: Array<{
            id: number;
            title: string;
            slug: string;
            user: {
                id: number;
                name: string;
                email: string;
                email_verified_at: string;
                roles: Array<{ name: string }>;
                created_at: string;
            };
            status: string;
            created_at: string;
        }>;
        current_page: number;
        per_page: number;
        total: number;
    };
    blogStats: {
        total: number;
        published: number;
        draft: number;
        archived: number;
    };
}>();

// Dummy blog statistics with Lucide icons
const stats = [
    { title: 'Total Posts', value: props.blogStats.total, icon: FileText },
    { title: 'Published Posts', value: props.blogStats.published, icon: CheckCircle },
    { title: 'Draft Posts', value: props.blogStats.draft, icon: Edit3 },
    { title: 'Archived Posts', value: props.blogStats.archived, icon: Archive },
];

// Search query for filtering blog posts
const searchQuery = ref('');

// Computed property to filter blog posts based on the search query
const filteredBlogPosts = computed(() => {
    if (!searchQuery.value) return props.blogs.data;
    const q = searchQuery.value.toLowerCase();
    return props.blogs.data.filter((post: any) =>
        post.title.toLowerCase().includes(q) ||
        post.user.name.toLowerCase().includes(q) ||
        post.user.email.toLowerCase().includes(q) ||
        post.status.toLowerCase().includes(q)
    );
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Blogs ACP" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Blog Stats Section -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in stats"
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

                <!-- Blog Posts Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">Blog Posts</h2>
                        <div class="flex space-x-2">
                            <Input
                                v-model="searchQuery"
                                placeholder="Search Blogs..."
                                class="w-full rounded-md"
                            />
                            <!-- Create New Post Button visible only if permission is granted -->
                            <Link :href="route('acp.blogs.create')" v-if="createBlogs">
                                <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                    Create New Post
                                </Button>
                            </Link>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead class="text-center">Author</TableHead>
                                    <TableHead class="text-center">Created</TableHead>
                                    <TableHead class="text-center">Status</TableHead>
                                    <TableHead class="text-center">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="(post, index) in filteredBlogPosts" :key="post.id">
                                    <TableCell>{{ post.id }}</TableCell>
                                    <TableCell>{{ post.title }}</TableCell>
                                    <TableCell class="text-center">{{ post.user.name }}</TableCell>
                                    <TableCell class="text-center">{{ dayjs(post.created_at).fromNow() }}</TableCell>
                                    <TableCell class="text-center" :class="{
                                    'text-green-500': post.status === 'published',
                                    'text-red-500': post.status === 'archived',
                                    'text-blue-500': post.status === 'draft'}">
                                        {{ post.status }}</TableCell>
                                    <TableCell class="text-center">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button variant="outline" size="icon">
                                                    <Ellipsis class="h-8 w-8" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent>
                                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                                <DropdownMenuSeparator v-if="publishBlogs" />
                                                <DropdownMenuGroup v-if="publishBlogs">
                                                    <DropdownMenuItem v-if="post.status === 'draft'">
                                                        <Eye class="mr-2" />
                                                        <span>Publish</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem v-if="post.status === 'published'">
                                                        <EyeOff class="mr-2" />
                                                        <span>Unpublish</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem v-if="post.status === 'archived'">
                                                        <ArchiveRestore class="mr-2" />
                                                        <span>Un Archive</span>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem v-if="post.status !== 'archived'">
                                                        <Archive class="mr-2" />
                                                        <span>Archive</span>
                                                    </DropdownMenuItem>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="editBlogs" />
                                                <DropdownMenuGroup v-if="editBlogs">
                                                    <Link :href="route('acp.blogs.edit', { blog: post.id })">
                                                        <DropdownMenuItem class="text-blue-500">
                                                            <Pencil class="mr-2" />
                                                            <span>Edit</span>
                                                        </DropdownMenuItem>
                                                    </Link>
                                                </DropdownMenuGroup>
                                                <DropdownMenuSeparator v-if="deleteBlogs" />
                                                <DropdownMenuItem v-if="deleteBlogs" class="text-red-500"
                                                    @click="$inertia.delete(route('acp.blogs.destroy',{ user: post.id }))"
                                                >
                                                    <Trash2 class="mr-2" />
                                                    <span>Delete</span>
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                                <TableRow v-if="filteredBlogPosts.length === 0">
                                    <TableCell colspan="6" class="text-center text-sm text-gray-600 dark:text-gray-300">
                                        No blog posts found.
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </div>

                <!-- Bottom Pagination -->
                <div class="flex justify-center">
                    <Pagination
                        v-slot="{ page }"
                        :items-per-page="props.blogs.per_page"
                        :total="props.blogs.total"
                        :sibling-count="1"
                        show-edges
                        :default-page="props.blogs.current_page"
                    >
                        <PaginationList v-slot="{ items }" class="flex items-center gap-1">
                            <PaginationFirst />
                            <PaginationPrev />

                            <template v-for="(item, index) in items" :key="index">
                                <PaginationListItem
                                    v-if="item.type === 'page'"
                                    :value="item.value"
                                    as-child
                                >
                                    <Button class="w-9 h-9 p-0" :variant="item.value === page ? 'default' : 'outline'">
                                        {{ item.value }}
                                    </Button>
                                </PaginationListItem>
                                <PaginationEllipsis
                                    v-else
                                    :index="index"
                                />
                            </template>

                            <PaginationNext />
                            <PaginationLast />
                        </PaginationList>
                    </Pagination>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
