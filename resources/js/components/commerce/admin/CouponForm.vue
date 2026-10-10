<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { generateCouponCode } from '@/lib/coupons';
import type { CatalogueLookup } from '@/types/catalogue';
import type { CouponDetails, CouponKind } from '@/types/coupons';
import { Link, useForm } from '@inertiajs/vue3';
import { Shuffle, X } from '@lucide/vue';
import dayjs from 'dayjs';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    /** Present when editing; absent when creating. */
    coupon?: CouponDetails;
    categories: CatalogueLookup[];
    currency: string;
    canSave: boolean;
}>();

const editing = computed(() => props.coupon !== undefined);

/** A time from the server (ISO, with its zone) as the browser's own local time for a date field. */
const toLocalInput = (value: string | null | undefined) => (value ? dayjs(value).local().format('YYYY-MM-DDTHH:mm') : '');

const form = useForm({
    code: props.coupon?.code ?? '',
    description: props.coupon?.description ?? '',
    type: (props.coupon?.type ?? 'percent') as CouponKind,
    value: props.coupon?.value ?? '',
    minimum_subtotal: props.coupon?.minimum_subtotal ?? '',
    starts_at: toLocalInput(props.coupon?.starts_at),
    ends_at: toLocalInput(props.coupon?.ends_at),
    max_redemptions: props.coupon?.max_redemptions?.toString() ?? '',
    max_redemptions_per_customer: props.coupon?.max_redemptions_per_customer?.toString() ?? '',
    is_active: props.coupon?.is_active ?? true,
    category_ids: [...(props.coupon?.category_ids ?? [])],
    product_ids: (props.coupon?.products ?? []).map((product) => product.id),
});

const errors = computed(() => form.errors as Record<string, string | undefined>);

const isPercent = computed(() => form.type === 'percent');
const hasValue = computed(() => form.type !== 'free_shipping');
const limitsItems = computed(() => form.type !== 'free_shipping');

const toggleCategory = (id: number, on: boolean | 'indeterminate') => {
    const without = form.category_ids.filter((existing) => existing !== id);

    form.category_ids = on === true ? [...without, id] : without;
};

// ----- Picking products ---------------------------------------------------------------------------

const chosen = ref<{ id: number; name: string }[]>([...(props.coupon?.products ?? [])]);
const search = ref('');
const found = ref<{ id: number; name: string }[]>([]);
const searching = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let latest = 0;

const lookUp = async () => {
    const term = search.value.trim();

    if (term === '') {
        found.value = [];

        return;
    }

    const ticket = ++latest;
    searching.value = true;

    try {
        const response = await fetch(route('acp.commerce.coupons.product-search', { search: term }), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const body = (await response.json()) as { data: { id: number; name: string }[] };

        // Only the newest answer counts: a slow earlier one must not overwrite it.
        if (ticket === latest) {
            found.value = body.data.filter((product) => !form.product_ids.includes(product.id));
        }
    } catch {
        if (ticket === latest) {
            found.value = [];
        }
    } finally {
        if (ticket === latest) {
            searching.value = false;
        }
    }
};

const onSearch = () => {
    clearTimeout(timer);
    timer = setTimeout(lookUp, 250);
};

const addProduct = (product: { id: number; name: string }) => {
    if (!form.product_ids.includes(product.id)) {
        chosen.value = [...chosen.value, product];
        form.product_ids = [...form.product_ids, product.id];
    }

    found.value = found.value.filter((other) => other.id !== product.id);
};

const removeProduct = (id: number) => {
    chosen.value = chosen.value.filter((product) => product.id !== id);
    form.product_ids = form.product_ids.filter((existing) => existing !== id);
};

onBeforeUnmount(() => clearTimeout(timer));

// ----- Saving -------------------------------------------------------------------------------------

const submit = () => {
    form.transform((data) => ({
        ...data,
        // The browser's local time, with its zone, so it means the same moment whoever saves it.
        starts_at: data.starts_at ? dayjs(data.starts_at).toISOString() : '',
        ends_at: data.ends_at ? dayjs(data.ends_at).toISOString() : '',
        // Limits only mean something for codes that take money off items.
        product_ids: limitsItems.value ? data.product_ids : [],
        category_ids: limitsItems.value ? data.category_ids : [],
    }));

    if (props.coupon) {
        form.put(route('acp.commerce.coupons.update', props.coupon.id), { preserveScroll: true });
    } else {
        form.post(route('acp.commerce.coupons.store'));
    }
};

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-5">
            <h3 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">The code</h3>

            <div class="grid gap-2">
                <Label for="coupon-code">Code</Label>
                <div class="flex gap-2">
                    <Input
                        id="coupon-code"
                        v-model="form.code"
                        maxlength="40"
                        autocomplete="off"
                        spellcheck="false"
                        class="max-w-xs font-mono uppercase"
                        placeholder="SPRING20"
                        :disabled="!canSave"
                        :aria-invalid="!!errors.code || undefined"
                    />
                    <Button v-if="canSave" type="button" variant="outline" @click="form.code = generateCouponCode()">
                        <Shuffle class="size-4" /> Generate
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    What customers type at checkout. Letters, numbers, hyphens and underscores; capitals and small letters mean the same.
                </p>
                <InputError :message="errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="coupon-description">Note <span class="text-muted-foreground">(optional)</span></Label>
                <Input
                    id="coupon-description"
                    v-model="form.description"
                    maxlength="255"
                    placeholder="Spring newsletter"
                    :disabled="!canSave"
                    :aria-invalid="!!errors.description || undefined"
                />
                <p class="text-xs text-muted-foreground">For your own reference. Customers only ever see the code.</p>
                <InputError :message="errors.description" />
            </div>

            <div class="flex items-center gap-3">
                <Switch id="coupon-active" v-model="form.is_active" :disabled="!canSave" />
                <Label for="coupon-active" class="font-normal">Switched on</Label>
            </div>
        </section>

        <section class="space-y-5">
            <h3 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">What it does</h3>

            <div class="grid gap-2 sm:max-w-xs">
                <Label for="coupon-type">Kind</Label>
                <select id="coupon-type" v-model="form.type" :class="selectClass" :disabled="!canSave">
                    <option value="percent">Percentage off</option>
                    <option value="fixed">Amount off</option>
                    <option value="free_shipping">Free shipping</option>
                </select>
                <InputError :message="errors.type" />
            </div>

            <div v-if="hasValue" class="grid gap-2">
                <Label for="coupon-value">{{ isPercent ? 'Percentage off' : `Amount off (${currency})` }}</Label>
                <div class="flex items-center gap-2">
                    <Input
                        id="coupon-value"
                        v-model="form.value"
                        inputmode="decimal"
                        class="w-32"
                        :placeholder="isPercent ? '10' : '5.00'"
                        :disabled="!canSave"
                        :aria-invalid="!!errors.value || undefined"
                    />
                    <span class="text-sm text-muted-foreground">{{ isPercent ? '%' : currency }}</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    <template v-if="isPercent">Taken off the items it applies to. Up to four decimals, for example 12.5.</template>
                    <template v-else>Taken off the items it applies to, never more than they cost. In the shop's currency.</template>
                </p>
                <InputError :message="errors.value" />
            </div>
            <p v-else class="text-sm text-muted-foreground">
                The shipping charge is waived (and the tax on it). Items keep their price. It only works on orders that are shipped.
            </p>
        </section>

        <section class="space-y-5">
            <h3 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">When it can be used</h3>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="coupon-starts">Starts <span class="text-muted-foreground">(optional)</span></Label>
                    <Input id="coupon-starts" v-model="form.starts_at" type="datetime-local" :disabled="!canSave" />
                    <InputError :message="errors.starts_at" />
                </div>
                <div class="grid gap-2">
                    <Label for="coupon-ends">Ends <span class="text-muted-foreground">(optional)</span></Label>
                    <Input id="coupon-ends" v-model="form.ends_at" type="datetime-local" :disabled="!canSave" />
                    <InputError :message="errors.ends_at" />
                </div>
            </div>

            <div class="grid gap-2 sm:max-w-xs">
                <Label for="coupon-minimum">Minimum spend ({{ currency }}) <span class="text-muted-foreground">(optional)</span></Label>
                <Input
                    id="coupon-minimum"
                    v-model="form.minimum_subtotal"
                    inputmode="decimal"
                    placeholder="50.00"
                    :disabled="!canSave"
                    :aria-invalid="!!errors.minimum_subtotal || undefined"
                />
                <p class="text-xs text-muted-foreground">
                    Counted on the items the code applies to, before the discount.
                    <template v-if="!limitsItems">For free shipping that is the whole cart.</template>
                </p>
                <InputError :message="errors.minimum_subtotal" />
            </div>

            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="coupon-max">Total uses <span class="text-muted-foreground">(optional)</span></Label>
                    <Input
                        id="coupon-max"
                        v-model="form.max_redemptions"
                        inputmode="numeric"
                        placeholder="Unlimited"
                        :disabled="!canSave"
                        :aria-invalid="!!errors.max_redemptions || undefined"
                    />
                    <p class="text-xs text-muted-foreground">Orders waiting for payment hold a use until they are paid or expire.</p>
                    <InputError :message="errors.max_redemptions" />
                </div>
                <div class="grid gap-2">
                    <Label for="coupon-per-customer">Uses per customer <span class="text-muted-foreground">(optional)</span></Label>
                    <Input
                        id="coupon-per-customer"
                        v-model="form.max_redemptions_per_customer"
                        inputmode="numeric"
                        placeholder="Unlimited"
                        :disabled="!canSave"
                        :aria-invalid="!!errors.max_redemptions_per_customer || undefined"
                    />
                    <p class="text-xs text-muted-foreground">
                        By account, and by the email on the order for guests: someone using a different email is a different customer.
                    </p>
                    <InputError :message="errors.max_redemptions_per_customer" />
                </div>
            </div>
        </section>

        <section v-if="limitsItems" class="space-y-5">
            <h3 class="text-sm font-semibold tracking-wide text-muted-foreground uppercase">What it applies to</h3>
            <p class="text-sm text-muted-foreground">
                Leave both empty to apply to everything in the cart. Otherwise only the products picked and the products in the categories ticked are
                discounted.
            </p>

            <div v-if="categories.length" class="grid gap-2">
                <Label>Categories</Label>
                <div class="grid max-h-48 gap-2 overflow-y-auto rounded-md border p-3 sm:grid-cols-2">
                    <label v-for="category in categories" :key="category.id" class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="form.category_ids.includes(category.id)"
                            :disabled="!canSave"
                            @update:model-value="(on) => toggleCategory(category.id, on)"
                        />
                        {{ category.name }}
                    </label>
                </div>
                <InputError :message="errors.category_ids" />
            </div>

            <div class="grid gap-2">
                <Label for="coupon-product-search">Products</Label>

                <ul v-if="chosen.length" class="flex flex-wrap gap-2">
                    <li
                        v-for="product in chosen"
                        :key="product.id"
                        class="flex items-center gap-1 rounded-full border bg-muted/40 py-1 pr-1 pl-3 text-sm"
                    >
                        {{ product.name }}
                        <button
                            v-if="canSave"
                            type="button"
                            class="rounded-full p-1 text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            :aria-label="`Remove ${product.name}`"
                            @click="removeProduct(product.id)"
                        >
                            <X class="size-3.5" />
                        </button>
                    </li>
                </ul>

                <template v-if="canSave">
                    <Input
                        id="coupon-product-search"
                        v-model="search"
                        type="search"
                        autocomplete="off"
                        placeholder="Search products by name"
                        class="sm:max-w-sm"
                        @input="onSearch"
                    />
                    <ul v-if="found.length" class="max-w-sm divide-y rounded-md border text-sm" role="listbox" aria-label="Matching products">
                        <li v-for="product in found" :key="product.id">
                            <button
                                type="button"
                                class="w-full px-3 py-2 text-left hover:bg-muted focus-visible:bg-muted focus-visible:outline-hidden"
                                @click="addProduct(product)"
                            >
                                {{ product.name }}
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="search.trim() && !searching" class="text-xs text-muted-foreground">No other products match.</p>
                </template>
                <InputError :message="errors.product_ids" />
            </div>
        </section>

        <div v-if="canSave" class="flex flex-wrap gap-2">
            <Button type="submit" :disabled="form.processing">{{ editing ? 'Save changes' : 'Create code' }}</Button>
            <Button v-if="!editing" type="button" variant="outline" as-child>
                <Link :href="route('acp.commerce.coupons.index')">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
