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
    <span class="flex items-center">
        <template v-if="iconOnly">
            <AppLogoIcon class="size-8" />
            <span class="sr-only">{{ page.props.name }}</span>
        </template>
        <template v-else>
            <img
                src="/brand/logo-light.png"
                :alt="page.props.name"
                width="180"
                height="40"
                decoding="async"
                class="h-8 w-auto dark:hidden"
            />
            <img
                src="/brand/logo-dark.png"
                :alt="page.props.name"
                width="180"
                height="40"
                decoding="async"
                class="hidden h-8 w-auto dark:block"
            />
        </template>
    </span>
</template>
