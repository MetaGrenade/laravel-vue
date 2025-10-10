<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    interval: string;
    currency: string;
    features: string[];
    stripe_price_id: string;
}

interface SubscriptionPayload {
    name: string;
    stripe_status: string | null;
    stripe_price: string | null;
    on_grace_period: boolean;
    cancelled: boolean;
    ends_at: string | null;
}

interface InvoicePayload {
    id: number;
    stripe_id: string;
    status: string;
    total: number;
    currency: string;
    created_at: string | null;
    paid_at: string | null;
}

interface Props {
    plans: Plan[];
    subscription: SubscriptionPayload | null;
    invoices: InvoicePayload[];
}

const props = defineProps<Props>();

const paymentMethod = ref('');
const coupon = ref('');
const subscribing = ref(false);
const canceling = ref(false);
const resuming = ref(false);
const setupLoading = ref(false);
const setupIntentSecret = ref<string | null>(null);

const initialPlanId = props.subscription
    ? props.plans.find(plan => plan.stripe_price_id === props.subscription?.stripe_price)?.id ?? null
    : props.plans[0]?.id ?? null;

const selectedPlanId = ref<number | null>(initialPlanId);

const currentPlan = computed(() => {
    if (!props.subscription) {
        return null;
    }

    return props.plans.find(plan => plan.stripe_price_id === props.subscription?.stripe_price) ?? null;
});

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};

const fetchSetupIntent = async () => {
    setupLoading.value = true;
    try {
        const response = await fetch(route('settings.billing.intent'), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to create setup intent');
        }

        const data = await response.json();
        setupIntentSecret.value = data.client_secret ?? null;
    } catch (error) {
        console.error(error);
    } finally {
        setupLoading.value = false;
    }
};

const subscribe = () => {
    if (!selectedPlanId.value) {
        return;
    }

    subscribing.value = true;
    router.post(
        route('settings.billing.subscribe'),
        {
            plan_id: selectedPlanId.value,
            payment_method: paymentMethod.value,
            coupon: coupon.value || null,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                subscribing.value = false;
            },
            onSuccess: () => {
                paymentMethod.value = '';
                coupon.value = '';
            },
        },
    );
};

const cancel = () => {
    canceling.value = true;
    router.post(
        route('settings.billing.cancel'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                canceling.value = false;
            },
        },
    );
};

const resume = () => {
    resuming.value = true;
    router.post(
        route('settings.billing.resume'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resuming.value = false;
            },
        },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Billing', href: '/settings/billing' }]">
        <Head title="Billing" />

        <SettingsLayout>
            <section class="space-y-6">
                <HeadingSmall
                    title="Subscription plans"
                    description="Choose the plan that fits your community involvement."
                />

                <div class="grid gap-4 md:grid-cols-2">
                    <Card
                        v-for="plan in props.plans"
                        :key="plan.id"
                        :class="[
                            'border transition',
                            plan.id === selectedPlanId ? 'border-primary shadow' : 'border-border'
                        ]"
                    >
                        <CardHeader>
                            <CardTitle class="flex items-center justify-between">
                                <span>{{ plan.name }}</span>
                                <span class="text-sm font-medium text-muted-foreground">
                                    {{ formatCurrency(plan.price, plan.currency) }} / {{ plan.interval }}
                                </span>
                            </CardTitle>
                            <CardDescription>
                                {{ plan.features[0] ?? 'Built for engaged members.' }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul class="space-y-2 text-sm text-muted-foreground">
                                <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-primary/70" />
                                    <span>{{ feature }}</span>
                                </li>
                            </ul>
                        </CardContent>
                        <CardFooter class="flex flex-col gap-2">
                            <Button
                                :variant="plan.id === selectedPlanId ? 'default' : 'outline'"
                                class="w-full"
                                @click="selectedPlanId = plan.id"
                            >
                                {{ plan.id === selectedPlanId ? 'Selected' : 'Select this plan' }}
                            </Button>
                            <p v-if="currentPlan && currentPlan.id === plan.id" class="text-center text-xs text-emerald-600">
                                You are on this plan
                            </p>
                        </CardFooter>
                    </Card>
                </div>
            </section>

            <Separator />

            <section class="grid gap-6 md:grid-cols-[2fr,1fr]">
                <div class="space-y-4">
                    <HeadingSmall
                        title="Payment details"
                        description="Add a Stripe payment method ID to activate your subscription."
                    />
                    <div class="grid gap-4">
                        <div class="grid gap-2">
                            <label class="text-sm font-medium" for="payment-method">Payment method ID</label>
                            <Input
                                id="payment-method"
                                v-model="paymentMethod"
                                placeholder="pm_..."
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-2">
                            <label class="text-sm font-medium" for="coupon">Coupon (optional)</label>
                            <Input
                                id="coupon"
                                v-model="coupon"
                                placeholder="PROMO2025"
                                class="w-full"
                            />
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            <Button :disabled="subscribing" @click="subscribe">
                                <span v-if="subscribing">Activating...</span>
                                <span v-else>Activate subscription</span>
                            </Button>
                            <Button variant="outline" :disabled="setupLoading" @click="fetchSetupIntent">
                                <span v-if="setupLoading">Generating...</span>
                                <span v-else>Generate setup intent</span>
                            </Button>
                        </div>
                        <p v-if="setupIntentSecret" class="rounded bg-muted px-3 py-2 text-xs font-mono">
                            Client secret: {{ setupIntentSecret }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4">
                    <HeadingSmall
                        title="Current status"
                        description="Manage the lifecycle of your subscription."
                    />
                    <div class="rounded-lg border border-border bg-card p-4 shadow-sm">
                        <p class="text-sm">
                            Status:
                            <span
                                class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="[
                                    props.subscription?.stripe_status === 'active'
                                        ? 'bg-emerald-100 text-emerald-700'
                                        : props.subscription?.cancelled
                                            ? 'bg-amber-100 text-amber-700'
                                            : 'bg-slate-100 text-slate-600'
                                ]"
                            >
                                {{ props.subscription?.stripe_status ?? 'inactive' }}
                            </span>
                        </p>
                        <p v-if="props.subscription?.ends_at" class="mt-2 text-sm text-muted-foreground">
                            Scheduled to end on {{ new Date(props.subscription.ends_at).toLocaleString() }}
                        </p>
                        <div class="mt-4 flex flex-col gap-2">
                            <Button
                                v-if="props.subscription && !props.subscription.cancelled"
                                variant="outline"
                                :disabled="canceling"
                                @click="cancel"
                            >
                                <span v-if="canceling">Canceling...</span>
                                <span v-else>Cancel at period end</span>
                            </Button>
                            <Button
                                v-else-if="props.subscription && props.subscription.cancelled"
                                variant="outline"
                                :disabled="resuming"
                                @click="resume"
                            >
                                <span v-if="resuming">Resuming...</span>
                                <span v-else>Resume subscription</span>
                            </Button>
                            <p v-else class="text-xs text-muted-foreground">
                                Activate a plan to unlock community billing perks.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <Separator />

            <section class="space-y-4">
                <HeadingSmall
                    title="Recent invoices"
                    description="A history of billing events handled via Stripe webhooks."
                />
                <div class="overflow-x-auto rounded-lg border border-border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Invoice</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Paid</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="props.invoices.length === 0">
                                <TableCell colspan="5" class="text-center text-sm text-muted-foreground">
                                    No invoices have been recorded yet.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="invoice in props.invoices" :key="invoice.id">
                                <TableCell class="font-mono text-xs">{{ invoice.stripe_id }}</TableCell>
                                <TableCell class="capitalize">{{ invoice.status }}</TableCell>
                                <TableCell>{{ formatCurrency(invoice.total, invoice.currency) }}</TableCell>
                                <TableCell>{{ invoice.created_at ? new Date(invoice.created_at).toLocaleDateString() : '—' }}</TableCell>
                                <TableCell>{{ invoice.paid_at ? new Date(invoice.paid_at).toLocaleDateString() : '—' }}</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </SettingsLayout>
    </AppLayout>
</template>
