<script setup lang="ts">
import AccountCard from '@/components/dashboard/AccountCard.vue';
import DigitalPurchasesCard from '@/components/dashboard/DigitalPurchasesCard.vue';
import OnboardingCard from '@/components/dashboard/OnboardingCard.vue';
import PlanCard from '@/components/dashboard/PlanCard.vue';
import RecentOrdersCard from '@/components/dashboard/RecentOrdersCard.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { LineChart } from '@/components/ui/chart-line';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import type { DashboardAccount, DashboardOnboarding, DashboardPlan, DashboardShop } from '@/types/dashboard';
import { Head, usePage } from '@inertiajs/vue3';
import { Activity, CheckCircle2, FileText, KeyRound, LifeBuoy, MessageSquare, ShoppingBag } from '@lucide/vue';
import { computed } from 'vue';

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
    /** The customer's orders and digital purchases; null when the shop is switched off. */
    shop: DashboardShop | null;
    /** The subscription; null when no plans are offered and the user has none. */
    plan: DashboardPlan | null;
    account: DashboardAccount | null;
    /** The "Get started" checklist; null once it is done or hidden. */
    onboarding: DashboardOnboarding | null;
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
    shop: null,
    plan: null,
    account: null,
    onboarding: null,
});

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

// Areas the site owner has switched off are left off the dashboard too.
const page = usePage();
const sections = computed(() => page.props.settings?.website_sections ?? { blog: true, forum: true, support: true, commerce: true });

const numberFormatter = new Intl.NumberFormat();
const dateFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' });

const formatNumber = (value: number | null | undefined) => numberFormatter.format(value ?? 0);
const formatDate = (value: string | null | undefined) => (value ? dateFormatter.format(new Date(value)) : '—');
const pluralize = (count: number, singular: string, plural: string) => (count === 1 ? singular : plural);

const statCards = computed(() => {
    const cards = [];

    if (props.shop) {
        cards.push(
            {
                title: 'Orders',
                value: props.shop.summary.orders,
                helper:
                    props.shop.summary.in_progress > 0
                        ? `${formatNumber(props.shop.summary.in_progress)} in progress`
                        : props.shop.summary.awaiting_payment > 0
                          ? `${formatNumber(props.shop.summary.awaiting_payment)} awaiting payment`
                          : 'All up to date',
                icon: ShoppingBag,
            },
            {
                title: 'Keys and downloads',
                value: props.shop.summary.digital,
                helper:
                    props.shop.summary.keys_waiting > 0
                        ? `${formatNumber(props.shop.summary.keys_waiting)} ${pluralize(props.shop.summary.keys_waiting, 'key', 'keys')} on the way`
                        : 'Ready to open from your orders',
                icon: KeyRound,
            },
        );
    }

    if (sections.value.support) {
        cards.push({
            title: 'Open support tickets',
            value: props.metrics.support.open,
            helper: `of ${formatNumber(props.metrics.support.total)} total`,
            icon: LifeBuoy,
        });
    }

    if (sections.value.forum) {
        cards.push(
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
        );
    }

    // Most people read the blog rather than write it, so the author's card only shows to authors.
    if (sections.value.blog && props.metrics.knowledge.published_articles + props.metrics.knowledge.drafts > 0) {
        cards.push({
            title: 'Published articles',
            value: props.metrics.knowledge.published_articles,
            helper: `${formatNumber(props.metrics.knowledge.drafts)} drafts in progress`,
            icon: FileText,
        });
    }

    return cards;
});

const chartSeries = computed(() => {
    const series: ('Forum Replies' | 'Support Tickets')[] = [];

    if (sections.value.forum) {
        series.push('Forum Replies');
    }

    if (sections.value.support) {
        series.push('Support Tickets');
    }

    return series;
});

const chartData = computed(() => props.activityChart ?? []);
const hasChartData = computed(() => chartData.value.some((item) => chartSeries.value.some((series) => (item[series] ?? 0) > 0)));

const recentActivity = computed(() => props.recentItems ?? []);
const articles = computed(() => props.recommendedArticles ?? []);
const showActivity = computed(() => sections.value.forum || sections.value.support);

const alertState = computed(() => {
    const keysWaiting = props.shop?.summary.keys_waiting ?? 0;
    if (keysWaiting > 0) {
        return {
            variant: 'default' as const,
            icon: KeyRound,
            title: 'A licence key is on its way',
            description: `${formatNumber(keysWaiting)} ${pluralize(keysWaiting, 'key you have', 'keys you have')} paid for ${pluralize(keysWaiting, 'is', 'are')} still being prepared. ${pluralize(keysWaiting, 'It', 'They')} will appear on the order page as soon as ${pluralize(keysWaiting, 'it is', 'they are')} ready.`,
        };
    }

    const openTickets = sections.value.support ? (props.metrics.support.open ?? 0) : 0;
    if (openTickets > 0) {
        return {
            variant: 'warning' as const,
            icon: LifeBuoy,
            title: 'Support tickets awaiting attention',
            description: `You have ${formatNumber(openTickets)} ${pluralize(openTickets, 'open ticket', 'open tickets')} with our support team.`,
        };
    }

    const unreadThreads = sections.value.forum ? (props.metrics.forum.unread_threads ?? 0) : 0;
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
            <OnboardingCard v-if="onboarding" :onboarding="onboarding" />

            <Alert :variant="alertState.variant">
                <component :is="alertState.icon" class="h-5 w-5" />
                <AlertTitle>{{ alertState.title }}</AlertTitle>
                <AlertDescription>{{ alertState.description }}</AlertDescription>
            </Alert>

            <div
                v-if="statCards.length"
                class="grid grid-cols-1 gap-4 md:grid-cols-2"
                :class="statCards.length === 4 ? 'xl:grid-cols-4' : 'xl:grid-cols-3'"
            >
                <Card v-for="(stat, index) in statCards" :key="index" class="overflow-hidden">
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

            <div v-if="shop" class="grid gap-4 lg:grid-cols-[2fr_1fr]">
                <RecentOrdersCard :orders="shop.recent_orders" :total="shop.summary.orders" class="min-w-0" />
                <DigitalPurchasesCard :items="shop.digital" :total="shop.summary.digital" class="min-w-0" />
            </div>

            <div v-if="account || plan" class="grid gap-4" :class="account && plan ? 'md:grid-cols-2' : ''">
                <PlanCard v-if="plan" :plan="plan" class="min-w-0" />
                <AccountCard
                    v-if="account"
                    :account="account"
                    :sections="{ forum: sections.forum, support: sections.support, commerce: sections.commerce }"
                    class="min-w-0"
                />
            </div>

            <Card v-if="chartSeries.length">
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
                    <p v-else class="text-sm text-muted-foreground">Not enough activity yet to visualise a trend.</p>
                </CardContent>
            </Card>

            <div v-if="showActivity || sections.blog" class="grid gap-4" :class="showActivity && sections.blog ? 'lg:grid-cols-[2fr_1fr]' : ''">
                <Card v-if="showActivity">
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
                        <p v-else class="text-sm text-muted-foreground">We will list your recent interactions here once you start engaging.</p>
                    </CardContent>
                </Card>

                <Card v-if="sections.blog">
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
                        <p v-else class="text-sm text-muted-foreground">Freshly published articles will appear here as soon as they are available.</p>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
