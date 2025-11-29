<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { ShieldCheck } from 'lucide-vue-next';
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
                'Accept': 'application/json',
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
                'Accept': 'application/json',
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

        <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a]">
            <main class="flex justify-center p-6">
                <div class="flex w-full max-w-7xl flex-col gap-10">
                    <section class="space-y-4">
                        <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">Pricing</p>
                        <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
                            <div class="space-y-3">
                                <h1 class="text-3xl font-semibold leading-tight text-[#1b1b18] dark:text-[#EDEDEC]">Plans that stay simple</h1>
                                <p class="max-w-2xl text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    Pick an active plan, add your payment details, and we'll create your account and start the subscription instantly.
                                </p>
                            </div>
                            <div class="flex items-center gap-3 text-sm text-[#34d399] dark:text-[#34d399]">
                                <ShieldCheck class="h-4 w-4" />
                                Secure checkout backed by Stripe
                            </div>
                        </div>
                    </section>

                    <section class="grid gap-6 lg:grid-cols-[2fr,1fr]">
                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <Card
                                    v-for="plan in props.plans"
                                    :key="plan.id"
                                    class="relative border-[#19140015] bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg dark:border-[#3E3E3A] dark:bg-[#161615]"
                                >
                                    <CardHeader>
                                        <CardTitle class="flex items-center justify-between text-[#1b1b18] dark:text-[#EDEDEC]">
                                            <span>{{ plan.name }}</span>
                                            <span class="text-sm font-medium text-[#706f6c] dark:text-[#A1A09A]">
                                                {{ formatCurrency(plan.price, plan.currency) }} / {{ plan.interval }}
                                            </span>
                                        </CardTitle>
                                        <CardDescription>
                                            {{ plan.features[0] ?? 'Built for engaged members.' }}
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <ul class="space-y-2 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                            <li v-for="feature in plan.features" :key="feature" class="flex items-start gap-2">
                                                <span class="mt-1 h-1.5 w-1.5 rounded-full bg-[#1b1b18] dark:bg-[#EDEDEC]" />
                                                <span>{{ feature }}</span>
                                            </li>
                                            <li v-if="!plan.features.length" class="text-sm text-muted-foreground">Includes the basics you need to launch.</li>
                                        </ul>
                                    </CardContent>
                                    <CardFooter class="flex flex-col gap-2">
                                        <Button
                                            :variant="plan.id === selectedPlanId ? 'default' : 'outline'"
                                            class="w-full"
                                            @click="() => { selectedPlanId = plan.id; startCheckout(); }"
                                        >
                                            {{ plan.id === selectedPlanId ? 'Selected' : 'Get started' }}
                                        </Button>
                                        <p
                                            v-if="plan.id === selectedPlanId"
                                            class="text-center text-xs text-emerald-600 dark:text-emerald-400"
                                        >
                                            Preparing checkout for this plan
                                        </p>
                                    </CardFooter>
                                </Card>
                            </div>
                            <div v-if="!props.plans.length" class="rounded-lg border border-dashed border-[#19140035] p-4 text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                                No active plans are available right now.
                            </div>
                        </div>

                        <div class="space-y-4">
                            <Card class="border-[#19140015] bg-white dark:border-[#34d399] dark:bg-[#161615]">
                                <CardHeader>
                                    <CardTitle class="text-lg text-[#1b1b18] dark:text-[#EDEDEC]">Checkout</CardTitle>
                                    <CardDescription>Enter your email and payment details to activate your subscription.</CardDescription>
                                </CardHeader>
                                <CardContent class="space-y-4">
                                    <div class="space-y-2">
                                        <label for="pricing-email" class="text-sm font-medium text-[#1b1b18] dark:text-[#EDEDEC]">Email</label>
                                        <Input
                                            id="pricing-email"
                                            v-model="email"
                                            :disabled="$page.props.auth.user !== null"
                                            placeholder="you@example.com"
                                        />
                                        <p class="text-xs text-[#706f6c] dark:text-[#A1A09A]">We'll create or connect your account with this email.</p>
                                    </div>
                                    <Separator />
                                    <div class="grid gap-3">
                                        <div v-if="!isStripeConfigured" class="rounded-lg border border-dashed border-[#19140035] p-3 text-sm text-[#706f6c] dark:border-[#3E3E3A] dark:text-[#A1A09A]">
                                            Add your Stripe publishable key to enable the payment form.
                                        </div>
                                        <div v-else id="pricing-payment-element" class="rounded-lg border border-[#19140035] bg-white p-4 shadow-sm dark:border-[#3E3E3A] dark:bg-[#0f0f0d]" />
                                        <p v-if="isStripeConfigured && !paymentElementReady" class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Load the payment form to continue.</p>
                                    </div>
                                    <div class="flex flex-col gap-3">
                                        <Button :disabled="setupLoading" variant="outline" @click="startCheckout">
                                            <span v-if="setupLoading">Preparing checkout…</span>
                                            <span v-else>Load payment form</span>
                                        </Button>
                                        <Button
                                            :disabled="subscribing || !paymentElementReady || confirmingPayment"
                                            @click="subscribe"
                                        >
                                            <span v-if="subscribing">Activating…</span>
                                            <span v-else-if="confirmingPayment">Confirming…</span>
                                            <span v-else>Start subscription</span>
                                        </Button>
                                    </div>
                                    <p v-if="paymentError" class="rounded border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">
                                        {{ paymentError }}
                                    </p>
                                    <p v-if="successMessage" class="rounded border border-emerald-400/40 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                        {{ successMessage }}
                                    </p>
                                </CardContent>
                            </Card>
                        </div>
                    </section>
                </div>
            </main>
        </div>
    </AppLayout>
</template>
