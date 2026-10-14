<script setup lang="ts">
import { Star } from '@lucide/vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** From 0 to 5; a part of a star is drawn as a part. */
        value: number;
        size?: 'sm' | 'md' | 'lg';
    }>(),
    { size: 'md' },
);

const percent = computed(() => Math.max(0, Math.min(5, props.value)) * 20);

const starClass = computed(() => ({ sm: 'size-3.5', md: 'size-4', lg: 'size-6' })[props.size]);
</script>

<template>
    <span class="relative inline-flex align-middle" role="img" :aria-label="`Rated ${value} out of 5`">
        <span class="flex gap-0.5 text-muted-foreground/30" aria-hidden="true">
            <Star v-for="n in 5" :key="n" class="shrink-0 fill-current" :class="starClass" />
        </span>
        <span class="absolute inset-y-0 left-0 flex gap-0.5 overflow-hidden text-warning" :style="{ width: `${percent}%` }" aria-hidden="true">
            <Star v-for="n in 5" :key="n" class="shrink-0 fill-current" :class="starClass" />
        </span>
    </span>
</template>
