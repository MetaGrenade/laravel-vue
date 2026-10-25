<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import { getInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { Search, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

interface MemberCard {
    id: number;
    nickname: string;
    url: string;
    avatar_url: string | null;
    bio: string | null;
    reputation: number;
    joined_at: string | null;
}

interface MemberPage {
    data: MemberCard[];
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

const props = defineProps<{
    members: MemberPage;
    search: string;
    sort: 'newest' | 'name' | 'reputation';
}>();

const { t } = useI18n();

const query = ref(props.search);

watch(
    () => props.search,
    (value) => {
        query.value = value;
    },
);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: t('members.directory.title'), href: route('members.index') }]);

const sorts = [
    { value: 'newest', label: 'members.directory.sort_newest' },
    { value: 'name', label: 'members.directory.sort_name' },
    { value: 'reputation', label: 'members.directory.sort_reputation' },
] as const;

const sortHref = (value: string) => route('members.index', { sort: value, ...(props.search !== '' ? { q: props.search } : {}) });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('members.directory.title')" />

        <div class="space-y-6 p-4">
            <header class="flex flex-col justify-between gap-4 rounded-xl border bg-card p-5 shadow-xs md:flex-row md:items-center">
                <div class="flex items-center gap-4">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Users class="size-5" />
                    </span>
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">{{ t('members.directory.title') }}</h1>
                        <p class="text-sm text-muted-foreground">{{ t('members.directory.description') }}</p>
                    </div>
                </div>

                <form :action="route('members.index')" method="get" class="flex w-full max-w-md gap-2" role="search">
                    <input v-if="sort !== 'newest'" type="hidden" name="sort" :value="sort" />
                    <label class="flex-1">
                        <span class="sr-only">{{ t('members.directory.search_label') }}</span>
                        <Input v-model="query" name="q" type="search" :placeholder="t('members.directory.search_placeholder')" maxlength="100" />
                    </label>
                    <Button type="submit">
                        <Search />
                        {{ t('members.directory.submit') }}
                    </Button>
                </form>
            </header>

            <nav class="flex flex-wrap items-center gap-2" :aria-label="t('members.directory.sort_label')">
                <Link
                    v-for="item in sorts"
                    :key="item.value"
                    :href="sortHref(item.value)"
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="
                        sort === item.value
                            ? 'bg-primary/10 font-medium text-foreground'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    :aria-current="sort === item.value ? 'true' : undefined"
                >
                    {{ t(item.label) }}
                </Link>
            </nav>

            <p v-if="members.data.length === 0" class="rounded-xl border bg-card px-5 py-10 text-center text-sm text-muted-foreground">
                {{ search !== '' ? t('members.directory.empty_search') : t('members.directory.empty') }}
            </p>

            <ul v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <li v-for="member in members.data" :key="member.id">
                    <Link
                        :href="member.url"
                        class="flex h-full gap-4 rounded-xl border bg-card p-4 shadow-xs transition-colors hover:border-primary/40"
                        :aria-label="t('members.profile.open_profile', { name: member.nickname })"
                    >
                        <Avatar class="size-14 shrink-0">
                            <AvatarImage v-if="member.avatar_url" :src="member.avatar_url" :alt="member.nickname" />
                            <AvatarFallback class="text-sm font-semibold uppercase">{{ getInitials(member.nickname) }}</AvatarFallback>
                        </Avatar>
                        <span class="min-w-0">
                            <span class="block truncate text-base font-semibold">{{ member.nickname }}</span>
                            <span v-if="member.joined_at" class="mt-0.5 block text-xs text-muted-foreground">
                                {{ t('members.directory.joined', { date: member.joined_at }) }}
                            </span>
                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                {{ t('members.directory.reputation', { count: member.reputation }) }}
                            </span>
                            <span v-if="member.bio" class="mt-2 line-clamp-2 block text-sm text-muted-foreground">{{ member.bio }}</span>
                        </span>
                    </Link>
                </li>
            </ul>

            <nav
                v-if="members.meta.last_page > 1"
                class="flex items-center justify-between gap-3"
                :aria-label="t('members.directory.page', { current: members.meta.current_page, last: members.meta.last_page })"
            >
                <Link
                    v-if="members.links.prev"
                    :href="members.links.prev"
                    class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    {{ t('members.directory.previous') }}
                </Link>
                <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.directory.previous') }}</span>
                <p class="text-sm text-muted-foreground">
                    {{ t('members.directory.page', { current: members.meta.current_page, last: members.meta.last_page }) }}
                </p>
                <Link
                    v-if="members.links.next"
                    :href="members.links.next"
                    class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    {{ t('members.directory.next') }}
                </Link>
                <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.directory.next') }}</span>
            </nav>
        </div>
    </AppLayout>
</template>
