<script setup lang="ts">
import type { AvatarImageProps } from "reka-ui"
import { AvatarImage } from "reka-ui"
import { computed, useAttrs } from "vue"

const props = defineProps<AvatarImageProps>()
const attrs = useAttrs()

// Reka gives every avatar picture role="img". A picture given alt="" is decoration (the name is beside it), and an empty alt with the
// role of a picture contradict each other, so it is presentation.
const role = computed(() => (attrs.alt === "" ? "presentation" : undefined))
</script>

<template>
  <AvatarImage
    data-slot="avatar-image"
    v-bind="props"
    :role="role"
    class="aspect-square size-full"
  >
    <slot />
  </AvatarImage>
</template>
