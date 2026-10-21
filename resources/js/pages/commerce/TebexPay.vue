<script setup lang="ts">
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatMoney } from '@/lib/money';
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, CreditCard, LoaderCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{
    /** `popup` (Tebex.js opens the checkout over this page) or `inline` (it is put inside this page). */
    mode: 'popup' | 'inline';
    /** The basket Tebex.js checks out. */
    ident: string;
    /** Tebex's own page for the basket: where to pay when Tebex.js cannot be used. */
    hostedUrl: string;
    /** The order's page, which waits for Tebex's webhook to say the payment has been taken. */
    completeUrl: string;
    cancelUrl: string;
    theme: 'auto' | 'default' | 'light' | 'dark';
    order: { number: string; total: string; currency: string };
}>();

/**
 * loading: Tebex.js is being fetched. ready: it is set up and waiting for the buyer. open: the popup is open. closed: they
 * closed it. unavailable: it could not be used, so Tebex's own page is the way to pay. completing: Tebex.js said the payment
 * is complete and the buyer is on the way to the order page.
 */
const phase = ref<'loading' | 'ready' | 'open' | 'closed' | 'unavailable' | 'completing'>('loading');
const paymentError = ref(false);
const frame = ref<HTMLElement | null>(null);

let checkout: (typeof import('@tebexio/tebex.js'))['checkout'] | null = null;
let unsubscribe: (() => void)[] = [];

const total = computed(() => formatMoney(props.order.total, props.order.currency));

/**
 * Tebex.js says the payment is complete. That tells the shop nothing it can act on (the browser could be saying anything):
 * the order is settled by Tebex's webhook. So the buyer is only taken to the order's page, which waits for it.
 */
const complete = () => {
    phase.value = 'completing';
    router.visit(props.completeUrl);
};

const launch = async () => {
    if (!checkout) {
        return;
    }

    paymentError.value = false;

    try {
        await checkout.launch();
    } catch {
        phase.value = 'unavailable';
    }
};

onMounted(async () => {
    try {
        // Fetched only here: no other page carries it.
        const tebex = await import('@tebexio/tebex.js');
        checkout = tebex.checkout;

        checkout.init({
            ident: props.ident,
            theme: props.theme,
            // The popup shuts itself when the payment is taken; the buyer is then on their way to the order's page.
            closeOnPaymentComplete: true,
        });

        unsubscribe = [
            checkout.on('open', () => (phase.value = 'open')),
            checkout.on('close', () => {
                if (phase.value !== 'completing') {
                    phase.value = 'closed';
                }
            }),
            checkout.on('payment:complete', complete),
            checkout.on('payment:error', () => (paymentError.value = true)),
        ];

        if (props.mode === 'inline' && frame.value) {
            await checkout.render(frame.value, '100%', 680, false);
        }

        phase.value = 'ready';
    } catch {
        phase.value = 'unavailable';
    }
});

onBeforeUnmount(() => {
    unsubscribe.forEach((off) => off());

    try {
        checkout?.destroy();
    } catch {
        // Nothing left to tidy.
    }
});
</script>

<template>
    <AppLayout>
        <Head title="Pay" />

        <div class="mx-auto w-full max-w-3xl space-y-6 py-8">
            <Card>
                <CardHeader>
                    <CardTitle>Pay for order {{ order.number }}</CardTitle>
                    <CardDescription>
                        You pay Tebex, who work out any tax and take any code you have. The order comes to {{ total }} before tax.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p v-if="phase === 'loading'" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                        <LoaderCircle class="size-4 animate-spin" /> Getting the payment ready…
                    </p>

                    <p v-else-if="phase === 'completing'" class="flex items-center gap-2 text-sm text-muted-foreground" role="status">
                        <LoaderCircle class="size-4 animate-spin" /> Thank you. Taking you to your order…
                    </p>

                    <Alert v-if="phase === 'unavailable'" variant="destructive">
                        <CircleAlert class="size-4" />
                        <AlertTitle>The payment could not be shown here</AlertTitle>
                        <AlertDescription
                            >Your browser or an extension may have blocked it. You can pay on Tebex's own page instead.</AlertDescription
                        >
                    </Alert>

                    <Alert v-if="paymentError" variant="destructive">
                        <CircleAlert class="size-4" />
                        <AlertTitle>That payment did not go through</AlertTitle>
                        <AlertDescription>Nothing was charged for it. Check the details and try again, or use another way to pay.</AlertDescription>
                    </Alert>

                    <div v-if="mode === 'popup' && (phase === 'ready' || phase === 'closed' || phase === 'open')" class="space-y-3">
                        <Button size="lg" class="w-full" :disabled="phase === 'open'" @click="launch">
                            <CreditCard class="size-4" /> {{ phase === 'closed' ? 'Continue to pay' : 'Pay now' }}
                        </Button>
                        <p v-if="phase === 'closed'" class="text-sm text-muted-foreground">You closed the payment. Nothing has been charged.</p>
                    </div>

                    <div v-if="mode === 'inline'" ref="frame" class="min-h-[40rem] w-full" aria-label="Payment" />

                    <Button v-if="phase === 'unavailable'" as-child size="lg" class="w-full">
                        <a :href="hostedUrl" rel="noopener"><CreditCard class="size-4" /> Pay on Tebex</a>
                    </Button>
                </CardContent>
            </Card>

            <p class="text-center text-sm text-muted-foreground">
                <template v-if="phase !== 'unavailable' && phase !== 'completing'">
                    Rather pay on Tebex's own page? <a :href="hostedUrl" rel="noopener" class="underline">Open it</a>.
                    <br />
                </template>
                Changed your mind? <Link :href="cancelUrl" class="underline">Back to your cart</Link>
            </p>
        </div>
    </AppLayout>
</template>
