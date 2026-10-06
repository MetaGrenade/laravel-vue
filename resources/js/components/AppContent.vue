<script setup lang="ts">
import { SidebarInset } from '@/components/ui/sidebar';
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
    fullWidth?: boolean;
    class?: HTMLAttributes['class'];
}

const props = defineProps<Props>();
</script>

<template>
    <SidebarInset v-if="props.variant === 'sidebar'" :class="props.class">
        <slot />
    </SidebarInset>
    <main v-else id="main-content" tabindex="-1" :class="cn('flex w-full flex-1 flex-col outline-none', props.class)">
        <slot v-if="props.fullWidth" />
        <!-- Pages add their own p-4, so the container only tops up the gutter on larger screens. -->
        <div v-else class="mx-auto flex w-full max-w-7xl flex-1 flex-col py-2 sm:px-2 lg:px-4">
            <slot />
        </div>
    </main>
</template>
