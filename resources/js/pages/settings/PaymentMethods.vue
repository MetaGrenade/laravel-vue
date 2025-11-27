<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/SettingsLayout.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';

interface PaymentMethodPayload {
    id: string;
    type: string;
    brand: string | null;
    last_four: string | null;
    exp_month: number | null;
    exp_year: number | null;
}

interface Props {
    payment_methods: PaymentMethodPayload[];
    default_payment_method: string | null;
}

type StripeInstance = any;
type StripeElementsInstance = any;
type StripePaymentElementInstance = any;

const props = defineProps<Props>();

const page = usePage<{ billing?: { stripeKey?: string | null } }>();
const stripeKey = computed(() => page.props.billing?.stripeKey ?? null);
const isStripeConfigured = computed(() => Boolean(stripeKey.value));

const stripe = shallowRef<StripeInstance | null>(null);
const elements = shallowRef<StripeElementsInstance | null>(null);
const paymentElement = shallowRef<StripePaymentElementInstance | null>(null);
const paymentElementReady = ref(false);
const setupIntentSecret = ref<string | null>(null);
const setupLoading = ref(false);
const saving = ref(false);
const errorMessage = ref<string | null>(null);
const successMessage = ref<string | null>(null);

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

    if (!stripe.value) {
        stripe.value = factory(stripeKey.value);
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
    let stripeInstance: StripeInstance | null = null;

    try {
        stripeInstance = await resolveStripe();
    } catch (error: unknown) {
        errorMessage.value = error instanceof Error ? error.message : 'Stripe.js failed to load.';
        return;
    }

    if (!stripeInstance) {
        errorMessage.value = 'Stripe.js could not be initialised. Check your publishable key.';
        return;
    }

    teardownElements();

    elements.value = stripeInstance.elements({
        clientSecret: secret,
    });

    paymentElement.value = elements.value.create('payment');
    paymentElement.value.mount('#payment-element');
    paymentElement.value.on('ready', () => {
        paymentElementReady.value = true;
    });
};

const fetchSetupIntent = async () => {
    if (!stripeKey.value) {
        errorMessage.value = 'Stripe publishable key is not configured.';
        return;
    }

    setupLoading.value = true;
    errorMessage.value = null;

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
            throw new Error('Unable to create setup intent');
        }

        const data = await response.json();
        setupIntentSecret.value = data.client_secret ?? null;

        if (setupIntentSecret.value) {
            await mountPaymentElement(setupIntentSecret.value);
        }
    } catch (error: unknown) {
        console.error(error);
        errorMessage.value = error instanceof Error ? error.message : 'Failed to prepare the payment form.';
    } finally {
        setupLoading.value = false;
    }
};

const addPaymentMethod = async () => {
    if (!stripe.value || !elements.value) {
        errorMessage.value = 'Ensure the payment form has finished loading before saving.';
        return;
    }

    saving.value = true;
    errorMessage.value = null;
    successMessage.value = null;

    try {
        const { error: submitError } = await elements.value.submit();

        if (submitError) {
            errorMessage.value = submitError.message ?? 'Your payment details need attention.';
            return;
        }

        const confirmation = await stripe.value.confirmSetup({
            elements: elements.value,
            redirect: 'if_required',
        });

        if (confirmation.error) {
            errorMessage.value = confirmation.error.message ?? 'Stripe rejected the payment method.';
            return;
        }

        const paymentMethodId = confirmation.setupIntent?.payment_method;

        if (!paymentMethodId) {
            errorMessage.value = 'Stripe did not return a payment method identifier.';
            return;
        }

        const response = await fetch(route('settings.billing.payment-methods.store'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
            },
            body: JSON.stringify({
                payment_method: paymentMethodId,
                make_default: !props.default_payment_method,
            }),
        });

        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            const firstError = payload?.errors ? Object.values(payload.errors as Record<string, string[]>)[0]?.[0] : null;
            errorMessage.value = payload?.message ?? firstError ?? 'Unable to save the payment method.';
            return;
        }

        successMessage.value = 'Payment method saved successfully.';

        await router.reload({
            only: ['payment_methods', 'default_payment_method'],
            preserveScroll: true,
        });

        await fetchSetupIntent();
    } finally {
        saving.value = false;
    }
};

const setDefault = (paymentMethodId: string) => {
    router.put(
        route('settings.billing.payment-methods.default', { paymentMethod: paymentMethodId }),
        {},
        {
            preserveScroll: true,
            onError: (errors) => {
                errorMessage.value = Object.values(errors)[0] as string;
            },
            onSuccess: async () => {
                successMessage.value = 'Default payment method updated.';
                await router.reload({
                    only: ['payment_methods', 'default_payment_method'],
                    preserveScroll: true,
                });
            },
        },
    );
};

const removePaymentMethod = (paymentMethodId: string) => {
    router.delete(route('settings.billing.payment-methods.destroy', { paymentMethod: paymentMethodId }), {
        preserveScroll: true,
        onError: (errors) => {
            const messages = Object.values(errors ?? {});
            errorMessage.value = (messages[0] as string) ?? 'Unable to remove the payment method.';
        },
        onSuccess: async () => {
            successMessage.value = 'Payment method removed.';

            await router.reload({
                only: ['payment_methods', 'default_payment_method'],
                preserveScroll: true,
            });
        },
    });
};

const formatExpiry = (month: number | null, year: number | null) => {
    if (!month || !year) {
        return '—';
    }

    return `${month.toString().padStart(2, '0')}/${year.toString().slice(-2)}`;
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
    <AppLayout :breadcrumbs="[{ title: 'Payment methods', href: '/settings/billing/payment-methods' }]">
        <Head title="Payment methods" />

        <SettingsLayout>
            <section class="space-y-4">
                <HeadingSmall
                    title="Saved payment methods"
                    description="Manage the cards connected to your subscription and purchases."
                />

                <div class="overflow-x-auto rounded-lg border border-border bg-card shadow-sm">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Card</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="props.payment_methods.length === 0">
                                <TableCell colspan="4" class="text-center text-sm text-muted-foreground">
                                    No payment methods saved yet.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="method in props.payment_methods" :key="method.id">
                                <TableCell>
                                    <div class="flex flex-col">
                                        <span class="font-medium">
                                            {{ method.brand ? method.brand.toUpperCase() : method.type }}
                                        </span>
                                        <span class="text-sm text-muted-foreground">•••• {{ method.last_four ?? '—' }}</span>
                                    </div>
                                </TableCell>
                                <TableCell>{{ formatExpiry(method.exp_month, method.exp_year) }}</TableCell>
                                <TableCell>
                                    <Badge v-if="method.id === props.default_payment_method" variant="secondary">
                                        Default
                                    </Badge>
                                    <span v-else class="text-sm text-muted-foreground">Backup</span>
                                </TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-2">
                                        <Button
                                            v-if="method.id !== props.default_payment_method"
                                            size="sm"
                                            variant="outline"
                                            @click="setDefault(method.id)"
                                        >
                                            Make default
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            :disabled="method.id === props.default_payment_method && props.payment_methods.length === 1"
                                            @click="removePaymentMethod(method.id)"
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <p v-if="errorMessage" class="text-sm text-destructive">{{ errorMessage }}</p>
                <p v-if="successMessage" class="text-sm text-emerald-600">{{ successMessage }}</p>
            </section>

            <section class="space-y-4">
                <HeadingSmall
                    title="Add a new payment method"
                    description="Use the secure Stripe form to attach an additional card."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Payment method</CardTitle>
                        <CardDescription>We never see your card details—everything is handled by Stripe.</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div
                            v-if="!isStripeConfigured"
                            class="rounded-lg border border-dashed border-border bg-muted/30 p-4 text-sm text-muted-foreground"
                        >
                            Add your Stripe publishable key to the environment to enable the payment form.
                        </div>
                        <template v-else>
                            <div class="grid gap-2">
                                <label class="text-sm font-medium" for="payment-element">Payment method</label>
                                <div
                                    id="payment-element"
                                    class="rounded-lg border border-border bg-card p-4 shadow-sm"
                                />
                                <p v-if="!paymentElementReady" class="text-sm text-muted-foreground">
                                    Loading the secure payment form…
                                </p>
                            </div>
                        </template>
                    </CardContent>
                    <CardFooter class="flex items-center justify-between gap-4">
                        <div class="flex flex-wrap gap-2">
                            <Button
                                :disabled="!isStripeConfigured || saving || !paymentElementReady"
                                @click="addPaymentMethod"
                            >
                                <span v-if="saving">Saving…</span>
                                <span v-else>Save payment method</span>
                            </Button>
                            <Button
                                variant="outline"
                                :disabled="setupLoading || !isStripeConfigured"
                                @click="fetchSetupIntent"
                            >
                                <span v-if="setupLoading">Refreshing…</span>
                                <span v-else>Refresh form</span>
                            </Button>
                        </div>
                        <p class="text-xs text-muted-foreground">Your details are encrypted and sent directly to Stripe.</p>
                    </CardFooter>
                </Card>
            </section>
        </SettingsLayout>
    </AppLayout>
</template>
