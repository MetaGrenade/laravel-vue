<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';

withDefaults(
    defineProps<{
        /** Hide the wordmark and show only the brand mark. */
        iconOnly?: boolean;
    }>(),
    { iconOnly: false },
);

const page = usePage<SharedData>();
</script>

<template>
    <span class="flex items-center gap-2.5">
        <span class="relative flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-primary-foreground shadow-xs">
            <AppLogoIcon class="size-[1.1rem]" />
            <span class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full bg-highlight ring-2 ring-background" aria-hidden="true" />
        </span>
        <span v-if="!iconOnly" class="truncate text-[0.95rem] font-semibold tracking-tight">{{ page.props.name }}</span>
    </span>
</template>
