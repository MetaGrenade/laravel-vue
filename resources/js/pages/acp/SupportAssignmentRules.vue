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
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useUserTimezone } from '@/composables/useUserTimezone';
import { ListChecks, MoveDown, MoveUp, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';

interface AssignmentRuleRelation {
    id: number;
    name: string;
    email?: string;
}

interface AssignmentRuleItem {
    id: number;
    support_ticket_category_id: number | null;
    priority: RulePriority | null;
    assigned_to: number;
    position: number;
    active: boolean;
    category: AssignmentRuleRelation | null;
    assignee: AssignmentRuleRelation | null;
    created_at: string | null;
    updated_at: string | null;
}

type RulePriority = 'low' | 'medium' | 'high';

const props = defineProps<{
    rules: AssignmentRuleItem[];
    categories: Array<{ id: number; name: string }>;
    agents: Array<{ id: number; nickname: string; email: string }>;
    can: {
        create: boolean;
        edit: boolean;
        delete: boolean;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'Assignment rules', href: route('acp.support.assignment-rules.index') },
];

const { formatDate } = useUserTimezone();

const hasRules = computed(() => props.rules.length > 0);
const hasRuleActions = computed(() => props.can.edit || props.can.delete);

const priorityLabels: Record<RulePriority, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
};

const priorityOptions: Array<{ value: RulePriority; label: string }> = [
    { value: 'low', label: priorityLabels.low },
    { value: 'medium', label: priorityLabels.medium },
    { value: 'high', label: priorityLabels.high },
];

const createForm = useForm({
    support_ticket_category_id: null as number | null,
    priority: null as RulePriority | null,
    assigned_to: null as number | null,
    active: true,
});

const submitCreate = () => {
    if (!props.can.create) {
        return;
    }

    createForm.post(route('acp.support.assignment-rules.store'), {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset();
            createForm.clearErrors();
        },
    });
};

const editingRule = ref<AssignmentRuleItem | null>(null);
const editDialogOpen = ref(false);

const editForm = useForm({
    support_ticket_category_id: null as number | null,
    priority: null as RulePriority | null,
    assigned_to: null as number | null,
    active: true,
});

const openEditDialog = (rule: AssignmentRuleItem) => {
    if (!props.can.edit) {
        return;
    }

    editingRule.value = rule;
    editForm.reset();
    editForm.clearErrors();
    editForm.support_ticket_category_id = rule.support_ticket_category_id;
    editForm.priority = rule.priority;
    editForm.assigned_to = rule.assigned_to;
    editForm.active = rule.active;
    editDialogOpen.value = true;
};

const closeEditDialog = () => {
    editDialogOpen.value = false;
};

watch(editDialogOpen, (open) => {
    if (!open) {
        editingRule.value = null;
        editForm.reset();
        editForm.clearErrors();
    }
});

const submitEdit = () => {
    const rule = editingRule.value;

    if (!rule || !props.can.edit) {
        return;
    }

    editForm.put(route('acp.support.assignment-rules.update', { rule: rule.id }), {
        preserveScroll: true,
        onSuccess: () => {
            closeEditDialog();
        },
    });
};

const deleteDialogOpen = ref(false);
const pendingRule = ref<AssignmentRuleItem | null>(null);
const deletingRuleId = ref<number | null>(null);

const requestDeleteRule = (rule: AssignmentRuleItem) => {
    if (!props.can.delete) {
        return;
    }

    pendingRule.value = rule;
    deleteDialogOpen.value = true;
};

watch(deleteDialogOpen, (open) => {
    if (!open) {
        pendingRule.value = null;
    }
});

const confirmDeleteRule = () => {
    const rule = pendingRule.value;

    if (!rule) {
        return;
    }

    deletingRuleId.value = rule.id;
    deleteDialogOpen.value = false;
    router.delete(route('acp.support.assignment-rules.destroy', { rule: rule.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingRuleId.value = null;
            pendingRule.value = null;
        },
    });
};

const cancelDeleteRule = () => {
    deleteDialogOpen.value = false;
};

const deleteDialogTitle = computed(() => {
    if (!pendingRule.value) {
        return 'Delete assignment rule?';
    }

    const assigneeName = pendingRule.value.assignee?.nickname ?? 'this agent';

    return `Delete rule for ${assigneeName}?`;
});

const reorderingRuleId = ref<number | null>(null);

const reorderRule = (rule: AssignmentRuleItem, direction: 'up' | 'down') => {
    if (!props.can.edit) {
        return;
    }

    reorderingRuleId.value = rule.id;
    router.patch(
        route('acp.support.assignment-rules.reorder', { rule: rule.id }),
        { direction },
        {
            preserveScroll: true,
            onFinish: () => {
                reorderingRuleId.value = null;
            },
        },
    );
};

const categoryName = (rule: AssignmentRuleItem) => rule.category?.name ?? 'All categories';

const priorityName = (rule: AssignmentRuleItem) => {
    if (!rule.priority) {
        return 'All priorities';
    }

    return priorityLabels[rule.priority];
};

const assigneeName = (rule: AssignmentRuleItem) => {
    if (!rule.assignee) {
        return 'Unknown agent';
    }

    return `${rule.assignee.nickname} (${rule.assignee.email ?? 'no email'})`;
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Support assignment rules" />

        <AdminLayout>
            <div class="flex flex-1 flex-col gap-6">
                <Card>
                    <CardHeader class="relative overflow-hidden">
                        <PlaceholderPattern class="absolute inset-0 opacity-10" />
                        <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle class="flex items-center gap-2">
                                    <ListChecks class="h-5 w-5" />
                                    Assignment rules
                                </CardTitle>
                                <CardDescription>
                                    Configure how tickets are routed automatically based on category and priority.
                                </CardDescription>
                            </div>
                            <div class="text-sm text-muted-foreground">
                                Rules run from top to bottom until a match is found.
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="grid gap-6 lg:grid-cols-2">
                            <form class="space-y-4" @submit.prevent="submitCreate">
                                <div>
                                    <Label for="create-category">Ticket category</Label>
                                    <select
                                        id="create-category"
                                        v-model="createForm.support_ticket_category_id"
                                        class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :disabled="createForm.processing"
                                    >
                                        <option :value="null">All categories</option>
                                        <option
                                            v-for="category in props.categories"
                                            :key="category.id"
                                            :value="category.id"
                                        >
                                            {{ category.name }}
                                        </option>
                                    </select>
                                    <InputError :message="createForm.errors.support_ticket_category_id" class="mt-2" />
                                </div>

                                <div>
                                    <Label for="create-priority">Ticket priority</Label>
                                    <select
                                        id="create-priority"
                                        v-model="createForm.priority"
                                        class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :disabled="createForm.processing"
                                    >
                                        <option :value="null">All priorities</option>
                                        <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                                            {{ option.label }}
                                        </option>
                                    </select>
                                    <InputError :message="createForm.errors.priority" class="mt-2" />
                                </div>

                                <div>
                                    <Label for="create-assigned-to">Assign to</Label>
                                    <select
                                        id="create-assigned-to"
                                        v-model="createForm.assigned_to"
                                        required
                                        class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                                        :disabled="createForm.processing"
                                    >
                                        <option :value="null" disabled>Select an agent</option>
                                        <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                                            {{ agent.nickname }} ({{ agent.email }})
                                        </option>
                                    </select>
                                    <InputError :message="createForm.errors.assigned_to" class="mt-2" />
                                </div>

                                <div class="flex items-center justify-between">
                                    <div>
                                        <Label>Active</Label>
                                        <p class="text-sm text-muted-foreground">Inactive rules are skipped during auto-assignment.</p>
                                    </div>
                                    <Switch v-model:checked="createForm.active" :disabled="createForm.processing" />
                                </div>

                                <div class="flex justify-end">
                                    <Button type="submit" :disabled="createForm.processing || !props.can.create">
                                        <PlusCircle class="mr-2 h-4 w-4" />
                                        Add rule
                                    </Button>
                                </div>
                            </form>

                            <div class="hidden flex-col gap-2 rounded-md border border-dashed border-muted p-6 text-sm text-muted-foreground lg:flex">
                                <p>
                                    Rules are processed in order. Place more specific filters higher than broader catch-all rules to
                                    ensure the right agent receives each ticket.
                                </p>
                                <p>
                                    Use the arrows to reorder rules as your team or categories evolve.
                                </p>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-semibold">Existing rules</h2>
                                <p class="text-sm text-muted-foreground">{{ props.rules.length }} total</p>
                            </div>

                            <div v-if="hasRules" class="overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="w-12">Order</TableHead>
                                            <TableHead>Category</TableHead>
                                            <TableHead>Priority</TableHead>
                                            <TableHead>Assignee</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last updated</TableHead>
                                            <TableHead v-if="hasRuleActions" class="w-[1%] whitespace-nowrap text-right">
                                                Actions
                                            </TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="rule in props.rules" :key="rule.id">
                                            <TableCell>
                                                <div class="flex items-center gap-1">
                                                    <Button
                                                        v-if="props.can.edit"
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-8 w-8"
                                                        :disabled="reorderingRuleId === rule.id"
                                                        @click="reorderRule(rule, 'up')"
                                                    >
                                                        <MoveUp class="h-4 w-4" />
                                                    </Button>
                                                    <Button
                                                        v-if="props.can.edit"
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        class="h-8 w-8"
                                                        :disabled="reorderingRuleId === rule.id"
                                                        @click="reorderRule(rule, 'down')"
                                                    >
                                                        <MoveDown class="h-4 w-4" />
                                                    </Button>
                                                    <span class="font-medium text-muted-foreground">#{{ rule.position }}</span>
                                                </div>
                                            </TableCell>
                                            <TableCell>{{ categoryName(rule) }}</TableCell>
                                            <TableCell>{{ priorityName(rule) }}</TableCell>
                                            <TableCell>{{ assigneeName(rule) }}</TableCell>
                                            <TableCell>
                                                <span
                                                    class="inline-flex items-center rounded-full px-2 py-1 text-xs font-medium"
                                                    :class="rule.active ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-muted text-muted-foreground'"
                                                >
                                                    {{ rule.active ? 'Active' : 'Inactive' }}
                                                </span>
                                            </TableCell>
                                            <TableCell>{{ rule.updated_at ? formatDate(rule.updated_at) : 'Never' }}</TableCell>
                                            <TableCell v-if="hasRuleActions" class="text-right">
                                                <div class="flex justify-end gap-1">
                                                    <Button
                                                        v-if="props.can.edit"
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        @click="openEditDialog(rule)"
                                                    >
                                                        <Pencil class="mr-2 h-4 w-4" />
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        v-if="props.can.delete"
                                                        type="button"
                                                        variant="destructive"
                                                        size="sm"
                                                        :disabled="deletingRuleId === rule.id"
                                                        @click="requestDeleteRule(rule)"
                                                    >
                                                        <Trash2 class="mr-2 h-4 w-4" />
                                                        Delete
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>

                            <div v-else class="rounded-lg border border-dashed border-muted p-10 text-center text-sm text-muted-foreground">
                                <p>No assignment rules yet. Create your first rule to start auto-routing tickets.</p>
                            </div>
                        </div>
                    </CardContent>
                    <CardFooter class="flex flex-col items-start gap-2 text-sm text-muted-foreground">
                        <p>
                            When no rule applies the ticket remains unassigned. Agents can still pick up tickets manually from the
                            queue.
                        </p>
                    </CardFooter>
                </Card>
            </div>
        </AdminLayout>

        <Dialog v-model:open="editDialogOpen">
            <DialogContent class="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Edit assignment rule</DialogTitle>
                    <DialogDescription>
                        Adjust the matching filters or change who receives tickets when this rule applies.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-4" @submit.prevent="submitEdit">
                    <div>
                        <Label for="edit-category">Ticket category</Label>
                        <select
                            id="edit-category"
                            v-model="editForm.support_ticket_category_id"
                            class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :disabled="editForm.processing"
                        >
                            <option :value="null">All categories</option>
                            <option v-for="category in props.categories" :key="category.id" :value="category.id">
                                {{ category.name }}
                            </option>
                        </select>
                        <InputError :message="editForm.errors.support_ticket_category_id" class="mt-2" />
                    </div>

                    <div>
                        <Label for="edit-priority">Ticket priority</Label>
                        <select
                            id="edit-priority"
                            v-model="editForm.priority"
                            class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :disabled="editForm.processing"
                        >
                            <option :value="null">All priorities</option>
                            <option v-for="option in priorityOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                        <InputError :message="editForm.errors.priority" class="mt-2" />
                    </div>

                    <div>
                        <Label for="edit-assigned-to">Assign to</Label>
                        <select
                            id="edit-assigned-to"
                            v-model="editForm.assigned_to"
                            required
                            class="mt-2 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm focus:border-primary focus:outline-none focus:ring-1 focus:ring-primary"
                            :disabled="editForm.processing"
                        >
                            <option :value="null" disabled>Select an agent</option>
                            <option v-for="agent in props.agents" :key="agent.id" :value="agent.id">
                                {{ agent.nickname }} ({{ agent.email }})
                            </option>
                        </select>
                        <InputError :message="editForm.errors.assigned_to" class="mt-2" />
                    </div>

                    <div class="flex items-center justify-between">
                        <div>
                            <Label>Active</Label>
                            <p class="text-sm text-muted-foreground">Inactive rules are skipped during auto-assignment.</p>
                        </div>
                        <Switch v-model:checked="editForm.active" :disabled="editForm.processing" />
                    </div>

                    <div class="flex justify-end gap-2">
                        <Button type="button" variant="outline" @click="closeEditDialog">Cancel</Button>
                        <Button type="submit" :disabled="editForm.processing">
                            <Pencil class="mr-2 h-4 w-4" />
                            Save changes
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="deleteDialogOpen"
            :title="deleteDialogTitle"
            confirm-text="Delete"
            confirm-variant="destructive"
            :confirm-loading="deletingRuleId !== null"
            @confirm="confirmDeleteRule"
            @cancel="cancelDeleteRule"
            @update:open="(open) => (deleteDialogOpen.value = open)"
        >
            This rule will be removed from the auto-assignment rotation. Tickets will fall through to later rules or remain
            unassigned.
        </ConfirmDialog>
    </AppLayout>
</template>
