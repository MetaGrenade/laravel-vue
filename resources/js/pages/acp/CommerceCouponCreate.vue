<script setup lang="ts">
import CouponForm from '@/components/commerce/admin/CouponForm.vue';
import { Card, CardContent } from '@/components/ui/card';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import type { CatalogueLookup } from '@/types/catalogue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    categories: CatalogueLookup[];
    currency: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Discount codes', href: route('acp.commerce.coupons.index') },
    { title: 'New code', href: route('acp.commerce.coupons.create') },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="New discount code" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight">New discount code</h2>
                    <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                        Customers type the code at checkout. Nothing is used up until an order is placed with it.
                    </p>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <CouponForm :categories="categories" :currency="currency" :can-save="true" />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    </AppLayout>
</template>
