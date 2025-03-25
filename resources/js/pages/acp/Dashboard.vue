<script setup lang="ts">
import { ref } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';

// Import Lucide icons
import { Users, BookOpen, MessageSquare, LifeBuoy } from 'lucide-vue-next';

// Import the LineChart component from shadcn‑vue (adjust the import path as needed)
import { LineChart } from '@/components/ui/chart-line'

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

// Dummy data for the line chart (User Signups Over Time)
// The keys have been updated: "Support Tickets" and "New User Registrations"
const chartData = ref([
    {
        year: 1970,
        'Support Tickets': 2.04,
        'New User Registrations': 1.53,
    },
    {
        year: 1971,
        'Support Tickets': 1.96,
        'New User Registrations': 1.58,
    },
    {
        year: 1972,
        'Support Tickets': 1.96,
        'New User Registrations': 1.61,
    },
    {
        year: 1973,
        'Support Tickets': 1.93,
        'New User Registrations': 1.61,
    },
    {
        year: 1974,
        'Support Tickets': 1.88,
        'New User Registrations': 1.67,
    },
    {
        year: 1975,
        'Support Tickets': 1.79,
        'New User Registrations': 1.64,
    },
    {
        year: 1976,
        'Support Tickets': 1.77,
        'New User Registrations': 1.62,
    },
    {
        year: 1977,
        'Support Tickets': 1.74,
        'New User Registrations': 1.69,
    },
    {
        year: 1978,
        'Support Tickets': 1.74,
        'New User Registrations': 1.7,
    },
    {
        year: 1979,
        'Support Tickets': 1.77,
        'New User Registrations': 1.67,
    },
    {
        year: 1980,
        'Support Tickets': 1.79,
        'New User Registrations': 1.7,
    },
    {
        year: 1981,
        'Support Tickets': 1.81,
        'New User Registrations': 1.72,
    },
    {
        year: 1982,
        'Support Tickets': 1.84,
        'New User Registrations': 1.73,
    },
    {
        year: 1983,
        'Support Tickets': 1.77,
        'New User Registrations': 1.73,
    },
    {
        year: 1984,
        'Support Tickets': 1.78,
        'New User Registrations': 1.78,
    },
    {
        year: 1985,
        'Support Tickets': 1.78,
        'New User Registrations': 1.81,
    },
    {
        year: 1986,
        'Support Tickets': 1.82,
        'New User Registrations': 1.89,
    },
    {
        year: 1987,
        'Support Tickets': 1.82,
        'New User Registrations': 1.91,
    },
    {
        year: 1988,
        'Support Tickets': 1.77,
        'New User Registrations': 1.94,
    },
    {
        year: 1989,
        'Support Tickets': 1.76,
        'New User Registrations': 1.94,
    },
    {
        year: 1990,
        'Support Tickets': 1.75,
        'New User Registrations': 1.97,
    },
    {
        year: 1991,
        'Support Tickets': 1.62,
        'New User Registrations': 1.99,
    },
    {
        year: 1992,
        'Support Tickets': 1.56,
        'New User Registrations': 2.12,
    },
    {
        year: 1993,
        'Support Tickets': 1.5,
        'New User Registrations': 2.13,
    },
    {
        year: 1994,
        'Support Tickets': 1.46,
        'New User Registrations': 2.15,
    },
    {
        year: 1995,
        'Support Tickets': 1.43,
        'New User Registrations': 2.17,
    },
    {
        year: 1996,
        'Support Tickets': 1.4,
        'New User Registrations': 2.2,
    },
    {
        year: 1997,
        'Support Tickets': 1.37,
        'New User Registrations': 2.15,
    },
    {
        year: 1998,
        'Support Tickets': 1.34,
        'New User Registrations': 2.07,
    },
    {
        year: 1999,
        'Support Tickets': 1.32,
        'New User Registrations': 2.05,
    },
    {
        year: 2000,
        'Support Tickets': 1.33,
        'New User Registrations': 2.07,
    },
    {
        year: 2001,
        'Support Tickets': 1.31,
        'New User Registrations': 2.08,
    },
    {
        year: 2002,
        'Support Tickets': 1.29,
        'New User Registrations': 2.1,
    },
    {
        year: 2003,
        'Support Tickets': 1.27,
        'New User Registrations': 2.15,
    },
    {
        year: 2004,
        'Support Tickets': 1.27,
        'New User Registrations': 2.21,
    },
    {
        year: 2005,
        'Support Tickets': 1.26,
        'New User Registrations': 2.23,
    },
    {
        year: 2006,
        'Support Tickets': 1.26,
        'New User Registrations': 2.29,
    },
    {
        year: 2007,
        'Support Tickets': 1.27,
        'New User Registrations': 2.34,
    },
    {
        year: 2008,
        'Support Tickets': 1.26,
        'New User Registrations': 2.36,
    },
    {
        year: 2009,
        'Support Tickets': 1.26,
        'New User Registrations': 2.36,
    },
    {
        year: 2010,
        'Support Tickets': 1.25,
        'New User Registrations': 2.35,
    },
    {
        year: 2011,
        'Support Tickets': 1.24,
        'New User Registrations': 2.34,
    },
    {
        year: 2012,
        'Support Tickets': 1.25,
        'New User Registrations': 2.39,
    },
    {
        year: 2013,
        'Support Tickets': 1.22,
        'New User Registrations': 2.3,
    },
    {
        year: 2014,
        'Support Tickets': 1.2,
        'New User Registrations': 2.35,
    },
    {
        year: 2015,
        'Support Tickets': 1.17,
        'New User Registrations': 2.39,
    },
    {
        year: 2016,
        'Support Tickets': 1.16,
        'New User Registrations': 2.41,
    },
    {
        year: 2017,
        'Support Tickets': 1.13,
        'New User Registrations': 2.44,
    },
    {
        year: 2018,
        'Support Tickets': 1.07,
        'New User Registrations': 2.45,
    },
    {
        year: 2019,
        'Support Tickets': 1.03,
        'New User Registrations': 2.47,
    },
    {
        year: 2020,
        'Support Tickets': 0.92,
        'New User Registrations': 2.48,
    },
    {
        year: 2021,
        'Support Tickets': 0.82,
        'New User Registrations': 2.51,
    },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Dashboard ACP" />
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
                    <h2 class="text-lg font-semibold mb-2">User Signups & Support Tickets</h2>
                    <!-- Use the LineChart component with the updated chartData -->
                    <LineChart
                        :data="chartData"
                        index="year"
                        :categories="['Support Tickets', 'New User Registrations']"
                        :y-formatter="(tick, i) => {
              return typeof tick === 'number'
                ? `${new Intl.NumberFormat('us').format(tick).toString()}k`
                : ''
            }"
                    />
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
