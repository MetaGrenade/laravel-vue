<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch, type HTMLAttributes } from 'vue'
import { Editor, EditorContent } from '@tiptap/vue-3'
import Blockquote from '@tiptap/extension-blockquote'
import Bold from '@tiptap/extension-bold'
import BulletList from '@tiptap/extension-bullet-list'
import Code from '@tiptap/extension-code'
import CodeBlock from '@tiptap/extension-code-block'
import Document from '@tiptap/extension-document'
import Dropcursor from '@tiptap/extension-dropcursor'
import Gapcursor from '@tiptap/extension-gapcursor'
import HardBreak from '@tiptap/extension-hard-break'
import History from '@tiptap/extension-history'
import HorizontalRule from '@tiptap/extension-horizontal-rule'
import Italic from '@tiptap/extension-italic'
import ListItem from '@tiptap/extension-list-item'
import OrderedList from '@tiptap/extension-ordered-list'
import Paragraph from '@tiptap/extension-paragraph'
import Placeholder from '@tiptap/extension-placeholder'
import Strike from '@tiptap/extension-strike'
import Text from '@tiptap/extension-text'
import TextStyle from '@tiptap/extension-text-style'
import { useDebounceFn } from '@vueuse/core'
import MentionSuggestionList, { type MentionSuggestionItem } from '@/components/editor/MentionSuggestionList.vue'
import { cn } from '@/lib/utils'
import { Bold as BoldIcon, MessageSquareCode, Code as CodeIcon, Eye, EyeOff, Italic as ItalicIcon, List, ListOrdered, Quote, Redo, Strikethrough, Undo } from 'lucide-vue-next'
import MentionExtension, { type MentionAttributes } from './extensions/mention'

const props = withDefaults(
  defineProps<{
    id?: string
    modelValue: string
    placeholder?: string
    class?: HTMLAttributes['class']
    storageKey?: string | null
    autofocus?: boolean
  }>(),
  {
    placeholder: '',
    storageKey: null,
    autofocus: false,
  },
)

const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()

const editor = ref<Editor | null>(null)
const isPreviewing = ref(false)
const lastSavedAt = ref<Date | null>(null)
const hasInitialised = ref(false)

const mentionState = reactive({
  active: false,
  query: '',
  range: null as { from: number; to: number } | null,
  position: { left: 0, top: 0 },
  items: [] as MentionSuggestionItem[],
  highlightedIndex: -1,
  loading: false,
})

const mentionCache = new Map<string, MentionSuggestionItem[]>()
let mentionAbortController: AbortController | null = null
let removeEditorKeydownListener: (() => void) | null = null

const fetchMentionResults = async (query: string) => {
  if (!mentionState.active) {
    return
  }

  if (query === '') {
    mentionState.items = []
    mentionState.highlightedIndex = -1
    mentionState.loading = false
    return
  }

  if (mentionCache.has(query)) {
    mentionState.items = mentionCache.get(query) ?? []
    mentionState.highlightedIndex = mentionState.items.length > 0 ? 0 : -1
    mentionState.loading = false
    return
  }

  if (mentionAbortController) {
    mentionAbortController.abort()
  }

  mentionAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null
  mentionState.loading = true

  try {
    const url = query !== '' ? route('forum.mentions.index', { q: query }) : route('forum.mentions.index')
    const response = await fetch(url, {
      signal: mentionAbortController?.signal,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
    })

    if (!response.ok) {
      throw new Error('Failed to load mention suggestions')
    }

    const payload = await response.json()
    const items = Array.isArray(payload.data) ? (payload.data as MentionSuggestionItem[]) : []

    mentionCache.set(query, items)
    mentionState.items = items
    mentionState.highlightedIndex = items.length > 0 ? 0 : -1
  } catch (error) {
    if ((error as DOMException)?.name === 'AbortError') {
      return
    }

    mentionState.items = []
    mentionState.highlightedIndex = -1
  } finally {
    mentionState.loading = false
  }
}

const fetchMentionResultsDebounced = useDebounceFn((query: string) => {
  void fetchMentionResults(query)
}, 180)

const requestMentionResults = (query: string, immediate = false) => {
  if (!mentionState.active) {
    return
  }

  if (immediate) {
    fetchMentionResultsDebounced.cancel()
    void fetchMentionResults(query)
    return
  }

  fetchMentionResultsDebounced(query)
}

const closeMention = () => {
  mentionState.active = false
  mentionState.query = ''
  mentionState.range = null
  mentionState.items = []
  mentionState.highlightedIndex = -1
  mentionState.loading = false

  if (mentionAbortController) {
    mentionAbortController.abort()
    mentionAbortController = null
  }

  fetchMentionResultsDebounced.cancel()
}

const updateMentionPosition = () => {
  if (!mentionState.active || !mentionState.range || !editor.value) {
    return
  }

  try {
    const coords = editor.value.view.coordsAtPos(mentionState.range.from)

    if (typeof window !== 'undefined') {
      mentionState.position.left = coords.left + window.scrollX
      mentionState.position.top = coords.bottom + window.scrollY
    } else {
      mentionState.position.left = coords.left
      mentionState.position.top = coords.bottom
    }
  } catch {
    // Ignore positioning errors when the cursor leaves the editor.
  }
}

const moveMentionHighlight = (direction: 1 | -1) => {
  if (mentionState.items.length === 0) {
    mentionState.highlightedIndex = -1
    return
  }

  if (mentionState.highlightedIndex === -1) {
    mentionState.highlightedIndex = direction === 1 ? 0 : mentionState.items.length - 1
    return
  }

  mentionState.highlightedIndex =
    (mentionState.highlightedIndex + direction + mentionState.items.length) % mentionState.items.length
}

const insertMention = (item: MentionSuggestionItem | undefined) => {
  if (!editor.value || !mentionState.range || !item) {
    return
  }

  const { from, to } = mentionState.range
  const attrs: MentionAttributes = {
    id: item.id,
    nickname: item.nickname,
    label: item.nickname,
    profileUrl: item.profile_url ?? null,
  }

  const chain = editor.value.chain().focus().deleteRange({ from, to }).insertContent([
    {
      type: MentionExtension.name,
      attrs,
    },
  ])

  const nextCharacter = editor.value.state.doc.textBetween(to, to + 1, '\u0000', '\u0000')
  if (nextCharacter === '' || !/^\s$/.test(nextCharacter)) {
    chain.insertContent(' ')
  }

  chain.run()
  closeMention()

  void nextTick(() => {
    editor.value?.commands.focus()
  })
}

const handleMentionSelect = (item: MentionSuggestionItem) => {
  insertMention(item)
}

const handleMentionHighlight = (index: number) => {
  mentionState.highlightedIndex = index
}

const handleEditorKeyDown = (event: KeyboardEvent) => {
  if (!mentionState.active) {
    return
  }

  if (event.key === 'ArrowDown') {
    event.preventDefault()
    moveMentionHighlight(1)
    return
  }

  if (event.key === 'ArrowUp') {
    event.preventDefault()
    moveMentionHighlight(-1)
    return
  }

  if (event.key === 'Enter' || event.key === 'Tab') {
    if (mentionState.items.length === 0) {
      closeMention()
      return
    }

    event.preventDefault()
    const index = mentionState.highlightedIndex === -1 ? 0 : mentionState.highlightedIndex
    insertMention(mentionState.items[index])
    return
  }

  if (event.key === 'Escape') {
    event.preventDefault()
    closeMention()
    return
  }

  if (event.key === ' ' || event.key === 'Spacebar') {
    closeMention()
  }
}

const detectMention = () => {
  if (!editor.value) {
    return
  }

  const { state } = editor.value
  const { selection } = state

  if (!selection.empty) {
    closeMention()
    return
  }

  const $from = selection.$from

  if ($from.nodeBefore?.type?.name === MentionExtension.name) {
    closeMention()
    return
  }

  const from = selection.from
  const textBefore = state.doc.textBetween(Math.max(0, from - 64), from, '\u0000', '\u0000')
  const atIndex = textBefore.lastIndexOf('@')

  if (atIndex === -1) {
    closeMention()
    return
  }

  const mentionCandidate = textBefore.slice(atIndex)

  if (!/^@[A-Za-z0-9_.-]*$/.test(mentionCandidate)) {
    closeMention()
    return
  }

  const precedingChar = textBefore[atIndex - 1] ?? ''
  if (precedingChar && /[A-Za-z0-9_.-@]/.test(precedingChar)) {
    closeMention()
    return
  }

  const query = mentionCandidate.slice(1)

  if (query.length > 50) {
    closeMention()
    return
  }

  const rangeFrom = from - mentionCandidate.length
  const rangeTo = from

  mentionState.active = true
  mentionState.range = { from: rangeFrom, to: rangeTo }
  mentionState.query = query
  updateMentionPosition()
}

const storageKey = computed(() => props.storageKey ?? null)

const createEditor = (initialContent: string) => {
  editor.value = new Editor({
    content: initialContent,
    autofocus: props.autofocus,
    extensions: [
      Document,
      Paragraph,
      Text,
      TextStyle,
      Bold,
      Italic,
      Strike,
      Code,
      CodeBlock,
      Blockquote,
      BulletList,
      OrderedList,
      ListItem,
      HorizontalRule,
      HardBreak,
      History,
      Dropcursor.configure({
        color: '#6366f1',
      }),
      Gapcursor,
      MentionExtension,
      Placeholder.configure({
        placeholder: props.placeholder,
      }),
    ],
    editorProps: {
      attributes: {
        class:
          'prose prose-sm dark:prose-invert max-w-none focus:outline-none px-3 py-2 min-h-[16rem]',
      },
    },
    onUpdate: ({ editor: current }) => {
      const html = current.getHTML()
      emit('update:modelValue', html)
      queueAutosave(html)
    },
  })
}

const loadInitialContent = () => {
  if (typeof window === 'undefined') {
    return props.modelValue
  }

  if (storageKey.value) {
    const stored = window.localStorage.getItem(storageKey.value)

    if (stored && stored.trim() !== '') {
      emit('update:modelValue', stored)
      lastSavedAt.value = new Date()
      return stored
    }
  }

  return props.modelValue
}

const queueAutosave = useDebounceFn((content: string) => {
  if (!storageKey.value || typeof window === 'undefined') {
    return
  }

  const trimmed = content.replace(/<[^>]*>/g, '').trim()

  if (trimmed === '') {
    window.localStorage.removeItem(storageKey.value)
    lastSavedAt.value = null
    return
  }

  window.localStorage.setItem(storageKey.value, content)
  lastSavedAt.value = new Date()
}, 1200)

const autosaveMessage = computed(() => {
  if (!storageKey.value) {
    return 'Formatting and autosave keep drafts safe as you write.'
  }

  if (!lastSavedAt.value) {
    return 'Draft autosaves will appear once you start typing.'
  }

  const diff = Date.now() - lastSavedAt.value.getTime()

  if (diff < 5000) {
    return 'Draft autosaved just now.'
  }

  const minute = 60 * 1000

  if (diff < minute) {
    const seconds = Math.round(diff / 1000)
    return `Draft autosaved ${seconds} second${seconds === 1 ? '' : 's'} ago.`
  }

  const minutes = Math.round(diff / minute)
  if (minutes < 60) {
    return `Draft autosaved ${minutes} minute${minutes === 1 ? '' : 's'} ago.`
  }

  const hours = Math.round(minutes / 60)
  return `Draft autosaved ${hours} hour${hours === 1 ? '' : 's'} ago.`
})

const clearDraft = () => {
  if (!storageKey.value || typeof window === 'undefined') {
    return
  }

  window.localStorage.removeItem(storageKey.value)
  lastSavedAt.value = null
}

watch(
  () => mentionState.active,
  (active) => {
    if (active) {
      requestMentionResults(mentionState.query, true)
      void nextTick(() => {
        updateMentionPosition()
      })
    }
  },
)

watch(
  () => mentionState.query,
  (query, previous) => {
    if (!mentionState.active || query === previous) {
      return
    }

    requestMentionResults(query)
  },
)

watch(
  () => mentionState.range,
  () => {
    if (!mentionState.active) {
      return
    }

    void nextTick(() => {
      updateMentionPosition()
    })
  },
  { deep: true },
)

watch(
  () => mentionState.items.length,
  () => {
    if (mentionState.items.length === 0) {
      mentionState.highlightedIndex = -1
      return
    }

    if (mentionState.highlightedIndex === -1 || mentionState.highlightedIndex >= mentionState.items.length) {
      mentionState.highlightedIndex = 0
    }
  },
)

onMounted(() => {
  const initialContent = loadInitialContent()
  createEditor(initialContent)

  const instance = editor.value

  if (instance) {
    instance.on('selectionUpdate', detectMention)
    instance.on('transaction', detectMention)
    instance.on('blur', closeMention)

    const dom = instance.view.dom
    dom.addEventListener('keydown', handleEditorKeyDown)
    removeEditorKeydownListener = () => {
      dom.removeEventListener('keydown', handleEditorKeyDown)
    }
  }

  if (typeof window !== 'undefined') {
    window.addEventListener('resize', updateMentionPosition)
    window.addEventListener('scroll', updateMentionPosition, true)
  }

  hasInitialised.value = true
})

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', updateMentionPosition)
    window.removeEventListener('scroll', updateMentionPosition, true)
  }

  removeEditorKeydownListener?.()

  closeMention()

  if (editor.value) {
    editor.value.off('selectionUpdate', detectMention)
    editor.value.off('transaction', detectMention)
    editor.value.off('blur', closeMention)
    editor.value.destroy()
  }
})

watch(
  () => props.modelValue,
  (value) => {
    if (!editor.value) {
      return
    }

    const current = editor.value.getHTML()
    if (value !== current && !(value === '' && current === '<p></p>')) {
      editor.value.commands.setContent(value || '<p></p>', false)
    }
  },
)

const togglePreview = () => {
  if (!editor.value) {
    return
  }

  if (isPreviewing.value) {
    isPreviewing.value = false
    editor.value.commands.focus('end')
    return
  }

  closeMention()
  isPreviewing.value = true
}

const formattingGroups = computed(() => [
  [
    {
      icon: BoldIcon,
      label: 'Bold',
      isActive: () => editor.value?.isActive('bold') ?? false,
      action: () => editor.value?.chain().focus().toggleBold().run(),
    },
    {
      icon: ItalicIcon,
      label: 'Italic',
      isActive: () => editor.value?.isActive('italic') ?? false,
      action: () => editor.value?.chain().focus().toggleItalic().run(),
    },
    {
      icon: Strikethrough,
      label: 'Strikethrough',
      isActive: () => editor.value?.isActive('strike') ?? false,
      action: () => editor.value?.chain().focus().toggleStrike().run(),
    },
    {
      icon: CodeIcon,
      label: 'Inline code',
      isActive: () => editor.value?.isActive('code') ?? false,
      action: () => editor.value?.chain().focus().toggleCode().run(),
    },
  ],
  [
    {
      icon: List,
      label: 'Bullet list',
      isActive: () => editor.value?.isActive('bulletList') ?? false,
      action: () => editor.value?.chain().focus().toggleBulletList().run(),
    },
    {
      icon: ListOrdered,
      label: 'Numbered list',
      isActive: () => editor.value?.isActive('orderedList') ?? false,
      action: () => editor.value?.chain().focus().toggleOrderedList().run(),
    },
    {
      icon: Quote,
      label: 'Quote',
      isActive: () => editor.value?.isActive('blockquote') ?? false,
      action: () => editor.value?.chain().focus().toggleBlockquote().run(),
    },
    {
      icon: MessageSquareCode,
      label: 'Code block',
      isActive: () => editor.value?.isActive('codeBlock') ?? false,
      action: () => editor.value?.chain().focus().toggleCodeBlock().run(),
    },
  ],
])

const undo = () => editor.value?.chain().focus().undo().run()
const redo = () => editor.value?.chain().focus().redo().run()

const toolbarButtonClass = (active: boolean) =>
  cn(
    'inline-flex h-8 w-8 items-center justify-center rounded-md text-sm transition-colors hover:bg-muted focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2 focus:ring-offset-background',
    active ? 'bg-muted text-foreground shadow-inner' : 'text-muted-foreground',
  )
</script>

<template>
  <div :id="id" :class="cn('flex flex-col gap-2', props.class)">
    <div class="overflow-hidden rounded-lg border border-border bg-card">
      <div class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/40 px-2 py-1">
        <div class="flex flex-wrap items-center gap-1">
          <template v-for="group in formattingGroups" :key="group[0].label">
            <div class="flex items-center gap-1">
              <button
                v-for="item in group"
                :key="item.label"
                type="button"
                class="shrink-0"
                :class="toolbarButtonClass(item.isActive())"
                :aria-label="item.label"
                @mousedown.prevent
                @click.prevent="item.action()"
              >
                <component :is="item.icon" class="h-4 w-4" />
              </button>
            </div>
          </template>
        </div>

        <div class="ml-auto flex items-center gap-1">
          <button
            type="button"
            class="shrink-0"
            :class="toolbarButtonClass(false)"
            aria-label="Undo"
            @mousedown.prevent
            @click.prevent="undo"
          >
            <Undo class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="shrink-0"
            :class="toolbarButtonClass(false)"
            aria-label="Redo"
            @mousedown.prevent
            @click.prevent="redo"
          >
            <Redo class="h-4 w-4" />
          </button>
          <button
            type="button"
            class="shrink-0"
            :class="toolbarButtonClass(isPreviewing)"
            :aria-pressed="isPreviewing"
            @mousedown.prevent
            @click.prevent="togglePreview"
          >
            <component :is="isPreviewing ? EyeOff : Eye" class="h-4 w-4" />
          </button>
        </div>
      </div>

      <div class="bg-background">
        <EditorContent v-if="!isPreviewing" :editor="editor" />
        <div v-else class="prose prose-sm dark:prose-invert max-w-none px-3 py-2 min-h-[16rem]">
          <div
            v-if="
              (editor && editor.getText().trim() !== '')
              || (props.modelValue && props.modelValue.trim() !== '')
            "
            v-html="editor?.getHTML() ?? props.modelValue"
          ></div>
          <p v-else class="text-sm text-muted-foreground">Nothing to preview yet.</p>
        </div>
      </div>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
      <span>{{ autosaveMessage }}</span>
      <button
        v-if="storageKey && hasInitialised"
        type="button"
        class="font-medium text-muted-foreground transition-colors hover:text-foreground"
        @click.prevent="clearDraft"
      >
        Discard draft
      </button>
    </div>

    <MentionSuggestionList
      v-if="mentionState.active && !isPreviewing"
      :items="mentionState.items"
      :highlighted-index="mentionState.highlightedIndex"
      :position="mentionState.position"
      :loading="mentionState.loading"
      @select="handleMentionSelect"
      @highlight="handleMentionHighlight"
    />
  </div>
</template>

<style scoped>
:deep(.ProseMirror) {
  min-height: 16rem;
  cursor: text;
}

:deep(.ProseMirror p.is-editor-empty:first-child::before) {
  color: theme('colors.muted.DEFAULT');
  content: attr(data-placeholder);
  float: left;
  height: 0;
  pointer-events: none;
}
</style>
