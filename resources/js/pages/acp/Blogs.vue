<script setup lang="ts">
import { ref, computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';

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
                        class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                    </div>
                </div>

                <!-- Blog Posts Management Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-4">
                        <h2 class="text-lg font-semibold mb-2 md:mb-0">Blog Posts</h2>
                        <div class="flex space-x-2">
                            <input
                                v-model="searchQuery"
                                type="text"
                                placeholder="Search blog posts..."
                                class="rounded-md border border-gray-300 p-2 focus:outline-none focus:ring-2 focus:ring-primary"
                            />
                            <button class="rounded bg-blue-500 px-4 py-2 text-white hover:bg-blue-600">
                                Create New Post
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full border-collapse">
                            <thead class="bg-gray-100 dark:bg-gray-800">
                            <tr>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">ID</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Title</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Author</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Created At</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Status</th>
                                <th class="px-4 py-2 text-left text-sm font-medium text-gray-700 dark:text-gray-300">Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr
                                v-for="post in filteredBlogPosts"
                                :key="post.id"
                                class="border-b border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-900"
                            >
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ post.id }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ post.title }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ post.author }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ post.created_at }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">{{ post.status }}</td>
                                <td class="px-4 py-2 text-sm text-gray-600 dark:text-gray-300">
                                    <button class="text-blue-500 hover:underline">Edit</button>
                                    <button class="ml-2 text-red-500 hover:underline">Delete</button>
                                </td>
                            </tr>
                            <tr v-if="filteredBlogPosts.length === 0">
                                <td colspan="6" class="px-4 py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                                    No blog posts found.
                                </td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
