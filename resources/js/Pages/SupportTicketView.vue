<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface TicketAssignee {
    id: number;
    nickname: string;
    email: string;
}

interface TicketUser {
    id: number;
    nickname: string;
    email: string;
}

interface TicketMessage {
    id: number;
    body: string;
    created_at: string | null;
    author: TicketAssignee | null;
    is_from_support: boolean;
}

const props = defineProps<{
    ticket: {
        id: number;
        subject: string;
        body: string;
        status: 'open' | 'pending' | 'closed';
        priority: 'low' | 'medium' | 'high';
        created_at: string | null;
        updated_at: string | null;
        assignee: TicketAssignee | null;
        user: TicketUser | null;
    };
    messages: TicketMessage[];
    canReply: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support Center', href: route('support') },
    { title: `Ticket #${props.ticket.id}`, href: route('support.tickets.show', { ticket: props.ticket.id }) },
];

const statusLabel = computed(() => props.ticket.status.replace(/^[a-z]/, (s) => s.toUpperCase()));
const priorityLabel = computed(() => props.ticket.priority.replace(/^[a-z]/, (s) => s.toUpperCase()));

const statusClasses = computed(() => {
    switch (props.ticket.status) {
        case 'open':
            return 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300';
        case 'pending':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        case 'closed':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300';
    }
});

const priorityClasses = computed(() => {
    switch (props.ticket.priority) {
        case 'high':
            return 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300';
        case 'medium':
            return 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300';
        case 'low':
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        default:
            return 'bg-gray-100 text-gray-700 dark:bg-gray-900/40 dark:text-gray-300';
    }
});

const { formatDate, fromNow } = useUserTimezone();

const formattedCreatedAt = computed(() => formatDate(props.ticket.created_at));
const formattedUpdatedAt = computed(() => formatDate(props.ticket.updated_at));

const replyForm = useForm({
    body: '',
});

const submitReply = () => {
    if (!props.canReply) {
        return;
    }

    replyForm.post(route('support.tickets.messages.store', { ticket: props.ticket.id }), {
        preserveScroll: true,
        onSuccess: () => {
            replyForm.reset('body');
        },
    });
};

const sortedMessages = computed(() => {
    const messages = props.messages ?? [];

    return [...messages].sort((a, b) => {
        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;

        return aTime - bTime;
    });
});

const resolveAuthorLabel = (message: TicketMessage) => {
    if (!message.author) {
        return message.is_from_support ? 'Support Team' : 'You';
    }

    if (!message.is_from_support) {
        return 'You';
    }

    return message.author.nickname ?? message.author.email ?? 'Support Team';
};

const messageTimestamp = (value: string | null) => {
    if (!value) {
        return '';
    }

    return `${formatDate(value)} · ${fromNow(value)}`;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Ticket #${props.ticket.id}`" />

        <div class="container mx-auto flex flex-1 flex-col gap-6 p-4">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-sm text-muted-foreground">Ticket #{{ props.ticket.id }}</p>
                    <h1 class="text-3xl font-semibold tracking-tight">{{ props.ticket.subject }}</h1>
                    <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                        View the full conversation with our support team and keep the discussion moving forward.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="rounded-full px-3 py-1 text-xs font-medium" :class="statusClasses">
                        Status: {{ statusLabel }}
                    </span>
                    <span class="rounded-full px-3 py-1 text-xs font-medium" :class="priorityClasses">
                        Priority: {{ priorityLabel }}
                    </span>
                    <Button variant="outline" as-child>
                        <Link :href="route('support')">Back to Support</Link>
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-[minmax(0,_2fr)_minmax(0,_1fr)]">
                <Card class="flex flex-col">
                    <CardHeader>
                        <CardTitle>Conversation</CardTitle>
                        <CardDescription>Messages between you and our support team.</CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-1 flex-col gap-6">
                        <div class="flex flex-col gap-4">
                            <div
                                v-for="message in sortedMessages"
                                :key="message.id"
                                class="flex flex-col gap-1"
                                :class="message.is_from_support ? 'items-start' : 'items-end'">
                                <div
                                    class="max-w-xl rounded-lg px-4 py-3 text-sm leading-relaxed shadow-sm"
                                    :class="message.is_from_support
                                        ? 'bg-background border'
                                        : 'bg-primary text-primary-foreground'
                                    "
                                >
                                    <p class="mb-2 whitespace-pre-line">{{ message.body }}</p>
                                    <p class="text-xs font-medium opacity-75">
                                        {{ resolveAuthorLabel(message) }} · {{ messageTimestamp(message.created_at) }}
                                    </p>
                                </div>
                            </div>

                            <p v-if="sortedMessages.length === 0" class="text-sm text-muted-foreground">
                                There are no messages on this ticket yet.
                            </p>
                        </div>

                        <form v-if="props.canReply" class="mt-4 flex flex-col gap-3" @submit.prevent="submitReply">
                            <label for="message" class="text-sm font-medium">Reply to support</label>
                            <Textarea
                                id="message"
                                v-model="replyForm.body"
                                placeholder="Share an update or ask a follow-up question..."
                                class="min-h-32"
                                :disabled="replyForm.processing"
                                required
                            />
                            <InputError :message="replyForm.errors.body" />
                            <div class="flex justify-end">
                                <Button type="submit" :disabled="replyForm.processing">
                                    Send message
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <div class="flex flex-col gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Ticket details</CardTitle>
                            <CardDescription>Key context for this request.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm">
                            <div>
                                <p class="text-xs uppercase text-muted-foreground">Opened by</p>
                                <p class="font-medium text-foreground">
                                    {{ props.ticket.user?.nickname ?? 'You' }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ props.ticket.user?.email ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-muted-foreground">Assigned agent</p>
                                <p class="font-medium text-foreground">
                                    {{ props.ticket.assignee?.nickname ?? 'Unassigned' }}
                                </p>
                                <p class="text-muted-foreground">
                                    {{ props.ticket.assignee?.email ?? '—' }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-muted-foreground">Created</p>
                                <p class="font-medium text-foreground">{{ formattedCreatedAt }}</p>
                                <p class="text-muted-foreground">{{ fromNow(props.ticket.created_at) }}</p>
                            </div>
                            <div>
                                <p class="text-xs uppercase text-muted-foreground">Last updated</p>
                                <p class="font-medium text-foreground">{{ formattedUpdatedAt }}</p>
                                <p class="text-muted-foreground">{{ fromNow(props.ticket.updated_at) }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Original description</CardTitle>
                            <CardDescription>The initial details you provided.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p class="whitespace-pre-line text-sm leading-relaxed text-foreground">
                                {{ props.ticket.body }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
