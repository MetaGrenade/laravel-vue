<script setup lang="ts">
import type { BulletLegendItemInterface } from '@unovis/ts'
import { omit } from '@unovis/ts'
import { VisCrosshair, VisTooltip } from '@unovis/vue'
import { type Component, computed, createApp } from 'vue'
import { ChartTooltip } from '.'

const props = withDefaults(defineProps<{
  colors: string[]
  index: string
  items: BulletLegendItemInterface[]
  /** The key of each line in the data, in the order of the lines (and of `colors`). */
  categories: readonly string[]
  customTooltip?: Component
}>(), {
  colors: () => [],
})

// The crosshair snaps to the data, and for that it is given the same accessors the lines have: one `y` per line, in the order of the lines (the
// `color` below is per accessor). The x of every line is the position of the point. Without them the crosshair looked and worked the same
// (checked: the tooltip and the dots on the lines, before and after), but Unovis logged "Y accessors have not been configured" on every chart,
// twice, as the page loaded.
const x = (_: unknown, i: number) => i
const y = computed(() => props.categories.map(category => (d: Record<string, any>) => d[category]))

// Use weakmap to store reference to each datapoint for Tooltip
const wm = new WeakMap()
function template(d: any) {
  if (wm.has(d)) {
    return wm.get(d)
  }
  else {
    const componentDiv = document.createElement('div')
    const omittedData = Object.entries(omit(d, [props.index])).map(([key, value]) => {
      const legendReference = props.items.find(i => i.name === key)
      return { ...legendReference, value }
    })
    const TooltipComponent = props.customTooltip ?? ChartTooltip
    createApp(TooltipComponent, { title: d[props.index].toString(), data: omittedData }).mount(componentDiv)
    wm.set(d, componentDiv.innerHTML)
    return componentDiv.innerHTML
  }
}

function color(d: unknown, i: number) {
  return props.colors[i] ?? 'transparent';
}
</script>

<template>
  <VisTooltip :horizontal-shift="20" :vertical-shift="20" />
  <VisCrosshair :template="template" :color="color" :x="x" :y="y" />
</template>
