<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <img
        src="/brand/mark.png"
        alt=""
        width="32"
        height="32"
        decoding="async"
        :class="cn('size-5 object-contain', props.class)"
        aria-hidden="true"
    />
</template>
