<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { cn } from '@/lib/utils';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Hide the wordmark and show only the brand mark. */
        iconOnly?: boolean;
        /** Wordmark height. */
        size?: 'sm' | 'md' | 'lg' | 'xl';
    }>(),
    { iconOnly: false, size: 'sm' },
);

const page = usePage<SharedData>();

const wordmarkClass = computed(() =>
    cn(
        'w-auto',
        props.size === 'xl' && 'h-14 sm:h-16',
        props.size === 'lg' && 'h-12 sm:h-14',
        props.size === 'md' && 'h-10',
        props.size === 'sm' && 'h-8',
    ),
);

const markClass = computed(() =>
    cn(
        props.size === 'xl' && 'size-14 sm:size-16',
        props.size === 'lg' && 'size-12 sm:size-14',
        props.size === 'md' && 'size-10',
        props.size === 'sm' && 'size-8',
    ),
);
</script>

<template>
    <span class="flex items-center">
        <template v-if="iconOnly">
            <AppLogoIcon :class="markClass" />
            <span class="sr-only">{{ page.props.name }}</span>
        </template>
        <template v-else>
            <img
                src="/brand/logo-light.png"
                :alt="page.props.name"
                width="280"
                height="56"
                decoding="async"
                :class="cn(wordmarkClass, 'dark:hidden')"
            />
            <img
                src="/brand/logo-dark.png"
                :alt="page.props.name"
                width="280"
                height="56"
                decoding="async"
                :class="cn(wordmarkClass, 'hidden dark:block')"
            />
        </template>
    </span>
</template>
