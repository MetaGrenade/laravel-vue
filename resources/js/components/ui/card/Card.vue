<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <div
    data-slot="card"
    :class="
      cn(
        'bg-card text-card-foreground flex flex-col gap-6 rounded-xl border py-6 shadow-[0_1px_2px_hsl(225_30%_12%/0.04),0_18px_40px_-28px_hsl(232_50%_20%/0.35)] dark:shadow-none',
        props.class,
      )
    "
  >
    <slot />
  </div>
</template>
