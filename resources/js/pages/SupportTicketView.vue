<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import Button from '@/components/ui/button/Button.vue';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';
import Input from '@/components/ui/input/Input.vue';
import { Paperclip } from 'lucide-vue-next';

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

interface TicketMessageAttachment {
    id: number;
    name: string;
    size: number;
    download_url: string;
}

interface TicketMessage {
    id: number;
    body: string;
    created_at: string | null;
    author: TicketAssignee | null;
    is_from_support: boolean;
    attachments: TicketMessageAttachment[];
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
        customer_satisfaction_rating: number | null;
    };
    messages: TicketMessage[];
    canReply: boolean;
    canRate: boolean;
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

interface ReplyFormPayload {
    body: string;
    attachments: File[];
}

const replyForm = useForm<ReplyFormPayload>({
    body: '',
    attachments: [],
});

const attachmentInput = ref<HTMLInputElement | null>(null);

const ratingOptions = [1, 2, 3, 4, 5];
const ratingForm = useForm<{ rating: number | null }>({
    rating: null,
});

const reopenForm = useForm<Record<string, never>>({});

const isClosed = computed(() => props.ticket.status === 'closed');
const canRateTicket = computed(() => props.canRate);

const handleAttachmentsChange = (event: Event) => {
    const target = event.target as HTMLInputElement;

    replyForm.attachments = target.files ? Array.from(target.files) : [];
};

const attachmentErrors = computed(() => {
    const errorEntries = Object.entries(replyForm.errors).filter(([key]) =>
        key === 'attachments' || key.startsWith('attachments.'),
    );

    return errorEntries.length > 0 ? errorEntries[0][1] : '';
});

const resetAttachmentsInput = () => {
    if (attachmentInput.value) {
        attachmentInput.value.value = '';
    }
};

const submitReply = () => {
    if (!props.canReply) {
        return;
    }

    replyForm.transform((data) => {
        if (!data.attachments || data.attachments.length === 0) {
            const payload = { ...data };
            delete payload.attachments;

            return payload;
        }

        return data;
    });

    replyForm.post(route('support.tickets.messages.store', { ticket: props.ticket.id }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            replyForm.reset('body', 'attachments');
            resetAttachmentsInput();
        },
        onFinish: () => {
            replyForm.transform((data) => ({ ...data }));
        },
    });
};

const selectRating = (value: number) => {
    if (!canRateTicket.value) {
        return;
    }

    ratingForm.rating = value;
    ratingForm.clearErrors('rating');
};

const submitRating = () => {
    if (!canRateTicket.value || ratingForm.processing) {
        return;
    }

    if (ratingForm.rating === null) {
        ratingForm.setError('rating', 'Please select a rating.');
        return;
    }

    ratingForm.post(route('support.tickets.rating.store', { ticket: props.ticket.id }), {
        preserveScroll: true,
        onSuccess: () => {
            ratingForm.reset('rating');
        },
    });
};

const confirmReopenTicket = () => {
    if (!isClosed.value || reopenForm.processing) {
        return;
    }

    if (
        !window.confirm(
            'Reopen this ticket to let our support team know you still need help? We will notify the team immediately.',
        )
    ) {
        return;
    }

    reopenForm.patch(route('support.tickets.reopen', { ticket: props.ticket.id }), {
        preserveScroll: true,
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

const formatFileSize = (bytes: number) => {
    if (!bytes) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const size = bytes / Math.pow(1024, exponent);

    const formatted = size >= 10 || exponent === 0 ? size.toFixed(0) : size.toFixed(1);

    return `${formatted} ${units[exponent]}`;
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
                                    <div v-if="message.attachments.length" class="mt-3 flex flex-col gap-2">
                                        <div
                                            class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide opacity-80"
                                        >
                                            <Paperclip class="h-3 w-3" />
                                            <span>Attachments</span>
                                        </div>
                                        <ul class="flex flex-col gap-1 text-xs">
                                            <li v-for="attachment in message.attachments" :key="attachment.id">
                                                <a
                                                    :href="attachment.download_url"
                                                    target="_blank"
                                                    rel="noopener"
                                                    class="flex items-center gap-2 underline underline-offset-4"
                                                    :class="message.is_from_support
                                                        ? 'text-primary hover:text-primary/80'
                                                        : 'text-primary-foreground hover:text-primary-foreground/80'"
                                                >
                                                    <span class="truncate">{{ attachment.name }}</span>
                                                    <span
                                                        class="whitespace-nowrap text-[0.7rem]"
                                                        :class="message.is_from_support
                                                            ? 'text-muted-foreground'
                                                            : 'text-primary-foreground/80'"
                                                    >
                                                        {{ formatFileSize(attachment.size) }}
                                                    </span>
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
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
                            <div class="flex flex-col gap-2">
                                <Input
                                    ref="attachmentInput"
                                    id="attachments"
                                    type="file"
                                    multiple
                                    :disabled="replyForm.processing"
                                    accept="image/*,application/pdf,text/plain,text/csv,application/zip,application/json,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/x-ndjson"
                                    @change="handleAttachmentsChange"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Attach relevant screenshots or log files (up to 5 files, 10&nbsp;MB each).
                                </p>
                                <ul v-if="replyForm.attachments.length" class="flex flex-wrap gap-2 text-xs">
                                    <li
                                        v-for="file in replyForm.attachments"
                                        :key="`${file.name}-${file.lastModified}`"
                                        class="flex items-center gap-2 rounded-md border border-dashed border-muted bg-muted/40 px-2 py-1"
                                    >
                                        <Paperclip class="h-3 w-3" />
                                        <span class="max-w-[10rem] truncate">{{ file.name }}</span>
                                        <span class="text-muted-foreground">{{ formatFileSize(file.size) }}</span>
                                    </li>
                                </ul>
                                <InputError :message="attachmentErrors" />
                            </div>
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
                                <p class="text-xs uppercase text-muted-foreground">Customer satisfaction</p>
                                <p class="font-medium text-foreground">
                                    {{
                                        typeof props.ticket.customer_satisfaction_rating === 'number'
                                            ? `${props.ticket.customer_satisfaction_rating} / 5`
                                            : 'Not yet rated'
                                    }}
                                </p>
                                <p class="text-muted-foreground">
                                    <span v-if="typeof props.ticket.customer_satisfaction_rating === 'number'">
                                        Thanks for letting us know how we did.
                                    </span>
                                    <span v-else-if="isClosed">
                                        Share your experience using the feedback card below.
                                    </span>
                                    <span v-else>
                                        We'll ask for feedback once this ticket is closed.
                                    </span>
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
                            <CardTitle>Rate your experience</CardTitle>
                            <CardDescription>
                                Let us know how satisfied you are with the resolution of this ticket.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm">
                            <div v-if="typeof props.ticket.customer_satisfaction_rating === 'number'" class="space-y-2">
                                <p class="text-lg font-semibold">
                                    {{ props.ticket.customer_satisfaction_rating }} / 5
                                </p>
                                <p class="text-muted-foreground">
                                    We appreciate your feedback and will use it to keep improving our support.
                                </p>
                            </div>
                            <div v-else-if="canRateTicket" class="space-y-4">
                                <form class="space-y-4" @submit.prevent="submitRating">
                                    <div class="flex items-center gap-2">
                                        <Button
                                            v-for="value in ratingOptions"
                                            :key="value"
                                            type="button"
                                            :variant="ratingForm.rating === value ? 'default' : 'outline'"
                                            class="h-10 w-10 p-0"
                                            :aria-pressed="ratingForm.rating === value"
                                            :disabled="ratingForm.processing"
                                            @click="selectRating(value)"
                                        >
                                            {{ value }}
                                        </Button>
                                    </div>
                                    <InputError :message="ratingForm.errors.rating" />
                                    <div class="flex justify-end">
                                        <Button type="submit" :disabled="ratingForm.processing">
                                            Submit rating
                                        </Button>
                                    </div>
                                </form>
                            </div>
                            <p v-else class="text-muted-foreground">
                                We'll invite you to rate your experience once our team resolves this ticket.
                            </p>
                        </CardContent>
                    </Card>

                    <Card v-if="isClosed">
                        <CardHeader>
                            <CardTitle>Need more help?</CardTitle>
                            <CardDescription>
                                Reopen this ticket to continue the conversation with our support team.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4 text-sm">
                            <p class="text-muted-foreground">
                                If the issue resurfaces or you have new information, let us know and we'll jump back in.
                            </p>
                            <Button
                                type="button"
                                :disabled="reopenForm.processing"
                                @click="confirmReopenTicket"
                            >
                                <span v-if="reopenForm.processing">Reopening…</span>
                                <span v-else>Reopen ticket</span>
                            </Button>
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
