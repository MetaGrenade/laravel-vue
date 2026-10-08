<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatDateTime } from '@/lib/orderStatus';
import type { Capabilities, StockMovement, StockRow } from '@/types/catalogue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    stock: StockRow | null;
    productId: number;
    /** The variant this stock belongs to; empty for the product itself. */
    variantId: number | null;
    threshold: number;
    can: Capabilities;
    idPrefix: string;
}>();

const status = computed(() => {
    if (!props.stock) {
        return null;
    }

    if (!props.stock.allow_backorder && props.stock.quantity <= 0) {
        return { label: 'Out of stock', variant: 'destructive' as const };
    }

    if (!props.stock.allow_backorder && props.stock.quantity <= props.threshold) {
        return { label: 'Low', variant: 'highlight' as const };
    }

    return { label: 'In stock', variant: 'default' as const };
});

// ----- Start tracking -----------------------------------------------------------------------------

const trackOpen = ref(false);
const trackForm = useForm({ product_variant_id: null as number | null, quantity: '', allow_backorder: false });

const openTrack = () => {
    trackForm.defaults({ product_variant_id: props.variantId, quantity: '0', allow_backorder: false }).reset();
    trackForm.clearErrors();
    trackOpen.value = true;
};

const track = () => {
    trackForm.post(route('acp.commerce.stock.track', props.productId), {
        preserveScroll: true,
        onSuccess: () => {
            trackOpen.value = false;
        },
    });
};

// ----- Change the count ---------------------------------------------------------------------------

const adjustForm = useForm({ mode: 'add' as 'add' | 'set', quantity: '', note: '' });

const adjust = () => {
    if (!props.stock) {
        return;
    }

    adjustForm.put(route('acp.commerce.stock.update', props.stock.id), {
        preserveScroll: true,
        onSuccess: () => adjustForm.reset('quantity', 'note'),
    });
};

const setBackorder = (value: boolean) => {
    if (props.stock) {
        router.put(route('acp.commerce.stock.update', props.stock.id), { allow_backorder: value }, { preserveScroll: true });
    }
};

// ----- Stop tracking ------------------------------------------------------------------------------

const untrackOpen = ref(false);

const untrack = () => {
    if (!props.stock) {
        return;
    }

    router.delete(route('acp.commerce.stock.destroy', props.stock.id), {
        preserveScroll: true,
        onFinish: () => {
            untrackOpen.value = false;
        },
    });
};

const describe = (movement: StockMovement) => {
    switch (movement.reason) {
        case 'reservation':
            return 'Held for an order';
        case 'release':
            return 'Order cancelled, stock returned';
        case 'restock':
            return 'Refunded, stock returned';
        case 'adjustment':
            return movement.note ?? 'Counted or corrected';
        default:
            return movement.reason;
    }
};

const selectClass =
    'h-9 min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <div class="space-y-4">
        <template v-if="stock && status">
            <div class="flex flex-wrap items-center gap-3">
                <span class="text-3xl font-semibold tabular-nums">{{ stock.quantity }}</span>
                <span class="text-sm text-muted-foreground">available</span>
                <Badge :variant="status.variant">{{ status.label }}</Badge>
            </div>

            <div class="flex items-start gap-3">
                <Switch
                    :id="`${idPrefix}-backorder`"
                    :model-value="stock.allow_backorder"
                    :disabled="!can.edit"
                    class="mt-0.5"
                    @update:model-value="setBackorder"
                />
                <div class="grid gap-1">
                    <Label :for="`${idPrefix}-backorder`" class="font-normal">Keep selling when it runs out</Label>
                    <p class="text-xs text-muted-foreground">The count goes below zero for orders that are waiting for stock.</p>
                </div>
            </div>

            <form v-if="can.edit" class="space-y-2 rounded-lg border p-3" @submit.prevent="adjust">
                <Label :for="`${idPrefix}-quantity`" class="text-sm font-medium">Change the count</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <select v-model="adjustForm.mode" :class="selectClass" aria-label="How to change the count">
                        <option value="add">Add or remove</option>
                        <option value="set">Set to</option>
                    </select>
                    <Input
                        :id="`${idPrefix}-quantity`"
                        v-model="adjustForm.quantity"
                        inputmode="numeric"
                        class="w-28"
                        :placeholder="adjustForm.mode === 'add' ? '+10 or -3' : '25'"
                        :aria-invalid="!!adjustForm.errors.quantity || undefined"
                    />
                    <Input
                        v-model="adjustForm.note"
                        class="min-w-40 flex-1"
                        maxlength="255"
                        placeholder="Note (delivery, damaged, stock take…)"
                        aria-label="Note"
                    />
                    <Button type="submit" size="sm" :disabled="adjustForm.processing || adjustForm.quantity.trim() === ''">Update</Button>
                </div>
                <InputError :message="adjustForm.errors.quantity || adjustForm.errors.mode || adjustForm.errors.note" />
                <p class="text-xs text-muted-foreground">
                    Orders hold stock while they wait for payment and give it back if they are cancelled, so this is what can still be sold.
                </p>
            </form>

            <div v-if="stock.movements.length">
                <h4 class="mb-2 text-sm font-medium">Recent changes</h4>
                <ul class="divide-y rounded-lg border text-sm">
                    <li v-for="movement in stock.movements" :key="movement.id" class="flex flex-wrap items-center justify-between gap-2 p-2.5">
                        <div>
                            <span class="font-medium tabular-nums" :class="movement.delta < 0 ? 'text-destructive' : 'text-success'">
                                {{ movement.delta > 0 ? '+' : '' }}{{ movement.delta }}
                            </span>
                            <span class="ml-2">{{ describe(movement) }}</span>
                            <Link
                                v-if="movement.order"
                                :href="route('acp.commerce.orders.show', movement.order.public_id)"
                                class="ml-1 text-primary hover:underline"
                                >{{ movement.order.number }}</Link
                            >
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ formatDateTime(movement.created_at) }}<template v-if="movement.by"> · {{ movement.by }}</template>
                        </span>
                    </li>
                </ul>
            </div>

            <Button v-if="can.delete" variant="ghost" size="sm" class="text-destructive" @click="untrackOpen = true">Stop tracking stock</Button>
        </template>

        <template v-else>
            <p class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                Stock is not tracked, so this is always available to buy. Track it to count what is left and stop selling when it runs out.
            </p>
            <Button v-if="can.create" variant="outline" size="sm" @click="openTrack">Track stock</Button>
        </template>

        <Dialog v-model:open="trackOpen">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Track stock</DialogTitle>
                    <DialogDescription>How many are there now? Orders will take from this count.</DialogDescription>
                </DialogHeader>

                <form :id="`${idPrefix}-track-form`" class="space-y-5" @submit.prevent="track">
                    <div class="grid gap-2">
                        <Label :for="`${idPrefix}-opening`">In stock now</Label>
                        <Input
                            :id="`${idPrefix}-opening`"
                            v-model="trackForm.quantity"
                            inputmode="numeric"
                            class="w-32"
                            :aria-invalid="!!trackForm.errors.quantity || undefined"
                        />
                        <InputError :message="trackForm.errors.quantity" />
                    </div>
                    <div class="flex items-center gap-3">
                        <Switch :id="`${idPrefix}-opening-backorder`" v-model="trackForm.allow_backorder" />
                        <Label :for="`${idPrefix}-opening-backorder`" class="font-normal">Keep selling when it runs out</Label>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="trackOpen = false">Cancel</Button>
                    <Button type="submit" :form="`${idPrefix}-track-form`" :disabled="trackForm.processing">Start tracking</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="untrackOpen"
            title="Stop tracking stock?"
            description="It will always be available to buy, and its history of stock changes is deleted."
            confirm-label="Stop tracking"
            @update:open="(value) => (untrackOpen = value)"
            @confirm="untrack"
            @cancel="untrackOpen = false"
        />
    </div>
</template>
