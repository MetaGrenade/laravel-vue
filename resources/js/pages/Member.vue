<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface MemberLink {
    label: string;
    url: string;
}

interface MemberThread {
    id: number;
    title: string;
    url: string;
    board: string;
    created_at: string | null;
}

interface MemberReply {
    id: number;
    excerpt: string | null;
    url: string;
    thread: string;
    created_at: string | null;
}

interface MemberProfile {
    id: number;
    nickname: string;
    avatar_url: string | null;
    bio: string | null;
    signature: string | null;
    role: string | null;
    reputation: number;
    joined_at: string | null;
    badges: Array<{ id: number; name: string; description: string | null }>;
    links: MemberLink[];
    activity: {
        forum: {
            thread_count: number;
            reply_count: number;
            threads: MemberThread[];
            replies: MemberReply[];
        } | null;
        blog: {
            comment_count: number;
        } | null;
    };
}

interface IgnoreState {
    can: boolean;
    ignoring: boolean;
}

interface FollowState {
    can: boolean;
    following: boolean;
}

const props = defineProps<{
    member: MemberProfile;
    ignore: IgnoreState | null;
    follow: FollowState | null;
}>();

const { t } = useI18n();
const page = usePage<SharedData>();
const viewingAs = computed(() => page.props.impersonation !== null);

const toggleIgnore = () => {
    if (props.ignore?.ignoring) {
        router.delete(route('forum.ignores.destroy', props.member.id), { preserveScroll: true });

        return;
    }

    router.put(route('forum.ignores.store', props.member.id), {}, { preserveScroll: true });
};

const toggleFollow = () => {
    if (props.follow?.following) {
        router.delete(route('forum.follows.destroy', props.member.id), { preserveScroll: true });

        return;
    }

    router.put(route('forum.follows.store', props.member.id), {}, { preserveScroll: true });
};

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('members.directory.title'), href: route('members.index') },
    { title: props.member.nickname, href: route('members.show', props.member.id) },
]);

const role = computed(() => props.member.role ?? t('members.profile.role_member'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="member.nickname" />

        <div class="space-y-6 p-4">
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <section class="rounded-xl border bg-card p-5 shadow-xs lg:col-span-1">
                    <div class="flex items-center gap-4">
                        <Avatar class="size-20">
                            <AvatarImage v-if="member.avatar_url" :src="member.avatar_url" :alt="member.nickname" />
                            <AvatarFallback class="text-lg font-semibold uppercase">{{ getInitials(member.nickname) }}</AvatarFallback>
                        </Avatar>
                        <div class="min-w-0">
                            <h1 class="truncate text-2xl font-semibold tracking-tight">{{ member.nickname }}</h1>
                            <p class="text-sm text-muted-foreground">{{ role }}</p>
                        </div>
                    </div>

                    <div v-if="!viewingAs && (follow || ignore?.can)" class="mt-4 flex flex-wrap gap-2">
                        <template v-if="follow">
                            <Button v-if="follow.can || follow.following" variant="outline" size="sm" type="button" @click="toggleFollow">
                                {{ follow.following ? t('members.profile.stop_following') : t('members.profile.follow') }}
                            </Button>
                            <Button v-else as-child variant="outline" size="sm">
                                <Link :href="route('forum.following')">{{ t('members.following.title') }}</Link>
                            </Button>
                        </template>
                        <Button v-if="ignore?.can" variant="outline" size="sm" type="button" @click="toggleIgnore">
                            {{ ignore?.ignoring ? t('members.profile.stop_ignoring') : t('members.profile.ignore') }}
                        </Button>
                    </div>

                    <p v-if="member.joined_at" class="mt-5 text-sm text-muted-foreground">
                        {{ t('members.profile.member_since', { date: member.joined_at }) }}
                    </p>

                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted-foreground">{{ t('members.profile.reputation') }}</dt>
                            <dd class="font-medium">{{ member.reputation }}</dd>
                        </div>
                        <div v-if="member.activity.forum" class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted-foreground">{{ t('members.profile.threads') }}</dt>
                            <dd class="font-medium">{{ member.activity.forum.thread_count }}</dd>
                        </div>
                        <div v-if="member.activity.forum" class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted-foreground">{{ t('members.profile.replies') }}</dt>
                            <dd class="font-medium">{{ member.activity.forum.reply_count }}</dd>
                        </div>
                        <div v-if="member.activity.blog" class="flex items-baseline justify-between gap-3">
                            <dt class="text-muted-foreground">{{ t('members.profile.comments') }}</dt>
                            <dd class="font-medium">{{ member.activity.blog.comment_count }}</dd>
                        </div>
                    </dl>

                    <ul v-if="member.badges.length > 0" class="mt-5 flex flex-wrap gap-2">
                        <li v-for="badge in member.badges" :key="badge.id">
                            <span
                                class="inline-flex items-center rounded-full border border-muted-foreground/20 bg-muted px-2 py-1 text-xs text-muted-foreground"
                                :title="badge.description ?? undefined"
                            >
                                {{ badge.name }}
                            </span>
                        </li>
                    </ul>
                </section>

                <div class="space-y-6 lg:col-span-2">
                    <section v-if="member.bio" class="rounded-xl border bg-card p-5 shadow-xs" aria-labelledby="member-about">
                        <h2 id="member-about" class="text-sm font-medium text-muted-foreground">{{ t('members.profile.about') }}</h2>
                        <p class="mt-2 text-sm whitespace-pre-line">{{ member.bio }}</p>
                    </section>

                    <section v-if="member.links.length > 0" class="rounded-xl border bg-card p-5 shadow-xs" aria-labelledby="member-links">
                        <h2 id="member-links" class="text-sm font-medium text-muted-foreground">{{ t('members.profile.links') }}</h2>
                        <ul class="mt-2 space-y-1">
                            <li v-for="link in member.links" :key="link.url">
                                <a :href="link.url" class="text-sm text-primary hover:underline" rel="noopener noreferrer" target="_blank">
                                    {{ link.label }}
                                </a>
                            </li>
                        </ul>
                    </section>

                    <section v-if="member.signature" class="rounded-xl border bg-card p-5 shadow-xs" aria-labelledby="member-signature">
                        <h2 id="member-signature" class="text-sm font-medium text-muted-foreground">{{ t('members.profile.signature') }}</h2>
                        <p class="mt-2 text-sm whitespace-pre-line text-muted-foreground">{{ member.signature }}</p>
                    </section>

                    <section v-if="member.activity.forum" class="rounded-xl border bg-card p-5 shadow-xs" aria-labelledby="member-threads">
                        <h2 id="member-threads" class="text-sm font-medium text-muted-foreground">{{ t('members.profile.recent_threads') }}</h2>
                        <p v-if="member.activity.forum.threads.length === 0" class="mt-2 text-sm text-muted-foreground">
                            {{ t('members.profile.no_threads') }}
                        </p>
                        <ul v-else class="mt-3 divide-y">
                            <li v-for="thread in member.activity.forum.threads" :key="thread.id" class="py-3 first:pt-0 last:pb-0">
                                <Link :href="thread.url" class="font-medium hover:underline">{{ thread.title }}</Link>
                                <p class="text-xs text-muted-foreground">
                                    {{ thread.board }}<span v-if="thread.created_at"> · {{ thread.created_at }}</span>
                                </p>
                            </li>
                        </ul>
                    </section>

                    <section v-if="member.activity.forum" class="rounded-xl border bg-card p-5 shadow-xs" aria-labelledby="member-replies">
                        <h2 id="member-replies" class="text-sm font-medium text-muted-foreground">{{ t('members.profile.recent_replies') }}</h2>
                        <p v-if="member.activity.forum.replies.length === 0" class="mt-2 text-sm text-muted-foreground">
                            {{ t('members.profile.no_replies') }}
                        </p>
                        <ul v-else class="mt-3 divide-y">
                            <li v-for="reply in member.activity.forum.replies" :key="reply.id" class="py-3 first:pt-0 last:pb-0">
                                <Link :href="reply.url" class="font-medium hover:underline">{{ reply.thread }}</Link>
                                <p v-if="reply.excerpt" class="mt-1 text-sm text-muted-foreground">{{ reply.excerpt }}</p>
                                <p v-if="reply.created_at" class="mt-1 text-xs text-muted-foreground">{{ reply.created_at }}</p>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
