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

type QueueAgingMetrics = {
    under_1_day: number;
    one_to_three_days: number;
    three_to_seven_days: number;
    over_seven_days: number;
};

type PendingVolumeTrendDatum = {
    period: string;
    'Pending Tickets': number;
};

type PendingVolumeMetrics = {
    total: number;
    by_priority: {
        low: number;
        medium: number;
        high: number;
    };
    trend: PendingVolumeTrendDatum[];
};

type ResponseTimeTrendDatum = {
    period: string;
    'Average First Response (hrs)': number;
};

type ResponseTimeMetrics = {
    average_first_response_hours: number | null;
    average_resolution_hours: number | null;
    trend: ResponseTimeTrendDatum[];
};

type DashboardActivity = {
    id: string | number;
    activity: string;
    time: string | null;
};

type SearchInsightEntry = {
    term: string;
    total_count: number;
    zero_result_count: number;
    last_ran_at: string | null;
};

interface SearchInsights {
    top_queries: SearchInsightEntry[];
    top_zero_queries: SearchInsightEntry[];
    zero_result_total: number;
    last_aggregated_at: string | null;
}

type QueueWorkerConfig = {
    name: string;
    connection: string;
    queues: string[];
    tries: number | null;
    backoff: number | null;
    sleep: number | null;
    timeout: number | null;
    max_jobs: number | null;
    max_time: number | null;
};

type QueueFailure = {
    id: string | number | null;
    queue: string | null;
    connection: string | null;
    failed_at: string | null;
    exception_excerpt: string | null;
};

type QueueHealth = {
    connection: string;
    queue: string;
    pending: number;
    failed: number;
    oldest_pending_available_at: string | null;
    oldest_pending_age_seconds: number | null;
    last_failed_at: string | null;
    recent_failures: QueueFailure[];
    workers: QueueWorkerConfig[];
};

interface SlaMetrics {
    queue_aging: QueueAgingMetrics;
    pending_volume: PendingVolumeMetrics;
    response_times: ResponseTimeMetrics;
}

interface DashboardProps {
    metrics: DashboardMetrics;
    chartData: DashboardChartDatum[];
    recentActivities: DashboardActivity[];
    slaMetrics: SlaMetrics;
    searchInsights: SearchInsights;
    queueHealth: QueueHealth;
}

const props = withDefaults(defineProps<DashboardProps>(), {
    metrics: () => ({
        users: { total: 0, new_this_week: 0 },
        blogs: { total: 0, published: 0 },
        tickets: { total: 0, open: 0, closed: 0, pending: 0, new_this_week: 0 },
    }),
    chartData: () => [],
    recentActivities: () => [],
    slaMetrics: () => ({
        queue_aging: {
            under_1_day: 0,
            one_to_three_days: 0,
            three_to_seven_days: 0,
            over_seven_days: 0,
        },
        pending_volume: {
            total: 0,
            by_priority: {
                low: 0,
                medium: 0,
                high: 0,
            },
            trend: [],
        },
        response_times: {
            average_first_response_hours: null,
            average_resolution_hours: null,
            trend: [],
        },
    }),
    searchInsights: () => ({
        top_queries: [],
        top_zero_queries: [],
        zero_result_total: 0,
        last_aggregated_at: null,
    }),
    queueHealth: () => ({
        connection: 'database',
        queue: 'default',
        pending: 0,
        failed: 0,
        oldest_pending_available_at: null,
        oldest_pending_age_seconds: null,
        last_failed_at: null,
        recent_failures: [],
        workers: [],
    }),
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/acp/dashboard',
    },
];

const numberFormatter = new Intl.NumberFormat();

const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);
const formatHours = (value: number | null | undefined) =>
    value === null || value === undefined ? '—' : Number(value).toFixed(1);
const formatDateTime = (value: string | null | undefined) => {
    if (! value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(date);
};

const formatDuration = (value: number | null | undefined) => {
    if (value === null || value === undefined) {
        return '—';
    }

    const totalSeconds = Math.max(0, Math.floor(value));

    if (totalSeconds < 60) {
        return `${totalSeconds}s`;
    }

    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    if (minutes < 60) {
        return seconds > 0 ? `${minutes}m ${seconds}s` : `${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (hours < 24) {
        return remainingMinutes > 0 ? `${hours}h ${remainingMinutes}m` : `${hours}h`;
    }

    const days = Math.floor(hours / 24);
    const remainingHours = hours % 24;

    return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`;
};

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

const slaSummaryCards = computed(() => [
    {
        title: 'Avg First Response (hrs)',
        value: props.slaMetrics.response_times.average_first_response_hours,
        type: 'hours' as const,
    },
    {
        title: 'Avg Resolution (hrs)',
        value: props.slaMetrics.response_times.average_resolution_hours,
        type: 'hours' as const,
    },
    { title: 'Pending Tickets', value: props.slaMetrics.pending_volume.total, type: 'count' as const },
]);

const queueAgingDefinitions = [
    { key: 'under_1_day', label: 'Under 24 hours' },
    { key: 'one_to_three_days', label: '1-3 days' },
    { key: 'three_to_seven_days', label: '3-7 days' },
    { key: 'over_seven_days', label: '7+ days' },
] as const;

const queueAgingRows = computed(() => {
    const buckets = props.slaMetrics.queue_aging ?? {
        under_1_day: 0,
        one_to_three_days: 0,
        three_to_seven_days: 0,
        over_seven_days: 0,
    };

    const total = queueAgingDefinitions.reduce((sum, definition) => sum + (buckets[definition.key] ?? 0), 0);

    return queueAgingDefinitions.map((definition) => {
        const count = buckets[definition.key] ?? 0;
        const percentage = total > 0 ? Math.round((count / total) * 100) : 0;

        return {
            ...definition,
            count,
            percentage,
        };
    });
});

const hasQueueAgingData = computed(() => queueAgingRows.value.some((row) => row.count > 0));

const pendingPriorityRows = computed(() => {
    const priorities = props.slaMetrics.pending_volume?.by_priority ?? { low: 0, medium: 0, high: 0 };

    return [
        { key: 'high', label: 'High', count: priorities.high ?? 0 },
        { key: 'medium', label: 'Medium', count: priorities.medium ?? 0 },
        { key: 'low', label: 'Low', count: priorities.low ?? 0 },
    ];
});

const pendingVolumeSeries = ['Pending Tickets'] as const;
const pendingVolumeChartData = computed(() => props.slaMetrics.pending_volume?.trend ?? []);
const hasPendingVolumeData = computed(() => pendingVolumeChartData.value.some((point) => point['Pending Tickets'] > 0));

const responseTimeSeries = ['Average First Response (hrs)'] as const;
const responseTimeChartData = computed(() => props.slaMetrics.response_times?.trend ?? []);
const hasResponseTimeData = computed(() =>
    responseTimeChartData.value.some((point) => point['Average First Response (hrs)'] > 0)
);

const searchInsights = computed(() => props.searchInsights ?? { top_queries: [], top_zero_queries: [], zero_result_total: 0 });
const hasSearchInsights = computed(
    () => (searchInsights.value.top_queries?.length ?? 0) > 0 || (searchInsights.value.top_zero_queries?.length ?? 0) > 0
);
const topSearchQueries = computed(() => searchInsights.value.top_queries ?? []);
const zeroResultQueries = computed(() => searchInsights.value.top_zero_queries ?? []);
const zeroResultTotal = computed(() => searchInsights.value.zero_result_total ?? 0);
const lastAggregatedAt = computed(() => formatDateTime(searchInsights.value.last_aggregated_at));

const queueHealth = computed(() => props.queueHealth ?? {} as QueueHealth);
const queueWorkers = computed(() => queueHealth.value.workers ?? []);
const hasQueueWorkers = computed(() => queueWorkers.value.length > 0);
const recentQueueFailures = computed(() => queueHealth.value.recent_failures ?? []);
const hasQueueFailures = computed(() => recentQueueFailures.value.length > 0);
const lastFailureAt = computed(() => formatDateTime(queueHealth.value.last_failed_at));
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

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 class="mb-3 text-lg font-semibold">SLA Snapshot</h2>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div
                                v-for="card in slaSummaryCards"
                                :key="card.title"
                                class="rounded-lg border border-border/60 p-3"
                            >
                                <div class="text-xs uppercase text-muted-foreground">{{ card.title }}</div>
                                <div class="text-xl font-semibold">
                                    <template v-if="card.type === 'hours'">
                                        {{ formatHours(card.value) }}
                                    </template>
                                    <template v-else>
                                        {{ formatNumber(card.value) }}
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 class="mb-3 text-lg font-semibold">Queue Aging</h2>
                        <template v-if="hasQueueAgingData">
                            <ul class="space-y-3">
                                <li v-for="row in queueAgingRows" :key="row.key">
                                    <div class="flex items-center justify-between text-sm font-medium">
                                        <span>{{ row.label }}</span>
                                        <span class="text-muted-foreground">{{ formatNumber(row.count) }} ({{ row.percentage }}%)</span>
                                    </div>
                                    <div class="mt-1 h-2 rounded-full bg-muted">
                                        <div
                                            class="h-2 rounded-full bg-primary"
                                            :style="{ width: `${row.percentage}%` }"
                                        ></div>
                                    </div>
                                </li>
                            </ul>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">No tickets are currently awaiting action.</p>
                    </div>

                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 class="mb-3 text-lg font-semibold">Pending by Priority</h2>
                        <template v-if="pendingPriorityRows.some((row) => row.count > 0)">
                            <ul class="space-y-2">
                                <li v-for="row in pendingPriorityRows" :key="row.key" class="flex justify-between text-sm">
                                    <span class="font-medium">{{ row.label }}</span>
                                    <span class="text-muted-foreground">{{ formatNumber(row.count) }}</span>
                                </li>
                            </ul>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">No pending tickets right now.</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 class="mb-2 text-lg font-semibold">Pending Volume (Last 7 Days)</h2>
                        <LineChart
                            v-if="hasPendingVolumeData"
                            :data="pendingVolumeChartData"
                            index="period"
                            :categories="pendingVolumeSeries"
                            :y-formatter="(tick) => (typeof tick === 'number' ? formatNumber(tick) : '')"
                        />
                        <p v-else class="text-sm text-muted-foreground">Not enough data to show trends yet.</p>
                    </div>

                    <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                        <h2 class="mb-2 text-lg font-semibold">Response Time Trend (Weekly)</h2>
                        <LineChart
                            v-if="hasResponseTimeData"
                            :data="responseTimeChartData"
                            index="period"
                            :categories="responseTimeSeries"
                            :y-formatter="(tick) => (typeof tick === 'number' ? formatHours(tick) : '')"
                        />
                        <p v-else class="text-sm text-muted-foreground">Not enough data to show trends yet.</p>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-lg font-semibold">Queue Health</h2>
                        <p class="text-xs text-muted-foreground">
                            {{ queueHealth.connection }} connection • {{ queueHealth.queue }} queue
                        </p>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-lg border border-border/60 p-3">
                            <div class="text-xs uppercase text-muted-foreground">Pending Jobs</div>
                            <div class="text-xl font-semibold">{{ formatNumber(queueHealth.pending) }}</div>
                        </div>
                        <div class="rounded-lg border border-border/60 p-3">
                            <div class="text-xs uppercase text-muted-foreground">Failed Jobs</div>
                            <div class="text-xl font-semibold">{{ formatNumber(queueHealth.failed) }}</div>
                        </div>
                        <div class="rounded-lg border border-border/60 p-3">
                            <div class="text-xs uppercase text-muted-foreground">Oldest Pending</div>
                            <div class="text-xl font-semibold">{{ formatDuration(queueHealth.oldest_pending_age_seconds) }}</div>
                            <p v-if="queueHealth.oldest_pending_available_at" class="text-xs text-muted-foreground">
                                Since {{ formatDateTime(queueHealth.oldest_pending_available_at) }}
                            </p>
                        </div>
                        <div class="rounded-lg border border-border/60 p-3">
                            <div class="text-xs uppercase text-muted-foreground">Last Failure</div>
                            <div class="text-xl font-semibold">{{ lastFailureAt ?? '—' }}</div>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <h3 class="text-sm font-semibold uppercase text-muted-foreground">Configured Workers</h3>
                            <template v-if="hasQueueWorkers">
                                <ul class="mt-2 space-y-2">
                                    <li
                                        v-for="worker in queueWorkers"
                                        :key="worker.name"
                                        class="rounded-lg border border-border/60 p-3 text-sm"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="font-semibold">{{ worker.name }}</span>
                                            <span class="text-muted-foreground">{{ worker.connection }}</span>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Queues: {{ worker.queues.join(', ') || '—' }}
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Tries: {{ worker.tries ?? '—' }} • Backoff: {{ worker.backoff ?? '—' }}s • Sleep:
                                            {{ worker.sleep ?? '—' }}s
                                        </p>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Timeout: {{ worker.timeout ?? '—' }}s • Max Jobs:
                                            {{ worker.max_jobs ?? '—' }} • Max Time: {{ worker.max_time ?? '—' }}s
                                        </p>
                                    </li>
                                </ul>
                            </template>
                            <p v-else class="mt-2 text-sm text-muted-foreground">
                                No workers have been configured yet.
                            </p>
                        </div>

                        <div>
                            <h3 class="text-sm font-semibold uppercase text-muted-foreground">Recent Failures</h3>
                            <template v-if="hasQueueFailures">
                                <ul class="mt-2 space-y-2">
                                    <li
                                        v-for="(failure, index) in recentQueueFailures"
                                        :key="failure.id ?? failure.failed_at ?? index"
                                        class="rounded-lg border border-border/60 p-3 text-sm"
                                    >
                                        <div class="flex items-center justify-between">
                                            <span class="font-semibold">{{ failure.queue ?? 'unknown queue' }}</span>
                                            <span class="text-xs text-muted-foreground">
                                                {{ formatDateTime(failure.failed_at) ?? '—' }}
                                            </span>
                                        </div>
                                        <p class="mt-1 text-xs text-muted-foreground">
                                            Connection: {{ failure.connection ?? '—' }}
                                        </p>
                                        <p v-if="failure.exception_excerpt" class="mt-1 text-xs text-muted-foreground">
                                            {{ failure.exception_excerpt }}
                                        </p>
                                    </li>
                                </ul>
                            </template>
                            <p v-else class="mt-2 text-sm text-muted-foreground">
                                No failed jobs have been recorded recently.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border">
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Search Insights</h2>
                        <span v-if="lastAggregatedAt" class="text-xs text-muted-foreground"
                            >Refreshed {{ lastAggregatedAt }}</span
                        >
                    </div>

                    <template v-if="hasSearchInsights">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <h3 class="text-sm font-semibold uppercase text-muted-foreground">Top Queries</h3>
                                <ul class="mt-2 space-y-3">
                                    <li v-for="entry in topSearchQueries" :key="entry.term">
                                        <div class="flex items-start justify-between text-sm">
                                            <span class="font-medium text-foreground">{{ entry.term }}</span>
                                            <span class="text-muted-foreground">{{ formatNumber(entry.total_count) }} searches</span>
                                        </div>
                                        <p
                                            v-if="entry.zero_result_count > 0"
                                            class="text-xs font-medium text-amber-600"
                                        >
                                            {{ formatNumber(entry.zero_result_count) }} searches returned no results
                                        </p>
                                    </li>
                                </ul>
                            </div>

                            <div>
                                <h3 class="text-sm font-semibold uppercase text-muted-foreground">Zero Result Hotlist</h3>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ formatNumber(zeroResultTotal) }} total zero-result searches in window.
                                </p>
                                <ul class="mt-3 space-y-3">
                                    <li v-for="entry in zeroResultQueries" :key="`zero-${entry.term}`">
                                        <div class="flex items-start justify-between text-sm">
                                            <span class="font-medium text-foreground">{{ entry.term }}</span>
                                            <span class="text-muted-foreground">{{ formatNumber(entry.zero_result_count) }} times</span>
                                        </div>
                                        <p v-if="entry.last_ran_at" class="text-xs text-muted-foreground">
                                            Last seen {{ formatDateTime(entry.last_ran_at) ?? 'recently' }}
                                        </p>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </template>
                    <p v-else class="text-sm text-muted-foreground">
                        Search data will appear after visitors use the global search.
                    </p>
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
