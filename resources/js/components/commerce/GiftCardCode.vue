<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import type { CartGiftCard } from '@/types/commerce';
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Gift, X } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Where a shopper enters a gift card code, and sees the card on their cart. Only the end of the code is ever shown.
 *
 * Deliberately not a <form>: on the checkout page it sits inside the checkout form, and forms cannot be nested.
 * Enter in the box applies the card instead of submitting the order.
 */
const props = defineProps<{
    giftCard: CartGiftCard | null;
    currency: string;
}>();

const form = useForm({ gift_card: '' });
const removing = ref(false);

const apply = () => {
    if (form.processing || form.gift_card.trim() === '') {
        return;
    }

    form.post(route('shop.cart.gift-card.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset(),
    });
};

const remove = () => {
    router.delete(route('shop.cart.gift-card.destroy'), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (removing.value = true),
        onFinish: () => (removing.value = false),
    });
};

/** What it pays of this order when that is known (at checkout), otherwise what it holds. */
const detail = computed(() => {
    if (!props.giftCard?.applied) {
        return null;
    }

    return props.giftCard.amount !== undefined
        ? `pays ${formatMoney(props.giftCard.amount, props.currency)}`
        : `holds ${formatMoney(props.giftCard.balance, props.currency)}`;
});
</script>

<template>
    <div class="space-y-2">
        <div v-if="giftCard" class="space-y-1">
            <div
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                :class="giftCard.applied ? 'border-primary/40 bg-primary/5' : 'border-destructive/40 bg-destructive/5'"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <Gift v-if="giftCard.applied" class="size-4 shrink-0 text-primary" aria-hidden="true" />
                    <CircleAlert v-else class="size-4 shrink-0 text-destructive" aria-hidden="true" />
                    <span class="font-medium"
                        >Gift card ending <span class="font-mono">{{ giftCard.last_four }}</span></span
                    >
                    <span v-if="detail" class="text-muted-foreground">{{ detail }}</span>
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :disabled="removing"
                    :aria-label="`Remove the gift card ending ${giftCard.last_four}`"
                    @click="remove"
                >
                    <X class="size-4" /> Remove
                </Button>
            </div>
            <p v-if="giftCard.problem" class="text-sm text-destructive" role="alert">{{ giftCard.problem }}</p>
        </div>

        <div v-else class="space-y-1">
            <label for="gift-card-code" class="text-sm font-medium">Gift card</label>
            <div class="flex gap-2">
                <Input
                    id="gift-card-code"
                    v-model="form.gift_card"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="40"
                    placeholder="XXXX-XXXX-XXXX-XXXX"
                    class="font-mono uppercase"
                    :aria-invalid="!!form.errors.gift_card || undefined"
                    :aria-describedby="form.errors.gift_card ? 'gift-card-code-error' : undefined"
                    @keydown.enter.prevent="apply"
                />
                <Button type="button" variant="outline" :disabled="form.processing || form.gift_card.trim() === ''" @click="apply">Apply</Button>
            </div>
            <InputError id="gift-card-code-error" :message="form.errors.gift_card" />
        </div>
    </div>
</template>
