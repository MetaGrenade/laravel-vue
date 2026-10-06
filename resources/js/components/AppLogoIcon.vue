<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Anvil } from '@lucide/vue';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <Anvil :class="cn('size-5', props.class)" aria-hidden="true" />
</template>
