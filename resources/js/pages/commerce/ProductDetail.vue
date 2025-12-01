<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';

interface Category {
    id: number;
    name: string;
    slug: string;
}

interface Tag {
    id: number;
    name: string;
    slug: string;
}

interface Brand {
    id: number;
    name: string;
    slug: string;
}

interface OptionValue {
    id: number;
    value: string;
}

interface Option {
    id: number;
    display_name: string;
    values: OptionValue[];
}

interface Variant {
    id: number;
    name: string;
    sku?: string | null;
    option_values?: Record<string, string> | null;
}

interface Price {
    id: number;
    currency: string;
    amount: string;
    compare_at_amount?: string | null;
}

interface InventoryItem {
    id: number;
    quantity: number;
    allow_backorder: boolean;
}

interface Product {
    id: number;
    name: string;
    slug: string;
    description?: string | null;
    options: Option[];
    variants: Variant[];
    prices: Price[];
    inventory_items: InventoryItem[];
    brand?: Brand | null;
    categories: Category[];
    tags: Tag[];
}

interface Props {
    product: Product;
}

const props = defineProps<Props>();
</script>

<template>
    <AppLayout>
        <Head :title="props.product.name" />

        <div class="space-y-6">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm text-muted-foreground uppercase">Product detail</p>
                    <h1 class="text-3xl font-bold tracking-tight">{{ props.product.name }}</h1>
                    <div v-if="props.product.brand" class="mt-1 flex items-center gap-2 text-sm text-muted-foreground">
                        <Badge variant="outline">{{ props.product.brand.name }}</Badge>
                    </div>
                </div>
                <Badge v-if="props.product.prices.length" variant="secondary">Pricing ready</Badge>
                <Badge v-else variant="outline">Needs pricing</Badge>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div>
                        <h3 class="text-sm font-semibold text-muted-foreground">Description</h3>
                        <p class="mt-1 text-sm text-foreground">{{ props.product.description || 'Add marketing copy to this product.' }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-for="category in props.product.categories" :key="category.id" variant="secondary">
                            {{ category.name }}
                        </Badge>
                        <Badge v-for="tag in props.product.tags" :key="tag.id" variant="outline">
                            {{ tag.name }}
                        </Badge>
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-muted-foreground">Options</h3>
                        <div v-if="props.product.options.length" class="mt-2 space-y-2">
                            <div v-for="option in props.product.options" :key="option.id" class="rounded border p-3">
                                <div class="font-medium">{{ option.display_name }}</div>
                                <p class="text-sm text-muted-foreground">{{ option.values.map((value) => value.value).join(', ') }}</p>
                            </div>
                        </div>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Define options like size or color to manage variants.</p>
                    </div>

                    <Separator />

                    <div>
                        <h3 class="text-sm font-semibold text-muted-foreground">Variants</h3>
                        <div v-if="props.product.variants.length" class="mt-2 space-y-2">
                            <div v-for="variant in props.product.variants" :key="variant.id" class="rounded border p-3">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <div class="font-medium">{{ variant.name }}</div>
                                        <p class="text-sm text-muted-foreground">SKU: {{ variant.sku || 'TBD' }}</p>
                                    </div>
                                    <Badge variant="outline">{{ variant.option_values ? 'Customizable' : 'Base' }}</Badge>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Add variants to sell different options and track inventory.</p>
                    </div>

                    <Separator />

                    <div>
                        <h3 class="text-sm font-semibold text-muted-foreground">Inventory</h3>
                        <div v-if="props.product.inventory_items.length" class="mt-2 space-y-2">
                            <div v-for="item in props.product.inventory_items" :key="item.id" class="rounded border p-3">
                                <div class="flex items-center justify-between">
                                    <div class="font-medium">Stock: {{ item.quantity }}</div>
                                    <Badge variant="secondary">Backorder: {{ item.allow_backorder ? 'Allowed' : 'No' }}</Badge>
                                </div>
                            </div>
                        </div>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Connect inventory to keep availability in sync.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
