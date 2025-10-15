<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';

import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { type BreadcrumbItem } from '@/types';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import PlaceholderPattern from '@/components/PlaceholderPattern.vue';
import { Layers, Pencil, PlusCircle, Trash2 } from 'lucide-vue-next';
import { useUserTimezone } from '@/composables/useUserTimezone';

interface PlanSummary {
    id: number;
    name: string;
    slug: string | null;
    stripe_price_id: string;
    interval: string;
    price: number;
    currency: string;
    description: string | null;
    features: string[];
    is_active: boolean;
    invoices_count: number;
    created_at: string | null;
    updated_at: string | null;
}

interface Props {
    plans: PlanSummary[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Billing invoices', href: route('acp.billing.invoices.index') },
    { title: 'Subscription plans', href: route('acp.billing.plans.index') },
];

const hasPlans = computed(() => props.plans.length > 0);
const { formatDate } = useUserTimezone();

const deleteDialogOpen = ref(false);
const pendingPlan = ref<PlanSummary | null>(null);
const deletingPlanId = ref<number | null>(null);

const deleteDialogTitle = computed(() => {
    const target = pendingPlan.value;

    if (!target) {
        return 'Delete subscription plan?';
    }

    return `Delete “${target.name}”?`;
});

watch(deleteDialogOpen, open => {
    if (!open) {
        pendingPlan.value = null;
    }
});

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};

const deletePlan = (plan: PlanSummary) => {
    pendingPlan.value = plan;
    deleteDialogOpen.value = true;
};

const cancelDeletePlan = () => {
    deleteDialogOpen.value = false;
};

const confirmDeletePlan = () => {
    const target = pendingPlan.value;

    if (!target) {
        deleteDialogOpen.value = false;
        return;
    }

    deletingPlanId.value = target.id;
    deleteDialogOpen.value = false;

    router.delete(route('acp.billing.plans.destroy', { plan: target.id }), {
        preserveScroll: true,
        onFinish: () => {
            deletingPlanId.value = null;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Subscription plans" />

        <AdminLayout>
            <Card class="flex-1">
                <CardHeader class="relative overflow-hidden">
                    <PlaceholderPattern class="absolute inset-0 opacity-10" />
                    <div class="relative flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle class="flex items-center gap-2">
                                <Layers class="h-5 w-5" />
                                Subscription plans
                            </CardTitle>
                            <CardDescription>
                                Configure the plans your community can subscribe to without touching config files.
                            </CardDescription>
                        </div>
                        <Button variant="secondary" as-child>
                            <Link :href="route('acp.billing.plans.create')">
                                <PlusCircle class="h-4 w-4" />
                                Create plan
                            </Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div
                        v-if="!hasPlans"
                        class="rounded-lg border border-dashed border-muted-foreground/40 p-6 text-center text-sm text-muted-foreground"
                    >
                        No subscription plans defined yet. Use the button above to add your first plan.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-1/3">Plan</TableHead>
                                    <TableHead class="w-24">Price</TableHead>
                                    <TableHead class="w-24">Interval</TableHead>
                                    <TableHead class="w-1/5">Stripe price ID</TableHead>
                                    <TableHead class="w-24 text-center">Invoices</TableHead>
                                    <TableHead class="w-32 text-center">Updated</TableHead>
                                    <TableHead class="w-32 text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="plan in props.plans" :key="plan.id" class="align-top">
                                    <TableCell>
                                        <div class="flex flex-col gap-1 text-sm">
                                            <div class="font-semibold leading-tight">{{ plan.name }}</div>
                                            <div class="text-xs text-muted-foreground">
                                                <span class="font-mono">{{ plan.slug ?? 'auto' }}</span>
                                            </div>
                                            <div v-if="plan.description" class="text-xs text-muted-foreground">
                                                {{ plan.description }}
                                            </div>
                                            <ul v-if="plan.features.length" class="ml-4 list-disc space-y-1 text-xs text-muted-foreground">
                                                <li v-for="(feature, index) in plan.features" :key="`${plan.id}-feature-${index}`">{{ feature }}</li>
                                            </ul>
                                            <span
                                                :class="[
                                                    'mt-2 inline-flex w-fit items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                    plan.is_active
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-200'
                                                        : 'bg-rose-100 text-rose-700 dark:bg-rose-500/10 dark:text-rose-200',
                                                ]"
                                            >
                                                {{ plan.is_active ? 'Active' : 'Inactive' }}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell class="whitespace-nowrap">
                                        {{ formatCurrency(plan.price, plan.currency) }}
                                    </TableCell>
                                    <TableCell class="capitalize">
                                        {{ plan.interval }}
                                    </TableCell>
                                    <TableCell>
                                        <span class="font-mono text-xs">{{ plan.stripe_price_id }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        <span class="font-semibold">{{ plan.invoices_count }}</span>
                                    </TableCell>
                                    <TableCell class="text-center">
                                        {{ plan.updated_at ? formatDate(plan.updated_at, 'MMM D, YYYY h:mm A') : '—' }}
                                    </TableCell>
                                    <TableCell class="flex justify-end gap-2">
                                        <Button variant="outline" size="sm" as-child>
                                            <Link :href="route('acp.billing.plans.edit', { plan: plan.id })">
                                                <Pencil class="h-4 w-4" />
                                                Edit
                                            </Link>
                                        </Button>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            :disabled="deletingPlanId === plan.id"
                                            @click="deletePlan(plan)"
                                        >
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
                :confirm-disabled="deletingPlanId !== null"
                @confirm="confirmDeletePlan"
                @cancel="cancelDeletePlan"
            />
        </AdminLayout>
    </AppLayout>
</template>
