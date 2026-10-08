<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import InputError from '@/components/InputError.vue';
import PriceSection from '@/components/commerce/admin/PriceSection.vue';
import StockSection from '@/components/commerce/admin/StockSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatMoney } from '@/lib/money';
import type { Capabilities, OptionRow, VariantRow } from '@/types/catalogue';
import { router, useForm } from '@inertiajs/vue3';
import { Boxes, Pencil, Plus, Tag, Trash2, WandSparkles } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    productId: number;
    variants: VariantRow[];
    options: OptionRow[];
    /** Whether the product itself has an active price in the shop's currency (variants fall back to it). */
    productHasPrice: boolean;
    currency: string;
    threshold: number;
    missing: number;
    limit: number;
    can: Capabilities;
}>();

// Options that have values are the ones a variant must choose from.
const choosable = computed(() => props.options.filter((option) => option.values.length > 0));

const ownPrice = (variant: VariantRow) => variant.prices.find((price) => price.usable) ?? null;

// ----- Add and edit -------------------------------------------------------------------------------

const open = ref(false);
const editing = ref<VariantRow | null>(null);
const nameEdited = ref(false);

const blank = () => ({
    name: '',
    sku: '',
    option_values: {} as Record<string, string>,
    is_default: false,
    is_active: true,
});

const form = useForm(blank());

const openNew = () => {
    editing.value = null;
    nameEdited.value = false;
    form.defaults(blank()).reset();
    form.clearErrors();
    open.value = true;
};

const openEdit = (variant: VariantRow) => {
    editing.value = variant;
    nameEdited.value = true;
    form.defaults({
        name: variant.name,
        sku: variant.sku ?? '',
        option_values: { ...variant.option_values },
        is_default: variant.is_default,
        is_active: variant.is_active,
    }).reset();
    form.clearErrors();
    open.value = true;
};

// A new variant is named after what was chosen until the name is typed by hand.
watch(
    () => ({ ...form.option_values }),
    (chosen) => {
        if (!editing.value && !nameEdited.value) {
            form.name = choosable.value
                .map((option) => chosen[option.name])
                .filter(Boolean)
                .join(' / ');
        }
    },
);

const save = () => {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (editing.value) {
        form.put(route('acp.commerce.variants.update', editing.value.id), options);
    } else {
        form.post(route('acp.commerce.variants.store', props.productId), options);
    }
};

// ----- Generate -----------------------------------------------------------------------------------

const generating = ref(false);

const generate = () => {
    router.post(
        route('acp.commerce.variants.generate', props.productId),
        {},
        {
            preserveScroll: true,
            onStart: () => {
                generating.value = true;
            },
            onFinish: () => {
                generating.value = false;
            },
        },
    );
};

// ----- Price and stock of one variant -------------------------------------------------------------

const priceFor = ref<number | null>(null);
const stockFor = ref<number | null>(null);

// Read back from the page so a dialog shows the result of what was just saved.
const pricing = computed(() => props.variants.find((variant) => variant.id === priceFor.value) ?? null);
const stocking = computed(() => props.variants.find((variant) => variant.id === stockFor.value) ?? null);

// ----- Delete -------------------------------------------------------------------------------------

const pendingDelete = ref<VariantRow | null>(null);

const remove = () => {
    if (!pendingDelete.value) {
        return;
    }

    router.delete(route('acp.commerce.variants.destroy', pendingDelete.value.id), {
        preserveScroll: true,
        onFinish: () => {
            pendingDelete.value = null;
        },
    });
};

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <div class="space-y-3">
        <div v-if="can.create" class="flex flex-wrap gap-2">
            <Button variant="outline" size="sm" @click="openNew"><Plus class="size-4" /> Add a variant</Button>
            <Button v-if="choosable.length" variant="outline" size="sm" :disabled="missing === 0 || generating" @click="generate">
                <WandSparkles class="size-4" />
                {{ missing === 0 ? 'Every combination has a variant' : `Create the ${missing} missing ${missing === 1 ? 'variant' : 'variants'}` }}
            </Button>
        </div>
        <p v-if="can.create && choosable.length" class="text-xs text-muted-foreground">
            A variant is one combination of the options (for example Medium / Red). Up to {{ limit }} per product.
        </p>

        <div v-if="variants.length" class="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Variant</TableHead>
                        <TableHead>Price</TableHead>
                        <TableHead>Stock</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead v-if="can.edit || can.delete" class="text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="variant in variants" :key="variant.id" :class="variant.is_active ? '' : 'opacity-60'">
                        <TableCell>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-medium">{{ variant.name }}</span>
                                <Badge v-if="variant.is_default" variant="outline">Default</Badge>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="variant.sku">SKU {{ variant.sku }}</template>
                                <template v-else>No SKU</template>
                            </p>
                        </TableCell>
                        <TableCell class="tabular-nums">
                            <template v-if="ownPrice(variant)">{{ formatMoney(ownPrice(variant)!.amount, ownPrice(variant)!.currency) }}</template>
                            <span v-else-if="productHasPrice" class="text-sm text-muted-foreground">Product price</span>
                            <span v-else class="text-sm text-destructive">No price</span>
                        </TableCell>
                        <TableCell class="tabular-nums">
                            <template v-if="variant.stock">
                                {{ variant.stock.quantity }}
                                <Badge v-if="!variant.stock.allow_backorder && variant.stock.quantity <= 0" variant="destructive" class="ml-1"
                                    >Out</Badge
                                >
                                <Badge
                                    v-else-if="!variant.stock.allow_backorder && variant.stock.quantity <= threshold"
                                    variant="highlight"
                                    class="ml-1"
                                    >Low</Badge
                                >
                            </template>
                            <span v-else class="text-sm text-muted-foreground">Not tracked</span>
                        </TableCell>
                        <TableCell>
                            <Badge :variant="variant.is_active ? 'default' : 'secondary'">{{ variant.is_active ? 'On sale' : 'Off' }}</Badge>
                        </TableCell>
                        <TableCell v-if="can.edit || can.delete" class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button variant="ghost" size="icon" :aria-label="`Price of ${variant.name}`" @click="priceFor = variant.id"
                                    ><Tag class="size-4"
                                /></Button>
                                <Button variant="ghost" size="icon" :aria-label="`Stock of ${variant.name}`" @click="stockFor = variant.id"
                                    ><Boxes class="size-4"
                                /></Button>
                                <Button v-if="can.edit" variant="ghost" size="icon" :aria-label="`Edit ${variant.name}`" @click="openEdit(variant)">
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    v-if="can.delete"
                                    variant="ghost"
                                    size="icon"
                                    class="text-destructive"
                                    :disabled="variant.ordered"
                                    :title="variant.ordered ? 'It has been ordered, so it is kept. Switch it off instead.' : undefined"
                                    :aria-label="`Delete ${variant.name}`"
                                    @click="pendingDelete = variant"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
        <p v-else class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
            No variants. A product without variants is sold as it is, with the price and stock on the "Price and stock" tab.
        </p>

        <Dialog v-model:open="open">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{{ editing ? 'Edit variant' : 'Add a variant' }}</DialogTitle>
                    <DialogDescription>Choose its option values, then price it and set its stock from the table.</DialogDescription>
                </DialogHeader>

                <form id="variant-form" class="space-y-5" @submit.prevent="save">
                    <div v-for="option in choosable" :key="option.id" class="grid gap-2">
                        <Label :for="`variant-option-${option.id}`">{{ option.display_name }}</Label>
                        <select :id="`variant-option-${option.id}`" v-model="form.option_values[option.name]" :class="selectClass">
                            <option :value="undefined" disabled>Choose…</option>
                            <option v-for="value in option.values" :key="value.id" :value="value.value">{{ value.value }}</option>
                        </select>
                    </div>
                    <InputError :message="form.errors.option_values" />

                    <div class="grid gap-2">
                        <Label for="variant-name">Name</Label>
                        <Input
                            id="variant-name"
                            v-model="form.name"
                            maxlength="255"
                            :aria-invalid="!!form.errors.name || undefined"
                            @input="nameEdited = true"
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="variant-sku">SKU <span class="text-muted-foreground">(optional)</span></Label>
                        <Input
                            id="variant-sku"
                            v-model="form.sku"
                            maxlength="100"
                            class="sm:max-w-xs"
                            :aria-invalid="!!form.errors.sku || undefined"
                        />
                        <InputError :message="form.errors.sku" />
                    </div>

                    <div class="flex items-center gap-3">
                        <Switch id="variant-default" v-model="form.is_default" />
                        <Label for="variant-default" class="font-normal">The default choice</Label>
                    </div>
                    <div class="grid gap-1">
                        <div class="flex items-center gap-3">
                            <Switch id="variant-active" v-model="form.is_active" />
                            <Label for="variant-active" class="font-normal">On sale</Label>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            Switch off to stop selling it. A variant that has been ordered can only be switched off, not deleted.
                        </p>
                    </div>
                </form>

                <DialogFooter>
                    <Button type="button" variant="outline" @click="open = false">Cancel</Button>
                    <Button type="submit" form="variant-form" :disabled="form.processing">Save variant</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="pricing !== null" @update:open="(value) => !value && (priceFor = null)">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Price of {{ pricing?.name }}</DialogTitle>
                    <DialogDescription>Without a price of its own, a variant is sold at the product's price.</DialogDescription>
                </DialogHeader>
                <PriceSection
                    v-if="pricing"
                    :prices="pricing.prices"
                    :store-url="route('acp.commerce.variants.prices.store', pricing.id)"
                    :currency="currency"
                    :can="can"
                    :id-prefix="`variant-${pricing.id}`"
                    :empty-hint="
                        productHasPrice
                            ? 'No price of its own: it is sold at the product price.'
                            : 'No price, and the product has none to fall back on, so it cannot be bought.'
                    "
                />
            </DialogContent>
        </Dialog>

        <Dialog :open="stocking !== null" @update:open="(value) => !value && (stockFor = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Stock of {{ stocking?.name }}</DialogTitle>
                </DialogHeader>
                <StockSection
                    v-if="stocking"
                    :stock="stocking.stock"
                    :product-id="productId"
                    :variant-id="stocking.id"
                    :threshold="threshold"
                    :can="can"
                    :id-prefix="`variant-${stocking.id}`"
                />
            </DialogContent>
        </Dialog>

        <ConfirmDialog
            :open="pendingDelete !== null"
            :title="`Delete ${pendingDelete?.name ?? 'this variant'}?`"
            description="Its price and stock go with it, and it is taken out of anyone's cart."
            confirm-label="Delete variant"
            @update:open="(value) => !value && (pendingDelete = null)"
            @confirm="remove"
            @cancel="pendingDelete = null"
        />
    </div>
</template>
