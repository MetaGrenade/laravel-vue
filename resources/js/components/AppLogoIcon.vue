<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { Bomb } from 'lucide-vue-next';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<template>
    <Bomb class="h-5 w-5 text-[#8b5a00] dark:text-[#f3d29e]" />
</template>
