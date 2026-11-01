<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        /** `auto` follows the page theme. `dark` always draws the luminous ridges. */
        tone?: 'auto' | 'dark' | 'light';
    }>(),
    { tone: 'auto' },
);
</script>

<template>
    <div :class="cn('forge-horizon', props.class)" :data-tone="tone" aria-hidden="true">
        <svg viewBox="0 0 1440 280" preserveAspectRatio="none" class="block h-full w-full">
            <path
                class="horizon-violet"
                d="M0 148 C 170 108, 300 196, 480 152 C 690 100, 830 186, 1050 142 C 1230 108, 1340 154, 1440 118 L 1440 280 L 0 280 Z"
            />
            <path
                class="horizon-gold"
                d="M0 206 C 210 164, 360 236, 560 194 C 780 148, 920 226, 1140 184 C 1290 154, 1365 196, 1440 172 L 1440 280 L 0 280 Z"
            />
            <path class="horizon-stroke-soft" d="M0 118 C 220 72, 390 146, 610 98 C 850 46, 990 128, 1210 82 C 1325 56, 1385 92, 1440 68" />
            <path class="horizon-stroke" d="M0 118 C 220 72, 390 146, 610 98 C 850 46, 990 128, 1210 82 C 1325 56, 1385 92, 1440 68" />
            <path
                class="horizon-stroke-gold-soft"
                d="M0 188 C 230 150, 380 214, 590 176 C 820 134, 960 208, 1180 168 C 1310 142, 1375 176, 1440 158"
            />
            <path class="horizon-stroke-gold" d="M0 188 C 230 150, 380 214, 590 176 C 820 134, 960 208, 1180 168 C 1310 142, 1375 176, 1440 158" />
        </svg>
    </div>
</template>
