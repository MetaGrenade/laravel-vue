<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatMoney } from '@/lib/money';
import type { CartCoupon } from '@/types/commerce';
import { router, useForm } from '@inertiajs/vue3';
import { CircleAlert, TicketPercent, X } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * Where a shopper enters a discount code, and sees the one on their cart.
 *
 * Deliberately not a <form>: on the checkout page it sits inside the checkout form, and forms
 * cannot be nested. Enter in the box applies the code instead of submitting the order.
 */
const props = defineProps<{
    coupon: CartCoupon | null;
    currency: string;
    /** What the code takes off the items now, when known. */
    discount?: string | null;
}>();

const form = useForm({ code: '' });
const removing = ref(false);

const apply = () => {
    if (form.processing || form.code.trim() === '') {
        return;
    }

    form.post(route('shop.cart.coupon.store'), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => form.reset(),
    });
};

const remove = () => {
    router.delete(route('shop.cart.coupon.destroy'), {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (removing.value = true),
        onFinish: () => (removing.value = false),
    });
};

const saving = computed(() => (props.discount && Number(props.discount) > 0 ? formatMoney(props.discount, props.currency) : null));
</script>

<template>
    <div class="space-y-2">
        <div v-if="coupon" class="space-y-1">
            <div
                class="flex flex-wrap items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                :class="coupon.applied ? 'border-primary/40 bg-primary/5' : 'border-destructive/40 bg-destructive/5'"
            >
                <span class="flex min-w-0 items-center gap-2">
                    <TicketPercent v-if="coupon.applied" class="size-4 shrink-0 text-primary" aria-hidden="true" />
                    <CircleAlert v-else class="size-4 shrink-0 text-destructive" aria-hidden="true" />
                    <span class="font-mono font-medium break-all">{{ coupon.code }}</span>
                    <span v-if="coupon.applied" class="text-muted-foreground">
                        <template v-if="coupon.free_shipping">free shipping</template>
                        <template v-else-if="saving">saves {{ saving }}</template>
                        <template v-else>applied</template>
                    </span>
                </span>
                <Button type="button" variant="ghost" size="sm" :disabled="removing" :aria-label="`Remove code ${coupon.code}`" @click="remove">
                    <X class="size-4" /> Remove
                </Button>
            </div>
            <p v-if="coupon.problem" class="text-sm text-destructive" role="alert">{{ coupon.problem }}</p>
        </div>

        <div v-else class="space-y-1">
            <label for="discount-code" class="text-sm font-medium">Discount code</label>
            <div class="flex gap-2">
                <Input
                    id="discount-code"
                    v-model="form.code"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    maxlength="60"
                    class="font-mono uppercase"
                    :aria-invalid="!!form.errors.code || undefined"
                    :aria-describedby="form.errors.code ? 'discount-code-error' : undefined"
                    @keydown.enter.prevent="apply"
                />
                <Button type="button" variant="outline" :disabled="form.processing || form.code.trim() === ''" @click="apply">Apply</Button>
            </div>
            <InputError id="discount-code-error" :message="form.errors.code" />
        </div>
    </div>
</template>
