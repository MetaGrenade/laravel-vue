<script setup lang="ts">
import { Star } from '@lucide/vue';
import { ref, useId } from 'vue';

/** Choosing a rating from 1 to 5. Real radio buttons underneath, so the arrow keys and screen readers work. */
const model = defineModel<number>({ default: 0 });

defineProps<{ invalid?: boolean }>();

const name = `rating-${useId()}`;
const hover = ref(0);

const labels = ['Terrible', 'Poor', 'Fair', 'Good', 'Excellent'];
</script>

<template>
    <fieldset class="space-y-1" :aria-invalid="invalid || undefined">
        <legend class="text-sm font-medium">Your rating</legend>
        <div class="flex items-center gap-1" @mouseleave="hover = 0">
            <label v-for="n in 5" :key="n" class="cursor-pointer" :title="`${n} out of 5: ${labels[n - 1]}`" @mouseenter="hover = n">
                <input v-model="model" type="radio" :name="name" :value="n" class="peer sr-only" />
                <span class="sr-only">{{ n }} out of 5, {{ labels[n - 1] }}</span>
                <Star
                    class="size-7 rounded-sm transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
                    :class="n <= (hover || model) ? 'fill-warning text-warning' : 'text-muted-foreground/40'"
                    aria-hidden="true"
                />
            </label>
            <span v-if="hover || model" class="ml-2 text-sm text-muted-foreground" aria-hidden="true">{{ labels[(hover || model) - 1] }}</span>
        </div>
    </fieldset>
</template>
