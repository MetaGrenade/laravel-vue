<script setup lang="ts">
import { computed } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { LineChart } from '@/components/ui/chart-line';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Activity, CheckCircle2, FileText, LifeBuoy, MessageSquare } from 'lucide-vue-next';

type SupportMetrics = {
    total: number;
    open: number;
    pending: number;
    resolved: number;
    new_this_month: number;
};

type ForumMetrics = {
    threads: number;
    active_this_month: number;
    replies: number;
    replies_this_week: number;
    unread_threads: number;
};

type KnowledgeMetrics = {
    published_articles: number;
    drafts: number;
};

interface DashboardMetrics {
    support: SupportMetrics;
    forum: ForumMetrics;
    knowledge: KnowledgeMetrics;
}

type ActivityChartDatum = {
    period: string;
    'Forum Replies': number;
    'Support Tickets': number;
};

type ActivityItem = {
    id: string | number;
    summary: string;
    context: string;
    time: string | null;
    url?: string | null;
};

type RecommendedArticle = {
    id: number | string;
    title: string;
    excerpt: string | null;
    url: string;
    published_at: string | null;
};

interface DashboardProps {
    metrics: DashboardMetrics;
    activityChart: ActivityChartDatum[];
    recentItems: ActivityItem[];
    recommendedArticles: RecommendedArticle[];
}

const props = withDefaults(defineProps<DashboardProps>(), {
    metrics: () => ({
        support: { total: 0, open: 0, pending: 0, resolved: 0, new_this_month: 0 },
        forum: { threads: 0, active_this_month: 0, replies: 0, replies_this_week: 0, unread_threads: 0 },
        knowledge: { published_articles: 0, drafts: 0 },
    }),
    activityChart: () => [],
    recentItems: () => [],
    recommendedArticles: () => [],
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const numberFormatter = new Intl.NumberFormat();
const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);
const formatDate = (value: string | null | undefined) => (value ? dateFormatter.format(new Date(value)) : '—');
const pluralize = (count: number, singular: string, plural: string) => (count === 1 ? singular : plural);

const statCards = computed(() => [
    {
        title: 'Open support tickets',
        value: props.metrics.support.open,
        helper: `of ${formatNumber(props.metrics.support.total)} total`,
        icon: LifeBuoy,
    },
    {
        title: 'Forum threads started',
        value: props.metrics.forum.threads,
        helper: `${formatNumber(props.metrics.forum.active_this_month)} active this month`,
        icon: MessageSquare,
    },
    {
        title: 'Forum replies posted',
        value: props.metrics.forum.replies,
        helper: `${formatNumber(props.metrics.forum.replies_this_week)} this week`,
        icon: Activity,
    },
    {
        title: 'Published articles',
        value: props.metrics.knowledge.published_articles,
        helper: `${formatNumber(props.metrics.knowledge.drafts)} drafts in progress`,
        icon: FileText,
    },
]);

const chartSeries = ['Forum Replies', 'Support Tickets'] as const;

const chartData = computed(() => props.activityChart ?? []);
const hasChartData = computed(() =>
    chartData.value.some(
        (item) => (item['Forum Replies'] ?? 0) > 0 || (item['Support Tickets'] ?? 0) > 0,
    ),
);

const recentActivity = computed(() => props.recentItems ?? []);
const articles = computed(() => props.recommendedArticles ?? []);

const alertState = computed(() => {
    const openTickets = props.metrics.support.open ?? 0;
    if (openTickets > 0) {
        return {
            variant: 'warning' as const,
            icon: LifeBuoy,
            title: 'Support tickets awaiting attention',
            description: `You have ${formatNumber(openTickets)} ${pluralize(openTickets, 'open ticket', 'open tickets')} with our support team.`,
        };
    }

    const unreadThreads = props.metrics.forum.unread_threads ?? 0;
    if (unreadThreads > 0) {
        return {
            variant: 'default' as const,
            icon: MessageSquare,
            title: 'New discussions to catch up on',
            description: `There ${unreadThreads === 1 ? 'is' : 'are'} ${formatNumber(unreadThreads)} unread ${pluralize(unreadThreads, 'discussion', 'discussions')} in the forum.`,
        };
    }

    return {
        variant: 'default' as const,
        icon: CheckCircle2,
        title: 'You are all caught up',
        description: 'Nothing needs your attention right now. We will update this space as new activity arrives.',
    };
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Dashboard" />

        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
<!--            <Alert variant="destructive">-->
<!--                <AlertCircle class="w-4 h-4" />-->
<!--                <AlertTitle>Error</AlertTitle>-->
<!--                <AlertDescription>-->
<!--                    Your session has expired. Please log in again.-->
<!--                </AlertDescription>-->
<!--            </Alert>-->
            <Alert :variant="alertState.variant">
                <component :is="alertState.icon" class="h-5 w-5" />
                <AlertTitle>{{ alertState.title }}</AlertTitle>
                <AlertDescription>{{ alertState.description }}</AlertDescription>
            </Alert>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card
                    v-for="(stat, index) in statCards"
                    :key="index"
                    class="overflow-hidden"
                >
                    <CardHeader class="flex flex-row items-start justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">{{ stat.title }}</CardTitle>
                        <component :is="stat.icon" class="h-5 w-5 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-semibold">{{ formatNumber(stat.value) }}</div>
                        <p class="text-xs text-muted-foreground">{{ stat.helper }}</p>
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Community engagement</CardTitle>
                    <CardDescription>Monthly view of your forum replies and support requests.</CardDescription>
                </CardHeader>
                <CardContent>
                    <LineChart
                        v-if="hasChartData"
                        :data="chartData"
                        index="period"
                        :categories="chartSeries"
                        :y-formatter="(tick) => (typeof tick === 'number' ? formatNumber(tick) : '')"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        Not enough activity yet to visualise a trend.
                    </p>
                </CardContent>
            </Card>

            <div class="grid gap-4 lg:grid-cols-[2fr,1fr]">
                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                        <CardDescription>The latest updates from your tickets and forum participation.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="recentActivity.length" class="space-y-3">
                            <li
                                v-for="item in recentActivity"
                                :key="item.id"
                                class="rounded-lg border border-sidebar-border/60 p-3 text-sm dark:border-sidebar-border"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-medium">{{ item.summary }}</p>
                                        <p class="text-xs text-muted-foreground">{{ item.context }}</p>
                                    </div>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ item.time ?? '—' }}</span>
                                </div>
                                <div v-if="item.url" class="mt-2">
                                    <a :href="item.url" class="text-xs font-medium text-primary hover:underline">View</a>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            We will list your recent interactions here once you start engaging.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Recommended reading</CardTitle>
                        <CardDescription>Brush up on the latest knowledge base articles.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="articles.length" class="space-y-4 text-sm">
                            <li v-for="article in articles" :key="article.id">
                                <a :href="article.url" class="font-medium text-primary hover:underline">
                                    {{ article.title }}
                                </a>
                                <p v-if="article.excerpt" class="mt-1 text-xs text-muted-foreground">
                                    {{ article.excerpt }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ formatDate(article.published_at) }}
                                </p>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">
                            Freshly published articles will appear here as soon as they are available.
                        </p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
