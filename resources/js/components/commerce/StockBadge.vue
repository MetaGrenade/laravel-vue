<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { StorefrontStock } from '@/types/commerce';
import { computed } from 'vue';

const props = defineProps<{ status: StorefrontStock }>();

const label = computed(() => {
    switch (props.status) {
        case 'low':
            return 'Only a few left';
        case 'out':
            return 'Out of stock';
        case 'backorder':
            return 'Available to order';
        default:
            return 'In stock';
    }
});

const variant = computed(() => {
    switch (props.status) {
        case 'out':
            return 'destructive' as const;
        case 'low':
        case 'backorder':
            return 'highlight' as const;
        default:
            return 'secondary' as const;
    }
});
</script>

<template>
    <Badge :variant="variant">{{ label }}</Badge>
</template>
