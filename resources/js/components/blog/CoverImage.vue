<script setup lang="ts">
import { ImageOff } from '@lucide/vue';

defineOptions({ inheritAttrs: false });

defineProps<{
    src: string | null;
    // The sizes the cover was made in, for the browser to choose from. Null for a cover that has only one.
    srcset?: string | null;
    // How wide the picture is drawn, as the page lays it out: the browser picks a size before it knows.
    sizes: string;
    alt: string;
}>();
</script>

<template>
    <img v-if="src" v-bind="$attrs" :src="src" :srcset="srcset ?? undefined" :sizes="srcset ? sizes : undefined" :alt="alt" />
    <div v-else class="flex h-full w-full items-center justify-center bg-muted text-muted-foreground" role="img" aria-label="No cover picture">
        <ImageOff class="size-8" aria-hidden="true" />
    </div>
</template>
