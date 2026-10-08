<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import OptionsSection from '@/components/commerce/admin/OptionsSection.vue';
import PriceSection from '@/components/commerce/admin/PriceSection.vue';
import ProductDetailsForm from '@/components/commerce/admin/ProductDetailsForm.vue';
import StockSection from '@/components/commerce/admin/StockSection.vue';
import VariantsSection from '@/components/commerce/admin/VariantsSection.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { CatalogueLookup, Capabilities, OptionRow, PriceRow, ProductDetails, Readiness, StockRow, VariantRow } from '@/types/catalogue';
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, ExternalLink, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';

const props = defineProps<{
    product: ProductDetails;
    brands: CatalogueLookup[];
    categories: CatalogueLookup[];
    tags: CatalogueLookup[];
    currency: string;
    prices: PriceRow[];
    stock: StockRow | null;
    options: OptionRow[];
    variants: VariantRow[];
    missing_variants: number;
    variant_limit: number;
    readiness: Readiness[];
    deletion_block: string | null;
    low_stock_threshold: number;
    can: Capabilities;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Products', href: route('acp.commerce.products.index') },
    { title: props.product.name, href: route('acp.commerce.products.edit', props.product.id) },
]);

const ready = computed(() => props.readiness.every((check) => check.ok));
const hasVariants = computed(() => props.variants.length > 0);
const productHasPrice = computed(() => props.prices.some((price) => price.usable));

const tab = ref('details');

const deleteOpen = ref(false);

const remove = () => {
    router.delete(route('acp.commerce.products.destroy', props.product.id), {
        onFinish: () => {
            deleteOpen.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="product.name" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-2">
                        <h2 class="text-xl font-semibold tracking-tight">{{ product.name }}</h2>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="product.is_active ? 'default' : 'secondary'">{{ product.is_active ? 'On sale' : 'Archived' }}</Badge>
                            <Badge v-if="!ready" variant="outline">Not ready to sell</Badge>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button v-if="product.shop_url" variant="outline" size="sm" as-child>
                            <a :href="product.shop_url" target="_blank" rel="noopener noreferrer">View in shop <ExternalLink class="size-3.5" /></a>
                        </Button>
                        <Button
                            v-if="can.delete"
                            variant="outline"
                            size="sm"
                            class="text-destructive"
                            :disabled="deletion_block !== null"
                            @click="deleteOpen = true"
                        >
                            <Trash2 class="size-4" /> Delete
                        </Button>
                    </div>
                </div>

                <Alert v-if="can.delete && deletion_block" variant="default">
                    <CircleAlert />
                    <AlertDescription>{{ deletion_block }}</AlertDescription>
                </Alert>

                <Card>
                    <CardHeader class="pb-3">
                        <CardTitle class="text-base">Ready to sell?</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ul class="space-y-1.5 text-sm">
                            <li v-for="check in readiness" :key="check.text" class="flex items-start gap-2">
                                <CircleCheck v-if="check.ok" class="mt-0.5 size-4 shrink-0 text-success" />
                                <CircleX v-else class="mt-0.5 size-4 shrink-0 text-destructive" />
                                <span :class="check.ok ? 'text-muted-foreground' : ''">{{ check.text }}</span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Tabs v-model="tab" class="space-y-4">
                    <TabsList class="max-w-full justify-start overflow-x-auto">
                        <TabsTrigger value="details">Details</TabsTrigger>
                        <TabsTrigger value="pricing">Price and stock</TabsTrigger>
                        <TabsTrigger value="variants">
                            Options and variants<span v-if="hasVariants" class="ml-1.5 text-muted-foreground">{{ variants.length }}</span>
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="details">
                        <Card>
                            <CardContent class="pt-6">
                                <ProductDetailsForm :product="product" :brands="brands" :categories="categories" :tags="tags" :can-save="can.edit" />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="pricing" class="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Price</CardTitle>
                                <CardDescription>
                                    Charged in {{ currency }}, before tax.
                                    <template v-if="hasVariants">Variants without a price of their own are sold at this price.</template>
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <PriceSection
                                    :prices="prices"
                                    :store-url="route('acp.commerce.prices.store', product.id)"
                                    :currency="currency"
                                    :can="can"
                                    id-prefix="product"
                                    :empty-hint="
                                        hasVariants
                                            ? 'No product price. Each variant needs a price of its own.'
                                            : 'No price yet, so this cannot be bought.'
                                    "
                                />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Stock</CardTitle>
                                <CardDescription>
                                    <template v-if="hasVariants">
                                        This product has variants, so track stock for each one from the Options and variants tab. Stock set here is
                                        only used for the product itself.
                                    </template>
                                    <template v-else>How many you have. Orders take from this count.</template>
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <StockSection
                                    :stock="stock"
                                    :product-id="product.id"
                                    :variant-id="null"
                                    :threshold="low_stock_threshold"
                                    :can="can"
                                    id-prefix="product"
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="variants" class="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Options</CardTitle>
                                <CardDescription>The choices a customer makes, such as Size or Colour.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <OptionsSection :product-id="product.id" :options="options" :can="can" />
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Variants</CardTitle>
                                <CardDescription>What is actually sold: each has its own SKU, price and stock.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <VariantsSection
                                    :product-id="product.id"
                                    :variants="variants"
                                    :options="options"
                                    :product-has-price="productHasPrice"
                                    :currency="currency"
                                    :threshold="low_stock_threshold"
                                    :missing="missing_variants"
                                    :limit="variant_limit"
                                    :can="can"
                                />
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>

            <ConfirmDialog
                :open="deleteOpen"
                :title="`Delete ${product.name}?`"
                description="Its variants, options, prices and stock are deleted with it, and it is taken out of any cart. This cannot be undone."
                confirm-label="Delete product"
                @update:open="(value) => (deleteOpen = value)"
                @confirm="remove"
                @cancel="deleteOpen = false"
            />
        </AdminLayout>
    </AppLayout>
</template>
