<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';

// Import Table components from shadcn-vue
import { Table, TableHeader, TableRow, TableHead, TableBody, TableCell } from '@/components/ui/table';

// Import Lucide icons for stats cards
import { FileText, CheckCircle, Edit3, MessageCircle } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Blogs ACP',
        href: '/acp/blogs',
    },
];

// Dummy blog statistics with Lucide icons
const blogStats = [
    { title: 'Total Posts', value: '120', icon: FileText },
    { title: 'Published Posts', value: '95', icon: CheckCircle },
    { title: 'Draft Posts', value: '25', icon: Edit3 },
    { title: 'Total Comments', value: '450', icon: MessageCircle },
];

// Define a type for blog posts
interface Blog {
    id: number;
    title: string;
    author: string;
    created_at: string;
    status: 'Published' | 'Draft';
}

// Dummy data for blog posts
const blogPosts = ref<Blog[]>([
    { id: 1, title: 'How to Build a Modern Web App', author: 'Alice Johnson', created_at: '2022-01-15', status: 'Published' },
    { id: 2, title: 'Understanding Laravel', author: 'Bob Smith', created_at: '2022-02-10', status: 'Draft' },
    { id: 3, title: 'Vue 3 Composition API in Depth', author: 'Charlie Brown', created_at: '2022-03-05', status: 'Published' },
    { id: 4, title: 'Managing State in Vuex', author: 'Diana Prince', created_at: '2022-04-20', status: 'Published' },
    { id: 5, title: 'Building Reusable Components', author: 'Ethan Hunt', created_at: '2022-05-12', status: 'Draft' },
]);

// Search query for filtering blog posts
const searchQuery = ref('');

// Computed property to filter blog posts based on the search query
const filteredBlogPosts = computed(() => {
    if (!searchQuery.value) return blogPosts.value;
    const q = searchQuery.value.toLowerCase();
    return blogPosts.value.filter(
        post =>
            post.title.toLowerCase().includes(q) ||
            post.author.toLowerCase().includes(q) ||
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
                        v-for="(stat, index) in blogStats"
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
                                placeholder="Search blog posts..."
                                class="w-full rounded-md"
                            />
                            <Button variant="secondary" class="text-sm text-white bg-green-500 hover:bg-green-600">
                                Create New Post
                            </Button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>ID</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Author</TableHead>
                                    <TableHead>Created At</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="post in filteredBlogPosts"
                                    :key="post.id"
                                    class="hover:bg-gray-50 dark:hover:bg-gray-900"
                                >
                                    <TableCell>{{ post.id }}</TableCell>
                                    <TableCell>{{ post.title }}</TableCell>
                                    <TableCell>{{ post.author }}</TableCell>
                                    <TableCell>{{ post.created_at }}</TableCell>
                                    <TableCell>{{ post.status }}</TableCell>
                                    <TableCell>
                                        <button class="text-blue-500 hover:underline">Edit</button>
                                        <button class="ml-2 text-red-500 hover:underline">Delete</button>
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
            </div>
        </AdminLayout>
    </AppLayout>
</template>
