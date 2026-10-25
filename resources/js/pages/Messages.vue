<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ConversationMember {
    id: number;
    nickname: string;
    banned: boolean;
}

interface ConversationRow {
    member: ConversationMember;
    excerpt: string | null;
    unread: boolean;
    url: string;
    created_at: string | null;
}

interface ConversationPage {
    data: ConversationRow[];
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
    conversations: ConversationPage;
}>();

const { t } = useI18n();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [{ title: t('members.messages.title'), href: route('messages.index') }]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('members.messages.title')" />

        <div class="space-y-8 p-4">
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">{{ t('members.messages.title') }}</h1>
                <p class="max-w-2xl text-sm text-muted-foreground">{{ t('members.messages.description') }}</p>
            </header>

            <section v-if="conversations.data.length === 0" class="rounded-xl border bg-card p-5 shadow-xs">
                <p class="text-sm text-muted-foreground">{{ t('members.messages.empty') }}</p>
                <Link :href="route('members.index')" class="mt-3 inline-block text-sm text-primary hover:underline">
                    {{ t('members.messages.find_members') }}
                </Link>
            </section>

            <template v-else>
                <ul class="divide-y rounded-xl border bg-card">
                    <li v-for="conversation in conversations.data" :key="conversation.member.id">
                        <Link :href="conversation.url" class="block px-4 py-3 hover:bg-muted/40">
                            <span class="flex items-baseline justify-between gap-3">
                                <span class="text-sm font-medium">{{ conversation.member.nickname }}</span>
                                <span v-if="conversation.unread" class="text-xs font-medium">{{ t('members.messages.unread') }}</span>
                            </span>
                            <span v-if="conversation.excerpt" class="mt-1 block text-sm text-muted-foreground">{{ conversation.excerpt }}</span>
                            <span v-if="conversation.created_at" class="mt-1 block text-xs text-muted-foreground">{{ conversation.created_at }}</span>
                        </Link>
                    </li>
                </ul>

                <nav
                    v-if="conversations.meta.last_page > 1"
                    class="flex items-center justify-between gap-3"
                    :aria-label="t('members.messages.page', { current: conversations.meta.current_page, last: conversations.meta.last_page })"
                >
                    <Link
                        v-if="conversations.links.prev"
                        :href="conversations.links.prev"
                        class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                    >
                        {{ t('members.messages.previous') }}
                    </Link>
                    <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.messages.previous') }}</span>
                    <p class="text-sm text-muted-foreground">
                        {{ t('members.messages.page', { current: conversations.meta.current_page, last: conversations.meta.last_page }) }}
                    </p>
                    <Link
                        v-if="conversations.links.next"
                        :href="conversations.links.next"
                        class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                    >
                        {{ t('members.messages.next') }}
                    </Link>
                    <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.messages.next') }}</span>
                </nav>
            </template>
        </div>
    </AppLayout>
</template>
