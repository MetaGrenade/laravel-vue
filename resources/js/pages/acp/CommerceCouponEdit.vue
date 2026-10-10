<script setup lang="ts">
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CouponForm from '@/components/commerce/admin/CouponForm.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '@/layouts/acp/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { couponStatusLabels, couponStatusVariant, describeCoupon } from '@/lib/coupons';
import { formatMoney } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import type { CatalogueLookup } from '@/types/catalogue';
import type { CouponDetails, CouponUsage } from '@/types/coupons';
import { Head, router } from '@inertiajs/vue3';
import { CircleAlert, Trash2 } from '@lucide/vue';
import { ref } from 'vue';

const props = defineProps<{
    coupon: CouponDetails;
    usage: CouponUsage;
    categories: CatalogueLookup[];
    currency: string;
    can: { edit: boolean; delete: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Commerce', href: route('acp.commerce.index') },
    { title: 'Discount codes', href: route('acp.commerce.coupons.index') },
    { title: props.coupon.code, href: route('acp.commerce.coupons.edit', props.coupon.id) },
];

const confirmingDelete = ref(false);

const remove = () => {
    router.delete(route('acp.commerce.coupons.destroy', props.coupon.id), {
        onFinish: () => {
            confirmingDelete.value = false;
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`Code ${coupon.code}`" />

        <AdminLayout>
            <div class="space-y-6 pb-4">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="flex flex-wrap items-center gap-3 text-xl font-semibold tracking-tight">
                            <span class="font-mono">{{ coupon.code }}</span>
                            <Badge :variant="couponStatusVariant(coupon.status)">{{ couponStatusLabels[coupon.status] }}</Badge>
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">{{ describeCoupon(coupon.type, coupon.value, coupon.currency) }}</p>
                    </div>
                    <Button v-if="can.delete" variant="outline" class="text-destructive" @click="confirmingDelete = true">
                        <Trash2 class="size-4" /> Delete
                    </Button>
                </div>

                <div
                    v-if="coupon.limits_missing"
                    class="flex items-start gap-3 rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm"
                    role="status"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>
                        <strong>This code applies to nothing.</strong> It was limited to products and categories that have since been deleted. Pick
                        new ones below, or save it with none to apply it to everything in the cart.
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardDescription>Orders using this code</CardDescription>
                            <CardTitle class="text-3xl tabular-nums">{{ usage.orders }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                <template v-if="coupon.max_redemptions !== null">Of {{ coupon.max_redemptions }} allowed. </template>
                                Cancelled orders do not count.
                            </p>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription>Taken off those orders</CardDescription>
                            <CardTitle v-if="usage.discounted.length === 0" class="text-3xl tabular-nums">{{ formatMoney('0', currency) }}</CardTitle>
                            <CardTitle v-else class="space-y-1 text-3xl tabular-nums">
                                <span v-for="total in usage.discounted" :key="total.currency" class="block">{{
                                    formatMoney(total.amount, total.currency)
                                }}</span>
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p class="text-sm text-muted-foreground">
                                Includes free shipping.
                                <template v-if="usage.discounted.length > 1">Orders in different currencies are not added together.</template>
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardContent class="pt-6">
                        <CouponForm :coupon="coupon" :categories="categories" :currency="currency" :can-save="can.edit" />
                    </CardContent>
                </Card>

                <p class="text-xs text-muted-foreground">
                    Changing a code only affects orders placed from now on. Orders already placed keep the code and the discount they were given.
                </p>
            </div>

            <ConfirmDialog
                :open="confirmingDelete"
                title="Delete this code?"
                :description="`${coupon.code} will stop working and carts that have it will lose it. A code that has been used on orders cannot be deleted: switch it off instead.`"
                confirm-label="Delete code"
                @update:open="(open) => !open && (confirmingDelete = false)"
                @confirm="remove"
                @cancel="confirmingDelete = false"
            />
        </AdminLayout>
    </AppLayout>
</template>
