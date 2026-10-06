<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

withDefaults(
    defineProps<{
        /** Hide the wordmark and show only the brand mark. */
        iconOnly?: boolean;
    }>(),
    { iconOnly: false },
);
</script>

<template>
    <span class="flex items-center gap-2.5">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-xs">
            <AppLogoIcon class="size-[1.1rem]" />
        </span>
        <span v-if="!iconOnly" class="truncate text-[0.95rem] font-semibold tracking-tight">MetaForge</span>
    </span>
</template>
