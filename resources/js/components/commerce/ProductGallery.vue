<script setup lang="ts">
import type { StorefrontImage } from '@/types/commerce';
import { ImageOff } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    images: StorefrontImage[];
}>();

const current = ref(0);

// A picture removed while the page is open must not leave the gallery pointing past the end.
watch(
    () => props.images.length,
    (length) => {
        if (current.value >= length) {
            current.value = 0;
        }
    },
);

const active = computed(() => props.images[current.value] ?? null);

// The medium size is 800 pixels wide at most; the large one is offered to screens that can use it.
const srcset = computed(() => {
    const image = active.value;

    return image && image.width > 800 ? `${image.medium} 800w, ${image.url} ${image.width}w` : undefined;
});

const step = (by: number) => {
    const count = props.images.length;

    if (count > 1) {
        current.value = (current.value + by + count) % count;
    }
};
</script>

<template>
    <div class="space-y-3" role="group" aria-roledescription="gallery" @keydown.left.prevent="step(-1)" @keydown.right.prevent="step(1)">
        <div class="overflow-hidden rounded-xl border bg-muted/30">
            <img
                v-if="active"
                :key="active.url"
                :src="active.medium"
                :srcset="srcset"
                sizes="(min-width: 1024px) 50vw, 100vw"
                :alt="active.alt"
                :width="active.width"
                :height="active.height"
                class="aspect-square w-full object-contain"
            />
            <div v-else class="flex aspect-square items-center justify-center text-muted-foreground" role="img" aria-label="No picture yet">
                <ImageOff class="size-12" aria-hidden="true" />
            </div>
        </div>

        <ul v-if="images.length > 1" class="grid grid-cols-5 gap-2 sm:grid-cols-6">
            <li v-for="(image, index) in images" :key="image.url">
                <button
                    type="button"
                    class="block w-full overflow-hidden rounded-md border-2 transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                    :class="index === current ? 'border-primary' : 'border-transparent hover:border-border'"
                    :aria-label="`Show picture ${index + 1} of ${images.length}`"
                    :aria-current="index === current ? 'true' : undefined"
                    @click="current = index"
                >
                    <img :src="image.thumb" alt="" class="aspect-square w-full object-cover" loading="lazy" />
                </button>
            </li>
        </ul>
    </div>
</template>
