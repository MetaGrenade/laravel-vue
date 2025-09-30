<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import Icon from '@/components/Icon.vue';

type ListType = 'ul' | 'ol';

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function applyInline(text: string): string {
    if (!text) {
        return '';
    }

    const codeMatches: string[] = [];
    let processed = text.replace(/`([^`]+)`/g, (_, code: string) => {
        const index = codeMatches.length;
        codeMatches.push(code);
        return `@@CODE${index}@@`;
    });

    processed = processed
        .replace(/(\*\*|__)(.+?)\1/g, '<strong>$2</strong>')
        .replace(/(~~)(.+?)\1/g, '<del>$2</del>')
        .replace(/(^|[^*])\*(?!\*)([^*]+)\*(?!\*)/g, (_, prefix: string, content: string) => `${prefix}<em>${content}</em>`)
        .replace(/(^|[^_])_(?!_)([^_]+)_(?!_)/g, (_, prefix: string, content: string) => `${prefix}<em>${content}</em>`)
        .replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noreferrer noopener">$1</a>');

    processed = processed.replace(/@@CODE(\d+)@@/g, (_, index: string) => `<code>${codeMatches[Number(index)]}</code>`);

    return processed;
}

function renderMarkdown(source: string): string {
    if (!source) {
        return '';
    }

    const escaped = escapeHtml(source);
    const lines = escaped.replace(/\r\n?/g, '\n').split('\n');

    const html: string[] = [];
    let inCodeBlock = false;
    let codeBuffer: string[] = [];
    let listType: ListType | null = null;
    let listBuffer: string[] = [];
    let paragraphLines: string[] = [];

    const flushCode = () => {
        if (!inCodeBlock) {
            return;
        }

        html.push(`<pre class="markdown-pre"><code>${codeBuffer.join('\n')}</code></pre>`);
        codeBuffer = [];
        inCodeBlock = false;
    };

    const flushList = () => {
        if (!listType || listBuffer.length === 0) {
            listType = null;
            listBuffer = [];
            return;
        }

        const tag = listType === 'ol' ? 'ol' : 'ul';
        const items = listBuffer.map((item) => `<li>${applyInline(item)}</li>`).join('');
        html.push(`<${tag}>${items}</${tag}>`);
        listType = null;
        listBuffer = [];
    };

    const flushParagraph = () => {
        if (paragraphLines.length === 0) {
            return;
        }

        const content = applyInline(paragraphLines.join(' '));
        html.push(`<p>${content}</p>`);
        paragraphLines = [];
    };

    lines.forEach((line) => {
        const trimmed = line.trim();

        if (line.startsWith('```')) {
            if (inCodeBlock) {
                flushCode();
            } else {
                flushParagraph();
                flushList();
                inCodeBlock = true;
            }
            return;
        }

        if (inCodeBlock) {
            codeBuffer.push(line);
            return;
        }

        if (!trimmed) {
            flushParagraph();
            flushList();
            return;
        }

        if (/^(-{3,}|\*{3,}|_{3,})$/.test(trimmed)) {
            flushParagraph();
            flushList();
            html.push('<hr />');
            return;
        }

        const headingMatch = trimmed.match(/^(#{1,6})\s+(.*)$/);
        if (headingMatch) {
            flushParagraph();
            flushList();
            const level = headingMatch[1].length;
            const content = applyInline(headingMatch[2]);
            html.push(`<h${level}>${content}</h${level}>`);
            return;
        }

        const quoteMatch = trimmed.match(/^>\s?(.*)$/);
        if (quoteMatch) {
            flushParagraph();
            flushList();
            html.push(`<blockquote>${applyInline(quoteMatch[1])}</blockquote>`);
            return;
        }

        const orderedMatch = trimmed.match(/^(\d+)\.\s+(.*)$/);
        if (orderedMatch) {
            flushParagraph();
            if (listType && listType !== 'ol') {
                flushList();
            }
            listType = 'ol';
            listBuffer.push(orderedMatch[2]);
            return;
        }

        const unorderedMatch = trimmed.match(/^[-*+]\s+(.*)$/);
        if (unorderedMatch) {
            flushParagraph();
            if (listType && listType !== 'ul') {
                flushList();
            }
            listType = 'ul';
            listBuffer.push(unorderedMatch[1]);
            return;
        }

        paragraphLines.push(trimmed);
    });

    flushParagraph();
    flushList();
    flushCode();

    return html.join('\n');
}

const props = withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
        id?: string;
        name?: string;
        required?: boolean;
        disabled?: boolean;
        rows?: number;
    }>(),
    {
        modelValue: '',
        placeholder: '',
        rows: 12,
    }
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    focus: [];
    blur: [];
}>();

const textareaRef = ref<HTMLTextAreaElement | null>(null);
const showPreview = ref(false);
const internalValue = ref(props.modelValue);

watch(
    () => props.modelValue,
    (value) => {
        if (value !== internalValue.value) {
            internalValue.value = value;
        }
    }
);

watch(internalValue, (value) => {
    emit('update:modelValue', value);
});

const previewHtml = computed(() => renderMarkdown(internalValue.value));

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Tab' && textareaRef.value) {
        event.preventDefault();
        insertText('    ');
        return;
    }

    if ((event.metaKey || event.ctrlKey) && textareaRef.value) {
        const key = event.key.toLowerCase();
        if (key === 'b') {
            event.preventDefault();
            applyBold();
        } else if (key === 'i') {
            event.preventDefault();
            applyItalic();
        } else if (key === 'k') {
            event.preventDefault();
            applyLink();
        }
    }
};

const updateSelection = (start: number, end: number) => {
    const el = textareaRef.value;
    if (!el) {
        return;
    }

    nextTick(() => {
        el.focus();
        el.setSelectionRange(start, end);
    });
};

const wrapSelection = (before: string, after: string, placeholder: string) => {
    const el = textareaRef.value;
    if (!el) {
        return;
    }

    const value = internalValue.value ?? '';
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = start !== end ? value.slice(start, end) : placeholder;
    const nextValue = `${value.slice(0, start)}${before}${selected}${after}${value.slice(end)}`;

    internalValue.value = nextValue;
    const selectionStart = start + before.length;
    const selectionEnd = selectionStart + selected.length;
    updateSelection(selectionStart, selectionEnd);
};

const applyLinePrefix = (prefix: string, placeholder: string) => {
    const el = textareaRef.value;
    if (!el) {
        return;
    }

    const value = internalValue.value ?? '';
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selection = value.slice(start, end);
    const content = start === end ? placeholder : selection;
    const lines = content.split('\n');
    const defaultText = prefix === '1. ' || prefix === '- '
        ? 'List item'
        : prefix === '> '
          ? 'Quoted text'
          : prefix.startsWith('#')
            ? 'Heading text'
            : placeholder;

    const formatted = lines
        .map((line, index) => {
            const trimmed = line.trim();

            if (!trimmed) {
                if (prefix === '1. ') {
                    return `${index + 1}. ${defaultText}`;
                }

                return `${prefix}${defaultText}`;
            }

            if (prefix === '1. ') {
                const cleaned = trimmed.replace(/^\d+\.\s+/, '');
                return `${index + 1}. ${cleaned || defaultText}`;
            }

            if (prefix === '- ') {
                const cleaned = trimmed.replace(/^[-*+]\s+/, '');
                return `${prefix}${cleaned || defaultText}`;
            }

            if (prefix === '> ') {
                const cleaned = trimmed.replace(/^>\s?/, '');
                return `${prefix}${cleaned || defaultText}`;
            }

            if (prefix.startsWith('#')) {
                const cleaned = trimmed.replace(/^#{1,6}\s+/, '');
                return `${prefix}${cleaned || defaultText}`;
            }

            return `${prefix}${trimmed}`;
        })
        .join('\n');

    const nextValue = `${value.slice(0, start)}${formatted}${value.slice(end)}`;
    internalValue.value = nextValue;
    updateSelection(start, start + formatted.length);
};

const insertText = (text: string, cursorOffset?: number) => {
    const el = textareaRef.value;
    if (!el) {
        return;
    }

    const value = internalValue.value ?? '';
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const nextValue = `${value.slice(0, start)}${text}${value.slice(end)}`;
    internalValue.value = nextValue;

    const position = start + (cursorOffset ?? text.length);
    updateSelection(position, position);
};

const applyBold = () => wrapSelection('**', '**', 'bold text');
const applyItalic = () => wrapSelection('*', '*', 'italic text');
const applyStrike = () => wrapSelection('~~', '~~', 'strikethrough');
const applyCode = () => wrapSelection('`', '`', 'inline code');
const applyCodeBlock = () => wrapSelection('```\n', '\n```', 'Code snippet');
const applyQuote = () => applyLinePrefix('> ', 'Quoted text');
const applyHeading = () => applyLinePrefix('# ', 'Heading');
const applyList = (type: ListType) => applyLinePrefix(type === 'ol' ? '1. ' : '- ', 'List item');
const insertDivider = () => insertText('\n\n---\n\n');

const applyLink = () => {
    const el = textareaRef.value;
    if (!el) {
        return;
    }

    const value = internalValue.value ?? '';
    const start = el.selectionStart;
    const end = el.selectionEnd;
    const selected = start !== end ? value.slice(start, end) : 'Link text';
    const placeholderUrl = 'https://';
    const replacement = `[${selected}](${placeholderUrl})`;
    internalValue.value = `${value.slice(0, start)}${replacement}${value.slice(end)}`;

    const urlStart = start + replacement.indexOf(placeholderUrl);
    updateSelection(urlStart, urlStart + placeholderUrl.length);
};

const togglePreview = (preview: boolean) => {
    showPreview.value = preview;
};

const onFocus = () => emit('focus');
const onBlur = () => emit('blur');
</script>

<template>
    <div class="flex flex-col overflow-hidden rounded-lg border border-input bg-background shadow-sm">
        <div class="flex flex-wrap items-center gap-1 border-b border-border bg-muted/40 px-3 py-2 text-xs font-medium text-muted-foreground">
            <div class="flex items-center gap-1">
                <button type="button" class="composer-button" @click="applyBold" title="Bold (Ctrl+B)">
                    <Icon name="bold" />
                </button>
                <button type="button" class="composer-button" @click="applyItalic" title="Italic (Ctrl+I)">
                    <Icon name="italic" />
                </button>
                <button type="button" class="composer-button" @click="applyStrike" title="Strikethrough">
                    <Icon name="strikethrough" />
                </button>
                <button type="button" class="composer-button" @click="applyHeading" title="Heading">
                    <Icon name="heading" />
                </button>
                <button type="button" class="composer-button" @click="applyQuote" title="Quote">
                    <Icon name="quote" />
                </button>
                <button type="button" class="composer-button" @click="applyList('ul')" title="Bulleted list">
                    <Icon name="list" />
                </button>
                <button type="button" class="composer-button" @click="applyList('ol')" title="Numbered list">
                    <Icon name="listOrdered" />
                </button>
                <button type="button" class="composer-button" @click="applyLink" title="Insert link (Ctrl+K)">
                    <Icon name="link" />
                </button>
                <button type="button" class="composer-button" @click="applyCode" title="Inline code">
                    <Icon name="code" />
                </button>
                <button type="button" class="composer-button" @click="applyCodeBlock" title="Code block">
                    <Icon name="codeSquare" />
                </button>
                <button type="button" class="composer-button" @click="insertDivider" title="Divider">
                    <Icon name="minus" />
                </button>
            </div>

            <div class="ml-auto flex items-center gap-1 rounded-md border border-transparent bg-background p-1">
                <button
                    type="button"
                    class="composer-toggle"
                    :class="!showPreview && 'active'"
                    @click="togglePreview(false)"
                >
                    Write
                </button>
                <button
                    type="button"
                    class="composer-toggle"
                    :class="showPreview && 'active'"
                    @click="togglePreview(true)"
                >
                    <Icon name="eye" class="mr-1" />
                    Preview
                </button>
            </div>
        </div>

        <div v-if="!showPreview" class="px-3 pb-3 pt-2">
            <textarea
                ref="textareaRef"
                v-model="internalValue"
                :id="props.id"
                :name="props.name"
                :rows="props.rows"
                :required="props.required"
                :disabled="props.disabled"
                :placeholder="props.placeholder"
                class="min-h-[240px] w-full resize-y bg-transparent text-sm text-foreground outline-none"
                @keydown="onKeydown"
                @focus="onFocus"
                @blur="onBlur"
            />
            <slot name="footer" />
        </div>
        <div v-else class="markdown-preview-container px-3 pb-3 pt-2">
            <div v-if="internalValue.trim().length" class="markdown-preview" v-html="previewHtml" />
            <p v-else class="text-sm italic text-muted-foreground">Nothing to preview yet. Start typing to see your message.</p>
        </div>
    </div>
</template>

<style scoped lang="postcss">
.composer-button {
    @apply inline-flex h-8 w-8 items-center justify-center rounded-md border border-transparent text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50;
}

.composer-toggle {
    @apply inline-flex items-center justify-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground;
}

.composer-toggle.active {
    @apply bg-primary/10 text-foreground;
}

.markdown-preview-container {
    @apply min-h-[240px] text-sm;
}

.markdown-preview {
    @apply space-y-4 text-sm leading-6 text-foreground;
}

.markdown-preview :deep(h1) {
    @apply text-2xl font-semibold;
}

.markdown-preview :deep(h2) {
    @apply text-xl font-semibold;
}

.markdown-preview :deep(h3) {
    @apply text-lg font-semibold;
}

.markdown-preview :deep(h4) {
    @apply text-base font-semibold;
}

.markdown-preview :deep(h5) {
    @apply text-sm font-semibold;
}

.markdown-preview :deep(h6) {
    @apply text-xs font-semibold uppercase tracking-wide;
}

.markdown-preview :deep(p) {
    @apply m-0 leading-6;
}

.markdown-preview :deep(code) {
    @apply rounded bg-muted px-1 py-0.5 text-sm;
}

.markdown-preview :deep(pre) {
    @apply overflow-x-auto rounded-lg border border-border bg-muted/50 p-4 text-sm;
}

.markdown-preview :deep(blockquote) {
    @apply border-l-4 border-muted-foreground/40 pl-4 text-muted-foreground;
}

.markdown-preview :deep(ul) {
    @apply list-disc space-y-2 pl-6;
}

.markdown-preview :deep(ol) {
    @apply list-decimal space-y-2 pl-6;
}

.markdown-preview :deep(li > ul),
.markdown-preview :deep(li > ol) {
    @apply mt-2;
}

.markdown-preview :deep(a) {
    @apply text-primary underline underline-offset-4 hover:text-primary/80;
}

.markdown-preview :deep(hr) {
    @apply my-6 border-muted;
}
</style>
