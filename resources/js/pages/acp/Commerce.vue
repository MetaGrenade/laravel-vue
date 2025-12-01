<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import type { BreadcrumbItem } from '@/types';
import Input from '@/components/ui/input/Input.vue';
import Button from '@/components/ui/button/Button.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';

type Product = {
    id: number;
    name: string;
    slug: string;
    is_active: boolean;
    variants_count: number;
    prices_count: number;
    inventory_items_count: number;
};

type Variant = {
    id: number;
    name: string;
    sku: string;
    is_default: boolean;
    product: { id: number; name: string };
    inventory_items_count: number;
};

type Price = {
    id: number;
    priceable_type: string;
    priceable_id: number;
    currency: string;
    amount: string;
    compare_at_amount: string | null;
    is_active: boolean;
};

type Inventory = {
    id: number;
    product_id: number;
    product_variant_id: number | null;
    quantity: number;
    allow_backorder: boolean;
    product?: { id: number; name: string };
    variant?: { id: number; name: string } | null;
};

type Order = {
    id: number;
    user_id: number | null;
    status: string;
    currency: string;
    grand_total: string;
    created_at: string | null;
    user?: { id: number; nickname: string; email: string } | null;
};

const props = defineProps<{
    products: Product[];
    variants: Variant[];
    prices: Price[];
    inventory: Inventory[];
    orders: Order[];
    metrics: {
        products: { total: number; active: number; options: number; variants: number };
        pricing: { active_prices: number; total_prices: number };
        inventory: { items: number; on_hand: number; backorderable: number };
        orders: { total: number; processing: number; completed: number; cancelled: number; revenue: number };
    };
    orderStatusBreakdown: Record<string, number>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: '/acp/commerce' },
];

const productForm = useForm({
    name: '',
    slug: '',
    description: '',
    is_active: true,
});

const optionForm = useForm({
    product_id: props.products[0]?.id ?? null,
    name: '',
    display_name: '',
    position: 0,
});

const optionValueForm = useForm({
    product_option_id: null as number | null,
    value: '',
    position: 0,
});

const variantForm = useForm({
    product_id: props.products[0]?.id ?? null,
    name: '',
    sku: '',
    option_values: [] as string[],
    is_default: false,
});

const variantOptionsText = ref('');

const priceForm = useForm({
    priceable_type: 'App\\Models\\Product',
    priceable_id: props.products[0]?.id ?? null,
    currency: 'USD',
    amount: 0,
    compare_at_amount: null as number | null,
    is_active: true,
});

const priceTargetSelection = ref('');

const inventoryForm = useForm({
    product_id: props.products[0]?.id ?? null,
    product_variant_id: props.variants[0]?.id ?? null,
    quantity: 0,
    allow_backorder: false,
});

const priceableTargets = computed(() => {
    const productTargets = props.products.map(product => ({
        id: product.id,
        label: `Product • ${product.name}`,
        type: 'App\\Models\\Product',
    }));

    const variantTargets = props.variants.map(variant => ({
        id: variant.id,
        label: `Variant • ${variant.name}`,
        type: 'App\\Models\\ProductVariant',
    }));

    return [...productTargets, ...variantTargets];
});

const productLookup = computed(() =>
    props.products.reduce<Record<number, string>>((carry, product) => {
        carry[product.id] = product.name;
        return carry;
    }, {}),
);

const variantLookup = computed(() =>
    props.variants.reduce<Record<number, string>>((carry, variant) => {
        carry[variant.id] = variant.name;
        return carry;
    }, {}),
);

const resolvePriceOwner = (price: Price) => {
    if (price.priceable_type.endsWith('ProductVariant')) {
        return variantLookup.value[price.priceable_id] ?? `Variant #${price.priceable_id}`;
    }

    return productLookup.value[price.priceable_id] ?? `Product #${price.priceable_id}`;
};

watch(
    priceableTargets,
    targets => {
        if (!priceTargetSelection.value && targets[0]) {
            priceTargetSelection.value = `${targets[0].type}:${targets[0].id}`;
        }
    },
    { immediate: true },
);

watch(
    priceTargetSelection,
    value => {
        const [type, id] = value.split(':');
        priceForm.priceable_type = type ?? 'App\\Models\\Product';
        priceForm.priceable_id = id ? Number.parseInt(id, 10) : null;
    },
    { immediate: true },
);

const updateVariantOptions = () => {
    variantForm.option_values = variantOptionsText.value
        .split(',')
        .map(value => value.trim())
        .filter(Boolean);
};

const submitProduct = () => {
    productForm.post(route('acp.commerce.products.store'), {
        preserveScroll: true,
        onSuccess: () => productForm.reset('name', 'slug', 'description'),
    });
};

const submitOption = () => {
    optionForm.post(route('acp.commerce.options.store'), {
        preserveScroll: true,
        onSuccess: () => optionForm.reset('name', 'display_name', 'position'),
    });
};

const submitOptionValue = () => {
    optionValueForm.post(route('acp.commerce.option-values.store'), {
        preserveScroll: true,
        onSuccess: () => optionValueForm.reset('value', 'position'),
    });
};

const submitVariant = () => {
    variantForm.post(route('acp.commerce.variants.store'), {
        preserveScroll: true,
        onSuccess: () => {
            variantForm.reset('name', 'sku', 'option_values', 'is_default');
            variantOptionsText.value = '';
        },
    });
};

const submitPrice = () => {
    priceForm.post(route('acp.commerce.prices.store'), {
        preserveScroll: true,
        onSuccess: () => priceForm.reset('amount', 'compare_at_amount'),
    });
};

const submitInventory = () => {
    inventoryForm.post(route('acp.commerce.inventory.store'), {
        preserveScroll: true,
        onSuccess: () => inventoryForm.reset('quantity', 'allow_backorder'),
    });
};

const statusLabels: Record<string, string> = {
    processing: 'Processing',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

const currencyFormatter = new Intl.NumberFormat(undefined, { style: 'currency', currency: 'USD' });

const formatCurrency = (value: number | string | null) => {
    const numericValue = typeof value === 'string' ? Number.parseFloat(value) : value;
    return currencyFormatter.format(numericValue ?? 0);
};

const formatStatus = (status: string) => statusLabels[status] ?? status;
</script>

<template>
    <Head title="Commerce ACP" />

    <AppLayout :breadcrumbs="breadcrumbs" title="Commerce" description="Manage products, pricing, and orders." sticky>
        <AdminLayout>
            <div class="space-y-6 w-full">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <Card>
                        <CardHeader>
                            <CardDescription>Products</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.products.total }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">{{ metrics.products.active }} active • {{ metrics.products.options }} options • {{ metrics.products.variants }} variants</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Pricing</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.pricing.total_prices }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">{{ metrics.pricing.active_prices }} active price points</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Inventory</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.inventory.items }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">{{ metrics.inventory.on_hand }} units on hand • {{ metrics.inventory.backorderable }} backorderable</p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Orders</CardDescription>
                            <CardTitle class="text-3xl">{{ metrics.orders.total }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">{{ metrics.orders.processing }} processing • {{ metrics.orders.completed }} completed • {{ formatCurrency(metrics.orders.revenue) }} revenue</p>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <Card class="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Product catalog</CardTitle>
                            <CardDescription>Active products and their relationships.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Name</TableHead>
                                        <TableHead>Slug</TableHead>
                                        <TableHead>Variants</TableHead>
                                        <TableHead>Prices</TableHead>
                                        <TableHead>Inventory</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="product in products" :key="product.id">
                                        <TableCell class="font-semibold">
                                            <div class="flex items-center gap-2">
                                                <span>{{ product.name }}</span>
                                                <Badge v-if="!product.is_active" variant="secondary">Draft</Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell>{{ product.slug }}</TableCell>
                                        <TableCell>{{ product.variants_count }}</TableCell>
                                        <TableCell>{{ product.prices_count }}</TableCell>
                                        <TableCell>{{ product.inventory_items_count }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Create product</CardTitle>
                            <CardDescription>Quickly seed new catalog items.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <Input v-model="productForm.name" placeholder="Product name" />
                            <Input v-model="productForm.slug" placeholder="Slug" />
                            <Input v-model="productForm.description" placeholder="Description" />
                            <label class="flex items-center space-x-2 text-sm text-muted-foreground">
                                <input v-model="productForm.is_active" type="checkbox" />
                                <span>Active</span>
                            </label>
                            <Button class="w-full" :disabled="productForm.processing" @click="submitProduct">
                                Save product
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Options</CardTitle>
                            <CardDescription>Capture configurable product choices.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <select v-model="optionForm.product_id" class="w-full rounded-md border bg-background px-3 py-2">
                                <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option>
                            </select>
                            <Input v-model="optionForm.name" placeholder="Option key (e.g. size)" />
                            <Input v-model="optionForm.display_name" placeholder="Display name" />
                            <Input v-model.number="optionForm.position" type="number" placeholder="Position" />
                            <Button class="w-full" :disabled="optionForm.processing" @click="submitOption">Add option</Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Option values</CardTitle>
                            <CardDescription>Populate allowed values per option.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <Input v-model.number="optionValueForm.product_option_id" type="number" placeholder="Option ID" />
                            <Input v-model="optionValueForm.value" placeholder="Value" />
                            <Input v-model.number="optionValueForm.position" type="number" placeholder="Position" />
                            <Button class="w-full" :disabled="optionValueForm.processing" @click="submitOptionValue">Add value</Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Variants</CardTitle>
                            <CardDescription>Attach SKUs to products.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <select v-model="variantForm.product_id" class="w-full rounded-md border bg-background px-3 py-2">
                                <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option>
                            </select>
                            <Input v-model="variantForm.name" placeholder="Variant name" />
                            <Input v-model="variantForm.sku" placeholder="SKU" />
                            <Input v-model="variantOptionsText" placeholder="Option values (comma separated)" @input="updateVariantOptions" />
                            <label class="flex items-center space-x-2 text-sm text-muted-foreground">
                                <input v-model="variantForm.is_default" type="checkbox" />
                                <span>Default</span>
                            </label>
                            <Button class="w-full" :disabled="variantForm.processing" @click="submitVariant">Add variant</Button>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Pricing</CardTitle>
                            <CardDescription>Attach prices to products or variants.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <select v-model="priceTargetSelection" class="w-full rounded-md border bg-background px-3 py-2">
                                <option v-for="target in priceableTargets" :key="`${target.type}-${target.id}`" :value="`${target.type}:${target.id}`">
                                    {{ target.label }}
                                </option>
                            </select>
                            <Input v-model="priceForm.currency" placeholder="Currency" />
                            <Input v-model.number="priceForm.amount" type="number" placeholder="Amount" />
                            <Input v-model.number="priceForm.compare_at_amount" type="number" placeholder="Compare at" />
                            <label class="flex items-center space-x-2 text-sm text-muted-foreground">
                                <input v-model="priceForm.is_active" type="checkbox" />
                                <span>Active</span>
                            </label>
                            <Button class="w-full" :disabled="priceForm.processing" @click="submitPrice">Add price</Button>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Inventory</CardTitle>
                            <CardDescription>Track stock levels per item or SKU.</CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-3">
                            <select v-model="inventoryForm.product_id" class="w-full rounded-md border bg-background px-3 py-2">
                                <option v-for="product in products" :key="product.id" :value="product.id">{{ product.name }}</option>
                            </select>
                            <select v-model="inventoryForm.product_variant_id" class="w-full rounded-md border bg-background px-3 py-2">
                                <option :value="null">No variant</option>
                                <option v-for="variant in variants" :key="variant.id" :value="variant.id">{{ variant.name }}</option>
                            </select>
                            <Input v-model.number="inventoryForm.quantity" type="number" placeholder="Quantity" />
                            <label class="flex items-center space-x-2 text-sm text-muted-foreground">
                                <input v-model="inventoryForm.allow_backorder" type="checkbox" />
                                <span>Allow backorder</span>
                            </label>
                            <Button class="w-full" :disabled="inventoryForm.processing" @click="submitInventory">Add inventory</Button>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Price book</CardTitle>
                            <CardDescription>Latest price entries across the catalog.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Owner</TableHead>
                                        <TableHead>Currency</TableHead>
                                        <TableHead>Amount</TableHead>
                                        <TableHead>Compare at</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="price in prices" :key="price.id">
                                        <TableCell>{{ resolvePriceOwner(price) }}</TableCell>
                                        <TableCell>{{ price.currency }}</TableCell>
                                        <TableCell>{{ formatCurrency(price.amount) }}</TableCell>
                                        <TableCell>{{ price.compare_at_amount ? formatCurrency(price.compare_at_amount) : '—' }}</TableCell>
                                        <TableCell>
                                            <Badge :variant="price.is_active ? 'outline' : 'secondary'">{{ price.is_active ? 'Active' : 'Inactive' }}</Badge>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Inventory ledger</CardTitle>
                            <CardDescription>Recent inventory items and availability.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Product</TableHead>
                                        <TableHead>Variant</TableHead>
                                        <TableHead>Qty</TableHead>
                                        <TableHead>Backorder</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="item in inventory" :key="item.id">
                                        <TableCell>{{ item.product?.name ?? `Product #${item.product_id}` }}</TableCell>
                                        <TableCell>{{ item.variant?.name ?? '—' }}</TableCell>
                                        <TableCell>{{ item.quantity }}</TableCell>
                                        <TableCell>
                                            <Badge :variant="item.allow_backorder ? 'outline' : 'secondary'">{{ item.allow_backorder ? 'Allowed' : 'No' }}</Badge>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent orders</CardTitle>
                            <CardDescription>Live view of commerce performance.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>ID</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Customer</TableHead>
                                        <TableHead>Total</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="order in orders" :key="order.id">
                                        <TableCell>#{{ order.id }}</TableCell>
                                        <TableCell><Badge variant="outline">{{ formatStatus(order.status) }}</Badge></TableCell>
                                        <TableCell>{{ order.user?.nickname ?? 'Guest' }}</TableCell>
                                        <TableCell>{{ formatCurrency(order.grand_total) }}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Status breakdown</CardTitle>
                            <CardDescription>Distribution of orders by state.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul class="space-y-2 text-sm text-muted-foreground">
                                <li v-for="(count, status) in orderStatusBreakdown" :key="status" class="flex items-center justify-between">
                                    <span class="font-medium text-foreground">{{ formatStatus(status) }}</span>
                                    <span>{{ count }} orders</span>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
