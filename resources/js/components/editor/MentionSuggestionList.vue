<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { SuggestionKeyDownProps } from '@tiptap/suggestion'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import type { MentionAttributes } from './extensions/mention'

export interface MentionSuggestionItem extends MentionAttributes {
  avatarUrl?: string | null
}

const props = defineProps<{
  items: MentionSuggestionItem[]
  command: (item: MentionSuggestionItem) => void
  query: string
  loading: boolean
}>()

const selectedIndex = ref(0)

const hasItems = computed(() => props.items.length > 0)
const isQueryEmpty = computed(() => props.query.trim() === '')

const selectItem = (index: number) => {
  const item = props.items[index]

  if (!item) {
    return
  }

  props.command(item)
}

watch(
  () => props.items,
  (items) => {
    if (items.length === 0) {
      selectedIndex.value = 0
      return
    }

    selectedIndex.value = 0
  },
  { immediate: true },
)

const onKeyDown = ({ event }: SuggestionKeyDownProps) => {
  if (event.key === 'ArrowDown') {
    event.preventDefault()

    if (!hasItems.value) {
      return true
    }

    selectedIndex.value = (selectedIndex.value + 1) % props.items.length
    return true
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()

    if (!hasItems.value) {
      return true
    }

    selectedIndex.value = (selectedIndex.value + props.items.length - 1) % props.items.length
    return true
  }

  if (event.key === 'Enter' || event.key === 'Tab') {
    if (!hasItems.value) {
      return false
    }

    event.preventDefault()
    selectItem(selectedIndex.value)
    return true
  }

  if (event.key === 'Escape') {
    event.preventDefault()
    return false
  }

  return false
}

defineExpose({
  onKeyDown,
})
</script>

<template>
  <div class="min-w-[14rem] overflow-hidden rounded-md border border-border bg-popover text-popover-foreground shadow-lg">
    <div v-if="loading" class="px-3 py-2 text-sm text-muted-foreground">Searching members…</div>
    <div v-else-if="isQueryEmpty" class="px-3 py-2 text-sm text-muted-foreground">Start typing to mention someone.</div>
    <div v-else-if="!hasItems" class="px-3 py-2 text-sm text-muted-foreground">No members found</div>
    <ul v-else class="max-h-64 overflow-y-auto py-1 text-sm">
      <li
        v-for="(item, index) in items"
        :key="item.id"
        :class="[
          'flex cursor-pointer items-center gap-2 px-3 py-2 transition-colors',
          index === selectedIndex ? 'bg-muted text-foreground' : 'hover:bg-muted/70',
        ]"
        @mousedown.prevent="selectItem(index)"
      >
        <Avatar class="h-8 w-8">
          <AvatarImage v-if="item.avatarUrl" :src="item.avatarUrl" :alt="item.nickname" />
          <AvatarFallback>{{ item.nickname.substring(0, 2).toUpperCase() }}</AvatarFallback>
        </Avatar>
        <div class="flex flex-1 flex-col">
          <span class="font-medium">@{{ item.nickname }}</span>
          <span v-if="item.profileUrl" class="text-xs text-muted-foreground">View profile</span>
        </div>
      </li>
    </ul>
  </div>
</template>
