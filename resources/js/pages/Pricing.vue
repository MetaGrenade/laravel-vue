<script setup lang="ts">
import { stripeAppearance, useStripeAppearanceSync } from '@/lib/stripeAppearance';
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Check, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/layouts/AppLayout.vue';

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

interface Props {
    plans: Plan[];
}

type StripeInstance = any;
type StripeElementsInstance = any;
type StripePaymentElementInstance = any;

const props = defineProps<Props>();

const page = usePage<{ billing?: { stripeKey?: string | null }; auth: { user: { email: string } | null } }>();
const stripeKey = computed(() => page.props.billing?.stripeKey ?? null);
const isStripeConfigured = computed(() => Boolean(stripeKey.value));

const email = ref(page.props.auth.user?.email ?? '');
const selectedPlanId = ref<number | null>(props.plans[0]?.id ?? null);
const setupIntentSecret = ref<string | null>(null);
const paymentError = ref<string | null>(null);
const successMessage = ref<string | null>(null);
const setupLoading = ref(false);
const subscribing = ref(false);
const confirmingPayment = ref(false);

const stripe = shallowRef<StripeInstance | null>(null);
const elements = shallowRef<StripeElementsInstance | null>(null);

useStripeAppearanceSync(elements);
const paymentElement = shallowRef<StripePaymentElementInstance | null>(null);
const paymentElementReady = ref(false);
const lastStripeKey = ref<string | null>(null);

let stripeScriptPromise: Promise<void> | null = null;

const formatCurrency = (amount: number, currency: string) => {
    const formatter = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.toUpperCase(),
    });

    return formatter.format(amount / 100);
};

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
    paymentElement.value.mount('#pricing-payment-element');
    paymentElement.value.on('ready', () => {
        paymentElementReady.value = true;
    });
};

const startCheckout = async () => {
    if (!selectedPlanId.value) {
        paymentError.value = 'Select a plan to continue.';
        return;
    }

    if (!isStripeConfigured.value) {
        paymentError.value = 'Stripe publishable key is not configured.';
        return;
    }

    if (!page.props.auth.user && !email.value) {
        paymentError.value = 'Please provide an email to create your account.';
        return;
    }

    setupLoading.value = true;
    paymentError.value = null;
    successMessage.value = null;

    try {
        const response = await fetch(route('pricing.intent'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({
                plan_id: selectedPlanId.value,
                email: email.value || undefined,
            }),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const firstError = payload?.errors ? Object.values(payload.errors as Record<string, string[]>)[0]?.[0] : null;
            paymentError.value = payload?.message ?? firstError ?? 'Unable to start the checkout session.';
            return;
        }

        setupIntentSecret.value = payload?.client_secret ?? null;

        if (setupIntentSecret.value) {
            await mountPaymentElement(setupIntentSecret.value);
        }
    } catch (error: unknown) {
        paymentError.value = error instanceof Error ? error.message : 'Unable to start checkout right now.';
    } finally {
        setupLoading.value = false;
    }
};

const subscribe = async () => {
    if (!selectedPlanId.value) {
        paymentError.value = 'Select a plan to continue.';
        return;
    }

    if (!stripe.value || !elements.value) {
        paymentError.value = 'Start checkout to load the secure payment form.';
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

        const response = await fetch(route('pricing.subscribe'), {
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

            successMessage.value = 'Payment confirmed! Redirecting you to billing...';
            await router.visit(route('settings.billing.index'));
            return;
        }

        if (!response.ok) {
            const firstError = payload?.errors ? Object.values(payload.errors as Record<string, string[]>)[0]?.[0] : null;
            paymentError.value = payload?.message ?? firstError ?? 'Unable to activate the subscription.';
            return;
        }

        successMessage.value = 'Subscription activated successfully. Opening billing...';
        await router.visit(route('settings.billing.index'));
    } finally {
        subscribing.value = false;
    }
};

onBeforeUnmount(() => {
    teardownElements();
});
</script>

<template>
    <AppLayout>
        <Head title="Pricing" />

        <div class="flex flex-col gap-10 px-4 py-10">
            <section class="mx-auto w-full max-w-3xl rounded-xl border bg-card px-6 py-8 text-center shadow-xs">
                <p class="eyebrow">Pricing</p>
                <h1 class="mt-3 text-3xl font-semibold tracking-tight sm:text-4xl">Simple plans that scale with you</h1>
                <p class="mt-4 text-muted-foreground">
                    Pick a plan, add your payment details, and we'll create your account and start the subscription instantly.
                </p>
                <p class="mt-4 inline-flex items-center gap-2 text-sm text-muted-foreground">
                    <ShieldCheck class="size-4 text-success" />
                    Secure checkout powered by Stripe
                </p>
            </section>

            <section class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                <div class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Card
                            v-for="plan in props.plans"
                            :key="plan.id"
                            :class="[
                                'relative gap-4 transition-colors',
                                plan.id === selectedPlanId ? 'border-primary ring-1 ring-primary' : 'hover:border-foreground/20',
                            ]"
                        >
                            <CardHeader>
                                <CardTitle class="text-base">{{ plan.name }}</CardTitle>
                                <p class="mt-2">
                                    <span class="text-3xl font-semibold tracking-tight tabular-nums">{{
                                        formatCurrency(plan.price, plan.currency)
                                    }}</span>
                                    <span class="text-sm text-muted-foreground"> / {{ plan.interval }}</span>
                                </p>
                                <CardDescription>
                                    {{ plan.features[0] ?? 'Built for engaged members.' }}
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="flex-1">
                                <ul class="space-y-2.5 text-sm">
                                    <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2">
                                        <Check class="mt-0.5 size-4 shrink-0 text-primary" />
                                        <span>{{ feature }}</span>
                                    </li>
                                    <li v-if="!plan.features.length" class="text-muted-foreground">Includes the basics you need to launch.</li>
                                </ul>
                            </CardContent>
                            <CardFooter class="flex flex-col gap-2">
                                <Button
                                    :variant="plan.id === selectedPlanId ? 'default' : 'outline'"
                                    class="w-full"
                                    @click="
                                        () => {
                                            selectedPlanId = plan.id;
                                            startCheckout();
                                        }
                                    "
                                >
                                    {{ plan.id === selectedPlanId ? 'Selected' : 'Choose plan' }}
                                </Button>
                            </CardFooter>
                        </Card>
                    </div>
                    <div v-if="!props.plans.length" class="rounded-xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground">
                        No plans are available right now.
                    </div>
                </div>

                <div>
                    <Card class="lg:sticky lg:top-20">
                        <CardHeader>
                            <CardTitle class="text-lg">Checkout</CardTitle>
                            <CardDescription>Enter your email and payment details to activate your subscription.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="space-y-2">
                                <Label for="pricing-email">Email</Label>
                                <Input id="pricing-email" v-model="email" :disabled="$page.props.auth.user !== null" placeholder="you@example.com" />
                                <p class="text-xs text-muted-foreground">We'll create or connect your account with this email.</p>
                            </div>
                            <Separator />
                            <div class="grid gap-3">
                                <div v-if="!isStripeConfigured" class="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                                    Add your Stripe publishable key to enable the payment form.
                                </div>
                                <div v-else id="pricing-payment-element" class="min-h-10" />
                                <p v-if="isStripeConfigured && !paymentElementReady" class="text-sm text-muted-foreground">
                                    Load the payment form to continue.
                                </p>
                            </div>
                            <div class="flex flex-col gap-2">
                                <Button :disabled="setupLoading" variant="outline" @click="startCheckout">
                                    <span v-if="setupLoading">Preparing checkout…</span>
                                    <span v-else>Load payment form</span>
                                </Button>
                                <Button :disabled="subscribing || !paymentElementReady || confirmingPayment" @click="subscribe">
                                    <span v-if="subscribing">Activating…</span>
                                    <span v-else-if="confirmingPayment">Confirming…</span>
                                    <span v-else>Start subscription</span>
                                </Button>
                            </div>
                            <p
                                v-if="paymentError"
                                class="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive"
                            >
                                {{ paymentError }}
                            </p>
                            <p v-if="successMessage" class="rounded-md border border-success/40 bg-success/10 px-3 py-2 text-sm text-success">
                                {{ successMessage }}
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
