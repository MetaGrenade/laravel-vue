<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

interface ConversationMember {
    id: number;
    nickname: string;
    banned: boolean;
}

interface TranscriptMessage {
    id: number;
    body: string;
    mine: boolean;
    created_at: string | null;
}

interface MessagePage {
    data: TranscriptMessage[];
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
    member: ConversationMember;
    messages: MessagePage;
    blocked: 'ignoring' | 'unavailable' | null;
}>();

const { t } = useI18n();
const page = usePage<SharedData>();
const viewingAs = computed(() => page.props.impersonation !== null);
const formVisible = computed(() => props.blocked === null && !viewingAs.value);

const blockedText = computed(() => {
    if (props.blocked === 'ignoring') {
        return t('members.messages.blocked_ignoring', { name: props.member.nickname });
    }

    if (props.blocked === 'unavailable') {
        return t('members.messages.blocked_unavailable');
    }

    return null;
});

const form = useForm({
    body: '',
    user: '',
});

const send = () => {
    form.post(route('messages.store', props.member.id), {
        preserveScroll: true,
        onSuccess: () => form.reset('body'),
    });
};

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: t('members.messages.title'), href: route('messages.index') },
    { title: props.member.nickname, href: route('messages.show', props.member.id) },
]);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="t('members.messages.with', { name: member.nickname })" />

        <div class="space-y-8 p-4">
            <header class="space-y-2">
                <h1 class="text-2xl font-semibold tracking-tight">
                    <Link v-if="!member.banned" :href="route('members.show', member.id)" class="hover:underline">
                        {{ member.nickname }}
                    </Link>
                    <template v-else>{{ member.nickname }}</template>
                </h1>
                <p class="max-w-2xl text-sm text-muted-foreground">{{ t('members.messages.description') }}</p>
            </header>

            <p v-if="messages.data.length === 0 && formVisible" class="text-sm text-muted-foreground">
                {{ t('members.messages.start', { name: member.nickname }) }}
            </p>

            <ol v-else class="space-y-3">
                <li v-for="message in messages.data" :key="message.id" class="rounded-xl border bg-card p-5 shadow-xs">
                    <p class="text-sm font-medium">{{ message.mine ? t('members.messages.you') : member.nickname }}</p>
                    <p class="mt-2 text-sm break-words whitespace-pre-line">{{ message.body }}</p>
                    <p v-if="message.created_at" class="mt-2 text-xs text-muted-foreground">{{ message.created_at }}</p>
                </li>
            </ol>

            <nav
                v-if="messages.meta.last_page > 1"
                class="flex items-center justify-between gap-3"
                :aria-label="t('members.messages.page', { current: messages.meta.current_page, last: messages.meta.last_page })"
            >
                <Link
                    v-if="messages.links.prev"
                    :href="messages.links.prev"
                    class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    {{ t('members.messages.previous') }}
                </Link>
                <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.messages.previous') }}</span>
                <p class="text-sm text-muted-foreground">
                    {{ t('members.messages.page', { current: messages.meta.current_page, last: messages.meta.last_page }) }}
                </p>
                <Link
                    v-if="messages.links.next"
                    :href="messages.links.next"
                    class="rounded-md border bg-card px-3 py-2 text-sm font-medium hover:bg-muted"
                >
                    {{ t('members.messages.next') }}
                </Link>
                <span v-else class="rounded-md border px-3 py-2 text-sm text-muted-foreground">{{ t('members.messages.next') }}</span>
            </nav>

            <p v-if="blockedText" class="text-sm text-muted-foreground">{{ blockedText }}</p>

            <form v-if="formVisible" class="space-y-3" @submit.prevent="send">
                <label for="direct-message-body" class="text-sm font-medium">{{ t('members.messages.body_label') }}</label>
                <Textarea id="direct-message-body" v-model="form.body" rows="5" maxlength="5000" name="body" />
                <InputError :message="form.errors.body" />
                <InputError :message="form.errors.user" />
                <Button type="submit" :disabled="form.processing">{{ t('members.messages.send') }}</Button>
            </form>
        </div>
    </AppLayout>
</template>
