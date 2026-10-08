<script setup lang="ts">
import ProductDetailsForm from '@/components/commerce/admin/ProductDetailsForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { CatalogueLookup } from '@/types/catalogue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    brands: CatalogueLookup[];
    categories: CatalogueLookup[];
    tags: CatalogueLookup[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Products', href: route('acp.commerce.products.index') },
    { title: 'New product', href: route('acp.commerce.products.create') },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="New product" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight">New product</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Start with the details. You will add the price, options and stock on the next page.
                    </p>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <ProductDetailsForm :brands="brands" :categories="categories" :tags="tags" :can-save="true" />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
