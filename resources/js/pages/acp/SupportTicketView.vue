<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import Button from '@/components/ui/button/Button.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import Input from '@/components/ui/input/Input.vue';
import { Paperclip, Sparkles } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface TicketParticipant {
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
    author: TicketParticipant | null;
    is_from_support: boolean;
    attachments: TicketMessageAttachment[];
}

interface TicketAudit {
    id: number;
    action: string;
    description: string;
    context: Record<string, unknown> | null;
    performed_by: number | null;
    actor: TicketParticipant | null;
    created_at: string | null;
}

interface SupportTemplateMeta {
    id: number;
    title: string;
    body: string;
    is_active: boolean;
    support_ticket_category_id: number | null;
    support_team_ids: number[];
    category: { id: number; name: string } | null;
    teams: { id: number; name: string }[];
}

const props = defineProps<{
    ticket: {
        id: number;
        subject: string;
        body: string;
        status: 'open' | 'pending' | 'closed';
        priority: 'low' | 'medium' | 'high';
        support_ticket_category_id: number | null;
        assigned_to: number | null;
        created_at: string | null;
        updated_at: string | null;
        resolved_at: string | null;
        resolved_by: number | null;
        assignee: TicketParticipant | null;
        resolver: TicketParticipant | null;
        user: TicketParticipant | null;
    };
    messages: TicketMessage[];
    audits: TicketAudit[];
    canReply: boolean;
    assignableAgents: TicketParticipant[];
    templates: SupportTemplateMeta[];
    agentTeamIds: number[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: `Ticket #${props.ticket.id}`, href: route('acp.support.tickets.show', { ticket: props.ticket.id }) },
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
const formattedResolvedAt = computed(() => formatDate(props.ticket.resolved_at));
const isClosed = computed(() => props.ticket.status === 'closed');

interface ReplyFormPayload {
    body: string;
    attachments: File[];
}

const replyForm = useForm<ReplyFormPayload>({
    body: '',
    attachments: [],
});

const agentTeamIdsSet = computed(() => new Set(props.agentTeamIds ?? []));

const availableTemplates = computed(() => {
    const categoryId = props.ticket.support_ticket_category_id;
    const templates = props.templates ?? [];

    return templates
        .filter((template) => template.is_active)
        .filter((template) => {
            if (!template.support_ticket_category_id) {
                return true;
            }

            return template.support_ticket_category_id === categoryId;
        })
        .filter((template) => {
            if (!template.support_team_ids || template.support_team_ids.length === 0) {
                return true;
            }

            const teams = agentTeamIdsSet.value;

            return template.support_team_ids.some((teamId) => teams.has(teamId));
        });
});

const templateGroups = computed(() => {
    const groups = new Map<string, SupportTemplateMeta[]>();

    for (const template of availableTemplates.value) {
        const targetTeams = template.teams && template.teams.length > 0 ? template.teams : [{ id: 0, name: 'General' }];

        for (const team of targetTeams) {
            const groupName = team.name ?? 'General';

            if (!groups.has(groupName)) {
                groups.set(groupName, []);
            }

            const group = groups.get(groupName)!;

            if (!group.includes(template)) {
                group.push(template);
            }
        }
    }

    const entries = Array.from(groups.entries()).map(([name, items]) => ({
        name,
        items: [...items].sort((a, b) => a.title.localeCompare(b.title)),
    }));

    return entries.sort((a, b) => {
        if (a.name === 'General') {
            return -1;
        }

        if (b.name === 'General') {
            return 1;
        }

        return a.name.localeCompare(b.name);
    });
});

const hasTemplateOptions = computed(() =>
    templateGroups.value.some((group) => group.items.length > 0),
);

const applyTemplate = (template: SupportTemplateMeta) => {
    const currentBody = replyForm.body ?? '';
    const existing = currentBody.trim().length > 0 ? currentBody.replace(/\s+$/, '') : '';
    const templateBody = template.body.trim();

    if (existing.length === 0) {
        replyForm.body = templateBody;
    } else if (templateBody.length === 0) {
        replyForm.body = existing;
    } else {
        replyForm.body = `${existing}\n\n${templateBody}`;
    }

    replyForm.clearErrors('body');
};

const attachmentInput = ref<HTMLInputElement | null>(null);

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

const statusOptions = [
    { label: 'Open', value: 'open' },
    { label: 'Pending', value: 'pending' },
    { label: 'Closed', value: 'closed' },
];

const priorityOptions = [
    { label: 'Low', value: 'low' },
    { label: 'Medium', value: 'medium' },
    { label: 'High', value: 'high' },
];

const assignmentForm = useForm<{ assigned_to: number | null }>({
    assigned_to: props.ticket.assigned_to,
});

const priorityForm = useForm<{ priority: 'low' | 'medium' | 'high' }>({
    priority: props.ticket.priority,
});

const statusForm = useForm<{ status: 'open' | 'pending' | 'closed' }>({
    status: props.ticket.status,
});

watch(
    () => props.ticket.assigned_to,
    (assignedTo) => {
        assignmentForm.assigned_to = assignedTo ?? null;
    },
    { immediate: true },
);

watch(
    () => props.ticket.priority,
    (priority) => {
        priorityForm.priority = priority;
    },
    { immediate: true },
);

watch(
    () => props.ticket.status,
    (status) => {
        statusForm.status = status;
    },
    { immediate: true },
);

const updateAssignment = () => {
    if (isClosed.value) {
        return;
    }

    assignmentForm.put(route('acp.support.tickets.assign', { ticket: props.ticket.id }), {
        preserveScroll: true,
    });
};

const updatePriority = () => {
    if (isClosed.value) {
        return;
    }

    priorityForm.put(route('acp.support.tickets.priority', { ticket: props.ticket.id }), {
        preserveScroll: true,
    });
};

const updateStatus = () => {
    if (isClosed.value) {
        return;
    }

    statusForm.put(route('acp.support.tickets.status', { ticket: props.ticket.id }), {
        preserveScroll: true,
    });
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

    replyForm.post(route('acp.support.tickets.messages.store', { ticket: props.ticket.id }), {
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

const sortedMessages = computed(() => {
    const messages = props.messages ?? [];

    return [...messages].sort((a, b) => {
        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;

        return aTime - bTime;
    });
});

const hasMessages = computed(() => sortedMessages.value.length > 0);

const sortedAudits = computed(() => {
    const audits = props.audits ?? [];

    return [...audits].sort((a, b) => {
        const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
        const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;

        return bTime - aTime;
    });
});

const hasAudits = computed(() => sortedAudits.value.length > 0);

const resolveAuthorLabel = (message: TicketMessage) => {
    if (!message.author) {
        return message.is_from_support
            ? 'Support Team'
            : props.ticket.user?.nickname ?? props.ticket.user?.email ?? 'Requester';
    }

    if (message.is_from_support) {
        return message.author.nickname ?? message.author.email ?? 'Support Team';
    }

    return message.author.nickname ?? message.author.email ?? 'Requester';
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

const resolveAuditActorLabel = (audit: TicketAudit) => {
    return audit.actor?.nickname ?? audit.actor?.email ?? 'System';
};

const humanizeAuditAction = (action: string) => {
    return action
        .split('_')
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
};

const humanizeAuditKey = (key: string) => {
    return key
        .split('_')
        .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
        .join(' ');
};

const formatAuditContextValue = (value: unknown) => {
    if (value === null || value === undefined) {
        return '—';
    }

    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    try {
        return JSON.stringify(value);
    } catch {
        return String(value);
    }
};

const auditContextEntries = (audit: TicketAudit) => {
    if (!audit.context || typeof audit.context !== 'object') {
        return [] as Array<[string, unknown]>;
    }

    return Object.entries(audit.context);
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Ticket #${props.ticket.id}`" />

        <AdminLayout>
            <div class="flex flex-1 flex-col gap-6">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="text-sm text-muted-foreground">Ticket #{{ props.ticket.id }}</p>
                        <h1 class="text-3xl font-semibold tracking-tight">{{ props.ticket.subject }}</h1>
                        <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                            Review the full correspondence and keep the requester updated from the admin console.
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
                            <Link :href="route('acp.support.index')">Back to Support</Link>
                        </Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_2fr)_minmax(0,_1fr)]">
                    <Card class="flex h-full flex-col">
                        <CardHeader>
                            <CardTitle>Conversation</CardTitle>
                            <CardDescription>Messages exchanged between staff and the requester.</CardDescription>
                        </CardHeader>
                        <CardContent class="flex flex-1 flex-col gap-6">
                            <div v-if="hasMessages" class="flex flex-col gap-4">
                                <div
                                    v-for="message in sortedMessages"
                                    :key="message.id"
                                    class="flex flex-col gap-1"
                                    :class="message.is_from_support ? 'items-end' : 'items-start'"
                                >
                                    <div
                                        class="max-w-xl rounded-lg px-4 py-3 text-sm leading-relaxed shadow-sm"
                                        :class="message.is_from_support
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-background border'"
                                    >
                                        <div class="mb-2 flex items-center justify-between text-xs font-semibold uppercase tracking-wide">
                                            <span>{{ resolveAuthorLabel(message) }}</span>
                                            <span class="opacity-75">{{ messageTimestamp(message.created_at) }}</span>
                                        </div>
                                        <p class="whitespace-pre-line">{{ message.body }}</p>
                                        <div v-if="message.attachments.length" class="mt-3 flex flex-col gap-2">
                                            <div class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide opacity-80">
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
                                                            ? 'text-primary-foreground hover:text-primary-foreground/80'
                                                            : 'text-primary hover:text-primary/80'"
                                                    >
                                                        <span class="truncate">{{ attachment.name }}</span>
                                                        <span class="whitespace-nowrap text-[0.7rem] opacity-80">
                                                            {{ formatFileSize(attachment.size) }}
                                                        </span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                There are no messages on this ticket yet.
                            </p>
                        </CardContent>
                        <CardFooter
                            v-if="props.canReply"
                            class="items-stretch border-t border-border/50"
                        >
                            <form class="flex w-full flex-col gap-3" @submit.prevent="submitReply">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <label for="message" class="text-sm font-medium">Post a staff reply</label>
                                    <DropdownMenu v-if="hasTemplateOptions">
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="flex items-center gap-2"
                                                :disabled="replyForm.processing"
                                            >
                                                <Sparkles class="h-4 w-4" />
                                                Insert template
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-80 max-h-80 overflow-y-auto">
                                            <template v-for="(group, index) in templateGroups" :key="group.name">
                                                <DropdownMenuLabel class="text-xs uppercase text-muted-foreground">
                                                    {{ group.name === 'General' ? 'All teams' : group.name }}
                                                </DropdownMenuLabel>
                                                <DropdownMenuItem
                                                    v-for="template in group.items"
                                                    :key="template.id"
                                                    class="whitespace-normal py-2"
                                                    :title="template.body"
                                                    @select="applyTemplate(template)"
                                                >
                                                    <div class="flex flex-col gap-1">
                                                        <span class="font-medium">{{ template.title }}</span>
                                                        <span class="text-xs text-muted-foreground">
                                                            {{ template.category?.name ?? 'All categories' }}
                                                        </span>
                                                    </div>
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator v-if="index < templateGroups.length - 1" />
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                                <Textarea
                                    id="message"
                                    v-model="replyForm.body"
                                    placeholder="Share updates, next steps, or troubleshooting guidance..."
                                    class="min-h-32"
                                    :disabled="replyForm.processing"
                                    required
                                    @keyup.meta.enter="submitReply"
                                    @keyup.ctrl.enter="submitReply"
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
                                        Attach relevant diagnostics or screenshots (up to 5 files, 10&nbsp;MB each).
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
                                        Send reply
                                    </Button>
                                </div>
                            </form>
                        </CardFooter>
                        <CardFooter
                            v-else
                            class="items-start border-t border-border/50"
                        >
                            <p class="text-sm text-muted-foreground">
                                Replies are disabled either because the ticket is closed or you lack reply permissions.
                            </p>
                        </CardFooter>
                    </Card>

                    <div class="flex flex-col gap-6">
                        <Card v-if="!isClosed">
                            <CardHeader>
                                <CardTitle>Ticket actions</CardTitle>
                                <CardDescription>Assign, triage, or progress the ticket.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm">
                                <form class="grid gap-2" @submit.prevent="updateAssignment">
                                    <label for="assigned_to" class="text-xs font-semibold uppercase text-muted-foreground">
                                        Assigned agent
                                    </label>
                                    <select
                                        id="assigned_to"
                                        v-model.number="assignmentForm.assigned_to"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                        :disabled="assignmentForm.processing"
                                    >
                                        <option :value="null">Unassigned</option>
                                        <option
                                            v-for="agent in props.assignableAgents"
                                            :key="agent.id"
                                            :value="agent.id"
                                        >
                                            {{ agent.nickname || agent.email }}
                                        </option>
                                    </select>
                                    <InputError :message="assignmentForm.errors.assigned_to" />
                                    <div class="flex justify-end">
                                        <Button type="submit" size="sm" :disabled="assignmentForm.processing">
                                            Update assignment
                                        </Button>
                                    </div>
                                </form>

                                <form class="grid gap-2" @submit.prevent="updatePriority">
                                    <label for="priority" class="text-xs font-semibold uppercase text-muted-foreground">
                                        Priority
                                    </label>
                                    <select
                                        id="priority"
                                        v-model="priorityForm.priority"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                        :disabled="priorityForm.processing"
                                    >
                                        <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="priorityForm.errors.priority" />
                                    <div class="flex justify-end">
                                        <Button type="submit" size="sm" :disabled="priorityForm.processing">
                                            Update priority
                                        </Button>
                                    </div>
                                </form>

                                <form class="grid gap-2" @submit.prevent="updateStatus">
                                    <label for="status" class="text-xs font-semibold uppercase text-muted-foreground">
                                        Status
                                    </label>
                                    <select
                                        id="status"
                                        v-model="statusForm.status"
                                        class="flex h-9 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                        :disabled="statusForm.processing"
                                    >
                                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="statusForm.errors.status" />
                                    <div class="flex justify-end">
                                        <Button type="submit" size="sm" :disabled="statusForm.processing">
                                            Update status
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Ticket history</CardTitle>
                                <CardDescription>Automation and SLA events recorded for this ticket.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <div v-if="hasAudits" class="flex flex-col gap-4">
                                    <div v-for="audit in sortedAudits" :key="audit.id" class="space-y-2">
                                        <div class="flex items-start justify-between gap-4">
                                            <p class="text-sm font-medium leading-6">
                                                {{ audit.description || humanizeAuditAction(audit.action) }}
                                            </p>
                                            <p class="whitespace-nowrap text-xs text-muted-foreground">
                                                {{ messageTimestamp(audit.created_at) }}
                                            </p>
                                        </div>
                                        <p class="text-xs text-muted-foreground">
                                            Logged by {{ resolveAuditActorLabel(audit) }}
                                        </p>
                                        <div
                                            v-if="auditContextEntries(audit).length"
                                            class="text-xs text-muted-foreground"
                                        >
                                            <dl class="flex flex-wrap gap-x-4 gap-y-1">
                                                <template v-for="[key, value] in auditContextEntries(audit)" :key="`${audit.id}-${key}`">
                                                    <dt class="font-medium">{{ humanizeAuditKey(key) }}</dt>
                                                    <dd>{{ formatAuditContextValue(value) }}</dd>
                                                </template>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-muted-foreground">
                                    No audit activity recorded for this ticket yet.
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Ticket details</CardTitle>
                                <CardDescription>Key context for staff triage.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm">
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Requester</p>
                                    <p class="font-medium text-foreground">
                                        {{ props.ticket.user?.nickname ?? props.ticket.user?.email ?? 'Unknown user' }}
                                    </p>
                                    <p class="text-muted-foreground">{{ props.ticket.user?.email ?? '—' }}</p>
                                </div>
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Assigned agent</p>
                                    <p class="font-medium text-foreground">
                                        {{ props.ticket.assignee?.nickname ?? 'Unassigned' }}
                                    </p>
                                    <p class="text-muted-foreground">{{ props.ticket.assignee?.email ?? '—' }}</p>
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
                                <div>
                                    <p class="text-xs uppercase text-muted-foreground">Resolved</p>
                                    <p class="font-medium text-foreground">
                                        {{ props.ticket.resolved_at ? formattedResolvedAt : 'Not resolved' }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ props.ticket.resolver?.nickname ?? (props.ticket.resolved_at ? 'Unknown' : '—') }}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Original description</CardTitle>
                                <CardDescription>The initial details provided by the requester.</CardDescription>
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
        </AdminLayout>
    </AppLayout>
</template>
