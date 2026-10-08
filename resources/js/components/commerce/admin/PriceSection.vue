<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { formatMoney } from '@/lib/money';
import type { Capabilities, PriceRow } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    prices: PriceRow[];
    /** Where a new price is posted (the product's or the variant's). */
    storeUrl: string;
    currency: string;
    can: Capabilities;
    /** Said when there is no price, to explain what that means here. */
    emptyHint: string;
    idPrefix: string;
}>();

const hasActive = computed(() => props.prices.some((price) => price.is_active && price.currency === props.currency));

const open = ref(false);
const editing = ref<PriceRow | null>(null);

const form = useForm({ amount: '', compare_at_amount: '', is_active: true });

// Inertia's reset() goes back to the last *submitted* values, so a new dialog would open pre-filled.
const blank = () => ({ amount: '', compare_at_amount: '', is_active: !hasActive.value });

const openNew = () => {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    open.value = true;
};

const openEdit = (price: PriceRow) => {
    editing.value = price;
    form.defaults({ amount: price.amount, compare_at_amount: price.compare_at_amount ?? '', is_active: price.is_active }).reset();
    form.clearErrors();
    open.value = true;
};

const save = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(route('acp.commerce.prices.update', editing.value.id), options);
    } else {
        form.post(props.storeUrl, options);
    }
};

const pendingDelete = ref<PriceRow | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.prices.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};
</script>

<template>
    <div class="space-y-3">
        <ul v-if="prices.length" class="divide-y rounded-lg border">
            <li v-for="price in prices" :key="price.id" class="flex flex-wrap items-center justify-between gap-3 p-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium tabular-nums">{{ formatMoney(price.amount, price.currency) }}</span>
                    <span v-if="price.compare_at_amount" class="text-sm text-muted-foreground tabular-nums line-through">{{
                        formatMoney(price.compare_at_amount, price.currency)
                    }}</span>
                    <Badge v-if="price.usable" variant="default">In use</Badge>
                    <Badge v-else-if="price.currency !== currency" variant="outline">Not used: the shop sells in {{ currency }}</Badge>
                    <Badge v-else variant="secondary">Switched off</Badge>
                </div>
                <div v-if="can.edit || can.delete" class="flex gap-1">
                    <Button v-if="can.edit" variant="ghost" size="icon" aria-label="Edit price" @click="openEdit(price)"
                        ><Pencil class="size-4"
                    /></Button>
                    <Button
                        v-if="can.delete"
                        variant="ghost"
                        size="icon"
                        class="text-destructive"
                        aria-label="Delete price"
                        @click="pendingDelete = price"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">{{ emptyHint }}</p>

        <Button v-if="can.create" variant="outline" size="sm" @click="openNew"><Plus class="size-4" /> Add a price</Button>

        <Dialog v-model:open="open">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Edit price' : 'Add a price' }}</DialogTitle>
                    <DialogDescription>Prices are in {{ editing ? editing.currency : currency }}, before tax.</DialogDescription>
                </DialogHeader>

                <form :id="`${idPrefix}-price-form`" class="space-y-5" @submit.prevent="save">
                    <div class="grid gap-2">
                        <Label :for="`${idPrefix}-amount`">Price</Label>
                        <Input
                            :id="`${idPrefix}-amount`"
                            v-model="form.amount"
                            inputmode="decimal"
                            class="w-40"
                            placeholder="25.00"
                            :aria-invalid="!!form.errors.amount || undefined"
                        />
                        <InputError :message="form.errors.amount" />
                    </div>

                    <div class="grid gap-2">
                        <Label :for="`${idPrefix}-compare`">Original price <span class="text-muted-foreground">(optional)</span></Label>
                        <Input
                            :id="`${idPrefix}-compare`"
                            v-model="form.compare_at_amount"
                            inputmode="decimal"
                            class="w-40"
                            placeholder="30.00"
                            :aria-invalid="!!form.errors.compare_at_amount || undefined"
                        />
                        <p class="text-xs text-muted-foreground">Shown struck through next to the price, to show a saving.</p>
                        <InputError :message="form.errors.compare_at_amount" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center gap-3">
                            <Switch :id="`${idPrefix}-active`" v-model="form.is_active" />
                            <Label :for="`${idPrefix}-active`" class="font-normal">In use</Label>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            One price at a time is charged. Switch the old one off before switching a new one on.
                        </p>
                        <InputError :message="form.errors.is_active" />
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" :form="`${idPrefix}-price-form`" :disabled="form.processing">Save price</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="pendingDelete !== null"
            title="Delete this price?"
            description="If it is the one in use, the product cannot be bought until it has another."
            confirm-label="Delete price"
            @update:open="(value) => !value && (pendingDelete = null)"
            @confirm="remove"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
