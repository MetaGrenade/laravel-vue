<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch, type HTMLAttributes } from 'vue'
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
import { cn } from '@/lib/utils'
import { Bold, Code, Eye, EyeOff, Italic, List, ListOrdered, Quote, Redo, Strikethrough, Undo } from 'lucide-vue-next'

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

onMounted(() => {
  const initialContent = loadInitialContent()
  createEditor(initialContent)
  hasInitialised.value = true
})

onBeforeUnmount(() => {
  editor.value?.destroy()
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

  isPreviewing.value = true
}

const formattingGroups = computed(() => [
  [
    {
      icon: Bold,
      label: 'Bold',
      isActive: () => editor.value?.isActive('bold') ?? false,
      action: () => editor.value?.chain().focus().toggleBold().run(),
    },
    {
      icon: Italic,
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
      icon: Code,
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
      icon: Code,
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
