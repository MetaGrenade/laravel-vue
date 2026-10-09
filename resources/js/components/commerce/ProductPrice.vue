<script setup lang="ts">
import { formatMoney } from '@/lib/money';
import type { StorefrontPrice } from '@/types/commerce';
import { computed } from 'vue';

const props = defineProps<{
    /** The price a shopper pays (the cheapest that applies). */
    price: StorefrontPrice | null;
    /** Say "From" before it: the product's price depends on what is chosen. */
    from?: boolean;
    large?: boolean;
}>();

// How much cheaper than the price it used to be, to the nearest whole percent.
const saving = computed(() => {
    const price = props.price;

    if (!price?.compare_at_amount) {
        return null;
    }

    const was = Number(price.compare_at_amount);
    const now = Number(price.amount);

    return was > now ? Math.round(((was - now) / was) * 100) : null;
});
</script>

<template>
    <p v-if="price" class="flex flex-wrap items-baseline gap-x-2">
        <span v-if="from" class="text-sm text-muted-foreground">From</span>
        <span class="font-semibold tabular-nums" :class="large ? 'text-3xl' : 'text-lg'">{{ formatMoney(price.amount, price.currency) }}</span>
        <template v-if="price.compare_at_amount">
            <span class="sr-only">Was</span>
            <span class="text-muted-foreground tabular-nums line-through" :class="large ? 'text-lg' : 'text-sm'">{{
                formatMoney(price.compare_at_amount, price.currency)
            }}</span>
            <span v-if="saving" class="text-sm font-medium text-success">Save {{ saving }}%</span>
        </template>
    </p>
    <p v-else class="text-sm text-muted-foreground">Currently unavailable</p>
</template>
