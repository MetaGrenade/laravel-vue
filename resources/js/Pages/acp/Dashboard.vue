<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Users, UserPlus, BookOpen, LifeBuoy } from 'lucide-vue-next';
import { LineChart } from '@/components/ui/chart-line';

type MetricGroup = {
    total: number;
    new_this_week?: number;
    open?: number;
    closed?: number;
    pending?: number;
    published?: number;
};

type DashboardMetrics = {
    users: MetricGroup & { new_this_week: number };
    blogs: MetricGroup & { published: number };
    tickets: MetricGroup & { open: number; new_this_week: number };
};

type DashboardChartDatum = {
    period: string;
    'Support Tickets': number;
    'New User Registrations': number;
};

type DashboardActivity = {
    id: string | number;
    activity: string;
    time: string | null;
};

interface DashboardProps {
    metrics: DashboardMetrics;
    chartData: DashboardChartDatum[];
    recentActivities: DashboardActivity[];
}

const props = withDefaults(defineProps<DashboardProps>(), {
    metrics: () => ({
        users: { total: 0, new_this_week: 0 },
        blogs: { total: 0, published: 0 },
        tickets: { total: 0, open: 0, closed: 0, pending: 0, new_this_week: 0 },
    }),
    chartData: () => [],
    recentActivities: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/acp/dashboard',
    },
];

const numberFormatter = new Intl.NumberFormat();

const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);

const statCards = computed(() => [
    { title: 'Total Users', value: props.metrics.users.total, icon: Users },
    { title: 'New Users (This Week)', value: props.metrics.users.new_this_week, icon: UserPlus },
    { title: 'Published Blogs', value: props.metrics.blogs.published ?? props.metrics.blogs.total, icon: BookOpen },
    { title: 'Open Tickets', value: props.metrics.tickets.open, icon: LifeBuoy },
]);

const chartSeries = ['Support Tickets', 'New User Registrations'] as const;

const chartData = computed(() => props.chartData ?? []);
const hasChartData = computed(() => chartData.value.length > 0);

const recentActivities = computed(() => props.recentActivities ?? []);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Dashboard ACP" />
        <AdminLayout>
            <div class="flex h-full flex-1 flex-col gap-4 rounded-xl pb-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="(stat, index) in statCards"
                        :key="index"
                        class="relative flex items-center overflow-hidden rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
                    >
                        <div class="mr-4">
                            <component :is="stat.icon" class="h-8 w-8 text-gray-600" />
                        </div>
                        <div>
                            <div class="text-sm text-gray-500">{{ stat.title }}</div>
                            <div class="text-xl font-bold">{{ formatNumber(stat.value) }}</div>
                        </div>
                        <PlaceholderPattern />
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="mb-2 text-lg font-semibold">User Signups &amp; Support Tickets</h2>
                    <LineChart
                        v-if="hasChartData"
                        :data="chartData"
                        index="period"
                        :categories="chartSeries"
                        :y-formatter="(tick) => (typeof tick === 'number' ? formatNumber(tick) : '')"
                    />
                    <p v-else class="text-sm text-muted-foreground">Not enough data to show trends yet.</p>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <h2 class="mb-2 text-lg font-semibold">Recent Activity</h2>
                    <ul v-if="recentActivities.length">
                        <li
                            v-for="activity in recentActivities"
                            :key="activity.id"
                            class="border-b border-gray-200 py-2 last:border-b-0"
                        >
                            <div class="flex justify-between">
                                <span>{{ activity.activity }}</span>
                                <span class="text-xs text-gray-500">{{ activity.time ?? '—' }}</span>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">No recent activity yet.</p>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
