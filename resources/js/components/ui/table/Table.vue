<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { onBeforeUnmount, onMounted, onUpdated, ref } from "vue"
import { cn } from "@/lib/utils"

const props = withDefaults(
  defineProps<{
    class?: HTMLAttributes["class"]
    /** What a screen reader calls the box when it scrolls sideways and can be reached with the keyboard. */
    label?: string
  }>(),
  { label: "Scrollable table" },
)

const container = ref<HTMLElement | null>(null)
const scrolls = ref(false)

// What the keyboard can stop on: a link, a button or a field in the table is enough, because moving to it scrolls the box.
const FOCUSABLE =
  'a[href], button:not(:disabled), input:not(:disabled):not([type="hidden"]), select:not(:disabled), textarea:not(:disabled), summary, [tabindex]:not([tabindex="-1"])'

/**
 * A box that scrolls sideways (a table wider than the screen, as on a phone) and has nothing in it the keyboard can stop on cannot be
 * scrolled without a mouse, so the columns off the edge cannot be read (axe: scrollable-region-focusable). Such a box becomes a tab stop,
 * and only such a box: a table that fits, or one with links in it, is not made one more thing to tab through.
 */
function measure() {
  const el = container.value

  scrolls.value = el !== null && el.scrollWidth > el.clientWidth && el.querySelector(FOCUSABLE) === null
}

let resize: ResizeObserver | undefined

onMounted(() => {
  measure()

  if (typeof ResizeObserver === "undefined" || !container.value) return

  // The box changes size with the window, and the table inside it with its rows and columns.
  resize = new ResizeObserver(measure)
  resize.observe(container.value)

  if (container.value.firstElementChild) resize.observe(container.value.firstElementChild)
})

onUpdated(measure)

onBeforeUnmount(() => resize?.disconnect())
</script>

<template>
  <div
    ref="container"
    data-slot="table-container"
    class="relative w-full overflow-auto outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
    :tabindex="scrolls ? 0 : undefined"
    :role="scrolls ? 'group' : undefined"
    :aria-label="scrolls ? props.label : undefined"
  >
    <table data-slot="table" :class="cn('w-full caption-bottom text-sm', props.class)">
      <slot />
    </table>
  </div>
</template>
