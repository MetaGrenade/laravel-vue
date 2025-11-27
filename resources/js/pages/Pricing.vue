<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { ArrowLeft, ShieldCheck } from 'lucide-vue-next';

interface PlanFeatureItem {
    key: string;
    label: string;
    value: string;
    note?: string;
    badge?: string;
}

interface PlanFeatureGroup {
    title: string;
    items: PlanFeatureItem[];
}

interface PlanLimit {
    label: string;
    value: string;
    helper?: string;
}

interface Plan {
    id: number;
    name: string;
    slug: string;
    price: number;
    interval: string;
    currency: string;
    features: string[];
    feature_groups: PlanFeatureGroup[];
    limits: PlanLimit[];
    stripe_price_id: string;
}

interface Faq {
    question: string;
    answer: string;
}

interface Props {
    plans: Plan[];
    faqs: Faq[];
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

const comparisonColumns = computed(() => {
    if (!props.plans.length) {
        return '1fr';
    }

    return `1.5fr repeat(${props.plans.length}, 1fr)`;
});

const comparisonGroups = computed(() => {
    if (!props.plans.length) {
        return [] as { title: string; rows: { key: string; label: string; values: PlanFeatureItem[] }[] }[];
    }

    const baseGroups = props.plans[0].feature_groups;

    return baseGroups.map((group) => ({
        title: group.title,
        rows: group.items.map((item) => ({
            key: item.key,
            label: item.label,
            values: props.plans.map((plan) => {
                const matchGroup = plan.feature_groups.find((candidate) => candidate.title === group.title);

                return matchGroup?.items.find((candidateItem) => candidateItem.key === item.key) ?? {
                    key: item.key,
                    label: item.label,
                    value: '—',
                };
            }),
        })),
    }));
});

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
    <Head title="Pricing" />

    <div class="min-h-screen bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a]">
        <header class="mb-8 flex w-full justify-center px-6 pt-6">
            <nav class="flex w-full max-w-5xl items-center justify-end gap-4 text-sm">
                <Link
                    :href="route('home')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    <ArrowLeft class="h-5 w-5" />
                </Link>
                <Link
                    :href="route('pricing')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Pricing
                </Link>
                <Link
                    v-if="$page.props.auth.user"
                    :href="route('dashboard')"
                    class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                >
                    Dashboard
                </Link>
                <template v-else>
                    <Link
                        :href="route('login')"
                        class="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#19140035] dark:text-[#EDEDEC] dark:hover:border-[#3E3E3A]"
                    >
                        Log in
                    </Link>
                    <Link
                        :href="route('register')"
                        class="inline-block rounded-sm border border-[#19140035] px-5 py-1.5 text-sm leading-normal text-[#1b1b18] hover:border-[#1915014a] dark:border-[#3E3E3A] dark:text-[#EDEDEC] dark:hover:border-[#62605b]"
                    >
                        Get started
                    </Link>
                </template>
            </nav>
        </header>

        <main class="flex justify-center px-6 py-12">
            <div class="flex w-full max-w-5xl flex-col gap-10">
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
                        <Card class="border-[#19140015] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]">
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

                <section v-if="props.plans.length" class="space-y-4">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div class="space-y-1">
                            <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Usage limits</h2>
                            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Soft limits keep workspaces fast and predictable. Upgrade when you're ready for more.</p>
                        </div>
                    </div>
                    <div class="grid gap-4 md:grid-cols-2">
                        <Card
                            v-for="plan in props.plans"
                            :key="`${plan.id}-limits`"
                            class="border-[#19140015] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <CardHeader>
                                <CardTitle class="text-lg text-[#1b1b18] dark:text-[#EDEDEC]">{{ plan.name }} limits</CardTitle>
                                <CardDescription>Stay within limits to avoid throttling.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <ul class="space-y-3 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                                    <li v-for="limit in plan.limits" :key="limit.label" class="space-y-1">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ limit.label }}</span>
                                            <span class="rounded-full bg-[#f6f2e8] px-3 py-1 text-xs font-medium text-[#8b5a00] dark:bg-[#1f1c15] dark:text-[#f3d29e]">
                                                {{ limit.value }}
                                            </span>
                                        </div>
                                        <p v-if="limit.helper" class="text-xs text-[#9b978f] dark:text-[#7e7b73]">{{ limit.helper }}</p>
                                    </li>
                                    <li v-if="!plan.limits.length" class="text-sm text-muted-foreground">Limits will be shared soon.</li>
                                </ul>
                            </CardContent>
                        </Card>
                    </div>
                </section>

                <section v-if="comparisonGroups.length" class="space-y-4">
                    <div class="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                        <div class="space-y-1">
                            <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Compare what's included</h2>
                            <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Feature breakdowns by plan to help you choose with confidence.</p>
                        </div>
                        <p class="text-xs uppercase tracking-[0.14em] text-[#8b5a00] dark:text-[#f3d29e]">{{ props.plans.length }} plans</p>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-[#19140015] bg-white shadow-sm dark:border-[#3E3E3A] dark:bg-[#0f0f0d]">
                        <div class="border-b border-[#19140015] bg-[#fbf7ee] px-4 py-3 text-sm font-semibold text-[#1b1b18] dark:border-[#3E3E3A] dark:bg-[#161615] dark:text-[#EDEDEC]">
                            Feature comparison
                        </div>
                        <div class="divide-y divide-[#19140015] dark:divide-[#3E3E3A]">
                            <div
                                v-for="group in comparisonGroups"
                                :key="group.title"
                                class="flex flex-col gap-1 bg-[#fefcf8] px-4 py-4 dark:bg-[#0f0f0d]"
                            >
                                <p class="text-sm font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">{{ group.title }}</p>
                                <div class="space-y-2">
                                    <div
                                        v-for="row in group.rows"
                                        :key="row.key"
                                        class="grid items-start gap-4 rounded-md border border-transparent bg-white px-3 py-2 text-sm text-[#706f6c] transition hover:border-[#19140015] dark:bg-[#161615] dark:text-[#A1A09A] dark:hover:border-[#3E3E3A]"
                                        :style="{ gridTemplateColumns: comparisonColumns }"
                                    >
                                        <div class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ row.label }}</div>
                                        <div
                                            v-for="(value, index) in row.values"
                                            :key="`${row.key}-${index}`"
                                            class="flex flex-col gap-1"
                                        >
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-[#1b1b18] dark:text-[#EDEDEC]">{{ value.value }}</span>
                                                <span
                                                    v-if="value.badge"
                                                    class="rounded-full bg-[#1b1b18] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white dark:bg-white dark:text-[#0f0f0d]"
                                                >
                                                    {{ value.badge }}
                                                </span>
                                            </div>
                                            <p v-if="value.note" class="text-xs text-[#9b978f] dark:text-[#7e7b73]">{{ value.note }}</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <section v-if="props.faqs?.length" class="space-y-4">
                    <div class="space-y-1">
                        <h2 class="text-2xl font-semibold text-[#1b1b18] dark:text-[#EDEDEC]">Frequently asked questions</h2>
                        <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">Answers to the most common billing and subscription questions.</p>
                    </div>
                    <div class="grid gap-3 md:grid-cols-2">
                        <Card
                            v-for="faq in props.faqs"
                            :key="faq.question"
                            class="border-[#19140015] bg-white dark:border-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <CardHeader>
                                <CardTitle class="text-base text-[#1b1b18] dark:text-[#EDEDEC]">{{ faq.question }}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p class="text-sm text-[#706f6c] dark:text-[#A1A09A]">{{ faq.answer }}</p>
                            </CardContent>
                        </Card>
                    </div>
                </section>
            </div>
        </main>
    </div>
</template>
