<script setup lang="ts">
import { describeGiven } from '@/lib/entitlements';
import { stripeAppearance, useStripeAppearanceSync } from '@/lib/stripeAppearance';
import type { PlanGiven } from '@/types/entitlements';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import PromotionCodeField from '@/components/PromotionCodeField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Separator } from '@/components/ui/separator';
import { ExternalLink } from '@lucide/vue';
type StripeInstance = any;
type StripeElementsInstance = any;
type StripePaymentElementInstance = any;

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    interval: string;
    currency: string;
    features: string[];
    entitlements: PlanGiven[];
    stripe_price_id: string;
    /** Free days before the first payment, for a customer who is eligible. */
    trial_days: number | null;
}

interface SubscriptionPayload {
    name: string;
    stripe_status: string | null;
    stripe_price: string | null;
    on_grace_period: boolean;
    cancelled: boolean;
    ends_at: string | null;
    on_trial: boolean;
    trial_ends_at: string | null;
    /** Behind on payment: the page says so and offers to pay now. */
    needs_payment: boolean;
    /** Whether being behind takes the plan's features away. */
    access_paused: boolean;
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
    /** Whether the trials the plans offer are for this customer: they get one, and only if they have never had a subscription. */
    trial_eligible: boolean;
    /** Whether to offer the way into Stripe's billing portal: it is switched on, and this customer has an account at Stripe. */
    portal: boolean;
    subscription: SubscriptionPayload | null;
    invoices: InvoicePayload[];
}

const props = defineProps<Props>();

const promotionCode = ref('');
const subscribing = ref(false);
const paying = ref(false);
const openingPortal = ref(false);
const canceling = ref(false);
const resuming = ref(false);
const setupLoading = ref(false);
const setupIntentSecret = ref<string | null>(null);
const paymentError = ref<string | null>(null);
const successMessage = ref<string | null>(null);
const confirmingPayment = ref(false);

const stripe = shallowRef<StripeInstance | null>(null);
const elements = shallowRef<StripeElementsInstance | null>(null);

useStripeAppearanceSync(elements);
const paymentElement = shallowRef<StripePaymentElementInstance | null>(null);
const paymentElementReady = ref(false);
const lastStripeKey = ref<string | null>(null);

const page = usePage<{ billing?: { stripeKey?: string | null } }>();
const stripeKey = computed(() => page.props.billing?.stripeKey ?? null);
const isStripeConfigured = computed(() => Boolean(stripeKey.value));

let stripeScriptPromise: Promise<void> | null = null;

const ensureStripeJsLoaded = async () => {
    if (typeof window === 'undefined') {
        return;
    }

    if ((window as any).Stripe) {
        return;
    }

    if (!stripeScriptPromise) {
        stripeScriptPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://js.stripe.com/v3';
            script.async = true;
            script.onload = () => resolve();
            script.onerror = () => reject(new Error('Unable to load Stripe.js'));
            document.head.appendChild(script);
        });
    }

    await stripeScriptPromise;
};

const resolveStripe = async (): Promise<StripeInstance | null> => {
    if (!stripeKey.value) {
        return null;
    }

    await ensureStripeJsLoaded();

    const factory = (window as any).Stripe as ((key: string) => StripeInstance) | undefined;

    if (!factory) {
        return null;
    }

    if (!stripe.value || lastStripeKey.value !== stripeKey.value) {
        stripe.value = factory(stripeKey.value);
        lastStripeKey.value = stripeKey.value;
    }

    return stripe.value;
};

const initialPlanId = props.subscription
    ? (props.plans.find((plan) => plan.stripe_price_id === props.subscription?.stripe_price)?.id ?? null)
    : (props.plans[0]?.id ?? null);

const selectedPlanId = ref<number | null>(initialPlanId);
/** The days of free trial the chosen plan gives this customer (none if they are not eligible). */
const trialDays = computed(() => (props.trial_eligible ? (props.plans.find((plan) => plan.id === selectedPlanId.value)?.trial_days ?? null) : null));
/**
 * What starting a subscription changes here: the subscription, its invoices, whether the free trial is still on offer (it is not
 * once there is one), and whether there is a billing account to open the portal for (there is, now).
 */
const subscriptionProps = ['subscription', 'invoices', 'trial_eligible', 'portal'];

const currentPlan = computed(() => {
    if (!props.subscription) {
        return null;
    }

    return props.plans.find((plan) => plan.stripe_price_id === props.subscription?.stripe_price) ?? null;
});

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};

const teardownElements = () => {
    paymentElementReady.value = false;

    if (paymentElement.value) {
        paymentElement.value.destroy();
        paymentElement.value = null;
    }

    if (elements.value) {
        elements.value = null;
    }
};

const mountPaymentElement = async (secret: string) => {
    if (!stripeKey.value) {
        return;
    }

    let stripeInstance: StripeInstance | null = null;

    try {
        stripeInstance = await resolveStripe();
    } catch (error: unknown) {
        paymentError.value = error instanceof Error ? error.message : 'Stripe.js failed to load.';
        return;
    }

    if (!stripeInstance) {
        paymentError.value = 'Stripe.js could not be initialised. Check your publishable key.';
        return;
    }

    teardownElements();

    elements.value = stripeInstance.elements({
        clientSecret: secret,
        appearance: stripeAppearance(),
    });

    paymentElement.value = elements.value.create('payment');
    paymentElement.value.mount('#payment-element');
    paymentElement.value.on('ready', () => {
        paymentElementReady.value = true;
    });
};

const fetchSetupIntent = async () => {
    if (!stripeKey.value) {
        paymentError.value = 'Stripe publishable key is not configured.';
        return;
    }

    setupLoading.value = true;
    paymentError.value = null;

    try {
        const response = await fetch(route('settings.billing.intent'), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
        });

        if (!response.ok) {
            throw new Error('Unable to create setup intent');
        }

        const data = await response.json();
        setupIntentSecret.value = data.client_secret ?? null;
        if (setupIntentSecret.value) {
            await mountPaymentElement(setupIntentSecret.value);
        }
    } catch (error: unknown) {
        console.error(error);
        paymentError.value = error instanceof Error ? error.message : 'Failed to prepare the payment form.';
    } finally {
        setupLoading.value = false;
    }
};

const subscribe = async () => {
    if (!selectedPlanId.value || !stripe.value || !elements.value) {
        paymentError.value = 'Select a plan and ensure the payment form is ready.';
        return;
    }

    subscribing.value = true;
    paymentError.value = null;
    successMessage.value = null;

    try {
        const { error: submitError } = await elements.value.submit();

        if (submitError) {
            paymentError.value = submitError.message ?? 'Your payment details need attention.';
            return;
        }

        const confirmation = await stripe.value.confirmSetup({
            elements: elements.value,
            redirect: 'if_required',
        });

        if (confirmation.error) {
            paymentError.value = confirmation.error.message ?? 'Stripe rejected the payment method.';
            return;
        }

        const paymentMethodId = confirmation.setupIntent?.payment_method;

        if (!paymentMethodId) {
            paymentError.value = 'Stripe did not return a payment method identifier.';
            return;
        }

        const response = await fetch(route('settings.billing.subscribe'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({
                plan_id: selectedPlanId.value,
                payment_method: paymentMethodId,
                promotion_code: promotionCode.value.trim() || null,
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (response.status === 409 && payload?.status === 'requires_action' && payload?.client_secret) {
            confirmingPayment.value = true;
            const result = await stripe.value.confirmCardPayment(payload.client_secret, { payment_method: paymentMethodId });
            confirmingPayment.value = false;

            if (result.error) {
                paymentError.value = result.error.message ?? 'Additional confirmation failed.';
                return;
            }

            successMessage.value = 'Payment confirmed! Refreshing your subscription details.';
            await router.reload({
                only: subscriptionProps,
            });
            await fetchSetupIntent();
            promotionCode.value = '';
            return;
        }

        if (!response.ok) {
            const firstError = payload?.errors ? Object.values(payload.errors as Record<string, string[]>)[0]?.[0] : null;
            paymentError.value = payload?.message ?? firstError ?? 'Unable to activate the subscription.';
            return;
        }

        successMessage.value = 'Subscription activated successfully.';
        promotionCode.value = '';

        await router.reload({
            only: subscriptionProps,
        });

        await fetchSetupIntent();
    } finally {
        subscribing.value = false;
    }
};

/** Ask Stripe to take the overdue payment now, with the card on file. */
const payNow = () => {
    paying.value = true;
    paymentError.value = null;
    router.post(
        route('settings.billing.retry-payment'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                paying.value = false;
            },
        },
    );
};

/** Go to Stripe's billing portal; it comes back to this page. The server answers with where to go, or says why it could not. */
const openPortal = () => {
    openingPortal.value = true;
    router.post(
        route('settings.billing.portal'),
        {},
        {
            onFinish: () => {
                openingPortal.value = false;
            },
        },
    );
};

const cancel = () => {
    canceling.value = true;
    paymentError.value = null;
    router.post(
        route('settings.billing.cancel'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                canceling.value = false;
            },
            onSuccess: async () => {
                successMessage.value = 'Subscription will cancel at the end of the current period.';
                await fetchSetupIntent();
            },
        },
    );
};

const resume = () => {
    resuming.value = true;
    paymentError.value = null;
    router.post(
        route('settings.billing.resume'),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                resuming.value = false;
            },
            onSuccess: async () => {
                successMessage.value = 'Subscription resumed successfully.';
                await fetchSetupIntent();
            },
        },
    );
};

watch(stripeKey, async (newKey) => {
    if (!newKey) {
        teardownElements();
        setupIntentSecret.value = null;
        return;
    }

    if (!setupIntentSecret.value) {
        await fetchSetupIntent();
    }
});

onMounted(async () => {
    if (isStripeConfigured.value) {
        await fetchSetupIntent();
    }
});

onBeforeUnmount(() => {
    teardownElements();
});
</script>

<template>
    <AppLayout :breadcrumbs="[{ title: 'Billing', href: '/settings/billing' }]">
        <Head title="Billing" />

        <SettingsLayout>
            <div
                v-if="props.subscription?.needs_payment"
                class="mb-6 rounded-lg border border-destructive/40 bg-destructive/10 p-4 text-sm"
                role="alert"
            >
                <p class="font-medium text-destructive">We couldn't take your last payment</p>
                <p class="mt-1 text-muted-foreground">
                    <template v-if="props.subscription.access_paused">What your plan gives you is paused until it goes through.</template>
                    <template v-else>Your plan stays active while we keep trying.</template>
                    If your card needs changing, update it first, then pay now.
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('settings.billing.payment-methods.index')">Update payment method</Link>
                    </Button>
                    <Button :disabled="paying" @click="payNow">
                        <span v-if="paying">Paying...</span>
                        <span v-else>Pay now</span>
                    </Button>
                </div>
            </div>

            <section class="space-y-6">
                <HeadingSmall title="Subscription plans" description="Choose the plan that fits your community involvement." />

                <div class="grid gap-4 md:grid-cols-2">
                    <Card
                        v-for="plan in props.plans"
                        :key="plan.id"
                        :class="['border transition', plan.id === selectedPlanId ? 'border-primary shadow-sm' : 'border-border']"
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
                            <p v-if="props.trial_eligible && plan.trial_days" class="text-sm font-medium text-primary">
                                {{ plan.trial_days }}-day free trial
                            </p>
                        </CardHeader>
                        <CardContent>
                            <ul class="space-y-2 text-sm text-muted-foreground">
                                <li v-for="given in plan.entitlements" :key="given.key" class="flex items-start gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-primary/70" />
                                    <span class="font-medium text-foreground">{{ describeGiven(given) }}</span>
                                </li>
                                <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 rounded-full bg-primary/70" />
                                    <span>{{ feature }}</span>
                                </li>
                            </ul>
                        </CardContent>
                        <CardFooter class="flex flex-col gap-2">
                            <Button :variant="plan.id === selectedPlanId ? 'default' : 'outline'" class="w-full" @click="selectedPlanId = plan.id">
                                {{ plan.id === selectedPlanId ? 'Selected' : 'Select this plan' }}
                            </Button>
                            <p v-if="currentPlan && currentPlan.id === plan.id" class="text-center text-xs text-success">You are on this plan</p>
                        </CardFooter>
                    </Card>
                </div>
            </section>

            <Separator />

            <section class="grid gap-6 md:grid-cols-[2fr_1fr]">
                <div class="space-y-4">
                    <HeadingSmall title="Payment details" description="Securely add or update your payment method through Stripe." />
                    <div class="grid gap-4">
                        <div
                            v-if="!isStripeConfigured"
                            class="rounded-lg border border-dashed border-border bg-muted/30 p-4 text-sm text-muted-foreground"
                        >
                            Add your Stripe publishable key to the environment to enable the payment form.
                        </div>
                        <template v-else>
                            <div class="grid gap-2">
                                <label class="text-sm font-medium" for="payment-element">Payment method</label>
                                <div id="payment-element" class="rounded-lg border border-border bg-card p-4 shadow-xs" />
                                <p v-if="!paymentElementReady" class="text-sm text-muted-foreground">Loading the secure payment form…</p>
                            </div>
                            <PromotionCodeField
                                v-model="promotionCode"
                                :plan-id="selectedPlanId"
                                :check-url="route('settings.billing.promotion-code')"
                            />
                            <div class="flex flex-wrap items-center gap-3">
                                <Button :disabled="subscribing || !paymentElementReady || confirmingPayment" @click="subscribe">
                                    <span v-if="subscribing">Activating...</span>
                                    <span v-else-if="confirmingPayment">Confirming…</span>
                                    <span v-else>{{ trialDays ? 'Start free trial' : 'Activate subscription' }}</span>
                                </Button>
                                <Button variant="outline" :disabled="setupLoading || subscribing" @click="fetchSetupIntent">
                                    <span v-if="setupLoading">Refreshing…</span>
                                    <span v-else>Refresh payment form</span>
                                </Button>
                            </div>
                            <p v-if="paymentError" class="rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                {{ paymentError }}
                            </p>
                            <p v-if="successMessage" class="rounded border border-success/30 bg-success/10 px-3 py-2 text-sm text-success">
                                {{ successMessage }}
                            </p>
                        </template>
                    </div>
                </div>

                <div class="space-y-4">
                    <HeadingSmall title="Current status" description="Manage the lifecycle of your subscription." />
                    <div class="rounded-lg border border-border bg-card p-4 shadow-xs">
                        <p class="text-sm">
                            Status:
                            <span
                                class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="[
                                    props.subscription?.stripe_status === 'active'
                                        ? 'bg-success/10 text-success'
                                        : props.subscription?.cancelled
                                          ? 'bg-warning/15 text-warning'
                                          : 'bg-muted text-muted-foreground',
                                ]"
                            >
                                {{ props.subscription?.stripe_status ?? 'inactive' }}
                            </span>
                        </p>
                        <p v-if="props.subscription?.on_trial && props.subscription.trial_ends_at" class="mt-2 text-sm text-muted-foreground">
                            Free trial until {{ new Date(props.subscription.trial_ends_at).toLocaleDateString() }}
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
                                <span v-else>{{ props.subscription?.on_trial ? 'Cancel trial' : 'Cancel at period end' }}</span>
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
                            <p v-else class="text-xs text-muted-foreground">Activate a plan to unlock community billing perks.</p>
                        </div>
                        <div v-if="props.portal" class="mt-4 space-y-2 border-t border-border pt-4">
                            <p class="text-xs text-muted-foreground">
                                Update your card, see your invoices or manage your subscription on Stripe's secure billing page.
                            </p>
                            <Button variant="outline" class="w-full" :disabled="openingPortal" @click="openPortal">
                                <ExternalLink class="size-4" aria-hidden="true" />
                                <span v-if="openingPortal">Opening…</span>
                                <span v-else>Manage billing in Stripe</span>
                            </Button>
                        </div>
                    </div>
                </div>
            </section>

            <Separator />

            <section class="space-y-4">
                <HeadingSmall title="Recent invoices" description="A history of billing events handled via Stripe webhooks." />
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
