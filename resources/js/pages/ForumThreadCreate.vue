<script setup lang="ts">
import { computed, watch } from 'vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { type BreadcrumbItem } from '@/types';
import Button from '@/components/ui/button/Button.vue';
import Input from '@/components/ui/input/Input.vue';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import { type ForumPrefixLabel } from '@/components/forum/ForumPrefixBadge.vue';
import RichTextEditor from '@/components/editor/RichTextEditor.vue';
import ForumAttachmentField from '@/components/forum/ForumAttachmentField.vue';

interface BoardSummary {
    id: number;
    title: string;
    slug: string;
    description: string | null;
    category?: {
        title: string | null;
        slug: string | null;
    } | null;
}

const props = defineProps<{
    board: BoardSummary;
    prefixes: ForumPrefixLabel[];
    prefixesRequired: boolean;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    const trail: BreadcrumbItem[] = [{ title: 'Forum', href: '/forum' }];

    if (props.board.category?.title) {
        trail.push({ title: props.board.category.title, href: '/forum' });
    }

    trail.push({ title: props.board.title, href: route('forum.boards.show', { board: props.board.slug }) });
    trail.push({ title: 'Create thread', href: route('forum.threads.create', { board: props.board.slug }) });

    return trail;
});

// `honeypot` is a field nobody sees, which a script fills in (docs/automod.md).
const form = useForm({
    title: '',
    body: '',
    prefix: '',
    honeypot: '',
    attachments: [] as File[],
});

const attachmentError = (errors: Record<string, string | undefined>): string => {
    const found = Object.entries(errors).find(([key]) => key === 'attachments' || key.startsWith('attachments.'));

    return found?.[1] ?? '';
};

const draftStorageKey = computed(() => `forum:thread:draft:${props.board.id}`);

watch(
    () => form.body,
    () => {
        if (form.errors.body) {
            form.clearErrors('body');
        }
    },
);

const hasContent = (html: string) => html.replace(/<[^>]*>/g, '').trim() !== '';

const submit = () => {
    if (!hasContent(form.body)) {
        form.setError('body', 'Please enter some content before publishing.');
        return;
    }

    form.post(route('forum.threads.store', { board: props.board.slug }), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            if (draftStorageKey.value && typeof window !== 'undefined') {
                window.localStorage.removeItem(draftStorageKey.value);
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="`New Thread • ${props.board.title}`" />

        <form class="flex flex-1 flex-col gap-8 p-4" @submit.prevent="submit">
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Start a new discussion</h1>
                    <p class="text-sm text-muted-foreground">
                        Share updates, ask questions, or kick off a new conversation in {{ props.board.title }}.
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="route('forum.boards.show', { board: props.board.slug })">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">Publish thread</Button>
                </div>
            </div>

            <div class="space-y-6 rounded-lg border border-border bg-card p-6 shadow-xs">
                <div class="grid gap-2">
                    <Label for="thread_title">Thread title</Label>
                    <Input
                        id="thread_title"
                        v-model="form.title"
                        type="text"
                        placeholder="Summarise the topic in a sentence"
                        autocomplete="off"
                        required
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <div v-if="prefixes.length > 0" class="grid gap-2">
                    <Label for="thread_prefix">Prefix</Label>
                    <select
                        id="thread_prefix"
                        v-model="form.prefix"
                        class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm shadow-xs"
                        :required="prefixesRequired"
                    >
                        <option value="">{{ prefixesRequired ? 'Choose a prefix' : 'No prefix' }}</option>
                        <option v-for="prefix in prefixes" :key="prefix.id" :value="String(prefix.id)">{{ prefix.name }}</option>
                    </select>
                    <InputError :message="form.errors.prefix" />
                </div>

                <div class="grid gap-2">
                    <Label for="thread_body">Message</Label>
                    <RichTextEditor
                        id="thread_body"
                        v-model="form.body"
                        :placeholder="'Share the details, context, or questions to kickstart the discussion.'"
                        :storage-key="draftStorageKey"
                    />
                    <input
                        v-model="form.honeypot"
                        type="text"
                        name="honeypot"
                        class="absolute left-[-9999px] h-px w-px opacity-0"
                        tabindex="-1"
                        autocomplete="off"
                        aria-hidden="true"
                    />
                    <InputError :message="form.errors.body" />
                </div>

                <ForumAttachmentField
                    id="thread_files"
                    v-model="form.attachments"
                    :error="attachmentError(form.errors as Record<string, string | undefined>)"
                />

                <div v-if="props.board.description" class="rounded-md bg-muted/40 p-4 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">About {{ props.board.title }}</p>
                    <p>{{ props.board.description }}</p>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2">
                <Button variant="outline" as-child>
                    <Link :href="route('forum.boards.show', { board: props.board.slug })">Cancel</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">Publish thread</Button>
            </div>
        </form>
    </AppLayout>
</template>
