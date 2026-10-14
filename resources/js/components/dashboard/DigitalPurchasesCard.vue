<script setup lang="ts">
import OrderDigitalBadges from '@/components/dashboard/OrderDigitalBadges.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { DigitalPurchase } from '@/types/dashboard';
import { Link } from '@inertiajs/vue3';
import { PackageOpen } from '@lucide/vue';

defineProps<{ items: DigitalPurchase[]; total: number }>();

const boughtOn = (value: string | null) => (value ? new Date(value).toLocaleDateString(undefined, { dateStyle: 'medium' }) : null);

/** What the button says it will do: the thing the customer most likely came for. */
const action = (item: DigitalPurchase) => {
    if (item.keys > 0 && !item.downloads) {
        return item.keys === 1 ? 'View your key' : 'View your keys';
    }

    if (item.keys === 0 && item.keys_waiting > 0 && !item.downloads) {
        return 'Check your order';
    }

    if (item.downloads && item.keys === 0) {
        return 'Get your files';
    }

    return 'Open order';
};
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle class="flex items-center gap-2">
                <PackageOpen class="size-5 text-muted-foreground" aria-hidden="true" /> Your keys and downloads
            </CardTitle>
            <CardDescription>Open the order to copy a licence key or download your files.</CardDescription>
        </CardHeader>
        <CardContent>
            <ul v-if="items.length" class="space-y-3">
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="space-y-2 rounded-lg border border-sidebar-border/60 p-3 text-sm dark:border-sidebar-border"
                >
                    <div>
                        <p class="font-medium break-words">
                            {{ item.description }}<span v-if="item.quantity > 1" class="text-muted-foreground"> × {{ item.quantity }}</span>
                        </p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="item.order_number">Order {{ item.order_number }}</template
                            ><template v-if="boughtOn(item.purchased_at)"> · {{ boughtOn(item.purchased_at) }}</template>
                        </p>
                    </div>
                    <OrderDigitalBadges :digital="{ keys: item.keys, keys_waiting: item.keys_waiting, downloads: item.downloads }" />
                    <Button v-if="item.url" size="sm" as-child class="w-full sm:w-auto">
                        <Link :href="item.url">{{ action(item) }}</Link>
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">Licence keys and downloads you buy appear here, so they are always one click away.</p>
            <p v-if="items.length && total > items.length" class="mt-3 text-xs text-muted-foreground">
                Showing your {{ items.length }} newest of {{ total }}. Older ones are on their orders.
            </p>
        </CardContent>
    </Card>
</template>
