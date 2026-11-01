<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';
import { onMounted, onUnmounted, ref, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        /** `auto` follows the page theme. `dark` always draws the luminous ridges. */
        tone?: 'auto' | 'dark' | 'light';
        /**
         * Home hero only. Draws each ridge as a graph line with a fill that
         * fades to transparent, and drifts them. Auth screens keep the static theme split.
         */
        motion?: boolean;
    }>(),
    { tone: 'auto', motion: false },
);

const violetCrest =
    'M 0 132 C 80 130 160 70 240 74 C 320 78 400 152 480 156 C 560 160 640 96 720 98 C 800 100 880 170 960 168 C 1040 166 1120 92 1200 86 C 1280 80 1360 134 1440 132 C 1520 130 1600 70 1680 74 C 1760 78 1840 152 1920 156 C 2000 160 2080 96 2160 98 C 2240 100 2320 170 2400 168 C 2480 166 2560 92 2640 86 C 2720 80 2800 134 2880 132';
const goldCrest =
    'M 0 198 C 80 197 160 152.3 240 156 C 320 159.7 400 217.7 480 220 C 560 222.3 640 169 720 170 C 800 171 880 227.3 960 226 C 1040 224.7 1120 166.7 1200 162 C 1280 157.3 1360 199 1440 198 C 1520 197 1600 152.3 1680 156 C 1760 159.7 1840 217.7 1920 220 C 2000 222.3 2080 169 2160 170 C 2240 171 2320 227.3 2400 226 C 2480 224.7 2560 166.7 2640 162 C 2720 157.3 2800 199 2880 198';

const gradientSuffix = useId().replace(/[^a-zA-Z0-9_-]/g, '');
const violetFillId = `horizon-violet-${gradientSuffix}`;
const goldFillId = `horizon-gold-${gradientSuffix}`;

const root = ref<HTMLElement | null>(null);
let observer: IntersectionObserver | undefined;

onMounted(() => {
    const node = root.value;

    if (!props.motion || !node || typeof IntersectionObserver === 'undefined') {
        return;
    }

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        return;
    }

    observer = new IntersectionObserver(([entry]) => {
        node.classList.toggle('is-quiet', !entry?.isIntersecting);
    });
    observer.observe(node);
});

onUnmounted(() => observer?.disconnect());
</script>

<template>
    <div
        ref="root"
        :class="cn('forge-horizon overflow-hidden', props.class)"
        :data-tone="tone"
        :data-motion="motion ? 'true' : undefined"
        aria-hidden="true"
    >
        <template v-if="motion">
            <svg viewBox="0 0 2880 280" preserveAspectRatio="none" class="horizon-layer horizon-layer-violet">
                <defs>
                    <linearGradient :id="violetFillId" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="hsl(var(--primary))" stop-opacity="0.35" />
                        <stop offset="100%" stop-color="hsl(var(--primary))" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <path class="horizon-graph-fill" :fill="`url(#${violetFillId})`" :d="`${violetCrest} L 2880 280 L 0 280 Z`" />
                <path class="horizon-graph-line" :d="violetCrest" />
            </svg>
            <svg viewBox="0 0 2880 280" preserveAspectRatio="none" class="horizon-layer horizon-layer-gold">
                <defs>
                    <linearGradient :id="goldFillId" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="hsl(var(--highlight))" stop-opacity="0.35" />
                        <stop offset="100%" stop-color="hsl(var(--highlight))" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <path class="horizon-graph-fill" :fill="`url(#${goldFillId})`" :d="`${goldCrest} L 2880 280 L 0 280 Z`" />
                <path class="horizon-graph-line horizon-graph-line-gold" :d="goldCrest" />
            </svg>
        </template>
        <svg v-else viewBox="0 0 1440 280" preserveAspectRatio="none" class="block h-full w-full">
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
