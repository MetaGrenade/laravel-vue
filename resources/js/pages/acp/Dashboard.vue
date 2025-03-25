<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

// Import Lucide icons
import { Users, BookOpen, MessageSquare, LifeBuoy } from 'lucide-vue-next';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/acp/dashboard',
    },
];

// Dummy data for stats cards with Lucide icons
const stats = [
    { title: 'Total Users', value: '1,234', icon: Users },
    { title: 'Total Blogs', value: '56', icon: BookOpen },
    { title: 'Total Threads', value: '78', icon: MessageSquare },
    { title: 'Open Tickets', value: '20', icon: LifeBuoy },
];

// Dummy data for recent activity
const recentActivities = [
    { id: 1, activity: 'User John Doe registered', time: '2 mins ago' },
    { id: 2, activity: 'Blog "How to Code" published', time: '10 mins ago' },
    { id: 3, activity: 'User Jane updated profile', time: '1 hour ago' },
    { id: 4, activity: 'Permission "Edit Posts" assigned to Editor role', time: '2 hours ago' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Dashboard" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <!-- Stats Cards Section -->
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in stats"
                        :key="index"
                        class="relative overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4 flex items-center"
                    >
                        <div class="mr-4">
                            <!-- Render the Lucide icon -->
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ stat.value }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <!-- Chart Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <h2 class="text-lg font-semibold mb-2">User Signups Over Time</h2>
                    <!-- Placeholder for a chart. Replace this with your chart component when ready -->
                    <div class="h-64 bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <span class="text-gray-500">Chart Placeholder</span>
                    </div>
                </div>

                <!-- Recent Activity Section -->
                <div class="rounded-xl border border-sidebar-border/70 dark:border-sidebar-border p-4">
                    <h2 class="text-lg font-semibold mb-2">Recent Activity</h2>
                    <ul>
                        <li
                            v-for="activity in recentActivities"
                            :key="activity.id"
                            class="py-2 border-b border-gray-200 last:border-b-0"
                        >
                            <div class="flex justify-between">
                                <span>{{ activity.activity }}</span>
                                <span class="text-xs text-gray-500">{{ activity.time }}</span>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
