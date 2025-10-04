<script setup lang="ts">
import { computed } from 'vue'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'

export interface MentionSuggestionItem {
  id: number | string
  nickname: string
  avatar_url?: string | null
  profile_url?: string | null
}

const props = defineProps<{
  items: MentionSuggestionItem[]
  highlightedIndex: number
  position: { left: number; top: number }
  loading: boolean
}>()

const emit = defineEmits<{
  select: [item: MentionSuggestionItem]
  highlight: [index: number]
}>()

const containerStyle = computed(() => ({
  left: `${props.position.left}px`,
  top: `${props.position.top}px`,
}))

const handleSelect = (index: number) => {
  const item = props.items[index]

  if (!item) {
    return
  }

  emit('select', item)
}

const handleMouseEnter = (index: number) => {
  emit('highlight', index)
}
</script>

<template>
  <Teleport to="body">
    <div class="pointer-events-none fixed z-50" :style="containerStyle">
      <div class="pointer-events-auto min-w-[14rem] rounded-md border border-border bg-popover text-popover-foreground shadow-lg">
        <div v-if="items.length === 0">
          <p class="px-3 py-2 text-sm text-muted-foreground">
            <span v-if="loading">Searching members…</span>
            <span v-else>No members found</span>
          </p>
        </div>
        <ul v-else class="max-h-64 overflow-y-auto py-1 text-sm">
          <li
            v-for="(item, index) in items"
            :key="item.id"
            :class="[
              'flex cursor-pointer items-center gap-2 px-3 py-2 transition-colors',
              index === highlightedIndex ? 'bg-muted text-foreground' : 'hover:bg-muted/70',
            ]"
            @mousedown.prevent="handleSelect(index)"
            @mouseenter="handleMouseEnter(index)"
          >
            <Avatar class="h-8 w-8">
              <AvatarImage v-if="item.avatar_url" :src="item.avatar_url" :alt="item.nickname" />
              <AvatarFallback>{{ item.nickname.substring(0, 2).toUpperCase() }}</AvatarFallback>
            </Avatar>
            <div class="flex flex-1 flex-col">
              <span class="font-medium">@{{ item.nickname }}</span>
              <span v-if="item.profile_url" class="text-xs text-muted-foreground">View profile</span>
            </div>
          </li>
        </ul>
      </div>
    </div>
  </Teleport>
</template>
