<script setup lang="ts">
import { computed, watch } from 'vue';
import type { HTMLAttributes } from 'vue';
import { useVModel } from '@vueuse/core';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { cn } from '@/lib/utils';

interface ToolbarAction {
    name: string;
    label: string;
    icon: string;
    action: () => void;
    isActive: boolean;
    isDisabled: boolean;
}

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        modelValue?: string;
        defaultValue?: string;
        placeholder?: string;
        class?: HTMLAttributes['class'];
        contentClass?: HTMLAttributes['class'];
        disabled?: boolean;
        toolbar?: boolean;
    }>(),
    {
        defaultValue: '',
        placeholder: 'Write something...',
        disabled: false,
        toolbar: true,
    },
);

const emits = defineEmits<{
    (event: 'update:modelValue', value: string): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});

const editor = useEditor({
    content: modelValue.value,
    extensions: [
        StarterKit.configure({
            heading: {
                levels: [1, 2, 3],
            },
            bulletList: {
                keepMarks: true,
                keepAttributes: false,
            },
            orderedList: {
                keepMarks: true,
                keepAttributes: false,
            },
        }),
        Placeholder.configure({
            placeholder: props.placeholder,
            includeChildren: true,
            showOnlyWhenEditable: true,
        }),
    ],
    editable: !props.disabled,
    onUpdate: ({ editor }) => {
        modelValue.value = editor.getHTML();
    },
});

watch(
    () => props.disabled,
    (disabled) => {
        if (!editor.value) return;
        editor.value.setEditable(!disabled);
    },
    { immediate: true },
);

watch(
    () => modelValue.value,
    (value) => {
        const instance = editor.value;

        if (!instance) {
            return;
        }

        const current = instance.getHTML();

        if (value === current) {
            return;
        }

        instance.commands.setContent(value ?? '', false);
    },
);

const toolbarActions = computed<ToolbarAction[]>(() => {
    const instance = editor.value;

    if (!instance) {
        return [];
    }

    return [
        {
            name: 'bold',
            label: 'Bold',
            icon: 'B',
            action: () => instance.chain().focus().toggleBold().run(),
            isActive: instance.isActive('bold'),
            isDisabled: !instance.can().chain().focus().toggleBold().run(),
        },
        {
            name: 'italic',
            label: 'Italic',
            icon: 'I',
            action: () => instance.chain().focus().toggleItalic().run(),
            isActive: instance.isActive('italic'),
            isDisabled: !instance.can().chain().focus().toggleItalic().run(),
        },
        {
            name: 'strike',
            label: 'Strikethrough',
            icon: 'S',
            action: () => instance.chain().focus().toggleStrike().run(),
            isActive: instance.isActive('strike'),
            isDisabled: !instance.can().chain().focus().toggleStrike().run(),
        },
        {
            name: 'bulletList',
            label: 'Bullet list',
            icon: '•',
            action: () => instance.chain().focus().toggleBulletList().run(),
            isActive: instance.isActive('bulletList'),
            isDisabled: !instance.can().chain().focus().toggleBulletList().run(),
        },
        {
            name: 'orderedList',
            label: 'Ordered list',
            icon: '1.',
            action: () => instance.chain().focus().toggleOrderedList().run(),
            isActive: instance.isActive('orderedList'),
            isDisabled: !instance.can().chain().focus().toggleOrderedList().run(),
        },
        {
            name: 'blockquote',
            label: 'Quote',
            icon: '❝',
            action: () => instance.chain().focus().toggleBlockquote().run(),
            isActive: instance.isActive('blockquote'),
            isDisabled: !instance.can().chain().focus().toggleBlockquote().run(),
        },
        {
            name: 'codeBlock',
            label: 'Code block',
            icon: '{ }',
            action: () => instance.chain().focus().toggleCodeBlock().run(),
            isActive: instance.isActive('codeBlock'),
            isDisabled: !instance.can().chain().focus().toggleCodeBlock().run(),
        },
    ];
});

const rootClasses = computed(() =>
    cn(
        'relative flex flex-col overflow-hidden rounded-md border border-input bg-background text-sm shadow-sm',
        'focus-within:outline-none focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background',
        props.disabled ? 'opacity-60' : '',
        props.class,
    ),
);

const editorClasses = computed(() =>
    cn(
        'tiptap-editor prose prose-sm max-w-none flex-1 px-3 py-2 text-foreground transition',
        'dark:prose-invert',
        props.contentClass,
    ),
);
</script>

<template>
    <div :class="rootClasses" :data-disabled="props.disabled ? 'true' : undefined">
        <div
            v-if="props.toolbar && editor"
            class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/40 px-2 py-1.5 text-xs font-medium"
        >
            <button
                v-for="action in toolbarActions"
                :key="action.name"
                type="button"
                class="inline-flex items-center justify-center rounded-md px-2 py-1 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                :class="
                    action.isActive
                        ? 'bg-background text-foreground shadow-sm'
                        : 'text-muted-foreground hover:bg-background/80 hover:text-foreground'
                "
                :disabled="props.disabled || action.isDisabled"
                :aria-pressed="action.isActive"
                :title="action.label"
                @click="action.action"
            >
                <span class="select-none text-[0.75rem] font-semibold tracking-wide">{{ action.icon }}</span>
            </button>
        </div>

        <EditorContent
            :id="props.id"
            :editor="editor"
            :aria-label="props.placeholder"
            :aria-disabled="props.disabled ? 'true' : undefined"
            role="textbox"
            :class="editorClasses"
        />
        <input v-if="props.name" type="hidden" :name="props.name" :value="modelValue" />
    </div>
</template>

<style scoped>
:deep(.tiptap-editor.ProseMirror) {
    min-height: 12rem;
    outline: none;
}

:deep(.tiptap-editor.ProseMirror p) {
    margin-top: 0;
    margin-bottom: 0.75rem;
}

:deep(.tiptap-editor.ProseMirror p:last-child) {
    margin-bottom: 0;
}

:deep(.tiptap-editor.ProseMirror ul),
:deep(.tiptap-editor.ProseMirror ol) {
    padding-left: 1.25rem;
    margin-bottom: 0.75rem;
}

:deep(.tiptap-editor.ProseMirror ul:last-child),
:deep(.tiptap-editor.ProseMirror ol:last-child) {
    margin-bottom: 0;
}

:deep(.tiptap-editor.ProseMirror blockquote) {
    border-left: 3px solid hsl(var(--border));
    margin: 0 0 0.75rem;
    padding-left: 0.75rem;
    color: hsl(var(--muted-foreground));
    font-style: italic;
}

:deep(.tiptap-editor.ProseMirror blockquote:last-child) {
    margin-bottom: 0;
}

:deep(.tiptap-editor.ProseMirror pre) {
    background-color: hsl(var(--muted));
    border-radius: 0.5rem;
    font-family: ui-monospace, SFMono-Regular, SFMono, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;
    font-size: 0.875rem;
    margin-bottom: 0.75rem;
    padding: 0.75rem 1rem;
    overflow-x: auto;
}

:deep(.tiptap-editor.ProseMirror pre:last-child) {
    margin-bottom: 0;
}

:deep(.tiptap-editor.ProseMirror p.is-editor-empty:first-child::before) {
    color: hsl(var(--muted-foreground));
    content: attr(data-placeholder);
    float: left;
    height: 0;
    pointer-events: none;
}
</style>
