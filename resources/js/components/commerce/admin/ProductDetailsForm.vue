<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { CatalogueLookup, ProductDetails } from '@/types/catalogue';
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    /** Present when editing; absent when creating. */
    product?: ProductDetails;
    brands: CatalogueLookup[];
    categories: CatalogueLookup[];
    tags: CatalogueLookup[];
    canSave: boolean;
}>();

const editing = computed(() => props.product !== undefined);

const form = useForm({
    name: props.product?.name ?? '',
    slug: props.product?.slug ?? '',
    description: props.product?.description ?? '',
    brand_id: (props.product?.brand_id ?? '') as number | '',
    category_ids: [...(props.product?.category_ids ?? [])],
    tag_ids: [...(props.product?.tag_ids ?? [])],
    is_active: props.product?.is_active ?? true,
    requires_shipping: props.product?.requires_shipping ?? true,
    is_taxable: props.product?.is_taxable ?? true,
});

// A new product's address follows its name until it is edited by hand.
const slugEdited = ref(editing.value);

const slugify = (text: string) =>
    text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');

const onName = () => {
    if (!slugEdited.value) {
        form.slug = slugify(form.name);
    }
};

const toggle = (list: number[], id: number, on: boolean | 'indeterminate') => {
    const without = list.filter((existing) => existing !== id);

    return on === true ? [...without, id] : without;
};

const submit = () => {
    form.transform((data) => ({ ...data, brand_id: data.brand_id === '' ? null : data.brand_id }));

    if (props.product) {
        form.put(route('acp.commerce.products.update', props.product.id), { preserveScroll: true });
    } else {
        form.post(route('acp.commerce.products.store'));
    }
};

const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 md:text-sm';
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="product-name">Name</Label>
            <Input
                id="product-name"
                v-model="form.name"
                maxlength="255"
                :disabled="!canSave"
                :aria-invalid="!!form.errors.name || undefined"
                @input="onName"
            />
            <InputError :message="form.errors.name" />
        </div>

        <div class="grid gap-2">
            <Label for="product-slug">Web address</Label>
            <div class="flex items-center gap-2">
                <span class="shrink-0 text-sm text-muted-foreground">/shop/products/</span>
                <Input
                    id="product-slug"
                    v-model="form.slug"
                    maxlength="255"
                    :disabled="!canSave"
                    :aria-invalid="!!form.errors.slug || undefined"
                    @input="slugEdited = true"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Lower-case words joined by hyphens.
                <template v-if="editing">Changing it breaks links that people already have, so only do it if you need to.</template>
                <template v-else>Made from the name unless you change it.</template>
            </p>
            <InputError :message="form.errors.slug" />
        </div>

        <div class="grid gap-2">
            <Label for="product-description">Description</Label>
            <Textarea id="product-description" v-model="form.description" rows="5" maxlength="10000" :disabled="!canSave" />
            <p class="text-xs text-muted-foreground">Plain text, shown on the product page and in search results.</p>
            <InputError :message="form.errors.description" />
        </div>

        <div class="grid gap-2 sm:max-w-xs">
            <Label for="product-brand">Brand</Label>
            <select id="product-brand" v-model="form.brand_id" :class="selectClass" :disabled="!canSave">
                <option value="">No brand</option>
                <option v-for="brand in brands" :key="brand.id" :value="brand.id">{{ brand.name }}</option>
            </select>
            <InputError :message="form.errors.brand_id" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Categories</legend>
                <p v-if="!categories.length" class="text-sm text-muted-foreground">
                    No categories yet. Add some under
                    <Link :href="route('acp.commerce.taxonomy.index')" class="text-primary hover:underline">brands, categories and tags</Link>.
                </p>
                <label v-for="category in categories" :key="category.id" class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.category_ids.includes(category.id)"
                        :disabled="!canSave"
                        @update:model-value="(on) => (form.category_ids = toggle(form.category_ids, category.id, on))"
                    />
                    {{ category.name }}
                </label>
                <InputError :message="form.errors.category_ids" />
            </fieldset>

            <fieldset class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Tags</legend>
                <p v-if="!tags.length" class="text-sm text-muted-foreground">No tags yet.</p>
                <label v-for="tag in tags" :key="tag.id" class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.tag_ids.includes(tag.id)"
                        :disabled="!canSave"
                        @update:model-value="(on) => (form.tag_ids = toggle(form.tag_ids, tag.id, on))"
                    />
                    {{ tag.name }}
                </label>
                <InputError :message="form.errors.tag_ids" />
            </fieldset>
        </div>

        <div class="space-y-4 rounded-lg border p-4">
            <div class="flex items-start gap-3">
                <Switch id="product-active" v-model="form.is_active" :disabled="!canSave" class="mt-0.5" />
                <div class="grid gap-1">
                    <Label for="product-active" class="font-normal">On sale</Label>
                    <p class="text-xs text-muted-foreground">
                        Switched off, the product is archived: it disappears from the shop but keeps its orders and history.
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <Switch id="product-shipping" v-model="form.requires_shipping" :disabled="!canSave" class="mt-0.5" />
                <div class="grid gap-1">
                    <Label for="product-shipping" class="font-normal">Needs shipping</Label>
                    <p class="text-xs text-muted-foreground">
                        Switch off for downloads and other digital goods: no address or shipping charge is needed.
                    </p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <Switch id="product-taxable" v-model="form.is_taxable" :disabled="!canSave" class="mt-0.5" />
                <div class="grid gap-1">
                    <Label for="product-taxable" class="font-normal">Charge tax</Label>
                    <p class="text-xs text-muted-foreground">Tax is added at checkout using the tax rates for where the order goes.</p>
                </div>
            </div>
            <InputError :message="form.errors.requires_shipping || form.errors.is_taxable || form.errors.is_active" />
        </div>

        <div v-if="canSave" class="flex gap-2">
            <Button type="submit" :disabled="form.processing">{{ editing ? 'Save changes' : 'Create product' }}</Button>
        </div>
    </form>
</template>
