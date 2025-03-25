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
const chartData = ref([
    {
        'year': 1970,
        'Export Growth Rate': 2.04,
        'Import Growth Rate': 1.53,
    },
    {
        'year': 1971,
        'Export Growth Rate': 1.96,
        'Import Growth Rate': 1.58,
    },
    {
        'year': 1972,
        'Export Growth Rate': 1.96,
        'Import Growth Rate': 1.61,
    },
    {
        'year': 1973,
        'Export Growth Rate': 1.93,
        'Import Growth Rate': 1.61,
    },
    {
        'year': 1974,
        'Export Growth Rate': 1.88,
        'Import Growth Rate': 1.67,
    },
    {
        'year': 1975,
        'Export Growth Rate': 1.79,
        'Import Growth Rate': 1.64,
    },
    {
        'year': 1976,
        'Export Growth Rate': 1.77,
        'Import Growth Rate': 1.62,
    },
    {
        'year': 1977,
        'Export Growth Rate': 1.74,
        'Import Growth Rate': 1.69,
    },
    {
        'year': 1978,
        'Export Growth Rate': 1.74,
        'Import Growth Rate': 1.7,
    },
    {
        'year': 1979,
        'Export Growth Rate': 1.77,
        'Import Growth Rate': 1.67,
    },
    {
        'year': 1980,
        'Export Growth Rate': 1.79,
        'Import Growth Rate': 1.7,
    },
    {
        'year': 1981,
        'Export Growth Rate': 1.81,
        'Import Growth Rate': 1.72,
    },
    {
        'year': 1982,
        'Export Growth Rate': 1.84,
        'Import Growth Rate': 1.73,
    },
    {
        'year': 1983,
        'Export Growth Rate': 1.77,
        'Import Growth Rate': 1.73,
    },
    {
        'year': 1984,
        'Export Growth Rate': 1.78,
        'Import Growth Rate': 1.78,
    },
    {
        'year': 1985,
        'Export Growth Rate': 1.78,
        'Import Growth Rate': 1.81,
    },
    {
        'year': 1986,
        'Export Growth Rate': 1.82,
        'Import Growth Rate': 1.89,
    },
    {
        'year': 1987,
        'Export Growth Rate': 1.82,
        'Import Growth Rate': 1.91,
    },
    {
        'year': 1988,
        'Export Growth Rate': 1.77,
        'Import Growth Rate': 1.94,
    },
    {
        'year': 1989,
        'Export Growth Rate': 1.76,
        'Import Growth Rate': 1.94,
    },
    {
        'year': 1990,
        'Export Growth Rate': 1.75,
        'Import Growth Rate': 1.97,
    },
    {
        'year': 1991,
        'Export Growth Rate': 1.62,
        'Import Growth Rate': 1.99,
    },
    {
        'year': 1992,
        'Export Growth Rate': 1.56,
        'Import Growth Rate': 2.12,
    },
    {
        'year': 1993,
        'Export Growth Rate': 1.5,
        'Import Growth Rate': 2.13,
    },
    {
        'year': 1994,
        'Export Growth Rate': 1.46,
        'Import Growth Rate': 2.15,
    },
    {
        'year': 1995,
        'Export Growth Rate': 1.43,
        'Import Growth Rate': 2.17,
    },
    {
        'year': 1996,
        'Export Growth Rate': 1.4,
        'Import Growth Rate': 2.2,
    },
    {
        'year': 1997,
        'Export Growth Rate': 1.37,
        'Import Growth Rate': 2.15,
    },
    {
        'year': 1998,
        'Export Growth Rate': 1.34,
        'Import Growth Rate': 2.07,
    },
    {
        'year': 1999,
        'Export Growth Rate': 1.32,
        'Import Growth Rate': 2.05,
    },
    {
        'year': 2000,
        'Export Growth Rate': 1.33,
        'Import Growth Rate': 2.07,
    },
    {
        'year': 2001,
        'Export Growth Rate': 1.31,
        'Import Growth Rate': 2.08,
    },
    {
        'year': 2002,
        'Export Growth Rate': 1.29,
        'Import Growth Rate': 2.1,
    },
    {
        'year': 2003,
        'Export Growth Rate': 1.27,
        'Import Growth Rate': 2.15,
    },
    {
        'year': 2004,
        'Export Growth Rate': 1.27,
        'Import Growth Rate': 2.21,
    },
    {
        'year': 2005,
        'Export Growth Rate': 1.26,
        'Import Growth Rate': 2.23,
    },
    {
        'year': 2006,
        'Export Growth Rate': 1.26,
        'Import Growth Rate': 2.29,
    },
    {
        'year': 2007,
        'Export Growth Rate': 1.27,
        'Import Growth Rate': 2.34,
    },
    {
        'year': 2008,
        'Export Growth Rate': 1.26,
        'Import Growth Rate': 2.36,
    },
    {
        'year': 2009,
        'Export Growth Rate': 1.26,
        'Import Growth Rate': 2.36,
    },
    {
        'year': 2010,
        'Export Growth Rate': 1.25,
        'Import Growth Rate': 2.35,
    },
    {
        'year': 2011,
        'Export Growth Rate': 1.24,
        'Import Growth Rate': 2.34,
    },
    {
        'year': 2012,
        'Export Growth Rate': 1.25,
        'Import Growth Rate': 2.39,
    },
    {
        'year': 2013,
        'Export Growth Rate': 1.22,
        'Import Growth Rate': 2.3,
    },
    {
        'year': 2014,
        'Export Growth Rate': 1.2,
        'Import Growth Rate': 2.35,
    },
    {
        'year': 2015,
        'Export Growth Rate': 1.17,
        'Import Growth Rate': 2.39,
    },
    {
        'year': 2016,
        'Export Growth Rate': 1.16,
        'Import Growth Rate': 2.41,
    },
    {
        'year': 2017,
        'Export Growth Rate': 1.13,
        'Import Growth Rate': 2.44,
    },
    {
        'year': 2018,
        'Export Growth Rate': 1.07,
        'Import Growth Rate': 2.45,
    },
    {
        'year': 2019,
        'Export Growth Rate': 1.03,
        'Import Growth Rate': 2.47,
    },
    {
        'year': 2020,
        'Export Growth Rate': 0.92,
        'Import Growth Rate': 2.48,
    },
    {
        'year': 2021,
        'Export Growth Rate': 0.82,
        'Import Growth Rate': 2.51,
    },
]);
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
                    <!-- Use the LineChart component with the dummy chartData -->
                    <LineChart
                        :data="chartData"
                        index="year"
                        :categories="['Export Growth Rate', 'Import Growth Rate']"
                        :y-formatter="(tick, i) => {
      return typeof tick === 'number'
        ? `$ ${new Intl.NumberFormat('us').format(tick).toString()}`
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
