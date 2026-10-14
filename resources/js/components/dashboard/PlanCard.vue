<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatMoney } from '@/lib/money';
import type { DashboardPlan } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { CreditCard } from '@lucide/vue';
import { computed } from 'vue';

const props = defineProps<{ plan: DashboardPlan }>();

const day = (value: string | null) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null);

const price = computed(() => {
    if (props.plan.price === null || props.plan.currency === null) {
        return null;
    }

    return `${formatMoney(props.plan.price, props.plan.currency)} / ${props.plan.interval ?? 'month'}`;
});

const badge = computed(() => {
    switch (props.plan.state) {
        case 'active':
            return { label: 'Active', variant: 'default' as const };
        case 'grace':
            return { label: 'Ending', variant: 'highlight' as const };
        case 'attention':
            return { label: 'Payment needed', variant: 'destructive' as const };
        default:
            return { label: 'Not subscribed', variant: 'outline' as const };
    }
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2"><CreditCard class="size-5 text-muted-foreground" aria-hidden="true" /> Your plan</CardTitle>
            <CardDescription>Your subscription and billing.</CardDescription>
        </CardHeader>
        <CardContent class="space-y-3 text-sm">
            <template v-if="plan.state === 'none' || plan.state === 'ended'">
                <p class="text-muted-foreground">
                    <template v-if="plan.state === 'ended'">Your plan has ended{{ day(plan.ends_at) ? ` on ${day(plan.ends_at)}` : '' }}. </template>
                    You are not on a plan right now.
                </p>
                <Button size="sm" as-child>
                    <Link :href="route('pricing')">See plans</Link>
                </Button>
            </template>
            <template v-else>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="font-medium">{{ plan.name ?? 'Your plan' }}</p>
                    <Badge :variant="badge.variant">{{ badge.label }}</Badge>
                </div>
                <p v-if="price" class="text-muted-foreground tabular-nums">{{ price }}</p>
                <p v-if="plan.state === 'grace'" class="text-muted-foreground">
                    It stays active until {{ day(plan.ends_at) }}. You can resume it before then.
                </p>
                <p v-else-if="plan.state === 'attention'" class="text-muted-foreground">
                    The last payment did not go through. Update your payment method to keep your plan.
                </p>
                <Button size="sm" variant="outline" as-child>
                    <Link :href="route('settings.billing.index')">{{ plan.state === 'attention' ? 'Update billing' : 'Manage billing' }}</Link>
                </Button>
            </template>
        </CardContent>
    </Card>
</template>
