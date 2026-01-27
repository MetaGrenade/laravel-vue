<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Pencil, PlusCircle, Trash2, Vote } from 'lucide-vue-next';

type PollSummary = {
    id: number;
    title: string;
    slug: string;
    status: string;
    options_count: number;
    votes_count: number;
    allow_multiple: boolean;
    starts_at: string | null;
    ends_at: string | null;
    created_at: string | null;
    updated_at: string | null;
};

const props = defineProps<{
    polls: PollSummary[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Polls ACP', href: route('acp.polls.index') },
];

const hasPolls = computed(() => props.polls.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingPoll = ref<PollSummary | null>(null);
const deletingPollId = ref<number | null>(null);

const deleteDialogTitle = computed(() => {
    const target = pendingPoll.value;

    if (!target) {
        return 'Delete poll?';
    }

    return `Delete “${target.title}”?`;
});

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingPoll.value = null;
    }
});

const deletePoll = (poll: PollSummary) => {
    pendingPoll.value = poll;
    deleteDialogOpen.value = true;
};

const cancelDeletePoll = () => {
    deleteDialogOpen.value = false;
};

const confirmDeletePoll = () => {
    const target = pendingPoll.value;

    if (!target) {
        deleteDialogOpen.value = false;
        return;
    }

    deletingPollId.value = target.id;
    deleteDialogOpen.value = false;

    router.delete(route('acp.polls.destroy', { poll: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingPollId.value = null;
        },
    });
};

const statusBadge = (status: string) => {
    switch (status) {
        case 'published':
            return 'default';
        case 'closed':
            return 'secondary';
        default:
            return 'outline';
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage polls" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Vote class="h-5 w-5" />
                                Polls & Surveys
                            </CardTitle>
                            <CardDescription>
                                Create time-bound polls, track responses, and share live results with your community.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.polls.create')">
                                <PlusCircle class="h-4 w-4" />
                                Create poll
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="!hasPolls" class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground">
                        No polls have been created yet. Use the button above to add the first poll.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/3">Title</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead class="text-center">Options</TableHead>
                                    <TableHead class="text-center">Votes</TableHead>
                                    <TableHead class="text-center">Schedule</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="poll in props.polls" :key="poll.id">
                                    <TableCell>
                                        <div class="space-y-1">
                                            <div class="font-medium">{{ poll.title }}</div>
                                            <div class="text-xs text-muted-foreground">/{{ poll.slug }}</div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="statusBadge(poll.status)">{{ poll.status }}</Badge>
                                    </TableCell>
                                    <TableCell class="text-center font-semibold">{{ poll.options_count }}</TableCell>
                                    <TableCell class="text-center font-semibold">{{ poll.votes_count }}</TableCell>
                                    <TableCell class="text-center text-xs text-muted-foreground">
                                        <div>
                                            {{ poll.starts_at ? formatDate(poll.starts_at, 'MMM D, YYYY h:mm A') : 'Starts anytime' }}
                                        </div>
                                        <div>
                                            {{ poll.ends_at ? formatDate(poll.ends_at, 'MMM D, YYYY h:mm A') : 'No end date' }}
                                        </div>
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.polls.edit', { poll: poll.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button variant="destructive" size="sm" @click="deletePoll(poll)">
                                            <Trash2 class="h-4 w-4" />
                                            Delete
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="This action cannot be undone."
                confirm-label="Delete"
                cancel-label="Cancel"
                :confirm-disabled="deletingPollId !== null"
                @confirm="confirmDeletePoll"
                @cancel="cancelDeletePoll"
            />
        </AdminLayout>
    </AppLayout>
</template>
