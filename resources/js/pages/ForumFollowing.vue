<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface FollowedMember {
    id: number;
    nickname: string;
    banned: boolean;
}

interface FollowedPost {
    id: number;
    excerpt: string | null;
    url: string;
    thread: string;
    board: string;
    author: {
        id: number;
        nickname: string;
    };
    created_at: string | null;
}

interface PostPage {
    data: FollowedPost[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
    links: {
        prev: string | null;
        next: string | null;
    };
}

defineProps<{
    people: FollowedMember[];
    posts: PostPage;
}>();

const { t } = useI18n();
const page = usePage<SharedData>();
const viewingAs = computed(() => page.props.impersonation !== null);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('ui.nav.forum'), href: route('forum.index') },
    { title: t('members.following.title'), href: route('forum.following') },
]);

const stopFollowing = (member: FollowedMember) => {
    router.delete(route('forum.follows.destroy', member.id), { preserveScroll: true });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('members.following.title')" />

        <div class="space-y-8 p-4">
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">{{ t('members.following.title') }}</h1>
                <p class="max-w-2xl text-sm text-muted-foreground">{{ t('members.following.description') }}</p>
            </header>

            <section v-if="people.length === 0" class="rounded-xl border bg-card p-5 shadow-xs">
                <p class="text-sm text-muted-foreground">{{ t('members.following.empty_people') }}</p>
                <Link :href="route('members.index')" class="mt-3 inline-block text-sm text-primary hover:underline">
                    {{ t('members.following.find_members') }}
                </Link>
            </section>

            <template v-else>
                <section class="space-y-3" aria-labelledby="people-you-follow">
                    <h2 id="people-you-follow" class="text-sm font-medium text-muted-foreground">{{ t('members.following.people') }}</h2>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li v-for="member in people" :key="member.id" class="flex items-center justify-between gap-3 px-4 py-3">
                            <Link v-if="!member.banned" :href="route('members.show', member.id)" class="text-sm font-medium hover:underline">
                                {{ member.nickname }}
                            </Link>
                            <span v-else class="text-sm font-medium">{{ member.nickname }}</span>
                            <Button v-if="!viewingAs" variant="outline" size="sm" type="button" @click="stopFollowing(member)">
                                {{ t('members.following.stop') }}
                            </Button>
                        </li>
                    </ul>
                </section>

                <section class="space-y-3">
                    <p v-if="posts.data.length === 0" class="text-sm text-muted-foreground">{{ t('members.following.empty_posts') }}</p>
                    <ul v-else class="space-y-3">
                        <li v-for="post in posts.data" :key="post.id" class="rounded-xl border bg-card p-5 shadow-xs">
                            <p class="text-sm font-medium">
                                <Link :href="post.url" class="hover:underline">
                                    {{ t('members.following.posted_in', { name: post.author.nickname, thread: post.thread }) }}
                                </Link>
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ post.board }}</p>
                            <p v-if="post.excerpt" class="mt-2 text-sm text-muted-foreground">{{ post.excerpt }}</p>
                            <p v-if="post.created_at" class="mt-2 text-xs text-muted-foreground">{{ post.created_at }}</p>
                        </li>
                    </ul>
                </section>

                <nav
                    v-if="posts.meta.last_page > 1"
                    class="flex items-center justify-between gap-3"
                    :aria-label="t('members.following.page', { current: posts.meta.current_page, last: posts.meta.last_page })"
                >
                    <Link
                        v-if="posts.links.prev"
                        :href="posts.links.prev"
                        class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                    >
                        {{ t('members.following.previous') }}
                    </Link>
                    <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.following.previous') }}</span>
                    <p class="text-sm text-muted-foreground">
                        {{ t('members.following.page', { current: posts.meta.current_page, last: posts.meta.last_page }) }}
                    </p>
                    <Link
                        v-if="posts.links.next"
                        :href="posts.links.next"
                        class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                    >
                        {{ t('members.following.next') }}
                    </Link>
                    <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.following.next') }}</span>
                </nav>
            </template>
        </div>
    </AppLayout>
</template>
