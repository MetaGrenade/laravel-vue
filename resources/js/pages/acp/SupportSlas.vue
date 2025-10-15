<script setup lang="ts">
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import Input from '@/components/ui/input/Input.vue';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';

interface PriorityMeta {
    value: string;
    label: string;
    order: number;
}

interface EscalationRule {
    after: string | null;
    to: string | null;
}

interface SlaPayload {
    priority_escalations: Record<string, EscalationRule | null>;
    reassign_after: Record<string, string | null>;
}

const props = defineProps<{
    priorities: PriorityMeta[];
    sla: SlaPayload;
    can: {
        edit: boolean;
    };
}>();

type PriorityKey = (typeof props.priorities)[number]['value'];

type EscalationState = Record<PriorityKey, { after: string; to: string }>;
type ReassignState = Record<PriorityKey, string>;

const priorityValues = computed(() => props.priorities.map(priority => priority.value as PriorityKey));

const buildEscalationState = (): EscalationState => {
    const state = {} as EscalationState;

    priorityValues.value.forEach(priority => {
        const rule = props.sla.priority_escalations?.[priority] ?? null;

        state[priority] = {
            after: rule?.after ?? '',
            to: rule?.to ?? '',
        };
    });

    return state;
};

const buildReassignState = (): ReassignState => {
    const state = {} as ReassignState;

    priorityValues.value.forEach(priority => {
        state[priority] = props.sla.reassign_after?.[priority] ?? '';
    });

    return state;
};

const form = useForm<{ priority_escalations: EscalationState; reassign_after: ReassignState }>(
    {
        priority_escalations: buildEscalationState(),
        reassign_after: buildReassignState(),
    }
);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Support ACP', href: route('acp.support.index') },
    { title: 'SLA thresholds', href: route('acp.support.sla.index') },
];

const targetOptions = computed(() => {
    return Object.fromEntries(
        props.priorities.map(priority => [
            priority.value,
            props.priorities
                .filter(candidate => candidate.order > priority.order)
                .map(candidate => ({ value: candidate.value, label: candidate.label })),
        ])
    ) as Record<PriorityKey, Array<{ value: PriorityKey; label: string }>>;
});

const escalationPriorities = computed(() =>
    props.priorities.filter(priority => targetOptions.value[priority.value as PriorityKey]?.length)
);

const canSubmit = computed(() => props.can.edit && !form.processing);

const submit = () => {
    if (!props.can.edit) {
        return;
    }

    form.put(route('acp.support.sla.update'), {
        preserveScroll: true,
        onSuccess: () => {
            form.setDefaults({
                priority_escalations: buildEscalationState(),
                reassign_after: buildReassignState(),
            });
            form.reset();
            form.clearErrors();
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Manage support SLA thresholds" />

        <AdminLayout>
            <form class="flex w-full flex-col gap-6" @submit.prevent="submit">
                <Card>
                    <CardHeader>
                        <CardTitle>Priority escalations</CardTitle>
                        <CardDescription>
                            Control when ticket priorities are automatically increased. Use natural language intervals
                            like “24 hours” or “3 days”. Leave both fields blank to disable an escalation.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-6">
                        <template v-if="escalationPriorities.length">
                            <div
                                v-for="priority in escalationPriorities"
                                :key="priority.value"
                                class="grid gap-4 lg:grid-cols-2"
                            >
                                <div class="flex flex-col gap-2">
                                    <Label :for="`escalate-after-${priority.value}`">
                                        Escalate {{ priority.label.toLowerCase() }} tickets after
                                    </Label>
                                    <Input
                                        :id="`escalate-after-${priority.value}`"
                                        v-model="form.priority_escalations[priority.value as PriorityKey].after"
                                        :disabled="!canSubmit"
                                        placeholder="e.g. 24 hours"
                                    />
                                    <InputError :message="form.errors[`priority_escalations.${priority.value}.after`]" />
                                </div>
                                <div class="flex flex-col gap-2">
                                    <Label :for="`escalate-to-${priority.value}`">Escalate to</Label>
                                    <select
                                        :id="`escalate-to-${priority.value}`"
                                        v-model="form.priority_escalations[priority.value as PriorityKey].to"
                                        :disabled="!canSubmit"
                                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <option value="">No escalation</option>
                                        <option
                                            v-for="target in targetOptions[priority.value as PriorityKey]"
                                            :key="target.value"
                                            :value="target.value"
                                        >
                                            {{ target.label }}
                                        </option>
                                    </select>
                                    <InputError :message="form.errors[`priority_escalations.${priority.value}.to`]" />
                                </div>
                            </div>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            No higher priority levels are available for automatic escalations.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Reassignment windows</CardTitle>
                        <CardDescription>
                            Define how long a ticket can remain untouched before it is reassigned to another available
                            agent.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="flex flex-col gap-4">
                        <div
                            v-for="priority in props.priorities"
                            :key="priority.value"
                            class="flex flex-col gap-2 lg:flex-row lg:items-end lg:gap-4"
                        >
                            <div class="flex flex-1 flex-col gap-2">
                                <Label :for="`reassign-after-${priority.value}`">
                                    Reassign {{ priority.label.toLowerCase() }} tickets after
                                </Label>
                                <Input
                                    :id="`reassign-after-${priority.value}`"
                                    v-model="form.reassign_after[priority.value as PriorityKey]"
                                    :disabled="!canSubmit"
                                    placeholder="e.g. 36 hours"
                                />
                                <InputError :message="form.errors[`reassign_after.${priority.value}`]" />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end">
                    <Button type="submit" :disabled="!canSubmit">
                        Save changes
                    </Button>
                </div>
            </form>
        </AdminLayout>
    </AppLayout>
</template>
