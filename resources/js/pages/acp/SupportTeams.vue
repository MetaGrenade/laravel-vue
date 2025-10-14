<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import Input from '@/components/ui/input/Input.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { Pencil, PlusCircle, Trash2, Users } from 'lucide-vue-next';

interface SupportTeamMember {
    id: number;
    nickname: string;
    email: string;
}

interface SupportTeamItem {
    id: number;
    name: string;
    templates_count: number;
    members_count: number;
    member_ids: number[];
    members: SupportTeamMember[];
    created_at: string | null;
    updated_at: string | null;
}

interface TeamMembershipItem {
    id: number;
    nickname: string;
    email: string;
    team_ids: number[];
    teams: Array<{ id: number; name: string }>;
}

const props = defineProps<{
    teams: SupportTeamItem[];
    agents: SupportTeamMember[];
    memberships: TeamMembershipItem[];
    can: {
        create: boolean;
        edit: boolean;
        delete: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Support teams', href: route('acp.support.teams.index') },
];

const { formatDate } = useUserTimezone();

const hasTeams = computed(() => props.teams.length > 0);
const hasTeamActions = computed(() => props.can.edit || props.can.delete);
const hasMemberships = computed(() => props.memberships.length > 0);

const createForm = useForm({
    name: '',
    member_ids: [] as number[],
});

const submitCreate = () => {
    if (!props.can.create) {
        return;
    }

    createForm.post(route('acp.support.teams.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createForm.clearErrors();
        },
    });
};

const editingTeam = ref<SupportTeamItem | null>(null);
const editDialogOpen = ref(false);

const editForm = useForm({
    name: '',
    member_ids: [] as number[],
});

const openEditDialog = (team: SupportTeamItem) => {
    if (!props.can.edit) {
        return;
    }

    editingTeam.value = team;
    editForm.reset();
    editForm.clearErrors();
    editForm.name = team.name;
    editForm.member_ids = [...team.member_ids];
    editDialogOpen.value = true;
};

const closeEditDialog = () => {
    editDialogOpen.value = false;
};

watch(editDialogOpen, (open) => {
    if (!open) {
        editingTeam.value = null;
        editForm.reset();
        editForm.clearErrors();
    }
});

const submitEdit = () => {
    const team = editingTeam.value;

    if (!team || !props.can.edit) {
        return;
    }

    editForm.put(route('acp.support.teams.update', { team: team.id }), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditDialog();
        },
    });
};

const deleteDialogOpen = ref(false);
const pendingTeam = ref<SupportTeamItem | null>(null);
const deletingTeamId = ref<number | null>(null);

const confirmDeleteTeam = () => {
    const team = pendingTeam.value;

    if (!team) {
        return;
    }

    deletingTeamId.value = team.id;
    deleteDialogOpen.value = false;
    router.delete(route('acp.support.teams.destroy', { team: team.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingTeamId.value = null;
            pendingTeam.value = null;
        },
    });
};

const requestDeleteTeam = (team: SupportTeamItem) => {
    if (!props.can.delete) {
        return;
    }

    pendingTeam.value = team;
    deleteDialogOpen.value = true;
};

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingTeam.value = null;
    }
});

const deleteDialogTitle = computed(() => {
    if (!pendingTeam.value) {
        return 'Delete team?';
    }

    return `Delete “${pendingTeam.value.name}”?`;
});

const cancelDeleteTeam = () => {
    deleteDialogOpen.value = false;
};

const memberSummary = (team: SupportTeamItem) => {
    if (!team.members || team.members.length === 0) {
        return 'No members yet';
    }

    return team.members.map((member) => member.nickname).join(', ');
};

const membershipDialogOpen = ref(false);
const editingMember = ref<TeamMembershipItem | null>(null);
const membershipForm = useForm({
    team_ids: [] as number[],
});

const membershipError = computed(() =>
    membershipForm.errors.team_ids ?? membershipForm.errors['team_ids.0'] ?? '',
);

const openMembershipDialog = (member: TeamMembershipItem) => {
    if (!props.can.edit) {
        return;
    }

    editingMember.value = member;
    membershipForm.reset();
    membershipForm.clearErrors();
    membershipForm.team_ids = [...member.team_ids];
    membershipDialogOpen.value = true;
};

const closeMembershipDialog = () => {
    membershipDialogOpen.value = false;
};

watch(membershipDialogOpen, (open) => {
    if (!open) {
        editingMember.value = null;
        membershipForm.reset();
        membershipForm.clearErrors();
    }
});

const submitMembership = () => {
    const member = editingMember.value;

    if (!member || !props.can.edit) {
        return;
    }

    membershipForm.put(route('acp.support.teams.memberships.update', { user: member.id }), {
        preserveScroll: true,
        onSuccess: () => {
            closeMembershipDialog();
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage support teams" />

        <AdminLayout>
            <div class="flex flex-1 flex-col gap-6">
                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle class="flex items-center gap-2">
                                    <Users class="h-5 w-5" />
                                    Support teams
                                </CardTitle>
                                <CardDescription>
                                    Organize support staff into teams so templates can target specific groups.
                                </CardDescription>
                            </div>
                            <div v-if="props.can.create" class="hidden sm:flex">
                                <Button
                                    variant="secondary"
                                    class="flex items-center gap-2 text-white"
                                    type="submit"
                                    form="support-team-create"
                                    :disabled="createForm.processing"
                                >
                                    <PlusCircle class="h-4 w-4" />
                                    Save team
                                </Button>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <form
                            v-if="props.can.create"
                            id="support-team-create"
                            class="grid gap-4 rounded-lg border border-border/60 p-4"
                            @submit.prevent="submitCreate"
                        >
                            <div class="grid gap-2">
                                <Label for="team_name">Team name</Label>
                                <Input
                                    id="team_name"
                                    v-model="createForm.name"
                                    :disabled="createForm.processing"
                                    placeholder="e.g. Incident Response"
                                    required
                                />
                                <InputError :message="createForm.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="team_members">Team members</Label>
                                <select
                                    id="team_members"
                                    v-model="createForm.member_ids"
                                    :disabled="createForm.processing"
                                    multiple
                                    class="flex min-h-32 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                                        {{ agent.nickname }} ({{ agent.email }})
                                    </option>
                                </select>
                                <p class="text-xs text-muted-foreground">
                                    Leave empty to add members later.
                                </p>
                                <InputError :message="createForm.errors.member_ids" />
                            </div>
                            <CardFooter class="justify-end px-0 pb-0">
                                <Button type="submit" :disabled="createForm.processing">Create team</Button>
                            </CardFooter>
                        </form>

                        <div v-else class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-sm text-muted-foreground">
                            You can view support teams but do not have permission to create new ones.
                        </div>

                        <div>
                            <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-muted-foreground">
                                Existing teams
                            </h3>
                            <div
                                v-if="!hasTeams"
                                class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground"
                            >
                                No teams yet. Create one to scope templates to specific agent groups.
                            </div>
                            <div v-else class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="w-1/3">Name</TableHead>
                                            <TableHead>Templates</TableHead>
                                            <TableHead>Members</TableHead>
                                            <TableHead>Updated</TableHead>
                                            <TableHead v-if="hasTeamActions" class="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="team in props.teams" :key="team.id">
                                            <TableCell>
                                                <div class="flex flex-col gap-1">
                                                    <span class="font-medium">{{ team.name }}</span>
                                                    <span class="text-xs text-muted-foreground">
                                                        Created {{ team.created_at ? formatDate(team.created_at, 'MMM D, YYYY h:mm A') : '—' }}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span class="text-sm text-muted-foreground">{{ team.templates_count }}</span>
                                            </TableCell>
                                            <TableCell>
                                                <div class="flex flex-col text-sm text-muted-foreground">
                                                    <span>{{ team.members_count }}</span>
                                                    <span class="text-xs" :title="memberSummary(team)">
                                                        {{ memberSummary(team) }}
                                                    </span>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ team.updated_at ? formatDate(team.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                                </span>
                                            </TableCell>
                                            <TableCell v-if="hasTeamActions" class="flex justify-end gap-2">
                                                <Button
                                                    v-if="props.can.edit"
                                                    variant="outline"
                                                    size="sm"
                                                    @click="openEditDialog(team)"
                                                >
                                                    <Pencil class="h-4 w-4" />
                                                    Edit
                                                </Button>
                                                <Button
                                                    v-if="props.can.delete"
                                                    variant="destructive"
                                                    size="sm"
                                                    :disabled="deletingTeamId === team.id"
                                                    @click="requestDeleteTeam(team)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                    Delete
                                                </Button>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Team membership</CardTitle>
                        <CardDescription>
                            Review which support agents belong to each team and make adjustments in one place.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="!hasMemberships" class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground">
                            No eligible support agents found. Once agents are available you can manage their team assignments here.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Agent</TableHead>
                                        <TableHead>Teams</TableHead>
                                        <TableHead v-if="props.can.edit" class="w-[1%] whitespace-nowrap text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="member in props.memberships" :key="member.id">
                                        <TableCell>
                                            <div class="flex flex-col">
                                                <span class="font-medium">{{ member.nickname }}</span>
                                                <span class="text-xs text-muted-foreground">{{ member.email }}</span>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex flex-wrap gap-2">
                                                <span v-if="member.teams.length === 0" class="text-sm text-muted-foreground">No teams</span>
                                                <span
                                                    v-for="team in member.teams"
                                                    :key="team.id"
                                                    class="inline-flex items-center rounded-full bg-muted px-2 py-1 text-xs"
                                                >
                                                    {{ team.name }}
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell v-if="props.can.edit" class="text-right">
                                            <Button variant="outline" size="sm" @click="openMembershipDialog(member)">
                                                <Pencil class="mr-2 h-4 w-4" />
                                                Manage teams
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <ConfirmDialog
                v-model:open="deleteDialogOpen"
                :title="deleteDialogTitle"
                description="This action cannot be undone."
                confirm-label="Delete"
                cancel-label="Cancel"
                :confirm-disabled="deletingTeamId !== null"
                @confirm="confirmDeleteTeam"
                @cancel="cancelDeleteTeam"
            />

            <template v-if="props.can.edit">
                <Dialog v-model:open="editDialogOpen">
                    <DialogContent class="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>Edit support team</DialogTitle>
                            <DialogDescription>Update the team name used to target canned responses.</DialogDescription>
                        </DialogHeader>
                        <form class="mt-4 grid gap-4" @submit.prevent="submitEdit">
                            <div class="grid gap-2">
                                <Label for="edit_team_name">Team name</Label>
                                <Input
                                    id="edit_team_name"
                                    v-model="editForm.name"
                                    :disabled="editForm.processing"
                                    required
                                />
                                <InputError :message="editForm.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="edit_team_members">Team members</Label>
                                <select
                                    id="edit_team_members"
                                    v-model="editForm.member_ids"
                                    :disabled="editForm.processing"
                                    multiple
                                    class="flex min-h-32 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                                        {{ agent.nickname }} ({{ agent.email }})
                                    </option>
                                </select>
                                <p class="text-xs text-muted-foreground">
                                    Leave empty to clear all members.
                                </p>
                                <InputError :message="editForm.errors.member_ids" />
                            </div>
                            <CardFooter class="justify-end gap-2 px-0 pb-0">
                                <Button type="button" variant="outline" :disabled="editForm.processing" @click="closeEditDialog">
                                    Cancel
                                </Button>
                                <Button type="submit" :disabled="editForm.processing">Update team</Button>
                            </CardFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>

            <template v-if="props.can.edit">
                <Dialog v-model:open="membershipDialogOpen">
                    <DialogContent class="sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle>
                                Manage teams for {{ editingMember?.nickname ?? 'agent' }}
                            </DialogTitle>
                            <DialogDescription>
                                Select all teams this agent should belong to. Changes apply immediately.
                            </DialogDescription>
                        </DialogHeader>
                        <form class="mt-4 grid gap-4" @submit.prevent="submitMembership">
                            <div class="grid gap-2">
                                <Label for="membership-teams">Teams</Label>
                                <select
                                    id="membership-teams"
                                    v-model="membershipForm.team_ids"
                                    multiple
                                    :disabled="membershipForm.processing"
                                    class="flex min-h-32 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    <option v-for="team in props.teams" :key="team.id" :value="team.id">
                                        {{ team.name }}
                                    </option>
                                </select>
                                <p class="text-xs text-muted-foreground">Hold Command (⌘) or Control (Ctrl) to select multiple teams.</p>
                                <InputError :message="membershipError" />
                            </div>
                            <CardFooter class="justify-end gap-2 px-0 pb-0">
                                <Button type="button" variant="outline" :disabled="membershipForm.processing" @click="closeMembershipDialog">
                                    Cancel
                                </Button>
                                <Button type="submit" :disabled="membershipForm.processing">
                                    Save membership
                                </Button>
                            </CardFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </template>
        </AdminLayout>
    </AppLayout>
</template>
