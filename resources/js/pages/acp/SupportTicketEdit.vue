<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import InputError from '@/components/InputError.vue';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { useUserTimezone } from '@/composables/useUserTimezone';
import SupportTicketUserSelect from '@/components/SupportTicketUserSelect.vue';

type TicketUser = { id: number; nickname: string; email: string };

const props = defineProps<{
    ticket: {
        id: number;
        subject: string;
        body: string;
        status: 'open' | 'pending' | 'closed';
        priority: 'low' | 'medium' | 'high';
        assigned_to: number | null;
        assignee: { id: number; nickname: string; email: string } | null;
        resolver: { id: number; nickname: string; email: string } | null;
        user: TicketUser;
        created_at: string;
        updated_at: string;
        resolved_at: string | null;
        resolved_by: number | null;
        customer_satisfaction_rating: number | null;
    };
    agents: Array<{ id: number; nickname: string; email: string }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: `Ticket #${props.ticket.id}`, href: route('acp.support.tickets.edit', { ticket: props.ticket.id }) },
];

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

const form = useForm({
    subject: props.ticket.subject,
    body: props.ticket.body,
    status: props.ticket.status,
    priority: props.ticket.priority,
    assigned_to: props.ticket.assignee?.id ?? null,
    user_id: props.ticket.user.id ?? null,
});

const { fromNow, formatDate } = useUserTimezone();

const lastUpdated = computed(() => formatDate(props.ticket.updated_at));
const createdAt = computed(() => formatDate(props.ticket.created_at));
const resolvedAt = computed(() =>
    props.ticket.resolved_at ? formatDate(props.ticket.resolved_at) : null,
);

const page = usePage<SharedData>();
const currentUser = computed(() => page.props.auth.user);
const selectedRequester = ref<TicketUser | null>(props.ticket.user);

const handleSubmit = () => {
    form.put(route('acp.support.tickets.update', { ticket: props.ticket.id }), {
        preserveScroll: true,
    });
};

const handleRequesterChange = (user: TicketUser | null) => {
    selectedRequester.value = user;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Edit ticket #${props.ticket.id}`" />

        <AdminLayout>
            <form class="flex flex-1 flex-col gap-6" @submit.prevent="handleSubmit">
                <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-semibold tracking-tight">Update ticket #{{ props.ticket.id }}</h1>
                        <p class="text-sm text-muted-foreground">
                            Fine tune the ticket details, update its status or hand it to a different agent.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="route('acp.support.index')">Back to Support</Link>
                        </Button>
                        <Button type="submit" :disabled="form.processing">Save changes</Button>
                    </div>
                </div>

                <div class="grid gap-6 lg:grid-cols-[minmax(0,_1fr)_320px]">
                    <Card>
                        <CardHeader class="relative overflow-hidden">
                            <PlaceholderPattern class="absolute inset-0 opacity-10" />
                            <div class="relative space-y-1">
                                <CardTitle>Ticket content</CardTitle>
                                <CardDescription>
                                    Keep the summary concise and the body detailed so the resolution history stays clear.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <div class="grid gap-2">
                                <Label for="requester">Requester</Label>
                                <SupportTicketUserSelect
                                    input-id="requester"
                                    v-model="form.user_id"
                                    :initial-user="props.ticket.user"
                                    @change="handleRequesterChange"
                                />
                                <InputError :message="form.errors.user_id" />
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="currentUser">
                                        Leave blank to reassign the ticket to yourself ({{ currentUser.nickname }}).
                                    </template>
                                    <template v-else>
                                        Leave blank to reassign the ticket to yourself.
                                    </template>
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="subject">Subject</Label>
                                <Input
                                    id="subject"
                                    v-model="form.subject"
                                    type="text"
                                    autocomplete="off"
                                    required
                                />
                                <InputError :message="form.errors.subject" />
                            </div>

                            <div class="grid gap-2">
                                <Label for="body">Description</Label>
                                <Textarea
                                    id="body"
                                    v-model="form.body"
                                    class="min-h-48"
                                    required
                                />
                                <InputError :message="form.errors.body" />
                            </div>
                        </CardContent>
                    </Card>

                    <div class="grid gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Workflow</CardTitle>
                                <CardDescription>Adjust the status, priority and ownership of this ticket.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4">
                                <div class="grid gap-2">
                                    <Label for="status">Status</Label>
                                    <select
                                        id="status"
                                        v-model="form.status"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.status" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="priority">Priority</Label>
                                    <select
                                        id="priority"
                                        v-model="form.priority"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.priority" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="assigned_to">Assigned agent</Label>
                                    <select
                                        id="assigned_to"
                                        v-model="form.assigned_to"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                    >
                                        <option :value="null">Unassigned</option>
                                        <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                                            {{ agent.nickname }} ({{ agent.email }})
                                        </option>
                                    </select>
                                    <InputError :message="form.errors.assigned_to" />
                                </div>
                            </CardContent>
                            <CardFooter class="justify-end">
                                <Button type="submit" :disabled="form.processing">Save changes</Button>
                            </CardFooter>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Ticket history</CardTitle>
                                <CardDescription>Quick reference for who opened the ticket and when it was updated.</CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-4 text-sm text-muted-foreground">
                                <div>
                                    <span class="font-medium text-foreground">Created</span>
                                    <p>{{ createdAt }} ({{ fromNow(props.ticket.created_at) }})</p>
                                </div>
                                <div>
                                    <span class="font-medium text-foreground">Last updated</span>
                                    <p>{{ lastUpdated }} ({{ fromNow(props.ticket.updated_at) }})</p>
                                </div>
                                <div v-if="props.ticket.resolved_at" class="space-y-1">
                                    <span class="font-medium text-foreground">Resolved</span>
                                    <p>
                                        {{ resolvedAt }} ({{ fromNow(props.ticket.resolved_at) }})
                                    </p>
                                    <p v-if="props.ticket.resolver" class="text-xs">
                                        by {{ props.ticket.resolver.nickname }}
                                        <span class="text-muted-foreground">({{ props.ticket.resolver.email }})</span>
                                    </p>
                                </div>
                                <div v-if="typeof props.ticket.customer_satisfaction_rating === 'number'">
                                    <span class="font-medium text-foreground">Customer satisfaction</span>
                                    <p>{{ props.ticket.customer_satisfaction_rating }} / 5</p>
                                </div>
                                <div class="space-y-1">
                                    <span class="font-medium text-foreground">Requester</span>
                                    <template v-if="selectedRequester">
                                        <p>{{ selectedRequester.nickname }}</p>
                                        <p>{{ selectedRequester.email }}</p>
                                    </template>
                                    <template v-else-if="currentUser">
                                        <p>{{ currentUser.nickname }}</p>
                                        <p>{{ currentUser.email }}</p>
                                        <p class="text-xs text-muted-foreground">Will default to you when saved.</p>
                                    </template>
                                    <template v-else>
                                        <p class="text-muted-foreground">Requester will be assigned on save.</p>
                                    </template>
                                </div>
                                <div class="space-y-1">
                                    <span class="font-medium text-foreground">Current status</span>
                                    <span class="inline-flex items-center rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-secondary-foreground">
                                        {{ form.status }}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
