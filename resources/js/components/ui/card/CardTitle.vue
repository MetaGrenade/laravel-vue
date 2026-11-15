<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = withDefaults(defineProps<{
  class?: HTMLAttributes["class"]
  // The heading level the card sits at on its page. A card directly under a page's h1 (nearly all of them: the settings and the admin
  // layouts carry the h1 themselves) is an h2, so the levels do not skip; a card inside a section that has an h2 of its own says "h3".
  // "div" is for a card that is only a figure with a caption, where the figure itself is no heading.
  as?: "h2" | "h3" | "h4" | "div"
}>(), {
  as: "h2",
})
</script>

<template>
  <component
    :is="props.as"
    data-slot="card-title"
    :class="cn('leading-none font-semibold', props.class)"
  >
    <slot />
  </component>
</template>
