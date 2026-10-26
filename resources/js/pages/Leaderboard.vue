<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Trophy } from '@lucide/vue';
import { computed } from 'vue';

interface Standing {
    rank: number;
    id: number;
    nickname: string;
    url: string;
    avatar_url: string | null;
    reputation: number;
    badges: { id: number; name: string }[];
}

interface ActivityItem {
    id: number;
    points: number;
    label: string;
    at: string | null;
    member: {
        id: number;
        nickname: string;
        url: string;
        avatar_url: string | null;
    };
    post: {
        excerpt: string | null;
        url: string;
        thread: string;
    };
}

defineProps<{
    standings: Standing[];
    activity: ActivityItem[];
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('ui.nav.members'), href: route('members.index') },
    { title: t('members.leaderboard.title'), href: route('leaderboard') },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('members.leaderboard.title')" />

        <div class="space-y-6 p-4">
            <header class="flex items-center gap-4 rounded-xl border bg-card p-5 shadow-xs">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Trophy class="size-5" />
                </span>
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">{{ t('members.leaderboard.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ t('members.leaderboard.description') }}</p>
                </div>
            </header>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">
                <section class="lg:col-span-3" aria-labelledby="leaderboard-standings">
                    <h2 id="leaderboard-standings" class="text-lg font-semibold tracking-tight">{{ t('members.leaderboard.standings') }}</h2>

                    <p v-if="standings.length === 0" class="mt-3 rounded-xl border bg-card px-5 py-10 text-center text-sm text-muted-foreground">
                        {{ t('members.leaderboard.empty_standings') }}
                    </p>

                    <ol v-else class="mt-3 space-y-3">
                        <li v-for="member in standings" :key="member.id">
                            <Link
                                :href="member.url"
                                class="flex items-center gap-4 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:border-primary/40"
                                :aria-label="t('members.leaderboard.open_profile', { name: member.nickname })"
                            >
                                <span
                                    class="w-8 shrink-0 text-center text-lg font-semibold tabular-nums"
                                    :aria-label="t('members.leaderboard.rank', { rank: member.rank })"
                                >
                                    {{ member.rank }}
                                </span>
                                <Avatar class="size-11 shrink-0">
                                    <AvatarImage v-if="member.avatar_url" :src="member.avatar_url" :alt="member.nickname" />
                                    <AvatarFallback class="text-sm font-semibold uppercase">{{ getInitials(member.nickname) }}</AvatarFallback>
                                </Avatar>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-base font-semibold">{{ member.nickname }}</span>
                                    <span v-if="member.badges.length > 0" class="mt-0.5 block truncate text-xs text-muted-foreground">
                                        {{ member.badges.map((badge) => badge.name).join(', ') }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-sm text-muted-foreground tabular-nums">
                                    {{ t('members.leaderboard.reputation', { count: member.reputation }) }}
                                </span>
                            </Link>
                        </li>
                    </ol>
                </section>

                <section class="lg:col-span-2" aria-labelledby="leaderboard-activity">
                    <h2 id="leaderboard-activity" class="text-lg font-semibold tracking-tight">{{ t('members.leaderboard.activity') }}</h2>

                    <p v-if="activity.length === 0" class="mt-3 rounded-xl border bg-card px-5 py-10 text-center text-sm text-muted-foreground">
                        {{ t('members.leaderboard.empty_activity') }}
                    </p>

                    <ol v-else class="mt-3 space-y-3">
                        <li v-for="item in activity" :key="item.id" class="rounded-xl border bg-card p-4 shadow-xs">
                            <div class="flex items-start justify-between gap-3">
                                <Link :href="item.member.url" class="truncate font-semibold hover:text-primary">
                                    {{ item.member.nickname }}
                                </Link>
                                <span class="shrink-0 text-sm text-muted-foreground tabular-nums">
                                    {{ t('members.leaderboard.awarded', { points: item.points }) }}
                                </span>
                            </div>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.label }}</p>
                            <p v-if="item.post.excerpt" class="mt-2 text-sm">{{ item.post.excerpt }}</p>
                            <Link :href="item.post.url" class="mt-2 inline-block text-sm font-medium text-primary hover:underline">
                                {{ t('members.leaderboard.open_post', { thread: item.post.thread }) }}
                            </Link>
                            <p v-if="item.at" class="mt-1 text-xs text-muted-foreground">{{ item.at }}</p>
                        </li>
                    </ol>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
